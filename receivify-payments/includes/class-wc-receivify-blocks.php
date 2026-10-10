<?php
/**
 * Cart & Checkout Blocks support.
 *
 * Since WooCommerce 8.3 a fresh store's checkout page is the BLOCK checkout,
 * which only lists payment methods that register themselves on the client
 * through `wc.wcBlocksRegistry`. A classic WC_Payment_Gateway on its own is
 * invisible there — the customer sees "no payment methods available". This
 * class is that registration. Payment itself still runs through the gateway's
 * process_payment(), which the Store API calls for classic gateways.
 */

if (!defined('ABSPATH')) exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Receivify_Blocks_Payment_Method extends AbstractPaymentMethodType {
    /** Must equal WC_Gateway_Receivify::$id. */
    protected $name = 'receivify';

    public function initialize() {
        $this->settings = get_option('woocommerce_receivify_settings', array());
    }

    public function is_active() {
        return filter_var($this->get_setting('enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'wc-receivify-blocks',
            plugins_url('assets/js/receivify-blocks.js', RECEIVIFY_PAYMENTS_FILE),
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'),
            RECEIVIFY_PAYMENTS_VERSION,
            true
        );
        return array('wc-receivify-blocks');
    }

    public function get_payment_method_data() {
        return array(
            'title' => $this->get_setting('title', 'Pay with crypto'),
            'description' => $this->get_setting('description', 'Pay with Bitcoin or another cryptocurrency.'),
            'supports' => array('products'),
        );
    }
}
