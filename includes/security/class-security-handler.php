<?php
/**
 * REST API Security & Throttling Handler
 *
 * Implements IP rate limiting, origin verification, and HTTP security headers
 * to protect the headless WooCommerce REST endpoints against automated tools,
 * brute-force attacks, and CSRF attempts.
 *
 * @package Handicraft_Auth
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_Security_Handler {

    /**
     * Rate limits per minute by route category.
     */
    const SENSITIVE_ROUTE_LIMIT = 15; // Max 15 attempts / minute for login, register, checkout
    const GENERAL_ROUTE_LIMIT   = 120; // Max 120 requests / minute for standard catalog/menu endpoints
    const WINDOW_SECONDS        = 60;  // 1 minute sliding window

    /**
     * Initialize security hooks.
     */
    public function init(): void {
        // Enforce rate limiting and origin checks before route callback executes
        add_filter('rest_pre_dispatch', [$this, 'inspect_request_security'], 10, 3);

        // Inject security and rate-limit headers to responses
        add_filter('rest_post_dispatch', [$this, 'attach_security_headers'], 10, 3);
    }

    /**
     * Inspect request for rate limits and origin security before execution.
     *
     * @param mixed $result
     * @param WP_REST_Server $server
     * @param WP_REST_Request $request
     * @return mixed
     */
    public function inspect_request_security($result, $server, $request) {
        $route = $request->get_route();

        // Only enforce on our API namespace
        if (strpos($route, '/handicraft/v1') !== 0) {
            return $result;
        }

        // 1. IP Rate Limiting
        $rate_limit_error = $this->enforce_rate_limit($request, $route);
        if (is_wp_error($rate_limit_error)) {
            return $rate_limit_error;
        }

        // 2. Origin validation on mutating requests
        $origin_error = $this->enforce_origin_security($request);
        if (is_wp_error($origin_error)) {
            return $origin_error;
        }

        return $result;
    }

    /**
     * Enforce sliding window rate limit per client IP.
     *
     * @param WP_REST_Request $request
     * @param string $route
     * @return true|WP_Error
     */
    private function enforce_rate_limit(WP_REST_Request $request, string $route) {
        $client_ip = $this->get_client_ip();
        $is_sensitive = $this->is_sensitive_route($route);

        $limit = $is_sensitive ? self::SENSITIVE_ROUTE_LIMIT : self::GENERAL_ROUTE_LIMIT;
        $category = $is_sensitive ? 'sens' : 'gen';
        $transient_key = 'hin_rl_' . md5($client_ip . '_' . $category);

        $now = time();
        $record = get_transient($transient_key);

        if (!$record || !is_array($record) || empty($record['reset_at']) || $now >= $record['reset_at']) {
            $record = [
                'count'    => 1,
                'reset_at' => $now + self::WINDOW_SECONDS,
            ];
            set_transient($transient_key, $record, self::WINDOW_SECONDS);
            return true;
        }

        if ($record['count'] >= $limit) {
            $retry_after = max(1, $record['reset_at'] - $now);
            header('Retry-After: ' . $retry_after);
            header('X-RateLimit-Limit: ' . $limit);
            header('X-RateLimit-Remaining: 0');

            return new WP_Error(
                'rate_limit_exceeded',
                __('Too many requests. Please wait a moment before trying again.', 'handicraft-auth'),
                [
                    'status'      => 429,
                    'retry_after' => $retry_after,
                ]
            );
        }

        // Increment count and preserve remaining TTL
        $record['count']++;
        $remaining_ttl = max(1, $record['reset_at'] - $now);
        set_transient($transient_key, $record, $remaining_ttl);

        return true;
    }

    /**
     * Check if a route requires sensitive throttling.
     */
    private function is_sensitive_route(string $route): bool {
        $sensitive_patterns = [
            '/handicraft/v1/auth/login',
            '/handicraft/v1/auth/register',
            '/handicraft/v1/auth/refresh',
            '/handicraft/v1/orders/checkout',
            '/handicraft/v1/orders/track',
            '/handicraft/v1/contact',
        ];

        foreach ($sensitive_patterns as $pattern) {
            if (strpos($route, $pattern) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify Origin on mutating HTTP requests to prevent unauthorized cross-origin tampering.
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    private function enforce_origin_security(WP_REST_Request $request) {
        $method = $request->get_method();

        // Only inspect state-modifying requests (POST, PUT, PATCH, DELETE)
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        $origin = !empty($_SERVER['HTTP_ORIGIN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';

        // If an Origin header was supplied by a browser, enforce allowed origin whitelist
        if ($origin) {
            $allowed_origins = get_option('hin_cors_allowed_origins', []);
            $host = wp_parse_url($origin, PHP_URL_HOST);
            $is_local = in_array($host, ['localhost', '127.0.0.1'], true);

            // If origins are configured and incoming origin is not in the list, block request
            if (!empty($allowed_origins) && !in_array($origin, $allowed_origins, true) && !$is_local) {
                return new WP_Error(
                    'unauthorized_origin',
                    __('Requests from this origin are not permitted.', 'handicraft-auth'),
                    ['status' => 403]
                );
            }
        }

        return true;
    }

    /**
     * Attach standard HTTP security headers to all REST responses.
     *
     * @param WP_REST_Response $response
     * @param WP_REST_Server $server
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function attach_security_headers($response, $server, $request) {
        if ($response instanceof WP_REST_Response) {
            $response->header('X-Content-Type-Options', 'nosniff');
            $response->header('X-Frame-Options', 'SAMEORIGIN');
            $response->header('X-XSS-Protection', '1; mode=block');
            $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        return $response;
    }

    /**
     * Safely resolve real client IP.
     *
     * @return string
     */
    private function get_client_ip(): string {
        $ip_keys = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',        // Nginx proxy
            'HTTP_X_FORWARDED_FOR',  // Standard proxy chain
            'REMOTE_ADDR',           // Direct connection
        ];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', sanitize_text_field(wp_unslash($_SERVER[$key])));
                $ip  = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * Check if the authenticated user has permission for a specific resource and modifier.
     *
     * @param string $resource E.g. 'order', 'custom_order', 'profile'
     * @param string $action   E.g. 'create', 'read', 'update', 'delete', 'view'
     * @return true|WP_Error
     */
    public static function check_permission(string $resource, string $action) {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_unauthorized', __('Authentication required.', 'handicraft-auth'), ['status' => 401]);
        }

        if (!HIN_Capability_Registry::check_permission($resource, $action)) {
            $cap_slug = "{$resource}_{$action}";
            return new WP_Error(
                'rest_forbidden',
                sprintf(__('Insufficient permissions: requires capability %s.', 'handicraft-auth'), $cap_slug),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Check if the authenticated user has a specific capability slug.
     *
     * @param string $capability E.g. 'order_create'
     * @return true|WP_Error
     */
    public static function check_capability(string $capability) {
        $parsed = HIN_Capability_Registry::parse_capability($capability);
        return self::check_permission($parsed['resource'], $parsed['modifier']);
    }
}
