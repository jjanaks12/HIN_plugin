<?php
/**
 * JWT Handler
 *
 * Lightweight, zero-dependency JWT generation, validation, and REST authentication.
 *
 * @package Handicraft_Auth
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_JWT_Handler {

    /**
     * Get the JWT signing secret key.
     */
    public static function get_secret_key(): string {
        if (defined('JWT_AUTH_SECRET_KEY') && !empty(JWT_AUTH_SECRET_KEY)) {
            return JWT_AUTH_SECRET_KEY;
        }

        if (defined('AUTH_KEY') && !empty(AUTH_KEY)) {
            return AUTH_KEY;
        }

        if (defined('SECURE_AUTH_KEY') && !empty(SECURE_AUTH_KEY)) {
            return SECURE_AUTH_KEY;
        }

        return 'hin-fallback-secret-key-change-in-production';
    }

    /**
     * Generate a signed JWT for a given WordPress user.
     *
     * @param WP_User $user
     * @param int $ttl Time to live in seconds (default 7 days)
     * @return string
     */
    public static function generate_token(WP_User $user, int $ttl = 604800): string {
        $issued_at  = time();
        $not_before = $issued_at;
        $expire_at  = $issued_at + $ttl;

        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256'
        ];

        $payload = [
            'iss'  => get_bloginfo('url'),
            'iat'  => $issued_at,
            'nbf'  => $not_before,
            'exp'  => $expire_at,
            'data' => [
                'user' => [
                    'id' => (int) $user->ID,
                ],
            ],
        ];

        $base64_header  = self::base64url_encode(wp_json_encode($header));
        $base64_payload = self::base64url_encode(wp_json_encode($payload));

        $signature = hash_hmac(
            'sha256',
            $base64_header . '.' . $base64_payload,
            self::get_secret_key(),
            true
        );

        $base64_signature = self::base64url_encode($signature);

        return $base64_header . '.' . $base64_payload . '.' . $base64_signature;
    }

    /**
     * Validate and decode a JWT token string.
     *
     * @param string $token
     * @return array|WP_Error
     */
    public static function validate_token(string $token) {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return new WP_Error('jwt_invalid_token', 'Invalid token format.', ['status' => 401]);
        }

        list($header64, $payload64, $sig64) = $parts;

        $header = json_decode(self::base64url_decode($header64), true);
        $payload = json_decode(self::base64url_decode($payload64), true);

        if (!$header || empty($header['alg']) || $header['alg'] !== 'HS256') {
            return new WP_Error('jwt_invalid_algorithm', 'Unsupported signature algorithm.', ['status' => 401]);
        }

        if (!$payload || empty($payload['exp']) || empty($payload['data']['user']['id'])) {
            return new WP_Error('jwt_invalid_payload', 'Token payload is missing required claims.', ['status' => 401]);
        }

        // Verify signature
        $expected_sig = hash_hmac('sha256', $header64 . '.' . $payload64, self::get_secret_key(), true);
        $provided_sig = self::base64url_decode($sig64);

        if (!hash_equals($expected_sig, $provided_sig)) {
            return new WP_Error('jwt_signature_mismatch', 'Invalid token signature.', ['status' => 401]);
        }

        // Verify expiration
        $now = time();
        if (isset($payload['nbf']) && $now < $payload['nbf']) {
            return new WP_Error('jwt_token_not_active', 'Token is not active yet.', ['status' => 401]);
        }

        if ($now >= $payload['exp']) {
            return new WP_Error('jwt_token_expired', 'Token has expired.', ['status' => 401]);
        }

        return $payload;
    }

    /**
     * Extract token from HTTP Authorization header.
     *
     * @return string|null
     */
    public static function get_token_from_request(): ?string {
        $auth_header = null;

        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth_header = sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth_header = sanitize_text_field(wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $auth_header = sanitize_text_field($headers['Authorization']);
            }
        }

        if (!$auth_header) {
            return null;
        }

        if (preg_match('/Bearer\s(\S+)/i', $auth_header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Hook into determine_current_user to authenticate WP REST requests with Bearer tokens.
     *
     * @param int|false $user_id
     * @return int|false
     */
    public static function determine_current_user($user_id) {
        if (!empty($user_id)) {
            return $user_id;
        }

        $token = self::get_token_from_request();
        if (!$token) {
            return $user_id;
        }

        $validation = self::validate_token($token);
        if (is_wp_error($validation)) {
            return false;
        }

        $user_id_from_token = (int) $validation['data']['user']['id'];
        $user = get_user_by('id', $user_id_from_token);

        return $user ? $user->ID : false;
    }

    /**
     * URL-safe Base64 encode.
     */
    private static function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL-safe Base64 decode.
     */
    private static function base64url_decode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
