<?php
/**
 * CORS Handler
 *
 * Manages Cross-Origin Resource Sharing (CORS) headers for the REST API
 * to allow the Headless Nuxt frontend to communicate securely.
 *
 * @package Handicraft_Auth
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_CORS_Handler {

    public function init() {
        // Headless CORS Headers for Nuxt 3 frontend
        add_action('init', [$this, 'handle_cors_preflight']);
        add_filter('allowed_http_origins', [$this, 'allow_custom_http_origins']);
        add_action('rest_api_init', [$this, 'setup_cors_headers'], 15);
    }

    /**
     * Whitelist incoming Origin in WordPress allowed HTTP origins.
     *
     * @param array $origins
     * @return array
     */
    public function allow_custom_http_origins(array $origins): array {
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            $origins[] = sanitize_text_field(wp_unslash($_SERVER['HTTP_ORIGIN']));
        }
        return array_values(array_unique(array_filter($origins)));
    }

    /**
     * Intercept and handle CORS preflight OPTIONS requests early.
     */
    public function handle_cors_preflight() {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->send_cors_headers();
            status_header(200);
            exit;
        }
    }

    /**
     * Send CORS headers.
     */
    public function send_cors_headers() {
        if (headers_sent()) {
            return;
        }

        $origin = !empty($_SERVER['HTTP_ORIGIN']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
        if ($origin) {
            header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Credentials: true');
            
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
                header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
            } else {
                header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Country-Code, X-Requested-With, Accept, Origin');
            }
            
            header('Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages, Link');
            header('Access-Control-Max-Age: 86400');
        }
        
        // Always add Vary: Origin so cached SSR responses don't break browser CORS
        header('Vary: Origin', false);
    }

    /**
     * Enable CORS for Headless Nuxt frontend on REST requests.
     */
    public function setup_cors_headers() {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', function ($value) {
            $this->send_cors_headers();
            return $value;
        });
    }
}
