<?php
/**
 * Plugin Name: Receivify Payments
 * Plugin URI: https://receivify.io
 * Description: Accept Bitcoin & crypto in WooCommerce via Receivify. Non-custodial, fiat-priced, signed webhooks.
 * Version: 1.1.0
 * Author: Receivify
 * Requires at least: 5.8
 * WC requires at least: 6.0
 * License: MIT
 *
 * The order total is sent to Receivify in the store currency (fiat); Receivify
 * locks the crypto rate, hosts checkout, and calls back a SIGNED webhook when the
 * payment settles. We verify the signature before touching the order.
 */

if (!defined('ABSPATH')) exit;

define('RECEIVIFY_PAYMENTS_FILE', __FILE__);
define('RECEIVIFY_PAYMENTS_VERSION', '1.1.0');

/*
 * Declare what this plugin is compatible with. Without it WooCommerce flags the
 * plugin as incompatible with High-Performance Order Storage (the default order
 * store on new installs) and with the block checkout. Both declarations are
 * true: orders are only touched through the WC_Order CRUD API (no direct post
 * meta), and the block checkout is supported via includes/class-wc-receivify-blocks.php.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/*
 * Block checkout. Since WooCommerce 8.3 a new store's checkout page is the block
 * checkout, which lists ONLY payment methods registered with the Blocks registry
 * — without this the gateway is invisible to customers there.
 */
