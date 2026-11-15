<?php

/**
 * Tier Pricing
 *
 * Handles quantity-based tier pricing with percentage discounts.
 *
 * @package   Handicraft_Auth
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_Tier_Pricing
{

    public function init()
    {
        add_action('woocommerce_product_options_pricing', [$this, 'add_tier_pricing_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_tier_pricing_fields']);
    }

    /**
     * Add Tier Pricing Fields in WooCommerce Product Data
     */
    public function add_tier_pricing_fields()
    {
        global $post;

        $tier_pricing = get_post_meta($post->ID, '_tier_pricing', true);
        if (!is_array($tier_pricing)) {
            $tier_pricing = [];
        }

        $product = wc_get_product($post->ID);
        $base_price = $product ? floatval($product->get_price()) : 0;

        echo '<div class="options_group show_if_simple show_if_external" style="padding: 0 10px;">';
        echo '<h4>' . __('Tier Pricing (Quantity & Percentage Discount)', 'handicraft-auth') . '</h4>';

        echo '<div id="hin_tier_pricing_container" style="padding-bottom: 10px;">';

        if (!empty($tier_pricing)) {
            foreach ($tier_pricing as $index => $tier) {
                $discount = isset($tier['discount']) && $tier['discount'] !== '' ? $tier['discount'] : '';
                if ($discount === '' && !empty($tier['price']) && $base_price > 0) {
                    $discount = round((($base_price - floatval($tier['price'])) / $base_price) * 100, 1);
                }
                $this->render_tier_row($index, $tier['min_qty'] ?? '', $tier['max_qty'] ?? '', $discount);
            }
        }

        // Template for new row
        echo '<div id="hin_tier_pricing_template" style="display:none;">';
        $this->render_tier_row('{index}', '', '', '');
        echo '</div>';

        echo '<button type="button" class="button" id="hin_add_tier_pricing">' . __('Add Tier', 'handicraft-auth') . '</button>';
        echo '</div>';
        echo '</div>';

        // Add JavaScript for adding/removing rows
?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var tierIndex = <?php echo count($tier_pricing); ?>;

                $('#hin_add_tier_pricing').on('click', function() {
                    var template = $('#hin_tier_pricing_template').html().replace(/{index}/g, tierIndex);
                    $(this).before(template);
                    tierIndex++;
                });

                $(document).on('click', '.hin_remove_tier', function() {
                    $(this).closest('.hin_tier_row').remove();
                });
            });
        </script>
<?php
    }

    private function render_tier_row($index, $min, $max, $discount)
    {
        echo '<div class="hin_tier_row" style="display:flex; gap: 10px; margin-bottom: 15px; align-items: center;">';

        echo '<span style="font-weight:600; width:auto; float:none; margin:0; padding:0;">' . __('Min Qty:', 'handicraft-auth') . '</span>';
        echo '<input type="number" name="hin_tier_min[' . $index . ']" value="' . esc_attr($min) . '" step="1" min="1" style="width: 80px; margin-right: 15px;" />';

        echo '<span style="font-weight:600; width:auto; float:none; margin:0; padding:0;">' . __('Max Qty:', 'handicraft-auth') . '</span>';
        echo '<input type="number" name="hin_tier_max[' . $index . ']" value="' . esc_attr($max) . '" step="1" min="1" style="width: 80px; margin-right: 15px;" placeholder="&#8734;" />';

        echo '<span style="font-weight:600; width:auto; float:none; margin:0; padding:0;">' . __('Discount (%):', 'handicraft-auth') . '</span>';
        echo '<input type="number" name="hin_tier_discount[' . $index . ']" value="' . esc_attr($discount) . '" step="0.1" min="0" max="100" style="width: 90px; margin-right: 15px;" placeholder="%" />';

        echo '<button type="button" class="button hin_remove_tier">' . __('Remove', 'handicraft-auth') . '</button>';

        echo '</div>';
    }

    /**
     * Save Tier Pricing Fields
     */
    public function save_tier_pricing_fields($post_id)
    {
        if (!isset($_POST['hin_tier_min']) || !is_array($_POST['hin_tier_min'])) {
            delete_post_meta($post_id, '_tier_pricing');
            return;
        }

        $tier_pricing = [];
        $mins = $_POST['hin_tier_min'];
        $maxes = $_POST['hin_tier_max'];
        $discounts = $_POST['hin_tier_discount'] ?? [];

        $product = wc_get_product($post_id);
        $wholesale_price = get_post_meta($post_id, '_wholesale_price', true);
        $base_price = (!empty($wholesale_price) && floatval($wholesale_price) > 0)
            ? floatval($wholesale_price)
            : ($product ? floatval($product->get_price()) : 0);

        foreach ($mins as $index => $min) {
            $min = intval($min);
            if ($min <= 0) {
                continue;
            }

            $max = !empty($maxes[$index]) ? intval($maxes[$index]) : '';
            $discount = isset($discounts[$index]) && $discounts[$index] !== '' ? floatval($discounts[$index]) : 0;
            $discount = max(0, min(100, $discount));

            $unit_price = $base_price > 0 ? round($base_price * (1 - ($discount / 100)), 2) : 0;

            $tier_pricing[] = [
                'min_qty'  => $min,
                'max_qty'  => $max,
                'discount' => $discount,
                'price'    => $unit_price,
            ];
        }

        // Sort tiers by min_qty
        usort($tier_pricing, function ($a, $b) {
            return $a['min_qty'] <=> $b['min_qty'];
        });

        update_post_meta($post_id, '_tier_pricing', $tier_pricing);
    }

    /**
     * Static helper to get the effective price for a given quantity.
     * 
     * @param int $product_id
     * @param int $qty
     * @param float $base_price 
     * @return float
     */
    public static function get_price_for_quantity($product_id, $qty, $base_price)
    {
        $tier_pricing = get_post_meta($product_id, '_tier_pricing', true);
        if (empty($tier_pricing) || !is_array($tier_pricing)) {
            return $base_price;
        }

        $effective_price = $base_price;
        foreach ($tier_pricing as $tier) {
            $min = intval($tier['min_qty']);
            $max = $tier['max_qty'] !== '' ? intval($tier['max_qty']) : PHP_INT_MAX;

            if ($qty >= $min && $qty <= $max) {
                if (isset($tier['discount']) && is_numeric($tier['discount'])) {
                    $discount = floatval($tier['discount']);
                    $effective_price = $base_price * (1 - ($discount / 100));
                } elseif (isset($tier['price']) && is_numeric($tier['price'])) {
                    $effective_price = floatval($tier['price']);
                }
            }
        }

        return round($effective_price, 2);
    }
}
