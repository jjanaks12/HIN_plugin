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

// Require Core Modules
require_once HIN_AUTH_PLUGIN_DIR . 'includes/class-jwt-handler.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/class-user-service.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/class-cors-handler.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/class-auth-routes.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/class-menu-routes.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/class-catalog-routes.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/class-currency-routes.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/class-order-routes.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/class-wholesale-settings.php';
require_once HIN_AUTH_PLUGIN_DIR . 'includes/class-documentation-viewer.php';

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

        // Register REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Initialize CORS Handler
        $cors_handler = new HIN_CORS_Handler();
        $cors_handler->init();

        // Initialize Wholesale Settings
        $wholesale_settings = new HIN_Wholesale_Settings();
        $wholesale_settings->init();

        // Initialize /documentation Endpoint Viewer
        $doc_viewer = new HIN_Documentation_Viewer();
        $doc_viewer->init();
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
        if (!get_role('wholesale')) {
            add_role(
                'wholesale',
                __('Wholesale Customer', 'handicraft-auth'),
                [
                    'read'         => true,
                    'edit_posts'   => false,
                    'delete_posts' => false,
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
    }

    public function register_rest_routes() {
        $this->register_routes();
    }
}

// Initialize Plugin
HIN_Auth_Plugin::instance();