add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) return;
    require_once __DIR__ . '/includes/class-wc-receivify-blocks.php';
    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        $registry->register(new WC_Receivify_Blocks_Payment_Method());
    });
});

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Gateway_Receivify extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'receivify';
            $this->method_title = 'Receivify (Crypto)';
            $this->method_description = 'Accept Bitcoin & crypto via Receivify. Non-custodial.';
            $this->has_fields = false;
            $this->supports = array('products');

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title', 'Pay with crypto');
            $this->description = $this->get_option('description', 'Pay with Bitcoin or another cryptocurrency.');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            // Webhook endpoint: /?wc-api=receivify_webhook
            add_action('woocommerce_api_receivify_webhook', array($this, 'handle_webhook'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array('title' => 'Enable/Disable', 'type' => 'checkbox', 'label' => 'Enable Receivify', 'default' => 'no'),
                'title' => array('title' => 'Title', 'type' => 'text', 'default' => 'Pay with crypto'),
                'description' => array('title' => 'Description', 'type' => 'textarea', 'default' => 'Pay with Bitcoin or another cryptocurrency.'),
                'api_url' => array('title' => 'Receivify API URL', 'type' => 'text', 'default' => 'https://receivify.io', 'description' => 'Base URL of your Receivify instance.'),
                'api_key' => array('title' => 'API key', 'type' => 'password', 'description' => 'Your rcv_live_… key. Server-side only.'),
                'webhook_secret' => array('title' => 'Webhook secret', 'type' => 'password', 'description' => 'Your whsec_… secret, used to verify callbacks.'),
                'webhook_secret_previous' => array('title' => 'Previous webhook secret', 'type' => 'password',
                    'description' => 'Only while a rotation is inside its grace window (24h by default). Receivify signs with the CURRENT secret, but the previous one stays acceptable until prev_expires_at, so a receiver must try both. Clear this afterwards.'),
                'asset' => array('title' => 'Settlement coin', 'type' => 'select', 'default' => 'btc',
                    'options' => array('btc' => 'Bitcoin', 'ltc' => 'Litecoin', 'doge' => 'Dogecoin', 'bch' => 'Bitcoin Cash', 'xmr' => 'Monero'),
                    'description' => 'The coin customers pay in. Must be enabled + settleable in your Receivify admin.'),
            );
        }

        /** Create a Receivify invoice for the order, redirect to hosted checkout. */
        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $api_url = rtrim($this->get_option('api_url'), '/');
            $callback = add_query_arg('wc-api', 'receivify_webhook', home_url('/'));
            $reference = self::order_reference($order_id);

            $res = wp_remote_post($api_url . '/api/v1/invoices', array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->get_option('api_key'),
                    'Content-Type' => 'application/json',
                    'Idempotency-Key' => $reference,
                ),
                'body' => wp_json_encode(array(
                    // A STRING, not a float: money never round-trips through a double.
                    'amount_fiat' => (string) $order->get_total(),
                    'currency' => get_woocommerce_currency(),
                    'asset' => $this->get_option('asset', 'btc'),
                    'reference' => $reference,
                    'callback_url' => $callback,
                    'metadata' => array('order_id' => $order_id, 'source' => 'woocommerce'),
                )),
            ));

            if (is_wp_error($res) || wp_remote_retrieve_response_code($res) >= 400) {
                $msg = is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_body($res);
                wc_add_notice('Payment error: ' . $msg, 'error');
                return array('result' => 'failure');
            }

            $data = json_decode(wp_remote_retrieve_body($res), true);
            if (empty($data['checkout_url'])) {
                wc_add_notice('Payment error: no checkout URL returned.', 'error');
                return array('result' => 'failure');
            }

            $order->update_status('pending', 'Awaiting crypto payment via Receivify.');
            $order->update_meta_data('_receivify_invoice_id', $data['id']);
            $order->save();
            wc_reduce_stock_levels($order_id);
            WC()->cart->empty_cart();

            return array('result' => 'success', 'redirect' => $data['checkout_url']);
        }

        /**
         * Verify the signed webhook and advance the order.
         *
         * The envelope is { event, sequence, created_at, data } for EVERY family —
         * `data` is the resource. Delivery is at-least-once and can arrive out of
         * order, so we de-duplicate on (event, sequence) before touching anything.
         */
        public function handle_webhook() {
            // The RAW bytes. Never json_encode(json_decode(...)) — re-encoding
            // changes key order and escaping, and the HMAC will not match.
            $body = file_get_contents('php://input');
            $header = isset($_SERVER['HTTP_X_RECEIVIFY_SIGNATURE']) ? $_SERVER['HTTP_X_RECEIVIFY_SIGNATURE'] : '';
            if (!$this->verify_signature($body, $header)) {
                status_header(401);
                echo 'bad signature';
                exit;
            }

            $event = json_decode($body, true);
            if (!is_array($event) || empty($event['event'])) {
                status_header(400);
                echo 'bad envelope';
                exit;
            }

            $type = (string) $event['event'];
            $sequence = isset($event['sequence']) ? (int) $event['sequence'] : 0;
            $data = (isset($event['data']) && is_array($event['data'])) ? $event['data'] : array();
            $order_id = isset($data['reference']) ? (int) self::order_id_from_reference((string) $data['reference']) : 0;
            $order = $order_id ? wc_get_order($order_id) : null;

            if ($order) {
                // Idempotency: the same (event, sequence) never applies twice.
                $seen = $order->get_meta('_receivify_seen_events');
                $seen = is_array($seen) ? $seen : array();
                $key = $type . ':' . $sequence;

                if (!in_array($key, $seen, true)) {
                    $seen[] = $key;
                    $order->update_meta_data('_receivify_seen_events', array_slice($seen, -50));
                    $order->save();

                    $invoice_id = isset($data['id']) ? $data['id'] : '';

                    if (in_array($type, array('invoice.paid', 'invoice.swept', 'sweep.sent'), true)) {
                        if (!$order->is_paid()) {
                            $order->payment_complete($invoice_id);
                            $order->add_order_note('Receivify: payment confirmed on-chain (' . $type . ').');
                        }
                    } elseif ($type === 'invoice.confirming') {
                        $order->add_order_note('Receivify: payment detected, awaiting confirmations.');
                    } elseif ($type === 'invoice.partial' || $type === 'invoice.partial_expired') {
                        $order->add_order_note('Receivify: underpaid — received '
                            . (isset($data['amount_received']) ? $data['amount_received'] : '?') . ' of '
                            . (isset($data['amount_expected']) ? $data['amount_expected'] : '?') . ' base units.');
                    } elseif ($type === 'invoice.expired' || $type === 'invoice.cancelled') {
                        $order->update_status('cancelled', 'Receivify: invoice ' . substr($type, 8) . ' unpaid.');
                    } elseif ($type === 'invoice.failed') {
                        $order->update_status('failed', 'Receivify: settlement failed — needs review.');
                    }
                    // `test`, `forward.*` and any event name added after this
                    // plugin was written fall through untouched, by design.
                }
            }

            status_header(200);   // any 2xx marks the delivery delivered
            echo 'ok';
            exit;
        }

        /**
         * A random token for THIS install, created once and kept in an option.
         *
         * Invoice `reference` de-duplicates per Receivify account, not per shop.
         * A bare order id ("11") collides when two shops share one account, or
         * when a reinstalled shop restarts its order numbers: Receivify then
         * replays the other order's invoice or refuses with 409. Prefixing the
         * reference with this token keeps it unique to this shop.
         */
        private static function store_token() {
            $token = get_option('receivify_store_token');
            if (!is_string($token) || !preg_match('/^[a-f0-9]{10}$/', $token)) {
                $token = bin2hex(random_bytes(5));
                // add_option is a no-op if another request created it first; re-read to agree.
                add_option('receivify_store_token', $token, '', false);
                $token = get_option('receivify_store_token');
            }
            return (string) $token;
        }

        /** `wc-<store token>-<order id>`, used as both `reference` and `Idempotency-Key`. */
        private static function order_reference($order_id) {
            return 'wc-' . self::store_token() . '-' . $order_id;
        }

        /**
         * The order id in a webhook's `reference`, or 0 when it belongs to another
         * shop. A bare numeric reference is an invoice created before 1.1.0.
         */
        private static function order_id_from_reference($reference) {
            if (preg_match('/^([a-z0-9]+)-([a-f0-9]{6,64})-(.+)$/', $reference, $m)) {
                if ($m[1] !== 'wc' || !hash_equals(self::store_token(), $m[2])) return 0;
                return ctype_digit($m[3]) ? (int) $m[3] : 0;
            }
            return ctype_digit($reference) ? (int) $reference : 0;
        }

        /**
         * x-receivify-signature: t=<ts>,v1=<hmac-sha256(secret, "ts.body")>
         *
         * Tried against a LIST of secrets: a rotation keeps the previous secret
         * acceptable for a grace window, so during a rotation two are valid.
         */
        private function verify_signature($body, $header) {
            $secrets = array_filter(array(
                $this->get_option('webhook_secret'),
                $this->get_option('webhook_secret_previous'),
            ));
            if (!$secrets || !$header) return false;

            $ts = ''; $sigs = array();
            foreach (explode(',', $header) as $part) {
                $kv = explode('=', trim($part), 2);
                if (count($kv) !== 2) continue;
                $k = strtolower(trim($kv[0]));
                $v = trim($kv[1]);
                if ($k === 't' && $ts === '') $ts = $v;
                if ($k === 'v1' && $v !== '') $sigs[] = strtolower($v);
            }
            if ($ts === '' || !$sigs || !ctype_digit($ts)) return false;

            // Replay window: reject a timestamp more than 5 minutes off.
            if (abs(time() - (int) $ts) > 300) return false;

            $ok = false;
            foreach ($secrets as $secret) {
                $expected = hash_hmac('sha256', $ts . '.' . $body, $secret);
                foreach ($sigs as $sig) {
                    if (hash_equals($expected, $sig)) $ok = true;
                }
            }
            return $ok;
        }
    }

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_Gateway_Receivify';
        return $gateways;
    });
});
