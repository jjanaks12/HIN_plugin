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
require_once HIN_AUTH_PLUGIN_DIR . 'includes/class-auth-routes.php';

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

        // Authenticate requests via JWT Bearer token
        add_filter('determine_current_user', ['HIN_JWT_Handler', 'determine_current_user'], 20);

        // Register REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Headless CORS Headers for Nuxt 3 frontend
        add_action('rest_api_init', [$this, 'setup_cors_headers'], 15);
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
    }

    public function register_rest_routes() {
        $this->register_routes();
    }

    /**
     * Enable CORS for Headless Nuxt frontend.
     */
    public function setup_cors_headers() {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', function ($value) {
            $origin = get_http_origin();
            if ($origin) {
                header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
                header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
                header('Access-Control-Allow-Credentials: true');
                header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Country-Code');
            }
            return $value;
        });
    }
}

// Initialize Plugin
HIN_Auth_Plugin::instance();
