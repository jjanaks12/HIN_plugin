<?php
/**
 * Wholesale Settings & Product Meta
 *
 * Handles WooCommerce Admin settings for minimum checkout amounts
 * and per-product quantity steps (increments).
 *
 * @package Handicraft_Auth
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_Wholesale_Settings {

    public function init() {
        // Add Global Setting for Wholesale Minimum Checkout
        add_filter('woocommerce_get_settings_general', [$this, 'add_wholesale_general_settings'], 10, 2);

        // Add Product Meta Fields for Quantity Steps
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_product_quantity_step_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_quantity_step_fields']);

        // Register REST Route
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes for Wholesale Settings.
     */
    public function register_routes() {
        register_rest_route('handicraft/v1', '/settings/wholesale', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_get_wholesale_settings'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Get wholesale global settings.
     */
    public function handle_get_wholesale_settings(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'minimumCheckoutAmount' => (float) get_option('hin_wholesale_min_amount', 10),
            'retailQuantityStep' => (int) get_option('hin_retail_quantity_step', 1),
            'wholesaleQuantityStep' => (int) get_option('hin_wholesale_quantity_step', 1),
        ], 200);
    }

    /**
     * Add global minimum checkout amount setting to WooCommerce > Settings > General.
     */
    public function add_wholesale_general_settings($settings, $current_section) {
        if ($current_section === '') {
            $settings[] = [
                'title' => __('Wholesale Settings', 'handicraft-auth'),
                'type'  => 'title',
                'desc'  => __('Configure global settings for B2B Wholesale customers.', 'handicraft-auth'),
                'id'    => 'hin_wholesale_settings_title',
            ];

            $settings[] = [
                'title'             => __('Minimum Checkout Amount', 'handicraft-auth'),
                'desc'              => __('The minimum order subtotal required for wholesale users to checkout.', 'handicraft-auth'),
                'id'                => 'hin_wholesale_min_amount',
                'type'              => 'number',
                'custom_attributes' => ['step' => 'any', 'min' => '0'],
                'default'           => '10',
                'css'               => 'width: 100px;',
            ];

            $settings[] = [
                'title'             => __('Global Retail Quantity Step', 'handicraft-auth'),
                'desc'              => __('The default increment amount when adding to cart for Retail customers.', 'handicraft-auth'),
                'id'                => 'hin_retail_quantity_step',
                'type'              => 'number',
                'custom_attributes' => ['step' => '1', 'min' => '1'],
                'default'           => '1',
                'css'               => 'width: 100px;',
            ];

            $settings[] = [
                'title'             => __('Global Wholesale Quantity Step', 'handicraft-auth'),
                'desc'              => __('The default increment amount when adding to cart for Wholesale customers.', 'handicraft-auth'),
                'id'                => 'hin_wholesale_quantity_step',
                'type'              => 'number',
                'custom_attributes' => ['step' => '1', 'min' => '1'],
                'default'           => '1',
                'css'               => 'width: 100px;',
            ];

            $settings[] = [
                'type' => 'sectionend',
                'id'   => 'hin_wholesale_settings_title',
            ];
        }
        return $settings;
    }

    /**
     * Add per-product quantity step fields in the General tab.
     */
    public function add_product_quantity_step_fields() {
        echo '<div class="options_group">';

        // Retail Quantity Step
        woocommerce_wp_text_input([
            'id'                => '_retail_quantity_step',
            'label'             => __('Retail Quantity Step', 'handicraft-auth'),
            'description'       => __('The increment amount when adding to cart for Retail customers (default: 1).', 'handicraft-auth'),
            'desc_tip'          => true,
            'type'              => 'number',
            'custom_attributes' => ['step' => '1', 'min' => '1'],
        ]);

        // Wholesale Quantity Step
        woocommerce_wp_text_input([
            'id'                => '_wholesale_quantity_step',
            'label'             => __('Wholesale Quantity Step', 'handicraft-auth'),
            'description'       => __('The increment amount when adding to cart for Wholesale customers (default: 1).', 'handicraft-auth'),
            'desc_tip'          => true,
            'type'              => 'number',
            'custom_attributes' => ['step' => '1', 'min' => '1'],
        ]);

        echo '</div>';
    }

    /**
     * Save the product quantity step fields.
     */
    public function save_product_quantity_step_fields($post_id) {
        $retail_step = isset($_POST['_retail_quantity_step']) ? intval($_POST['_retail_quantity_step']) : 1;
        $wholesale_step = isset($_POST['_wholesale_quantity_step']) ? intval($_POST['_wholesale_quantity_step']) : 1;

        update_post_meta($post_id, '_retail_quantity_step', max(1, $retail_step));
        update_post_meta($post_id, '_wholesale_quantity_step', max(1, $wholesale_step));
    }
}
