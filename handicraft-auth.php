<?php
/**
 * Plugin Name: Handicraft Auth
 * Plugin URI: https://handicraftinnepal.com
 * Description: Native JWT Authentication & REST API User Endpoints for Headless Nuxt 3 frontend.
 * Version: 1.0.0
 * Author: HIN Engineering
 * Text Domain: handicraft-auth
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HIN_AUTH_VERSION', '1.0.0');
define('HIN_AUTH_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Require Services
require_once HIN_AUTH_PLUGIN_DIR . 'includes/services/class-capability-registry.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/services/class-jwt-handler.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/services/class-user-service.php';

// Require Security
require_once HIN_AUTH_PLUGIN_DIR . 'includes/security/class-cors-handler.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/security/class-security-handler.php';

// Require REST API Routes
require_once HIN_AUTH_PLUGIN_DIR . 'includes/routes/class-auth-routes.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/routes/class-menu-routes.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/routes/class-catalog-routes.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/routes/class-currency-routes.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/routes/class-order-routes.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/routes/class-contact-routes.php';

// Require Admin Modules
require_once HIN_AUTH_PLUGIN_DIR . 'includes/admin/class-cors-settings.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/admin/class-roles-admin.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/admin/class-documentation-viewer.php';

// Require WooCommerce Modules
require_once HIN_AUTH_PLUGIN_DIR . 'includes/woocommerce/class-wholesale-settings.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/woocommerce/class-tier-pricing.php';

// Require Custom Post Types
require_once HIN_AUTH_PLUGIN_DIR . 'includes/post-types/class-quotes.php';

/**
 * Main Plugin Bootstrap Class
 */
class HIN_Auth_Plugin {

    /**
     * Singleton instance
     */
    private static ?HIN_Auth_Plugin $instance = null;

    public static function instance(): HIN_Auth_Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Register custom roles upon plugin activation
        register_activation_hook(__FILE__, [$this, 'on_activate']);

        // Register Navigation Menus
        add_action('after_setup_theme', [$this, 'register_nav_menus']);
        add_action('init', [$this, 'register_nav_menus']);

        // Authenticate requests via JWT Bearer token
        add_filter('determine_current_user', ['HIN_JWT_Handler', 'determine_current_user'], 20);
        add_filter('rest_authentication_errors', ['HIN_JWT_Handler', 'rest_authentication_errors'], 101);

        // Register REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Initialize CORS Handler
        $cors_handler = new HIN_CORS_Handler();
        $cors_handler->init();

        // Initialize CORS Settings
        $cors_settings = new HIN_CORS_Settings();
        $cors_settings->init();

        // Initialize Wholesale Settings
        $wholesale_settings = new HIN_Wholesale_Settings();
        $wholesale_settings->init();

        // Initialize Tier Pricing
        $tier_pricing = new HIN_Tier_Pricing();
        $tier_pricing->init();

        // Initialize /documentation Endpoint Viewer
        $doc_viewer = new HIN_Documentation_Viewer();
        $doc_viewer->init();

        // Initialize Custom Roles Admin UI
        if (is_admin()) {
            $roles_admin = new HIN_Roles_Admin();
            $roles_admin->init();
        }

        // Initialize Quotes (CPT & Endpoints)
        new HIN_Quotes();

        // Initialize Security Handler (Rate Limiting, Security Headers & Origin Checks)
        $security_handler = new HIN_Security_Handler();
        $security_handler->init();
    }

    /**
     * Register navigation menu locations for headless frontend.
     */
    public function register_nav_menus() {
        if (function_exists('register_nav_menus')) {
            register_nav_menus([
                'primary' => __('Primary Navigation Menu', 'handicraft-auth'),
                'footer'  => __('Footer Navigation Menu', 'handicraft-auth'),
                'mobile'  => __('Mobile Navigation Menu', 'handicraft-auth'),
            ]);
        }
    }

    /**
     * Activation logic: register custom roles like 'wholesale'.
     */
    public function on_activate() {
        // 1. Register Wholesale Customer Role
        if (!get_role('wholesale')) {
            add_role(
                'wholesale',
                __('Wholesale Customer', 'handicraft-auth'),
                [
                    'read'                   => true,
                    'pricing_wholesale_view' => true,
                    'view_wholesale_pricing' => true, // legacy alias
                    'custom_order_create'    => true,
                    'custom_order_read'      => true,
                    'custom_order_update'    => true,
                    'request_custom_order'   => true, // legacy alias
                    'order_create'           => true,
                    'order_read'             => true,
                    'dashboard_view'         => true,
                    'cart_add'               => true,
                    'profile_edit'           => true,
                ]
            );
        } else {
            $role = get_role('wholesale');
            $role->add_cap('pricing_wholesale_view');
            $role->add_cap('custom_order_create');
            $role->add_cap('custom_order_read');
            $role->add_cap('custom_order_update');
            $role->add_cap('order_create');
            $role->add_cap('order_read');
            $role->add_cap('dashboard_view');
            $role->add_cap('cart_add');
            $role->add_cap('profile_edit');
        }

        // Ensure customer role has standard self-service capabilities
        $customer_role = get_role('customer');
        if ($customer_role) {
            $customer_role->add_cap('dashboard_view');
            $customer_role->add_cap('cart_add');
            $customer_role->add_cap('profile_edit');
            $customer_role->add_cap('profile_upgradeable');
            $customer_role->add_cap('order_create');
            $customer_role->add_cap('order_read');
        }

        // 2. Register Product Manager Role
        if (!get_role('product_manager')) {
            add_role(
                'product_manager',
                __('Product Manager', 'handicraft-auth'),
                [
                    'read'                   => true,
                    'edit_posts'             => true,
                    'edit_published_posts'   => true,
                    'edit_others_posts'      => true,
                    'edit_products'          => true,
                    'edit_published_products'=> true,
                    'manage_woocommerce'     => true, // Give access to basic WC management if needed
                ]
            );
        }
    }

    /**
     * Register REST API routes.
     */
    public function register_routes() {
        $auth_routes = new HIN_Auth_Routes();
        $auth_routes->register_routes();

        $menu_routes = new HIN_Menu_Routes();
        $menu_routes->register_routes();

        $catalog_routes = new HIN_Catalog_Routes();
        $catalog_routes->register_routes();

        $currency_routes = new HIN_Currency_Routes();
        $currency_routes->register_routes();

        $order_routes = new HIN_Order_Routes();
        $order_routes->register_routes();

        $contact_routes = new HIN_Contact_Routes();
        $contact_routes->register_routes();
    }

    public function register_rest_routes() {
        $this->register_routes();
    }
}

// Initialize Plugin
HIN_Auth_Plugin::instance();
