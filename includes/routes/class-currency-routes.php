<?php
/**
 * Currency REST API Routes
 *
 * Exposes active multi-currency options, exchange rates, and formatting rules
 * integrated with YayCurrency and native WooCommerce settings.
 *
 * @package   Handicraft_Auth
 * @namespace handicraft/v1
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_Currency_Routes {

    const NAMESPACE = 'handicraft/v1';

    /**
     * Register REST API routes for Currencies.
     */
    public function register_routes() {
        /**
         * @route   GET /wp-json/handicraft/v1/currencies
         * @desc    Retrieve active currencies list, exchange rates, and formatting rules.
         * @auth    Public
         */
        register_rest_route(self::NAMESPACE, '/currencies', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_get_currencies'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Get list of currencies, exchange rates, and symbols.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_get_currencies(WP_REST_Request $request): WP_REST_Response {
        $base_currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
        if (empty($base_currency)) {
            $base_currency = 'USD';
        }

        $currencies = [];

        // 1. Check YayCurrency Plugin Integration
        if (class_exists('Yay_Currency\Helpers\YayCurrencyHelper') && class_exists('Yay_Currency\Helpers\Helper')) {
            $yay_currencies = \Yay_Currency\Helpers\YayCurrencyHelper::converted_currency();

            if (!empty($yay_currencies) && is_array($yay_currencies)) {
                $country_codes_map = \Yay_Currency\Helpers\Helper::currency_code_by_country_code();
                $all_wc_currencies = function_exists('get_woocommerce_currencies') ? get_woocommerce_currencies() : [];

                foreach ($yay_currencies as $curr) {
                    $code = strtoupper(trim($curr['currency'] ?? ''));
                    if (empty($code)) {
                        continue;
                    }

                    // Check if enabled (status === '1' or true)
                    $is_enabled = !isset($curr['status']) || $curr['status'] === '1' || $curr['status'] === true;
                    if (!$is_enabled) {
                        continue;
                    }

                    $rate_val = 1.0;
                    if (isset($curr['rate'])) {
                        if (is_array($curr['rate'])) {
                            $rate_val = floatval($curr['rate']['value'] ?? 1.0);
                        } else {
                            $rate_val = floatval($curr['rate']);
                        }
                    }
                    if ($rate_val <= 0) {
                        $rate_val = 1.0;
                    }

                    $country_slug = isset($country_codes_map[$code]) ? $country_codes_map[$code] : strtolower(substr($code, 0, 2));
                    $flag_url = \Yay_Currency\Helpers\Helper::get_flag_by_country_code($country_slug);

                    $currencies[] = [
                        'code'              => $code,
                        'name'              => $all_wc_currencies[$code] ?? $code,
                        'symbol'            => html_entity_decode($curr['symbol'] ?? get_woocommerce_currency_symbol($code), ENT_QUOTES, 'UTF-8'),
                        'rate'              => $rate_val,
                        'isDefault'         => ($code === $base_currency) || ($rate_val == 1.0 && $code === 'USD'),
                        'flag'              => $flag_url,
                        'flagEmoji'         => $this->get_currency_flag_emoji($code),
                        'currencyPosition'  => $curr['currencyPosition'] ?? 'left',
                        'thousandSeparator' => $curr['thousandSeparator'] ?? ',',
                        'decimalSeparator'  => $curr['decimalSeparator'] ?? '.',
                        'numberDecimal'     => intval($curr['numberDecimal'] ?? 2),
                    ];
                }
            }
        }

        // 2. Fallback Default Currencies if YayCurrency is not configured yet
        if (empty($currencies)) {
            $default_list = [
                'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'rate' => 1.0, 'country' => 'us', 'emoji' => '🇺🇸'],
                'EUR' => ['name' => 'Euro', 'symbol' => '€', 'rate' => 0.92, 'country' => 'eu', 'emoji' => '🇪🇺'],
                'GBP' => ['name' => 'British Pound', 'symbol' => '£', 'rate' => 0.79, 'country' => 'gb', 'emoji' => '🇬🇧'],
                'AUD' => ['name' => 'Australian Dollar', 'symbol' => 'A$', 'rate' => 1.52, 'country' => 'au', 'emoji' => '🇦🇺'],
                'CAD' => ['name' => 'Canadian Dollar', 'symbol' => 'C$', 'rate' => 1.38, 'country' => 'ca', 'emoji' => '🇨🇦'],
                'NPR' => ['name' => 'Nepalese Rupee', 'symbol' => 'रू', 'rate' => 133.5, 'country' => 'np', 'emoji' => '🇳🇵'],
                'JPY' => ['name' => 'Japanese Yen', 'symbol' => '¥', 'rate' => 155.0, 'country' => 'jp', 'emoji' => '🇯🇵'],
            ];

            foreach ($default_list as $code => $info) {
                $currencies[] = [
                    'code'              => $code,
                    'name'              => $info['name'],
                    'symbol'            => $info['symbol'],
                    'rate'              => $info['rate'],
                    'isDefault'         => $code === $base_currency,
                    'flag'              => plugins_url('assets/flags/' . $info['country'] . '.svg', WP_PLUGIN_DIR . '/yaycurrency/yaycurrency.php'),
                    'flagEmoji'         => $info['emoji'],
                    'currencyPosition'  => 'left',
                    'thousandSeparator' => ',',
                    'decimalSeparator'  => '.',
                    'numberDecimal'     => ($code === 'NPR' || $code === 'JPY') ? 0 : 2,
                ];
            }
        }

        return new WP_REST_Response([
            'success'      => true,
            'baseCurrency' => $base_currency,
            'currencies'   => $currencies,
        ], 200);
    }

    /**
     * Map currency code to standard ISO flag emoji.
     *
     * @param string $code
     * @return string
     */
    private function get_currency_flag_emoji(string $code): string {
        $emoji_map = [
            'USD' => '🇺🇸',
            'EUR' => '🇪🇺',
            'GBP' => '🇬🇧',
            'AUD' => '🇦🇺',
            'CAD' => '🇨🇦',
            'NPR' => '🇳🇵',
            'INR' => '🇮🇳',
            'JPY' => '🇯🇵',
            'CNY' => '🇨🇳',
            'SGD' => '🇸🇬',
            'NZD' => '🇳🇿',
            'CHF' => '🇨🇭',
            'AED' => '🇦🇪',
            'HKD' => '🇭🇰',
            'THB' => '🇹🇭',
            'MYR' => '🇲🇾',
            'KRW' => '🇰🇷',
            'BRL' => '🇧🇷',
            'MXN' => '🇲🇽',
            'SEK' => '🇸🇪',
            'NOK' => '🇳🇴',
            'DKK' => '🇩🇰',
        ];

        return $emoji_map[strtoupper($code)] ?? '🌐';
    }
}
