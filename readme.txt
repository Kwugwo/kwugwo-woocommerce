=== Kwugwo for WooCommerce ===
Contributors: kwugwo
Tags: woocommerce, payments, kwugwo, paystack, nigeria, bank transfer, ussd, africa
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments across Africa's PSPs with Kwugwo. Uses the Kwugwo embedded checkout overlay, with one-click sandbox/live switching.

== Description ==

Kwugwo for WooCommerce lets your store take payments through the Kwugwo
embedded checkout. When a customer places an order, the plugin creates a
Kwugwo payment request (an "ugwo") on your workspace and opens the hosted
Kwugwo overlay so the customer can pay by bank transfer, USSD, and any other
medium you've enabled — card and bank data never touch your site, so you stay
out of PCI scope.

= Features =

* **Embedded checkout** — opens the official `@kwugwo/checkout` overlay; no redirect away from your store and no iframe plumbing.
* **Sandbox & live keys** — store both sets of keys and flip between them with a single toggle in the gateway settings.
* **Webhook reconciliation** — orders are marked paid from the signed `ugwo.updated` webhook (the source of truth), with idempotency and HMAC signature verification.
* **Customer records** — billing details are pushed to Kwugwo as an "onye" so the dashboard groups payments by payer.
* **Classic and Block checkout** — works with both the shortcode/classic checkout and the Cart & Checkout blocks.
* **HPOS compatible** — supports WooCommerce High-Performance Order Storage.

= Supported currencies =

Kwugwo currently supports **NGN** (Nigeria). The gateway hides itself
automatically when the store currency is not supported.

== Installation ==

1. Upload the `kwugwo-woocommerce` folder to `/wp-content/plugins/`, or install the zip from **Plugins → Add New → Upload Plugin**.
2. Activate **Kwugwo for WooCommerce**.
3. Go to **WooCommerce → Settings → Payments → Kwugwo**.
4. Tick **Enable Kwugwo**.
5. Choose your environment:
   * Leave **Sandbox mode** ticked while you build, and fill in the **Sandbox** public + secret keys.
   * Untick **Sandbox mode** to take live payments, and fill in the **Live** public + secret keys.
6. Copy the **Webhook URL** shown at the top of the settings screen and register it as a webhook endpoint in your Kwugwo dashboard — once for sandbox and once for live. Paste each endpoint's signing secret into the matching **Webhook secret** field.
7. Save.

You can get your keys from the Kwugwo dashboard's **API Keys** screen. Keys
are scoped to one environment; a sandbox key only works against the sandbox
API, and vice versa.

== How it works ==

1. The customer places the order. The plugin calls `POST /v1/ugwo` with your
   secret key to create the payment request and stores its id on the order.
2. The customer is sent to the order-pay page, where the Kwugwo overlay opens
   automatically against that ugwo using your public key.
3. The customer pays inside the overlay. On success the overlay redirects to
   the order-received page.
4. Kwugwo delivers a signed webhook to your site. The plugin verifies the
   signature, re-fetches the ugwo from the API, and — once the ugwo is
   `ugwo_successful` — completes the order.

Because mediums such as bank transfer and USSD settle asynchronously, the
webhook (not the browser) is what marks an order paid. An order may briefly
remain "Pending payment" after the customer returns; it moves to Processing /
Completed when the webhook confirms settlement.

== Frequently Asked Questions ==

= Where do I register the webhook? =

Use the **Webhook URL** shown on the gateway settings screen
(`https://your-store/?wc-api=kwugwo_webhook`). Register it in the Kwugwo
dashboard for each environment and paste the signing secret back into the
plugin so deliveries can be verified.

= Do I need to whitelist an IP? =

If your endpoint sits behind an allowlist, whitelist Kwugwo's static outbound
IP `176.97.192.227` (the same in sandbox and live).

= How do I switch to live? =

Untick **Sandbox mode**, make sure your live keys and live webhook secret are
filled in, and save. No code changes are needed.

= Why is Kwugwo not showing at checkout? =

Check that the gateway is enabled, the store currency is supported (NGN), and
both the public and secret keys for the active environment are filled in.

== Changelog ==

= 1.0.0 =
* Initial release: embedded checkout, sandbox/live toggle, webhook
  reconciliation, classic and block checkout support.
