<?php
/**
 * Capability Registry Service
 *
 * Defines, groups, and manages business capabilities following the <resource>_<modifier> standard.
 * Provides APIs for admin UI rendering and route permission checks.
 *
 * @package Handicraft_Auth
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_Capability_Registry {

    /**
     * Get grouped capability schema definition.
     *
     * @return array
     */
    public static function get_schema(): array {
        return [
            // ==========================================
            // Grouped CRUD Resources
            // ==========================================
            'crud_groups' => [
                'order' => [
                    'label'       => __('Orders (CRUD)', 'handicraft-auth'),
                    'description' => __('Customer & wholesale order lifecycle management', 'handicraft-auth'),
                    'resource'    => 'order',
                    'actions'     => [
                        'create' => [
                            'slug'        => 'order_create',
                            'modifier'    => 'create',
                            'label'       => __('Create Order', 'handicraft-auth'),
                            'description' => __('Submit new checkout orders', 'handicraft-auth'),
                        ],
                        'read' => [
                            'slug'        => 'order_read',
                            'modifier'    => 'read',
                            'label'       => __('View Orders', 'handicraft-auth'),
                            'description' => __('View order receipts and order history', 'handicraft-auth'),
                            'aliases'     => ['view_order'],
                        ],
                        'update' => [
                            'slug'        => 'order_update',
                            'modifier'    => 'update',
                            'label'       => __('Update Order', 'handicraft-auth'),
                            'description' => __('Update order details, addresses, or status', 'handicraft-auth'),
                        ],
                        'delete' => [
                            'slug'        => 'order_delete',
                            'modifier'    => 'delete',
                            'label'       => __('Cancel / Delete Order', 'handicraft-auth'),
                            'description' => __('Cancel order or remove order records', 'handicraft-auth'),
                        ],
                    ],
                ],
                'custom_order' => [
                    'label'       => __('Custom Orders & Quotes (CRUD)', 'handicraft-auth'),
                    'description' => __('B2B custom bespoke quotes and production requests', 'handicraft-auth'),
                    'resource'    => 'custom_order',
                    'actions'     => [
                        'create' => [
                            'slug'        => 'custom_order_create',
                            'modifier'    => 'create',
                            'label'       => __('Create Custom Order', 'handicraft-auth'),
                            'description' => __('Submit bespoke product quote inquiries', 'handicraft-auth'),
                            'aliases'     => ['request_custom_order'],
                        ],
                        'read' => [
                            'slug'        => 'custom_order_read',
                            'modifier'    => 'read',
                            'label'       => __('View Custom Orders', 'handicraft-auth'),
                            'description' => __('Inspect submitted custom orders and quotations', 'handicraft-auth'),
                            'aliases'     => ['view_quote'],
                        ],
                        'update' => [
                            'slug'        => 'custom_order_update',
                            'modifier'    => 'update',
                            'label'       => __('Update Custom Order', 'handicraft-auth'),
                            'description' => __('Revise specifications or update quotation status', 'handicraft-auth'),
                        ],
                        'delete' => [
                            'slug'        => 'custom_order_delete',
                            'modifier'    => 'delete',
                            'label'       => __('Cancel Custom Order', 'handicraft-auth'),
                            'description' => __('Cancel or retract quote request', 'handicraft-auth'),
                        ],
                    ],
                ],
            ],

            // ==========================================
            // Standalone Capabilities (Non-CRUD)
            // ==========================================
            'standalone_groups' => [
                'dashboard' => [
                    'label'    => __('Dashboard', 'handicraft-auth'),
                    'resource' => 'dashboard',
                    'actions'  => [
                        'view' => [
                            'slug'        => 'dashboard_view',
                            'modifier'    => 'view',
                            'label'       => __('View Dashboard', 'handicraft-auth'),
                            'description' => __('Access the headless account dashboard overview', 'handicraft-auth'),
                            'aliases'     => ['view_dashboard'],
                        ],
                    ],
                ],
                'cart' => [
                    'label'    => __('Cart', 'handicraft-auth'),
                    'resource' => 'cart',
                    'actions'  => [
                        'add' => [
                            'slug'        => 'cart_add',
                            'modifier'    => 'add',
                            'label'       => __('Add to Cart', 'handicraft-auth'),
                            'description' => __('Add items to cart and adjust cart quantities', 'handicraft-auth'),
                            'aliases'     => ['add_to_cart'],
                        ],
                    ],
                ],
                'profile' => [
                    'label'    => __('User Profile', 'handicraft-auth'),
                    'resource' => 'profile',
                    'actions'  => [
                        'edit' => [
                            'slug'        => 'profile_edit',
                            'modifier'    => 'edit',
                            'label'       => __('Edit Profile', 'handicraft-auth'),
                            'description' => __('Update profile details and address book', 'handicraft-auth'),
                            'aliases'     => ['update_address'],
                        ],
                        'upgradeable' => [
                            'slug'        => 'profile_upgradeable',
                            'modifier'    => 'upgradeable',
                            'label'       => __('Upgrade Profile', 'handicraft-auth'),
                            'description' => __('Eligible to upgrade account to wholesale status', 'handicraft-auth'),
                        ],
                    ],
                ],
                'pricing' => [
                    'label'    => __('Pricing & Catalog', 'handicraft-auth'),
                    'resource' => 'pricing',
                    'actions'  => [
                        'wholesale_view' => [
                            'slug'        => 'pricing_wholesale_view',
                            'modifier'    => 'wholesale_view',
                            'label'       => __('View Wholesale Pricing', 'handicraft-auth'),
                            'description' => __('View B2B tiered wholesale pricing on catalog', 'handicraft-auth'),
                            'aliases'     => ['view_wholesale_pricing'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get all registered capabilities (Predefined + Custom) as a flat slug => label array.
     *
     * @return array<string, string>
     */
    public static function get_all_capabilities(): array {
        $schema = self::get_schema();
        $caps   = [];

        // Add CRUD groups
        foreach ($schema['crud_groups'] as $group) {
            foreach ($group['actions'] as $act) {
                $caps[$act['slug']] = $act['label'];
            }
        }

        // Add Standalone groups
        foreach ($schema['standalone_groups'] as $group) {
            foreach ($group['actions'] as $act) {
                $caps[$act['slug']] = $act['label'];
            }
        }

        return $caps;
    }

    /**
     * Deconstruct a capability slug into resource and modifier.
     *
     * Resolution Strategy:
     * 1. Check exact match in registered capability catalog.
     * 2. If unknown/dynamic, split on the LAST underscore to disambiguate
     *    multi-word resources like 'custom_order' with action 'read'.
     *
     * @param string $slug
     * @return array{resource: string, modifier: string, label: string}
     */
    public static function parse_capability(string $slug): array {
        $schema = self::get_schema();

        // Check CRUD groups
        foreach ($schema['crud_groups'] as $group_key => $group) {
            foreach ($group['actions'] as $act_key => $act) {
                if ($act['slug'] === $slug) {
                    return [
                        'resource' => $group['resource'],
                        'modifier' => $act['modifier'],
                        'label'    => $act['label'],
                    ];
                }
            }
        }

        // Check Standalone groups
        foreach ($schema['standalone_groups'] as $group_key => $group) {
            foreach ($group['actions'] as $act_key => $act) {
                if ($act['slug'] === $slug) {
                    return [
                        'resource' => $group['resource'],
                        'modifier' => $act['modifier'],
                        'label'    => $act['label'],
                    ];
                }
            }
        }

        // Fallback: Disambiguate by splitting at the LAST underscore
        $last_underscore = strrpos($slug, '_');
        if ($last_underscore !== false) {
            return [
                'resource' => substr($slug, 0, $last_underscore),
                'modifier' => substr($slug, $last_underscore + 1),
                'label'    => ucwords(str_replace('_', ' ', $slug)),
            ];
        }

        return [
            'resource' => $slug,
            'modifier' => '',
            'label'    => $slug,
        ];
    }

    /**
     * Check if a user possesses permission for a given resource and modifier.
     *
     * Supports backward-compatible aliases and administrator overrides.
     *
     * @param string   $resource E.g. 'order', 'custom_order', 'profile'
     * @param string   $action   E.g. 'create', 'read', 'update', 'delete', 'view'
     * @param int|null $user_id  Optional WP_User ID (defaults to current user)
     * @return bool
     */
    public static function check_permission(string $resource, string $action, ?int $user_id = null): bool {
        $user = $user_id ? get_userdata($user_id) : wp_get_current_user();
        if (!$user || !$user->exists()) {
            return false;
        }

        // Administrator bypass
        if (in_array('administrator', (array) $user->roles, true) || user_can($user, 'manage_options')) {
            return true;
        }

        $canonical_slug = "{$resource}_{$action}";

        // 1. Direct check
        if (user_can($user, $canonical_slug)) {
            return true;
        }

        // 2. Check registered legacy aliases
        $aliases = self::get_aliases_for_slug($canonical_slug);
        foreach ($aliases as $alias) {
            if (user_can($user, $alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get fallback aliases for a canonical capability slug.
     *
     * @param string $slug
     * @return array
     */
    public static function get_aliases_for_slug(string $slug): array {
        $schema = self::get_schema();
        foreach (array_merge($schema['crud_groups'], $schema['standalone_groups']) as $group) {
            foreach ($group['actions'] as $act) {
                if ($act['slug'] === $slug && !empty($act['aliases'])) {
                    return $act['aliases'];
                }
            }
        }
        return [];
    }
}
