# Receivify for WooCommerce

[![CI](https://github.com/receivify/receivify-woocommerce/actions/workflows/ci.yml/badge.svg)](https://github.com/receivify/receivify-woocommerce/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/receivify/receivify-woocommerce)](https://github.com/receivify/receivify-woocommerce/releases/latest)
[![License: MIT](https://img.shields.io/badge/license-MIT-a3e635.svg)](LICENSE)

Accept Bitcoin, Ethereum, USDT, USDC and other crypto in your WooCommerce store with [Receivify](https://receivify.io).
Customers pay on a hosted checkout, the payment is checked on the blockchain, and the money is forwarded
straight to **your own wallet**, minus a 1% fee. Non-custodial, no ID checks, signed webhooks mark orders paid.

This repository is the full, readable source of the official plugin, so you can audit exactly what runs in your store.

## Requirements

- WordPress 5.8 or newer
- WooCommerce 6.0 or newer (HPOS and the block checkout are supported)
- PHP 7.4 or newer
- A Receivify account (free) with an API key and webhook secret

## Install

1. Download `receivify-woocommerce.zip` from the [latest release](https://github.com/receivify/receivify-woocommerce/releases/latest)
   (or from [receivify.io/plugins/receivify-woocommerce.zip](https://receivify.io/plugins/receivify-woocommerce.zip)).
2. In WordPress go to **Plugins → Add New → Upload Plugin**.
3. Upload `receivify-woocommerce.zip` and click **Install Now**, then **Activate**.
4. Go to **WooCommerce → Settings → Payments → Receivify (Crypto)**.

## Configure

In your Receivify dashboard create an **API key** (Dashboard → API keys) and copy your **webhook secret**
(Dashboard → Webhooks), then paste both into the plugin settings. The webhook URL (`https://your-store.com/?wc-api=receivify_webhook`) is sent with every invoice automatically, so there is nothing to paste.

## Test before going live

1. In the Receivify dashboard open **Webhooks → Send test** and check your store accepts the signed message.
2. Place one small real order at the coin's minimum (a stablecoin on a cheap network costs a few cents in fees),
   pay it from your own wallet, and watch the order move to paid when the webhook lands.

## Support

- Documentation: https://receivify.io/docs
- Integration guide: https://receivify.io/integrations/woocommerce
- Help: https://receivify.io/support
- Issues with this plugin: [open an issue](https://github.com/receivify/receivify-woocommerce/issues)

## Security

Please report vulnerabilities privately to **security@receivify.io** (see our
[disclosure policy](https://receivify.io/bug-bounty)). Do not open public issues for security problems.

## License

MIT. See [LICENSE](LICENSE).

---

## Detailed guide

Accept Bitcoin & crypto in WooCommerce via Receivify. Non-custodial: funds settle
to your own payout addresses; the store only ever sees a hosted checkout URL and a
signed webhook.

## How it works
1. At checkout the plugin creates a **fiat-priced** Receivify invoice
   (`POST /api/v1/invoices` with `amount_fiat` + the store currency). Receivify
   locks the crypto rate and returns a hosted `checkout_url`.
2. The customer is redirected to Receivify's checkout (`/pay/…`) and pays.
3. When the payment settles, Receivify POSTs a **signed** webhook to
   `https://yourstore/?wc-api=receivify_webhook`. The plugin verifies the
   `x-receivify-signature` HMAC before calling `payment_complete()` on the order.

## Install
1. Copy the `receivify-payments/` folder into `wp-content/plugins/`.
2. Activate **Receivify Payments** in WP admin → Plugins.
3. WooCommerce → Settings → Payments → **Receivify (Crypto)** → Manage:
   - **API URL** — your Receivify base URL (e.g. `https://receivify.io`).
   - **API key** — your `rcv_live_…` key (create in the Receivify dashboard → API keys).
   - **Webhook secret** — your `whsec_…` secret (shown at signup / dashboard).
   - **Settlement coin** — the coin customers pay in (must be enabled + settleable
     in your Receivify admin → Assets).
4. Save. In your Receivify dashboard open **Webhooks → Send test** to confirm your store accepts the signed message, then place one small real order at the coin's minimum (a stablecoin on a cheap network costs a few cents in fees) and pay it from your own wallet.

## Notes
- The invoice `reference` is `wc-<store token>-<order id>`. The store token is a
  random value created once per install (option `receivify_store_token`), so two
  shops on one Receivify account, or a reinstalled shop that restarts its order
  numbers, never collide. The webhook maps the reference back to the order and
  ignores another shop's. The same value is sent as the `Idempotency-Key`, so
  retried creates are safe. Invoices created before 1.1.0 used the bare order id
  and are still recognised.
- Events handled: `invoice.paid` / `invoice.swept` / `sweep.sent` → order
  complete; `invoice.confirming` → note; `invoice.partial*` → underpaid note;
  `invoice.expired` / `invoice.cancelled` → order cancelled; `invoice.failed` →
  order failed. Unknown event names are acknowledged and ignored.
- Signature scheme: `x-receivify-signature: t=<ts>,v1=<hmac-sha256(secret, "ts.body")>`
  over the **raw** request body. A timestamp more than 5 minutes out is rejected.
- **Previous webhook secret**: fill this in only while a rotation is inside its
  grace window (24h by default). Receivify signs with the current secret, but the
  previous one stays acceptable until `prev_expires_at`, so the receiver must try
  both. Clear the field afterwards.
- The webhook envelope is `{ event, sequence, created_at, data }` — `data` is the
  invoice. Delivery is at-least-once, so the plugin de-duplicates on
  `(event, sequence)` in the `_receivify_seen_events` order meta before acting.
- Works with both the **block checkout** (the default on new WooCommerce stores
  since 8.3) and the classic `[woocommerce_checkout]` shortcode, and declares
  compatibility with High-Performance Order Storage.
- Receivify rejects a non-https `callback_url` in production, so the store's
  site URL must be https or checkout fails with `callback_url must be https`.
- v1 uses a single settlement coin per gateway. For customer coin choice at
  checkout, run multiple gateway instances or wait for the multi-coin update.
