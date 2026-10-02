<?php

/**
 * Auth REST API Routes
 *
 * Registers and handles endpoints for user login, registration, token validation,
 * and authenticated user profile retrieval.
 *
 * @package   Handicraft_Auth
 * @namespace handicraft/v1
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_Auth_Routes
{

    const NAMESPACE = 'handicraft/v1';

    /**
     * Register all REST API routes for Handicraft Auth.
     */
    public function register_routes()
    {

        register_rest_route(self::NAMESPACE, '/auth/refresh', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_refresh'],
            'permission_callback' => '__return_true',
            'args'                => [
                'token' => [
                    'description'       => 'The active refresh token string',
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        /**
         * @route   POST /wp-json/handicraft/v1/auth/login
         * @desc    Authenticate user credentials and issue signed JWT.
         * @auth    Public
         * @params  string username (required) - WP username or email address
         * @params  string password (required) - User plain text password
         * @returns 200 { success: bool, token: string, tokenType: 'Bearer', user: object }
         * @errors  400 Bad Request, 401 Unauthorized
         */
        register_rest_route(self::NAMESPACE, '/auth/login', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_login'],
            'permission_callback' => '__return_true',
            'args'                => [
                'username' => [
                    'description'       => 'User login username or email address',
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'password' => [
                    'description' => 'User account password',
                    'required'    => true,
                    'type'        => 'string',
                ],
            ],
        ]);

        /**
         * @route   POST /wp-json/handicraft/v1/auth/register
         * @desc    Register a new customer or wholesale account and issue signed JWT.
         * @auth    Public
         * @params  string email (required), string password (required), string [username], string [role]
         * @returns 201 { success: bool, message: string, token: string, tokenType: 'Bearer', user: object }
         * @errors  400 Bad Request, 409 Conflict
         */
        register_rest_route(self::NAMESPACE, '/auth/register', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_register'],
            'permission_callback' => '__return_true',
            'args'                => [
                'email' => [
                    'description'       => 'Unique user email address',
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_email',
                ],
                'password' => [
                    'description' => 'User password (min 6 characters)',
                    'required'    => true,
                    'type'        => 'string',
                ],
                'username' => [
                    'description'       => 'Optional unique username. Auto-generated if omitted.',
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_user',
                ],
                'role' => [
                    'description'       => 'User account role (customer or wholesale)',
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'customer',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'company_name' => [
                    'description'       => 'Company name for wholesale accounts',
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'tax_id' => [
                    'description'       => 'VAT or Tax ID for wholesale accounts',
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'country' => [
                    'description'       => 'Two-letter ISO country code',
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'phone' => [
                    'description'       => 'User contact phone number',
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        /**
         * @route   POST /wp-json/handicraft/v1/auth/validate
         * @desc    Validate JWT bearer token authenticity and expiration.
         * @auth    Public / Bearer header
         * @params  string [token]
         * @returns 200 { success: bool, valid: bool, user: object, exp: int }
         * @errors  400 Bad Request, 401 Unauthorized
         */
        register_rest_route(self::NAMESPACE, '/auth/validate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_validate'],
            'permission_callback' => '__return_true',
            'args'                => [
                'token' => [
                    'description'       => 'JWT Token string (optional if sent via Authorization Bearer header)',
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        /**
         * @route   GET /wp-json/handicraft/v1/auth/me
         * @desc    Retrieve profile data of the currently authenticated user.
         * @auth    Bearer JWT Token required
         * @returns 200 { success: bool, user: object }
         * @errors  401 Unauthorized, 404 Not Found
         */
        register_rest_route(self::NAMESPACE, '/auth/me', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'handle_me'],
                'permission_callback' => [$this, 'check_authenticated_permission'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'handle_update_me'],
                'permission_callback' => [$this, 'check_authenticated_permission'],
            ]
        ]);

        /**
         * @route   POST /wp-json/handicraft/v1/auth/upgrade
         * @desc    Upgrade to wholesale account.
         * @auth    Bearer JWT Token required
         */
        register_rest_route(self::NAMESPACE, '/auth/upgrade', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_upgrade'],
            'permission_callback' => [$this, 'check_authenticated_permission'],
            'args'                => [
                'company_name' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'tax_id'       => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'address'      => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
                'phone'        => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Handle Login Request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_login(WP_REST_Request $request): WP_REST_Response
    {
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

        $token_response = [
            'accessToken' => $token,
            'expiresIn'   => 30 * DAY_IN_SECONDS,
        ];

        // Issue refresh token only if "remember" is explicitly requested
        if ($request->get_param('remember')) {
            $expiration = 30 * DAY_IN_SECONDS;
            $refresh_token = bin2hex(random_bytes(32));
            $hashed_token = hash('sha256', $refresh_token);
            set_transient('hin_refresh_' . $hashed_token, $user->ID, $expiration);
            
            $token_response['refreshToken'] = $refresh_token;
        }

        return new WP_REST_Response([
            'success' => true,
            'token' => $token_response,
            'tokenType' => 'Bearer',
            'user' => $profile,
        ], 200);
    }

    /**
     * Handle Registration Request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_register(WP_REST_Request $request): WP_REST_Response
    {
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

        $token_response = [
            'accessToken' => $token,
            'expiresIn'   => 30 * DAY_IN_SECONDS,
        ];

        if ($request->get_param('remember')) {
            $expiration = 30 * DAY_IN_SECONDS;
            $refresh_token = bin2hex(random_bytes(32));
            $hashed_token = hash('sha256', $refresh_token);
            set_transient('hin_refresh_' . $hashed_token, $user->ID, $expiration);
            
            $token_response['refreshToken'] = $refresh_token;
        }

        return new WP_REST_Response([
            'success'   => true,
            'message'   => 'Account registered successfully.',
            'token'     => $token_response,
            'tokenType' => 'Bearer',
            'user'      => $profile,
        ], 201);
    }

    /**
     * Handle Token Validation Request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_validate(WP_REST_Request $request): WP_REST_Response
    {
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
     * Handle Get Me Request (Current Authenticated User).
     *
     * @return WP_REST_Response
     */
    public function handle_me(): WP_REST_Response
    {
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
     * Handle Update Me Request (Update Profile).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_update_me(WP_REST_Request $request): WP_REST_Response
    {
        $current_user_id = get_current_user_id();
        $params = $request->get_params();

        // Prevent updating sensitive fields via this endpoint
        unset($params['email'], $params['password'], $params['username'], $params['role']);

        $user = HIN_User_Service::update_user($current_user_id, $params);
        if (is_wp_error($user)) {
            return new WP_REST_Response([
                'success' => false,
                'code'    => $user->get_error_code(),
                'message' => $user->get_error_message(),
            ], $user->get_error_data()['status'] ?? 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'user'    => HIN_User_Service::format_user_profile($user),
        ], 200);
    }

    /**
     * Handle Upgrade to Wholesale.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_upgrade(WP_REST_Request $request): WP_REST_Response
    {
        $user_id = get_current_user_id();
        $user = get_user_by('id', $user_id);

        if (!$user) {
            return new WP_REST_Response(['message' => 'User not found.'], 404);
        }

        $company_name = $request->get_param('company_name');
        $tax_id       = $request->get_param('tax_id');
        $address      = $request->get_param('address');
        $phone        = $request->get_param('phone');

        // Update user meta
        update_user_meta($user_id, 'billing_company', $company_name);
        update_user_meta($user_id, 'billing_phone', $phone);
        update_user_meta($user_id, 'tax_id', $tax_id);
        update_user_meta($user_id, 'business_address', $address);
        
        // Custom wholesale flags
        update_user_meta($user_id, 'is_wholesale', 1);

        // Add wholesale role if not already present
        if (!in_array('wholesale', $user->roles)) {
            $user->add_role('wholesale');
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Successfully upgraded to wholesale account.',
            'user'    => HIN_User_Service::format_user_profile($user),
        ], 200);
    }

    /**
     * Permission callback for authenticated requests.
     *
     * @return bool
     */
    public function check_authenticated_permission(): bool
    {
        return is_user_logged_in();
    }

    /**
     * Handle Refresh Token Request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_refresh(WP_REST_Request $request): WP_REST_Response
    {
        $refresh_token = $request->get_param('token');
        $hashed_token = hash('sha256', $refresh_token);

        // 1. Check Redis for the token
        $user_id = get_transient('hin_refresh_' . $hashed_token);

        if (!$user_id) {
            return new WP_REST_Response(['message' => 'Invalid or expired refresh token'], 401);
        }

        $user = get_user_by('id', $user_id);

        // 2. Generate a new Access Token
        $new_access_token = HIN_JWT_Handler::generate_token($user);

        // 3. (Optional) Implement Token Rotation by deleting the old refresh token and generating a new one
        delete_transient('hin_refresh_' . $hashed_token);
        $new_refresh_token = bin2hex(random_bytes(32));
        set_transient('hin_refresh_' . hash('sha256', $new_refresh_token), $user->ID, 30 * DAY_IN_SECONDS);

        return new WP_REST_Response([
            'success' => true,
            'token' => [
                'accessToken' => $new_access_token,
                'refreshToken' => $new_refresh_token
            ]
        ], 200);
    }
}
