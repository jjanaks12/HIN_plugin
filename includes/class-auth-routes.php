<?php
/**
 * Auth REST API Routes
 *
 * Registers endpoints for authentication, registration, validation, and user profile retrieval.
 *
 * @package Handicraft_Auth
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_Auth_Routes {

    const NAMESPACE = 'handicraft/v1';

    /**
     * Register REST API routes.
     */
    public function register_routes() {
        // Login Endpoint
        register_rest_route(self::NAMESPACE, '/auth/login', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_login'],
            'permission_callback' => '__return_true',
            'args'                => [
                'username' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'password' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ]);

        // Register Endpoint
        register_rest_route(self::NAMESPACE, '/auth/register', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_register'],
            'permission_callback' => '__return_true',
            'args'                => [
                'email' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_email',
                ],
                'password' => [
                    'required' => true,
                    'type'     => 'string',
                ],
                'username' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_user',
                ],
                'role' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);

        // Token Validate Endpoint
        register_rest_route(self::NAMESPACE, '/auth/validate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_validate'],
            'permission_callback' => '__return_true',
        ]);

        // Get Current User Profile (Me)
        register_rest_route(self::NAMESPACE, '/auth/me', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_me'],
            'permission_callback' => [$this, 'check_authenticated_permission'],
        ]);
    }

    /**
     * Handle Login Request.
     */
    public function handle_login(WP_REST_Request $request): WP_REST_Response {
        $username = $request->get_param('username');
        $password = $request->get_param('password');

        $user = HIN_User_Service::authenticate($username, $password);
        if (is_wp_error($user)) {
            return new WP_REST_Response([
                'success' => false,
                'code'    => $user->get_error_code(),
                'message' => $user->get_error_message(),
            ], $user->get_error_data()['status'] ?? 400);
        }

        $token = HIN_JWT_Handler::generate_token($user);
        $profile = HIN_User_Service::format_user_profile($user);

        return new WP_REST_Response([
            'success'   => true,
            'token'     => $token,
            'tokenType' => 'Bearer',
            'user'      => $profile,
        ], 200);
    }

    /**
     * Handle Registration Request.
     */
    public function handle_register(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_params();

        $user = HIN_User_Service::register($params);
        if (is_wp_error($user)) {
            return new WP_REST_Response([
                'success' => false,
                'code'    => $user->get_error_code(),
                'message' => $user->get_error_message(),
            ], $user->get_error_data()['status'] ?? 400);
        }

        $token = HIN_JWT_Handler::generate_token($user);
        $profile = HIN_User_Service::format_user_profile($user);

        return new WP_REST_Response([
            'success'   => true,
            'message'   => 'Account registered successfully.',
            'token'     => $token,
            'tokenType' => 'Bearer',
            'user'      => $profile,
        ], 201);
    }

    /**
     * Handle Token Validation Request.
     */
    public function handle_validate(WP_REST_Request $request): WP_REST_Response {
        $token = HIN_JWT_Handler::get_token_from_request();

        if (!$token) {
            $token = $request->get_param('token');
        }

        if (empty($token)) {
            return new WP_REST_Response([
                'success' => false,
                'valid'   => false,
                'message' => 'No token provided.',
            ], 400);
        }

        $payload = HIN_JWT_Handler::validate_token($token);
        if (is_wp_error($payload)) {
            return new WP_REST_Response([
                'success' => false,
                'valid'   => false,
                'code'    => $payload->get_error_code(),
                'message' => $payload->get_error_message(),
            ], 401);
        }

        $user_id = (int) $payload['data']['user']['id'];
        $user = get_user_by('id', $user_id);

        if (!$user) {
            return new WP_REST_Response([
                'success' => false,
                'valid'   => false,
                'message' => 'User no longer exists.',
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'valid'   => true,
            'user'    => HIN_User_Service::format_user_profile($user),
            'exp'     => $payload['exp'],
        ], 200);
    }

    /**
     * Handle Get Me Request.
     */
    public function handle_me(): WP_REST_Response {
        $current_user_id = get_current_user_id();
        $user = get_user_by('id', $current_user_id);

        if (!$user) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'user'    => HIN_User_Service::format_user_profile($user),
        ], 200);
    }

    /**
     * Permission callback for authenticated requests.
     */
    public function check_authenticated_permission(): bool {
        return is_user_logged_in();
    }
}
