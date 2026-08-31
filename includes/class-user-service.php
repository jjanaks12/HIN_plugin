<?php
/**
 * User Service
 *
 * Handles user authentication, registration, role assignment, and profile data formatting.
 *
 * @package Handicraft_Auth
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_User_Service {

    /**
     * Authenticate user credentials.
     *
     * @param string $username_or_email
     * @param string $password
     * @return WP_User|WP_Error
     */
    public static function authenticate(string $username_or_email, string $password) {
        $username_or_email = sanitize_text_field($username_or_email);

        if (empty($username_or_email) || empty($password)) {
            return new WP_Error(
                'empty_credentials',
                'Username/email and password are required.',
                ['status' => 400]
            );
        }

        // Support logging in via email directly
        if (is_email($username_or_email)) {
            $user = get_user_by('email', $username_or_email);
            if ($user) {
                $username_or_email = $user->user_login;
            }
        }

        $authenticated_user = wp_authenticate($username_or_email, $password);

        if (is_wp_error($authenticated_user)) {
            return new WP_Error(
                'invalid_credentials',
                'Invalid username, email, or password.',
                ['status' => 401]
            );
        }

        return $authenticated_user;
    }

    /**
     * Register a new user with role assignment and custom metadata.
     *
     * @param array $data
     * @return WP_User|WP_Error
     */
    public static function register(array $data) {
        $email      = sanitize_email($data['email'] ?? '');
        $username   = sanitize_user($data['username'] ?? '');
        $password   = $data['password'] ?? '';
        $first_name = sanitize_text_field($data['first_name'] ?? '');
        $last_name  = sanitize_text_field($data['last_name'] ?? '');
        $role       = sanitize_key($data['role'] ?? 'customer');

        // Validation
        if (empty($email) || !is_email($email)) {
            return new WP_Error('invalid_email', 'A valid email address is required.', ['status' => 400]);
        }

        if (empty($password) || strlen($password) < 6) {
            return new WP_Error('invalid_password', 'Password must be at least 6 characters.', ['status' => 400]);
        }

        if (empty($username)) {
            // Auto-generate username from email prefix
            $username = sanitize_user(strstr($email, '@', true), true);
            if (username_exists($username)) {
                $username .= '_' . wp_rand(100, 999);
            }
        }

        if (username_exists($username)) {
            return new WP_Error('username_exists', 'This username is already taken.', ['status' => 409]);
        }

        if (email_exists($email)) {
            return new WP_Error('email_exists', 'An account with this email already exists.', ['status' => 409]);
        }

        // Limit allowed registration roles for security
        $allowed_roles = ['customer', 'wholesale'];
        if (!in_array($role, $allowed_roles, true)) {
            $role = 'customer';
        }

        $user_id = wp_insert_user([
            'user_login'   => $username,
            'user_pass'    => $password,
            'user_email'   => $email,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim("$first_name $last_name") ?: $username,
            'role'         => $role,
        ]);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        // Optional B2B / Wholesale metadata
        if (!empty($data['company_name'])) {
            update_user_meta($user_id, 'billing_company', sanitize_text_field($data['company_name']));
        }
        if (!empty($data['tax_id'])) {
            update_user_meta($user_id, '_tax_id', sanitize_text_field($data['tax_id']));
        }
        if (!empty($data['phone'])) {
            update_user_meta($user_id, 'billing_phone', sanitize_text_field($data['phone']));
        }
        if (!empty($data['country'])) {
            update_user_meta($user_id, 'billing_country', sanitize_text_field($data['country']));
        }

        return get_user_by('id', $user_id);
    }

    /**
     * Format WP_User into a clean API response object.
     *
     * @param WP_User $user
     * @return array
     */
    public static function format_user_profile(WP_User $user): array {
        $roles = (array) $user->roles;
        $is_wholesale = in_array('wholesale', $roles, true);

        return [
            'id'           => (int) $user->ID,
            'username'     => $user->user_login,
            'email'        => $user->user_email,
            'firstName'    => $user->first_name,
            'lastName'     => $user->last_name,
            'displayName'  => $user->display_name,
            'roles'        => $roles,
            'isWholesale'  => $is_wholesale,
            'companyName'  => get_user_meta($user->ID, 'billing_company', true) ?: '',
            'taxId'        => get_user_meta($user->ID, '_tax_id', true) ?: '',
            'country'      => get_user_meta($user->ID, 'billing_country', true) ?: '',
            'phone'        => get_user_meta($user->ID, 'billing_phone', true) ?: '',
            'registeredAt' => $user->user_registered,
        ];
    }
}
