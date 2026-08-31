<?php
/**
 * REST API Order Endpoints for Headless WooCommerce Checkout
 *
 * @package HandicraftAuth
 * @subpackage Includes
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class HIN_Order_Routes
 *
 * Handles order creation, receipt viewing, and customer order history.
 */
class HIN_Order_Routes {

    /**
     * Namespace for REST API
     *
     * @var string
     */
    protected string $namespace = 'handicraft/v1';

    /**
     * Register REST API routes.
     */
    public function register_routes(): void {
        // POST /wp-json/handicraft/v1/orders/checkout
        register_rest_route($this->namespace, '/orders/checkout', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'create_checkout_order'],
            'permission_callback' => '__return_true',
            'args'                => $this->get_checkout_args_schema(),
        ]);

        // GET /wp-json/handicraft/v1/orders/(?P<id>\d+)
        register_rest_route($this->namespace, '/orders/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_single_order'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'description'       => __('Unique identifier for the WooCommerce Order.', 'handicraft-auth'),
                    'type'              => 'integer',
                    'required'          => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && intval($param) > 0;
                    },
                ],
                'order_key' => [
                    'description' => __('WooCommerce order key for guest verification.', 'handicraft-auth'),
                    'type'        => 'string',
                    'required'    => false,
                ],
            ],
        ]);

        // GET /wp-json/handicraft/v1/orders/my-orders
        register_rest_route($this->namespace, '/orders/my-orders', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_my_orders'],
            'permission_callback' => [$this, 'check_authenticated_user'],
            'args'                => [
                'page' => [
                    'type'    => 'integer',
                    'default' => 1,
                ],
                'per_page' => [
                    'type'    => 'integer',
                    'default' => 10,
                ],
            ],
        ]);
    }

    /**
     * Ensure user is authenticated for private order history.
     */
    public function check_authenticated_user(WP_REST_Request $request): bool {
        return get_current_user_id() > 0;
    }

    /**
     * Argument schema for POST /orders/checkout
     */
    public function get_checkout_args_schema(): array {
        return [
            'customer' => [
                'type'        => 'object',
                'required'    => true,
                'properties'  => [
                    'email'     => ['type' => 'string', 'format' => 'email', 'required' => true],
                    'phone'     => ['type' => 'string', 'required' => true],
                    'firstName' => ['type' => 'string', 'required' => true],
                    'lastName'  => ['type' => 'string', 'required' => true],
                ],
            ],
            'shipping' => [
                'type'        => 'object',
                'required'    => true,
                'properties'  => [
                    'firstName' => ['type' => 'string', 'required' => true],
                    'lastName'  => ['type' => 'string', 'required' => true],
                    'address1'  => ['type' => 'string', 'required' => true],
                    'address2'  => ['type' => 'string', 'required' => false],
                    'city'      => ['type' => 'string', 'required' => true],
                    'state'     => ['type' => 'string', 'required' => false],
                    'postcode'  => ['type' => 'string', 'required' => true],
                    'country'   => ['type' => 'string', 'required' => true],
                ],
            ],
            'billing' => [
                'type'        => 'object',
                'required'    => false,
            ],
            'items' => [
                'type'        => 'array',
                'required'    => true,
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'productId' => ['type' => 'integer', 'required' => true],
                        'quantity'  => ['type' => 'integer', 'required' => true],
                    ],
                ],
            ],
            'shippingMethod' => [
                'type'       => 'object',
                'required'   => true,
                'properties' => [
                    'methodId'    => ['type' => 'string', 'required' => true],
                    'methodTitle' => ['type' => 'string', 'required' => true],
                    'cost'        => ['type' => 'number', 'required' => true],
                ],
            ],
            'paymentMethod' => [
                'type'     => 'string',
                'required' => true,
            ],
            'currency' => [
                'type'     => 'string',
                'required' => false,
            ],
            'orderType' => [
                'type'     => 'string',
                'enum'     => ['wholesale', 'retail'],
                'required' => false,
                'default'  => 'retail',
            ],
            'customerNote' => [
                'type'     => 'string',
                'required' => false,
            ],
        ];
    }

    /**
     * Create WooCommerce order from checkout payload.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function create_checkout_order(WP_REST_Request $request): WP_REST_Response {
        if (!function_exists('wc_create_order')) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('WooCommerce is required for order processing.', 'handicraft-auth'),
            ], 500);
        }

        $customer_data   = $request->get_param('customer');
        $shipping_data   = $request->get_param('shipping');
        $billing_data    = $request->get_param('billing') ?: $shipping_data;
        $items_data      = $request->get_param('items');
        $shipping_method = $request->get_param('shippingMethod');
        $payment_method  = sanitize_text_field($request->get_param('paymentMethod') ?: 'wire_transfer');
        $customer_note   = sanitize_textarea_field($request->get_param('customerNote') ?: '');
        $requested_type  = sanitize_text_field($request->get_param('orderType') ?: '');

        if (empty($items_data) || !is_array($items_data)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Your order must contain at least one item.', 'handicraft-auth'),
            ], 400);
        }

        // Determine current user & wholesale status
        $current_user_id = get_current_user_id();
        $is_user_wholesale = false;
        if ($current_user_id > 0) {
            $user_roles = (array) (get_userdata($current_user_id)->roles ?? []);
            $is_user_wholesale = in_array('wholesale', $user_roles, true);
        }

        $country_header = sanitize_text_field($request->get_header('x_country_code') ?: $shipping_data['country'] ?? 'US');
        $is_intl = strtoupper($country_header) !== 'NP';
        $order_type = ($requested_type === 'wholesale' || $is_user_wholesale || ($is_intl && $requested_type !== 'retail')) ? 'wholesale' : 'retail';

        try {
            // Initialize Order
            $order = wc_create_order([
                'customer_id'   => $current_user_id > 0 ? $current_user_id : 0,
                'customer_note' => $customer_note,
            ]);

            if (is_wp_error($order) || !$order) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => __('Unable to create order. Please try again.', 'handicraft-auth'),
                ], 500);
            }

            // Set Addresses
            $order->set_address([
                'first_name' => sanitize_text_field($shipping_data['firstName'] ?? ''),
                'last_name'  => sanitize_text_field($shipping_data['lastName'] ?? ''),
                'address_1'  => sanitize_text_field($shipping_data['address1'] ?? ''),
                'address_2'  => sanitize_text_field($shipping_data['address2'] ?? ''),
                'city'       => sanitize_text_field($shipping_data['city'] ?? ''),
                'state'      => sanitize_text_field($shipping_data['state'] ?? ''),
                'postcode'   => sanitize_text_field($shipping_data['postcode'] ?? ''),
                'country'    => sanitize_text_field($shipping_data['country'] ?? 'NP'),
                'email'      => sanitize_email($customer_data['email'] ?? ''),
                'phone'      => sanitize_text_field($customer_data['phone'] ?? ''),
            ], 'shipping');

            $order->set_address([
                'first_name' => sanitize_text_field($billing_data['firstName'] ?? $shipping_data['firstName'] ?? ''),
                'last_name'  => sanitize_text_field($billing_data['lastName'] ?? $shipping_data['lastName'] ?? ''),
                'address_1'  => sanitize_text_field($billing_data['address1'] ?? $shipping_data['address1'] ?? ''),
                'address_2'  => sanitize_text_field($billing_data['address2'] ?? $shipping_data['address2'] ?? ''),
                'city'       => sanitize_text_field($billing_data['city'] ?? $shipping_data['city'] ?? ''),
                'state'      => sanitize_text_field($billing_data['state'] ?? $shipping_data['state'] ?? ''),
                'postcode'   => sanitize_text_field($billing_data['postcode'] ?? $shipping_data['postcode'] ?? ''),
                'country'    => sanitize_text_field($billing_data['country'] ?? $shipping_data['country'] ?? 'NP'),
                'email'      => sanitize_email($customer_data['email'] ?? ''),
                'phone'      => sanitize_text_field($customer_data['phone'] ?? ''),
            ], 'billing');

            // Determine transaction currency & fetch live exchange rate from YayCurrency at the exact time of order placement
            $requested_currency = strtoupper(sanitize_text_field($request->get_param('currency') ?? ''));
            if (empty($requested_currency)) {
                $requested_currency = ($order_type === 'wholesale' || $country_header !== 'NP') ? 'USD' : 'NPR';
            }

            $exchange_rate = 1.0;
            if ($requested_currency !== 'USD' && class_exists('Yay_Currency\Helpers\YayCurrencyHelper')) {
                $yay_currencies = \Yay_Currency\Helpers\YayCurrencyHelper::converted_currency();
                if (!empty($yay_currencies) && is_array($yay_currencies)) {
                    foreach ($yay_currencies as $curr) {
                        if (strtoupper($curr['currency'] ?? '') === $requested_currency) {
                            if (isset($curr['rate'])) {
                                $rate_val = is_array($curr['rate']) ? floatval($curr['rate']['value'] ?? 1.0) : floatval($curr['rate']);
                                if ($rate_val > 0) {
                                    $exchange_rate = $rate_val;
                                }
                            }
                            break;
                        }
                    }
                }
            }

            // Add Product Line Items with Locked Exchange Rate Pricing
            $total_weight = 0.0;
            foreach ($items_data as $item) {
                $product_id = intval($item['productId']);
                $qty        = max(1, intval($item['quantity']));
                $product    = wc_get_product($product_id);

                if (!$product) {
                    continue;
                }

                // Calculate product base unit price (in USD) based on order type
                $wholesale_price = get_post_meta($product_id, '_wholesale_price', true);
                if ($order_type === 'wholesale' && !empty($wholesale_price) && is_numeric($wholesale_price)) {
                    $base_unit_price = floatval($wholesale_price);
                } else {
                    $base_unit_price = floatval($product->get_price());
                }

                // Convert and lock unit price in the customer's selected transaction currency
                $effective_unit_price = round($base_unit_price * $exchange_rate, 2);

                $item_id = $order->add_product($product, $qty, [
                    'subtotal' => $effective_unit_price * $qty,
                    'total'    => $effective_unit_price * $qty,
                ]);

                // Track item metadata for permanent audit record
                if ($item_id) {
                    wc_add_order_item_meta($item_id, '_base_unit_price_usd', $base_unit_price);
                    wc_add_order_item_meta($item_id, '_effective_unit_price', $effective_unit_price);
                    wc_add_order_item_meta($item_id, '_exchange_rate_applied', $exchange_rate);
                    wc_add_order_item_meta($item_id, '_order_type', $order_type);
                }

                $p_weight = floatval($product->get_weight());
                $total_weight += ($p_weight > 0 ? $p_weight : 0.5) * $qty;
            }

            // Add Shipping Line
            if (!empty($shipping_method)) {
                $shipping_cost_base = max(0, floatval($shipping_method['cost'] ?? 0));
                $shipping_cost      = round($shipping_cost_base * $exchange_rate, 2);
                $shipping_title     = sanitize_text_field($shipping_method['methodTitle'] ?? 'Standard Courier');
                $shipping_id        = sanitize_text_field($shipping_method['methodId'] ?? 'custom_shipping');

                $shipping_item = new WC_Order_Item_Shipping();
                $shipping_item->set_method_title($shipping_title);
                $shipping_item->set_method_id($shipping_id);
                $shipping_item->set_total($shipping_cost);
                $order->add_item($shipping_item);
            }

            // Set Payment Method & Locked Currency
            $payment_titles = [
                'wire_transfer' => 'International Wire Transfer / SWIFT',
                'card'          => 'Credit / Debit Card Payment',
                'bank_transfer' => 'Direct Bank Transfer',
                'esewa'         => 'eSewa / Fonepay Digital Wallet',
                'cod'           => 'Cash on Delivery (Nepal)',
            ];
            $payment_title = $payment_titles[$payment_method] ?? ucwords(str_replace('_', ' ', $payment_method));

            $order->set_payment_method($payment_method);
            $order->set_payment_method_title($payment_title);
            $order->set_currency($requested_currency);

            // Record Meta for Order Classification & Exchange Rate
            $order->update_meta_data('_order_type', $order_type);
            $order->update_meta_data('_is_wholesale', $order_type === 'wholesale' ? 'yes' : 'no');
            $order->update_meta_data('_country_code', $country_header);
            $order->update_meta_data('_total_weight_kg', round($total_weight, 2));
            $order->update_meta_data('_exchange_rate', $exchange_rate);
            $order->update_meta_data('_base_currency', 'USD');

            // Calculate totals & set pending payment status
            $order->calculate_totals();
            $order->set_status('pending', __('Order placed via Headless Nuxt Storefront.', 'handicraft-auth'));
            $order->save();

            // Format response
            $formatted_order = $this->format_order_response($order);

            return new WP_REST_Response([
                'success' => true,
                'message' => __('Order placed successfully.', 'handicraft-auth'),
                'order'   => $formatted_order,
            ], 201);

        } catch (Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch single order details by ID and order key / customer authentication.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_single_order(WP_REST_Request $request): WP_REST_Response {
        $order_id  = intval($request->get_param('id'));
        $order_key = sanitize_text_field($request->get_param('order_key') ?: '');

        if (!function_exists('wc_get_order')) {
            return new WP_REST_Response(['success' => false, 'message' => 'WooCommerce not available.'], 500);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_REST_Response(['success' => false, 'message' => 'Order not found.'], 404);
        }

        $current_user_id = get_current_user_id();
        $order_customer_id = $order->get_customer_id();

        // Permission check: Authenticated owner OR valid matching order_key
        $is_owner = ($current_user_id > 0 && $current_user_id === $order_customer_id);
        $is_valid_key = (!empty($order_key) && hash_equals($order->get_order_key(), $order_key));

        if (!$is_owner && !$is_valid_key && !current_user_can('manage_woocommerce')) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Access denied. Invalid order key or credentials.', 'handicraft-auth'),
            ], 403);
        }

        return new WP_REST_Response([
            'success' => true,
            'order'   => $this->format_order_response($order),
        ], 200);
    }

    /**
     * Fetch order history for the authenticated user.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_my_orders(WP_REST_Request $request): WP_REST_Response {
        $user_id  = get_current_user_id();
        $page     = max(1, intval($request->get_param('page') ?: 1));
        $per_page = max(1, min(50, intval($request->get_param('per_page') ?: 10)));

        if (!function_exists('wc_get_orders')) {
            return new WP_REST_Response(['success' => false, 'orders' => []], 500);
        }

        $args = [
            'customer_id' => $user_id,
            'page'        => $page,
            'limit'       => $per_page,
            'paginate'    => true,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ];

        $results = wc_get_orders($args);
        $orders  = [];

        foreach ($results->orders as $order) {
            $orders[] = $this->format_order_response($order);
        }

        return new WP_REST_Response([
            'success'    => true,
            'orders'     => $orders,
            'pagination' => [
                'total'       => $results->total,
                'totalPages'  => $results->max_num_pages,
                'currentPage' => $page,
                'perPage'     => $per_page,
            ],
        ], 200);
    }

    /**
     * Format WooCommerce order object into clean REST response array.
     *
     * @param WC_Order $order
     * @return array
     */
    protected function format_order_response(WC_Order $order): array {
        $items = [];
        foreach ($order->get_items() as $item_id => $item) {
            /** @var WC_Order_Item_Product $item */
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            $image_url = '';
            if ($product) {
                $img_id = $product->get_image_id();
                $image_url = $img_id ? wp_get_attachment_image_url($img_id, 'medium') : '';
            }

            $items[] = [
                'id'        => $item_id,
                'productId' => $item->get_product_id(),
                'name'      => $item->get_name(),
                'quantity'  => $item->get_quantity(),
                'subtotal'  => floatval($item->get_subtotal()),
                'total'     => floatval($item->get_total()),
                'unitPrice' => floatval($item->get_subtotal() / max(1, $item->get_quantity())),
                'sku'       => $product ? $product->get_sku() : '',
                'image'     => $image_url,
            ];
        }

        $order_type = $order->get_meta('_order_type') ?: ($order->get_meta('_is_wholesale') === 'yes' ? 'wholesale' : 'retail');

        return [
            'id'             => $order->get_id(),
            'orderNumber'    => $order->get_order_number(),
            'orderKey'       => $order->get_order_key(),
            'status'         => $order->get_status(),
            'orderType'      => $order_type,
            'isWholesale'    => $order_type === 'wholesale',
            'currency'       => $order->get_currency(),
            'dateCreated'    => $order->get_date_created() ? $order->get_date_created()->date('c') : '',
            'total'          => floatval($order->get_total()),
            'subtotal'       => floatval($order->get_subtotal()),
            'shippingTotal'  => floatval($order->get_shipping_total()),
            'discountTotal'  => floatval($order->get_discount_total()),
            'paymentMethod'  => [
                'id'    => $order->get_payment_method(),
                'title' => $order->get_payment_method_title(),
            ],
            'shippingMethod' => [
                'title' => $order->get_shipping_method(),
                'cost'  => floatval($order->get_shipping_total()),
            ],
            'customer' => [
                'email'     => $order->get_billing_email(),
                'phone'     => $order->get_billing_phone(),
                'firstName' => $order->get_billing_first_name(),
                'lastName'  => $order->get_billing_last_name(),
            ],
            'shippingAddress' => [
                'firstName' => $order->get_shipping_first_name(),
                'lastName'  => $order->get_shipping_last_name(),
                'address1'  => $order->get_shipping_address_1(),
                'address2'  => $order->get_shipping_address_2(),
                'city'      => $order->get_shipping_city(),
                'state'     => $order->get_shipping_state(),
                'postcode'  => $order->get_shipping_postcode(),
                'country'   => $order->get_shipping_country(),
            ],
            'billingAddress' => [
                'firstName' => $order->get_billing_first_name(),
                'lastName'  => $order->get_billing_last_name(),
                'address1'  => $order->get_billing_address_1(),
                'address2'  => $order->get_billing_address_2(),
                'city'      => $order->get_billing_city(),
                'state'     => $order->get_billing_state(),
                'postcode'  => $order->get_billing_postcode(),
                'country'   => $order->get_billing_country(),
            ],
            'items'        => $items,
            'customerNote' => $order->get_customer_note(),
        ];
    }
}
