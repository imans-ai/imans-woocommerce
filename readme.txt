=== Imans Analytics for WooCommerce ===
Contributors: imans
Tags: woocommerce, analytics, sessions, conversion, funnel, gdpr
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

First-party storefront analytics for WooCommerce — sessions, visitors, conversion rate, cart abandonment, funnel and search terms — exposed to the Imans dashboard.

== Description ==

WooCommerce core sees orders, products and revenue but never sees raw traffic to your storefront. That means metrics like sessions, unique visitors, bounce rate, conversion rate, cart abandonment rate, funnel steps and on-site search terms are not available to merchants or to analytics tools layered on top of WooCommerce.

**Imans Analytics for WooCommerce** captures those missing data points on your own server using a lightweight, vanilla-JS tracker, and exposes daily aggregates over the WordPress REST API. The [Imans](https://imans.ai) backend pulls those aggregates on its existing daily schedule and joins them with `wc-analytics` data into one unified view — no third-party trackers, no shared analytics accounts, no data leaving your server until you choose to ship it.

= What it captures =

* Sessions, unique visitors, page views
* `view_item`, `view_item_list`, `add_to_cart`, `remove_from_cart`, `begin_checkout` funnel events
* On-site search terms with conversion counts
* UTM parameters, referrer, landing page, device type
* Optional country code (via GeoIP, opt-in)

= Privacy first =

* Tracking is **OFF by default**. You must opt in via the settings screen.
* No personally identifiable information is stored.
* Visitor and session IDs are random UUIDs, not user identifiers.
* IP addresses are hashed (SHA-256), never stored in plaintext.
* `DNT` header and the `imans_optout` cookie are honored unconditionally.
* Integrates with CookieYes, the WP Consent API, and the `IMANS_ANALYTICS_HAS_CONSENT` JavaScript global.
* Implements WP Privacy data-export and erasure hooks for GDPR / LGPD compliance.
* All data stays on your WordPress server.

= REST API surface =

All read endpoints authenticate with the same WooCommerce consumer key and secret used by `wc-analytics`. The Imans backend reuses its existing credentials — no extra setup per store.

* `GET /wp-json/imans-analytics/v1/health` — version, schema version, row counts
* `GET /wp-json/imans-analytics/v1/sessions/stats` — daily session aggregates
* `GET /wp-json/imans-analytics/v1/funnel/stats` — daily funnel event counts
* `GET /wp-json/imans-analytics/v1/products/stats` — daily per-product / per-variation view & add-to-cart counts
* `GET /wp-json/imans-analytics/v1/search-terms` — daily search-term aggregates
* `POST /wp-json/imans-analytics/v1/track` — internal tracker ingest (nonce + rate-limited; not for external use)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via the Plugins screen.
2. Activate through the **Plugins** menu in WordPress.
3. Go to **WooCommerce → Settings → Imans Analytics** and toggle tracking on.
4. Connect your store to Imans (or check the existing connection's "plugin detected" status in the Imans integrations screen).

== Frequently Asked Questions ==

= Do I need an Imans account to use this plugin? =

You can install and run the plugin without an Imans account — your data simply stays on your server and is queryable via the REST API. To see it in a dashboard, connect your store to Imans.

= Does this work with caching plugins or Cloudflare? =

Yes. The tracker is a small async script that fires after page render and uses `navigator.sendBeacon` on unload. Full-page caching (WP Super Cache, W3 Total Cache, Cloudflare APO) is supported.

= Will this slow down my storefront? =

The tracker bundle is roughly 8 KB gzipped, vanilla JS, no jQuery. It loads asynchronously after the page is interactive.

= How long is event data retained? =

Raw events are kept for 30 days by default (configurable). Daily aggregates are kept indefinitely.

== Changelog ==

= 0.2.0 =
* Add `GET /products/stats` — daily per-product / per-variation `view_item` and `add_to_cart` counts, computed on-the-fly from raw events (no schema change). Enables per-listing traffic attribution in the Imans dashboard.

= 0.1.0 =
* Initial release. Scaffolding, custom tables, activation, tracker, /track and /health endpoints.

== Upgrade Notice ==

= 0.2.0 =
Adds the per-product/per-variation traffic endpoint used by Imans for per-listing analytics.

= 0.1.0 =
First release.
