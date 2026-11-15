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
        $first_name = sanitize_text_field($data['firstName'] ?? $data['first_name'] ?? '');
        $last_name  = sanitize_text_field($data['lastName'] ?? $data['last_name'] ?? '');
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
        $company_name = $data['companyName'] ?? $data['company_name'] ?? '';
        if (!empty($company_name)) {
            update_user_meta($user_id, 'billing_company', sanitize_text_field($company_name));
        }
        
        $tax_id = $data['taxId'] ?? $data['tax_id'] ?? '';
        if (!empty($tax_id)) {
            update_user_meta($user_id, '_tax_id', sanitize_text_field($tax_id));
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
     * Update user profile data.
     *
     * @param int $user_id
     * @param array $data
     * @return WP_User|WP_Error
     */
    public static function update_user(int $user_id, array $data) {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found.', ['status' => 404]);
        }

        $userdata = ['ID' => $user_id];

        if (isset($data['firstName']) || isset($data['first_name'])) {
            $userdata['first_name'] = sanitize_text_field($data['firstName'] ?? $data['first_name']);
        }
        if (isset($data['lastName']) || isset($data['last_name'])) {
            $userdata['last_name'] = sanitize_text_field($data['lastName'] ?? $data['last_name']);
        }

        if (count($userdata) > 1) {
            $userdata['display_name'] = trim(($userdata['first_name'] ?? $user->first_name) . ' ' . ($userdata['last_name'] ?? $user->last_name)) ?: $user->user_login;
            $result = wp_update_user($userdata);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        if (isset($data['companyName']) || isset($data['company_name'])) {
            update_user_meta($user_id, 'billing_company', sanitize_text_field($data['companyName'] ?? $data['company_name']));
        }
        if (isset($data['taxId']) || isset($data['tax_id'])) {
            update_user_meta($user_id, '_tax_id', sanitize_text_field($data['taxId'] ?? $data['tax_id']));
        }
        if (isset($data['phone'])) {
            update_user_meta($user_id, 'billing_phone', sanitize_text_field($data['phone']));
        }
        if (isset($data['country'])) {
            update_user_meta($user_id, 'billing_country', sanitize_text_field($data['country']));
        }

        // Update Shipping Address if provided
        $shipping = $data['shippingAddress'] ?? $data['shipping_address'] ?? null;
        if (is_array($shipping)) {
            if (isset($shipping['firstName']) || isset($shipping['first_name'])) {
                update_user_meta($user_id, 'shipping_first_name', sanitize_text_field($shipping['firstName'] ?? $shipping['first_name']));
            }
            if (isset($shipping['lastName']) || isset($shipping['last_name'])) {
                update_user_meta($user_id, 'shipping_last_name', sanitize_text_field($shipping['lastName'] ?? $shipping['last_name']));
            }
            if (isset($shipping['company'])) {
                update_user_meta($user_id, 'shipping_company', sanitize_text_field($shipping['company']));
            }
            if (isset($shipping['address1']) || isset($shipping['address_1'])) {
                update_user_meta($user_id, 'shipping_address_1', sanitize_text_field($shipping['address1'] ?? $shipping['address_1']));
            }
            if (isset($shipping['address2']) || isset($shipping['address_2'])) {
                update_user_meta($user_id, 'shipping_address_2', sanitize_text_field($shipping['address2'] ?? $shipping['address_2']));
            }
            if (isset($shipping['city'])) {
                update_user_meta($user_id, 'shipping_city', sanitize_text_field($shipping['city']));
            }
            if (isset($shipping['state'])) {
                update_user_meta($user_id, 'shipping_state', sanitize_text_field($shipping['state']));
            }
            if (isset($shipping['postcode'])) {
                update_user_meta($user_id, 'shipping_postcode', sanitize_text_field($shipping['postcode']));
            }
            if (isset($shipping['country'])) {
                update_user_meta($user_id, 'shipping_country', sanitize_text_field($shipping['country']));
            }
            if (isset($shipping['phone'])) {
                update_user_meta($user_id, 'shipping_phone', sanitize_text_field($shipping['phone']));
            }
        }

        // Update Billing Address if provided
        $billing = $data['billingAddress'] ?? $data['billing_address'] ?? null;
        if (is_array($billing)) {
            if (isset($billing['firstName']) || isset($billing['first_name'])) {
                update_user_meta($user_id, 'billing_first_name', sanitize_text_field($billing['firstName'] ?? $billing['first_name']));
            }
            if (isset($billing['lastName']) || isset($billing['last_name'])) {
                update_user_meta($user_id, 'billing_last_name', sanitize_text_field($billing['lastName'] ?? $billing['last_name']));
            }
            if (isset($billing['company'])) {
                update_user_meta($user_id, 'billing_company', sanitize_text_field($billing['company']));
            }
            if (isset($billing['address1']) || isset($billing['address_1'])) {
                update_user_meta($user_id, 'billing_address_1', sanitize_text_field($billing['address1'] ?? $billing['address_1']));
            }
            if (isset($billing['address2']) || isset($billing['address_2'])) {
                update_user_meta($user_id, 'billing_address_2', sanitize_text_field($billing['address2'] ?? $billing['address_2']));
            }
            if (isset($billing['city'])) {
                update_user_meta($user_id, 'billing_city', sanitize_text_field($billing['city']));
            }
            if (isset($billing['state'])) {
                update_user_meta($user_id, 'billing_state', sanitize_text_field($billing['state']));
            }
            if (isset($billing['postcode'])) {
                update_user_meta($user_id, 'billing_postcode', sanitize_text_field($billing['postcode']));
            }
            if (isset($billing['country'])) {
                update_user_meta($user_id, 'billing_country', sanitize_text_field($billing['country']));
            }
            if (isset($billing['phone'])) {
                update_user_meta($user_id, 'billing_phone', sanitize_text_field($billing['phone']));
            }
            if (isset($billing['email'])) {
                update_user_meta($user_id, 'billing_email', sanitize_email($billing['email']));
            }
        }

        // Sync with WooCommerce Customer if active
        if (class_exists('WC_Customer')) {
            try {
                $customer = new WC_Customer($user_id);
                if (is_array($shipping)) {
                    if (isset($shipping['firstName']) || isset($shipping['first_name'])) {
                        $customer->set_shipping_first_name(sanitize_text_field($shipping['firstName'] ?? $shipping['first_name']));
                    }
                    if (isset($shipping['lastName']) || isset($shipping['last_name'])) {
                        $customer->set_shipping_last_name(sanitize_text_field($shipping['lastName'] ?? $shipping['last_name']));
                    }
                    if (isset($shipping['company'])) {
                        $customer->set_shipping_company(sanitize_text_field($shipping['company']));
                    }
                    if (isset($shipping['address1']) || isset($shipping['address_1'])) {
                        $customer->set_shipping_address_1(sanitize_text_field($shipping['address1'] ?? $shipping['address_1']));
                    }
                    if (isset($shipping['address2']) || isset($shipping['address_2'])) {
                        $customer->set_shipping_address_2(sanitize_text_field($shipping['address2'] ?? $shipping['address_2']));
                    }
                    if (isset($shipping['city'])) {
                        $customer->set_shipping_city(sanitize_text_field($shipping['city']));
                    }
                    if (isset($shipping['state'])) {
                        $customer->set_shipping_state(sanitize_text_field($shipping['state']));
                    }
                    if (isset($shipping['postcode'])) {
                        $customer->set_shipping_postcode(sanitize_text_field($shipping['postcode']));
                    }
                    if (isset($shipping['country'])) {
                        $customer->set_shipping_country(sanitize_text_field($shipping['country']));
                    }
                    if (isset($shipping['phone']) && method_exists($customer, 'set_shipping_phone')) {
                        $customer->set_shipping_phone(sanitize_text_field($shipping['phone']));
                    }
                }
                if (is_array($billing)) {
                    if (isset($billing['firstName']) || isset($billing['first_name'])) {
                        $customer->set_billing_first_name(sanitize_text_field($billing['firstName'] ?? $billing['first_name']));
                    }
                    if (isset($billing['lastName']) || isset($billing['last_name'])) {
                        $customer->set_billing_last_name(sanitize_text_field($billing['lastName'] ?? $billing['last_name']));
                    }
                    if (isset($billing['company'])) {
                        $customer->set_billing_company(sanitize_text_field($billing['company']));
                    }
                    if (isset($billing['address1']) || isset($billing['address_1'])) {
                        $customer->set_billing_address_1(sanitize_text_field($billing['address1'] ?? $billing['address_1']));
                    }
                    if (isset($billing['address2']) || isset($billing['address_2'])) {
                        $customer->set_billing_address_2(sanitize_text_field($billing['address2'] ?? $billing['address_2']));
                    }
                    if (isset($billing['city'])) {
                        $customer->set_billing_city(sanitize_text_field($billing['city']));
                    }
                    if (isset($billing['state'])) {
                        $customer->set_billing_state(sanitize_text_field($billing['state']));
                    }
                    if (isset($billing['postcode'])) {
                        $customer->set_billing_postcode(sanitize_text_field($billing['postcode']));
                    }
                    if (isset($billing['country'])) {
                        $customer->set_billing_country(sanitize_text_field($billing['country']));
                    }
                    if (isset($billing['phone'])) {
                        $customer->set_billing_phone(sanitize_text_field($billing['phone']));
                    }
                    if (isset($billing['email'])) {
                        $customer->set_billing_email(sanitize_email($billing['email']));
                    }
                }
                $customer->save();
            } catch (Throwable $e) {
                // Ignore WC_Customer exception if not properly initialized in CLI/headless
            }
        }

        clean_user_cache($user_id);
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

        // Resolve Shipping Address
        $shipping_address_1 = get_user_meta($user->ID, 'shipping_address_1', true);
        $shipping_country   = get_user_meta($user->ID, 'shipping_country', true);
        $shipping_city      = get_user_meta($user->ID, 'shipping_city', true);

        $has_shipping = !empty($shipping_address_1) || !empty($shipping_country) || !empty($shipping_city);

        $shipping_address = $has_shipping ? [
            'firstName' => get_user_meta($user->ID, 'shipping_first_name', true) ?: $user->first_name,
            'lastName'  => get_user_meta($user->ID, 'shipping_last_name', true) ?: $user->last_name,
            'company'   => get_user_meta($user->ID, 'shipping_company', true) ?: '',
            'address1'  => $shipping_address_1 ?: '',
            'address2'  => get_user_meta($user->ID, 'shipping_address_2', true) ?: '',
            'city'      => $shipping_city ?: '',
            'state'     => get_user_meta($user->ID, 'shipping_state', true) ?: '',
            'postcode'  => get_user_meta($user->ID, 'shipping_postcode', true) ?: '',
            'country'   => $shipping_country ?: '',
            'phone'     => get_user_meta($user->ID, 'shipping_phone', true) ?: get_user_meta($user->ID, 'billing_phone', true) ?: '',
            'email'     => $user->user_email,
        ] : null;

        // Resolve Billing Address
        $billing_address_1 = get_user_meta($user->ID, 'billing_address_1', true);
        $billing_country   = get_user_meta($user->ID, 'billing_country', true);
        $billing_city      = get_user_meta($user->ID, 'billing_city', true);

        $has_billing = !empty($billing_address_1) || !empty($billing_country) || !empty($billing_city);

        $billing_address = $has_billing ? [
            'firstName' => get_user_meta($user->ID, 'billing_first_name', true) ?: $user->first_name,
            'lastName'  => get_user_meta($user->ID, 'billing_last_name', true) ?: $user->last_name,
            'company'   => get_user_meta($user->ID, 'billing_company', true) ?: '',
            'address1'  => $billing_address_1 ?: '',
            'address2'  => get_user_meta($user->ID, 'billing_address_2', true) ?: '',
            'city'      => $billing_city ?: '',
            'state'     => get_user_meta($user->ID, 'billing_state', true) ?: '',
            'postcode'  => get_user_meta($user->ID, 'billing_postcode', true) ?: '',
            'country'   => $billing_country ?: '',
            'phone'     => get_user_meta($user->ID, 'billing_phone', true) ?: '',
            'email'     => get_user_meta($user->ID, 'billing_email', true) ?: $user->user_email,
        ] : null;

        return [
            'id'              => (int) $user->ID,
            'username'        => $user->user_login,
            'email'           => $user->user_email,
            'firstName'       => $user->first_name,
            'lastName'        => $user->last_name,
            'displayName'     => $user->display_name,
            'roles'           => $roles,
            'capabilities'    => array_keys(array_filter((array) $user->allcaps)),
            'isWholesale'     => $is_wholesale,
            'companyName'     => get_user_meta($user->ID, 'billing_company', true) ?: '',
            'taxId'           => get_user_meta($user->ID, '_tax_id', true) ?: '',
            'country'         => get_user_meta($user->ID, 'billing_country', true) ?: '',
            'phone'           => get_user_meta($user->ID, 'billing_phone', true) ?: '',
            'shippingAddress' => $shipping_address,
            'billingAddress'  => $billing_address,
            'registeredAt'    => $user->user_registered,
        ];
    }
}
