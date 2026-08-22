<?php
/**
 * Menu REST API Routes
 *
 * Registers and handles endpoints for retrieving WordPress Navigation Menus
 * formatted as structured hierarchical trees for the Nuxt 3 frontend.
 *
 * @package   Handicraft_Auth
 * @namespace handicraft/v1
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_Menu_Routes {

    const NAMESPACE = 'handicraft/v1';

    /**
     * Register REST API routes for Menus.
     */
    public function register_routes() {

        /**
         * @route   GET /wp-json/handicraft/v1/menus
         * @route   GET /wp-json/handicraft/v1/menus/{location}
         * @desc    Retrieve structured navigation menu tree by location or slug.
         * @auth    Public
         * @params  string location (optional) - Menu theme location (e.g. 'primary', 'footer')
         * @params  string slug (optional) - Menu slug
         * @returns 200 { success: bool, location: string, data: Menu[] }
         */
        register_rest_route(self::NAMESPACE, '/menus', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_get_menu'],
            'permission_callback' => '__return_true',
            'args'                => [
                'location' => [
                    'description'       => 'Menu theme location identifier (e.g. primary, footer)',
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'primary',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'slug' => [
                    'description'       => 'Menu slug identifier',
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/menus/(?P<location>[a-zA-Z0-9_-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_get_menu_by_location'],
            'permission_callback' => '__return_true',
            'args'                => [
                'location' => [
                    'description'       => 'Menu theme location identifier',
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);
    }

    /**
     * Handle Get Menu by Location Path Param.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_get_menu_by_location(WP_REST_Request $request): WP_REST_Response {
        return $this->handle_get_menu($request);
    }

    /**
     * Handle Get Menu Request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_get_menu(WP_REST_Request $request): WP_REST_Response {
        $location = $request->get_param('location') ?: 'primary';
        $slug     = $request->get_param('slug');

        $menu_object = null;

        // 1. Check by slug if provided
        if (!empty($slug)) {
            $menu_object = wp_get_nav_menu_object($slug);
        }

        // 2. Check by location
        if (!$menu_object && !empty($location)) {
            $locations = get_nav_menu_locations();
            if (!empty($locations[$location])) {
                $menu_object = wp_get_nav_menu_object($locations[$location]);
            }
        }

        // 3. Fallback to any menu if requested location not mapped
        if (!$menu_object) {
            $menus = wp_get_nav_menus();
            if (!empty($menus)) {
                $menu_object = $menus[0];
            }
        }

        if (!$menu_object) {
            return new WP_REST_Response([
                'success'  => true,
                'location' => $location,
                'data'     => [],
            ], 200);
        }

        $items = wp_get_nav_menu_items($menu_object->term_id);

        if (empty($items) || is_wp_error($items)) {
            return new WP_REST_Response([
                'success'  => true,
                'location' => $location,
                'data'     => [],
            ], 200);
        }

        $tree = $this->build_menu_tree($items);

        return new WP_REST_Response([
            'success'  => true,
            'location' => $location,
            'menu_name'=> $menu_object->name,
            'data'     => $tree,
        ], 200);
    }

    /**
     * Build nested hierarchical menu tree from flat nav menu items list.
     *
     * @param array $items
     * @return array
     */
    private function build_menu_tree(array $items): array {
        $site_url = home_url();
        $formatted = [];

        foreach ($items as $item) {
            $url = $item->url;

            // Make internal URLs relative for Nuxt router
            if (strpos($url, $site_url) === 0) {
                $relative = substr($url, strlen($site_url));
                $url = empty($relative) ? '/' : $relative;
            } elseif (preg_match('#^https?://[^/]+(/.*)?$#i', $url, $matches)) {
                // If it starts with slash or local domain
                $path = $matches[1] ?? '/';
                if (strpos($url, 'localhost') !== false || strpos($url, '.test') !== false || strpos($url, 'handicraftsinnepal.com') !== false) {
                    $url = $path;
                }
            }

            // Ensure leading slash on internal routes
            if (!preg_match('#^(https?:)?//#i', $url) && strpos($url, '/') !== 0) {
                $url = '/' . $url;
            }

            // Extract badge if specified in CSS classes, meta, or description
            $badge = $this->extract_badge($item);

            $formatted[$item->ID] = [
                'id'       => (int) $item->ID,
                'label'    => html_entity_decode($item->title, ENT_QUOTES, 'UTF-8'),
                'href'     => $url,
                'badge'    => $badge,
                'target'   => !empty($item->target) ? $item->target : null,
                'order'    => (int) $item->menu_order,
                'parentId' => (int) $item->menu_item_parent,
                'children' => [],
            ];
        }

        // Build tree hierarchy
        $tree = [];
        foreach ($formatted as $id => &$node) {
            $parentId = $node['parentId'];
            unset($node['parentId']); // Clean up response

            if (!empty($node['badge']) === false) {
                unset($node['badge']);
            }
            if (empty($node['target'])) {
                unset($node['target']);
            }

            if ($parentId === 0 || !isset($formatted[$parentId])) {
                $tree[] = &$node;
            } else {
                $formatted[$parentId]['children'][] = &$node;
            }
        }

        // Clean up empty children arrays if not needed, or keep for consistency
        return $tree;
    }

    /**
     * Extract badge metadata from menu item.
     * Looks for classes like 'badge-new', 'badge:new', 'new', 'hot', 'popular', 'sale',
     * or custom post meta '_menu_item_badge'.
     *
     * @param WP_Post $item
     * @return string|null
     */
    private function extract_badge($item): ?string {
        // 1. Check custom post meta
        $meta_badge = get_post_meta($item->ID, '_menu_item_badge', true);
        if (!empty($meta_badge)) {
            return sanitize_text_field($meta_badge);
        }

        // 2. Check classes
        $classes = (array) $item->classes;
        $known_badges = ['new', 'hot', 'popular', 'sale', 'featured', 'trending'];

        foreach ($classes as $class) {
            $class = trim(strtolower($class));
            if (empty($class)) continue;

            if (preg_match('/^badge[-:]([a-z0-9_-]+)$/i', $class, $matches)) {
                return $matches[1];
            }

            if (in_array($class, $known_badges, true)) {
                return $class;
            }
        }

        return null;
    }
}
