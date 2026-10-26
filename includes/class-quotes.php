<?php
if (!defined('ABSPATH')) {
    exit;
}

class HIN_Quotes {
    const NAMESPACE = 'handicraft/v1';

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('rest_api_init', [$this, 'register_routes']);
        
        // Admin hooks for Quote status
        add_action('add_meta_boxes', [$this, 'add_status_meta_box']);
        add_action('save_post_hin_quote_request', [$this, 'save_status_meta_box']);
        add_filter('manage_hin_quote_request_posts_columns', [$this, 'set_custom_columns']);
        add_action('manage_hin_quote_request_posts_custom_column', [$this, 'custom_column_content'], 10, 2);
    }

    public function register_cpt() {
        register_post_type('hin_quote_request', [
            'labels' => [
                'name'               => __('Quote Requests', 'handicraft'),
                'singular_name'      => __('Quote Request', 'handicraft'),
                'add_new'            => __('Add New Quote', 'handicraft'),
                'add_new_item'       => __('Add New Quote Request', 'handicraft'),
                'edit_item'          => __('Edit Quote', 'handicraft'),
                'new_item'           => __('New Quote', 'handicraft'),
                'view_item'          => __('View Quote', 'handicraft'),
                'search_items'       => __('Search Quotes', 'handicraft'),
                'not_found'          => __('No quotes found', 'handicraft'),
                'not_found_in_trash' => __('No quotes found in trash', 'handicraft'),
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_position'       => 56,
            'menu_icon'           => 'dashicons-media-document',
            'supports'            => ['title', 'editor', 'author'],
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'show_in_rest'        => false,
            'capabilities' => [
                'create_posts' => 'do_not_allow', // Prevents creating from wp-admin (read-only for admins)
            ],
            'map_meta_cap' => true,
        ]);
    }

    public function add_status_meta_box() {
        add_meta_box(
            'hin_quote_status_box',
            __('Quote Status', 'handicraft'),
            [$this, 'render_status_meta_box'],
            'hin_quote_request',
            'side',
            'high'
        );
    }

    public function render_status_meta_box($post) {
        wp_nonce_field('hin_save_quote_status', 'hin_quote_status_nonce');
        $current_status = get_post_meta($post->ID, 'quote_status', true) ?: 'pending';
        $statuses = [
            'pending'       => 'Pending Review',
            'reviewing'     => 'Reviewing',
            'quoted'        => 'Quote Sent',
            'approved'      => 'Approved & Paid',
            'in_production' => 'In Production',
            'shipped'       => 'Shipped',
            'cancelled'     => 'Cancelled'
        ];

        echo '<select name="quote_status" style="width: 100%;">';
        foreach ($statuses as $val => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($current_status, $val, false), esc_html($label));
        }
        echo '</select>';
    }

    public function save_status_meta_box($post_id) {
        if (!isset($_POST['hin_quote_status_nonce']) || !wp_verify_nonce($_POST['hin_quote_status_nonce'], 'hin_save_quote_status')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['quote_status'])) {
            update_post_meta($post_id, 'quote_status', sanitize_text_field($_POST['quote_status']));
        }
    }

    public function set_custom_columns($columns) {
        $columns['quote_status'] = __('Status', 'handicraft');
        $columns['target_qty'] = __('Target Qty', 'handicraft');
        return $columns;
    }

    public function custom_column_content($column, $post_id) {
        if ($column === 'quote_status') {
            $status = get_post_meta($post_id, 'quote_status', true) ?: 'pending';
            $formatted_status = ucwords(str_replace('_', ' ', $status));
            echo esc_html($formatted_status);
        }
        if ($column === 'target_qty') {
            echo esc_html(get_post_meta($post_id, 'quote_target_quantity', true));
        }
    }

    public function register_routes() {
        /**
         * @route   POST /wp-json/handicraft/v1/quotes
         * @desc    Submit a new custom quote request.
         * @auth    Logged In Users
         */
        register_rest_route(self::NAMESPACE, '/quotes', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_submit_quote'],
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ]);

        /**
         * @route   GET /wp-json/handicraft/v1/quotes
         * @desc    Retrieve the current user's quote requests.
         * @auth    Logged In Users
         */
        register_rest_route(self::NAMESPACE, '/quotes', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_get_quotes'],
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ]);

        /**
         * @route   PUT /wp-json/handicraft/v1/quotes/(?P<id>\d+)
         * @desc    Update quote status.
         * @auth    Logged In Users (Admin/Manager or quote owner)
         */
        register_rest_route(self::NAMESPACE, '/quotes/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'handle_update_quote'],
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ]);
    }

    public function handle_submit_quote(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $params = $request->get_json_params();

        $product_type = sanitize_text_field($params['productType'] ?? '');
        $company_name = sanitize_text_field($params['companyName'] ?? '');
        $specifications = sanitize_textarea_field($params['specifications'] ?? '');
        $target_quantity = intval($params['targetQuantity'] ?? 0);
        $target_date = sanitize_text_field($params['targetDate'] ?? '');
        $reference_links = sanitize_text_field($params['referenceLinks'] ?? '');

        if (empty($product_type) || empty($specifications) || empty($target_quantity)) {
            return new WP_Error('missing_fields', 'Product type, specifications, and target quantity are required.', ['status' => 400]);
        }

        $title = sprintf('Quote Request from %s (%s)', get_userdata($user_id)->display_name, date('Y-m-d'));

        $post_id = wp_insert_post([
            'post_type'    => 'hin_quote_request',
            'post_title'   => $title,
            'post_content' => $specifications,
            'post_status'  => 'publish',
            'post_author'  => $user_id,
        ]);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, 'quote_category', $product_type);
        update_post_meta($post_id, 'quote_company_name', $company_name);
        update_post_meta($post_id, 'quote_target_quantity', $target_quantity);
        update_post_meta($post_id, 'quote_target_date', $target_date);
        update_post_meta($post_id, 'quote_reference_links', $reference_links);
        update_post_meta($post_id, 'quote_status', 'pending');

        return new WP_REST_Response([
            'success'  => true,
            'quote_id' => $post_id,
            'message'  => 'Quote submitted successfully',
        ], 201);
    }

    public function handle_get_quotes(WP_REST_Request $request) {
        $user_id = get_current_user_id();

        $args = [
            'post_type'      => 'hin_quote_request',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $query = new WP_Query($args);
        $quotes = [];

        foreach ($query->posts as $post) {
            $quotes[] = [
                'id'              => $post->ID,
                'title'           => get_post_meta($post->ID, 'quote_category', true) ?: 'General Quote',
                'category'        => get_post_meta($post->ID, 'quote_category', true),
                'status'          => get_post_meta($post->ID, 'quote_status', true) ?: 'pending',
                'target_quantity' => get_post_meta($post->ID, 'quote_target_quantity', true),
                'target_date'     => get_post_meta($post->ID, 'quote_target_date', true),
                'date'            => get_the_date('c', $post->ID),
                'specifications'  => $post->post_content,
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'quotes'  => $quotes,
        ], 200);
    }

    public function handle_update_quote(WP_REST_Request $request) {
        $quote_id = $request->get_param('id');
        $status = sanitize_text_field($request->get_param('status'));
        
        $post = get_post($quote_id);
        if (!$post || $post->post_type !== 'hin_quote_request') {
            return new WP_Error('not_found', 'Quote not found.', ['status' => 404]);
        }

        // Check if user is author or has edit capability
        $user_id = get_current_user_id();
        if ($post->post_author != $user_id && !current_user_can('edit_others_posts')) {
            return new WP_Error('forbidden', 'You are not allowed to update this quote.', ['status' => 403]);
        }

        // Optional: Ensure only specific statuses are allowed
        $valid_statuses = ['pending', 'reviewing', 'quoted', 'approved', 'in_production', 'shipped', 'cancelled'];
        if (!in_array($status, $valid_statuses)) {
            return new WP_Error('invalid_status', 'Invalid status provided.', ['status' => 400]);
        }

        update_post_meta($quote_id, 'quote_status', $status);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Quote status updated successfully.',
            'status'  => $status
        ], 200);
    }
}
