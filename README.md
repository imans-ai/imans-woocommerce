# Imans Analytics for WooCommerce

> First-party storefront analytics for WooCommerce — sessions, visitors, conversion rate, cart abandonment, funnel events and on-site search terms — all stored on your own server and surfaced in the [Imans](https://imans.ai) dashboard.

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-96588A?logo=woocommerce&logoColor=white)](https://woocommerce.com/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Status](https://img.shields.io/badge/status-MVP-orange.svg)](#roadmap)

---

## Why this plugin exists

WooCommerce core sees orders, products and revenue, but it **never sees raw HTTP traffic** to your storefront. That means every WooCommerce-only analytics dashboard has the same gaping hole:

| Metric | wc-analytics | This plugin |
| --- | :---: | :---: |
| Gross sales, orders, AOV | yes | yes |
| Customers, new vs returning | yes | yes |
| **Sessions, unique visitors** | no | **yes** |
| **Bounce rate** | no | **yes** |
| **Conversion rate** | no | **yes** |
| **Cart abandonment rate** | no | **yes** |
| **Funnel events** (`view_item`, `add_to_cart`, `begin_checkout`) | no | **yes** |
| **On-site search terms + conversions** | no | **yes** |
| **UTM / referrer / landing-page attribution** | partial | **yes** |

`imans-woocommerce` closes that gap **on the merchant's own server**, with no third-party trackers, no shared analytics accounts, and no data leaving WordPress unless you connect the store to Imans.

---

## How it works

```
Storefront page
  │
  ├─ tracker.js (~8 KB, vanilla ES6, no jQuery)
  │     ├─ random UUID session cookie (30-min sliding)
  │     ├─ random UUID visitor cookie (2-year)
  │     └─ honors DNT / consent / opt-out before firing
  │
  ▼
POST /wp-json/imans-analytics/v1/track   (nonce + per-IP rate-limit)
  │
  ▼
wp_imans_events  +  wp_imans_sessions       ← raw rows
  │
  ├─ WP-Cron hourly → wp_imans_daily_stats   (idempotent recompute)
  └─ WP-Cron daily  → wp_imans_search_terms  (aggregated, then events aged out)
  │
  ▼
GET /wp-json/imans-analytics/v1/{health,sessions/stats,funnel/stats,search-terms}
  │  (HTTP Basic auth with your WooCommerce consumer_key/secret)
  ▼
Imans backend  →  joined with wc-analytics into a unified view
```

The Imans backend probes `/health` on every daily sync. When the plugin is reachable, traffic and funnel data are merged onto the **same** `ChannelPerformanceSnapshot` row that `wc-analytics` already populates — no parallel sources, no duplicated days.

---

## Privacy

This plugin is built so it can ship in stores with strict privacy regimes (GDPR, LGPD, CCPA).

- **Tracking is OFF by default.** Merchant must opt in.
- **No PII.** Visitor and session IDs are random UUIDs; they are not user IDs.
- **IP addresses are SHA-256 hashed** (salted with `wp_salt`), never stored in plaintext.
- **`DNT: 1` and the `imans_optout` cookie are honored unconditionally.**
- **Consent integrations:** auto-detects the [WP Consent API](https://github.com/rlankhorst/wp-consent-level-api); for CookieYes and other regimes the tracker listens for the standard `cookieyes_consent_update` and `wp_listen_for_consent_change` events.
- **Manual override:** set `window.IMANS_ANALYTICS_HAS_CONSENT = true|false` to force a decision.
- **All data stays on your WordPress server.** Imans only ever reads the aggregated daily rollups via authenticated REST.

---

## Installation

### From a release zip

1. Download the latest `.zip` from the [Releases](https://github.com/imans-ai/imans-woocommerce/releases) page.
2. **Plugins → Add New → Upload Plugin**, choose the zip, install and activate.
3. **WooCommerce → Settings → Imans Analytics**, toggle tracking on.
4. (Optional) Open your Imans workspace; the next daily sync will auto-detect the plugin.

### Manual install

```bash
cd wp-content/plugins
git clone git@github.com:imans-ai/imans-woocommerce.git imans-analytics
wp plugin activate imans-analytics
wp option update imans_analytics_tracking_enabled 1
```

### Requirements

- PHP 7.4 or newer
- WordPress 6.0 or newer
- WooCommerce 7.0 or newer (tested up to 9.4)

---

## Configuration

All settings are stored as WordPress options. You can edit them via the (forthcoming) settings tab, the WP-CLI, or by writing to `wp_options` directly.

| Option | Default | Meaning |
| --- | :---: | --- |
| `imans_analytics_tracking_enabled` | `0` | `1` to enable storefront tracking. |
| `imans_analytics_retention_days` | `30` | Days to keep raw events. Daily aggregates are forever. |
| `imans_analytics_sample_rate` | `100` | 0–100. Client-side sampling for high-volume stores. |
| `imans_analytics_last_aggregation_at` | `` | Set by the hourly cron; useful for debugging staleness. |

### Cron events

| Hook | Cadence | What it does |
| --- | --- | --- |
| `imans_analytics_hourly_roll` | hourly | Recomputes today + yesterday in `wp_imans_daily_stats`. |
| `imans_analytics_daily_purge` | daily at 02:00 store-time | Rolls search terms, deletes events past `retention_days`. |

If WP-Cron is disabled, hook these to your real cron with WP-CLI: `wp cron event run imans_analytics_hourly_roll`.

---

## REST API

All endpoints are namespaced under `/wp-json/imans-analytics/v1/`.

| Endpoint | Method | Auth | Purpose |
| --- | :---: | :---: | --- |
| `/track` | POST | nonce + rate-limit | Internal tracker ingest. Not for external use. |
| `/health` | GET | WC Basic | Plugin presence probe — version, schema, row counts. |
| `/sessions/stats` | GET | WC Basic | Daily session aggregates with computed conversion / bounce / abandonment. |
| `/funnel/stats` | GET | WC Basic | Daily counts of `view_item`, `add_to_cart`, `begin_checkout`, etc. |
| `/search-terms` | GET | WC Basic | Daily search-term aggregates with conversion counts. |

### Authentication

The read endpoints reuse the same **WooCommerce consumer key/secret** that already powers `wc-analytics` — there is no separate API key to manage. The Imans backend reuses credentials from `IntegrationCredential`; merchants don't paste anything new.

### Example

```bash
curl -u ck_REDACTED:cs_REDACTED \
  "https://your-store.com/wp-json/imans-analytics/v1/sessions/stats?after=2026-04-01&before=2026-05-01"
```

```json
{
  "intervals": [
    {
      "date_start": "2026-04-30 00:00:00",
      "date_end":   "2026-04-30 23:59:59",
      "subtotals": {
        "sessions": 1240,
        "sessions_with_purchase": 38,
        "unique_visitors": 1102,
        "bounce_rate": 0.4123,
        "conversion_rate": 0.0306,
        "cart_abandonment_rate": 0.5421,
        "page_views": 4870
      }
    }
  ]
}
```

---

## Schema

| Table | Row | Retention |
| --- | --- | --- |
| `wp_imans_sessions` | one row per browser session, with attribution + purchase link | indefinite |
| `wp_imans_events` | one row per event (page view, add-to-cart, search…) | `retention_days` (30 default) |
| `wp_imans_daily_stats` | one row per day, denormalized counts ready for the dashboard | indefinite |
| `wp_imans_search_terms` | one row per `(stat_date, term)`, with conversion counts | indefinite |

Tables are dropped on **uninstall** (not on deactivate), so you can temporarily turn off tracking without losing history.

---

## Development

```bash
# Clone
git clone git@github.com:imans-ai/imans-woocommerce.git
cd imans-woocommerce

# Symlink into a local WordPress install
ln -s "$(pwd)" /path/to/wp-content/plugins/imans-analytics

# Or use wp-env / Local by Flywheel
```

The codebase is plain PHP 7.4+ with a custom namespaced autoloader; **no Composer runtime dependency.** Build tooling for the JS bundle is not yet in the repo — `assets/js/tracker.js` is shipped as-is.

### Tests

The end-to-end test loop lives in the [Imans backend](https://github.com/imans-ai/imans-backend):

```bash
pytest imans/tests/integrations/woocommerce/test_analytics_plugin.py
```

That suite includes a regression guard that **proves the backend still works when this plugin is absent** — installing or removing the plugin should never break a merchant's existing analytics.

---

## Roadmap

| Phase | Status |
| --- | :---: |
| Custom tables + activation | shipped (v0.1.0) |
| Tracker + `/track` + `/health` | shipped (v0.1.0) |
| Hourly aggregator + daily purger | shipped (v0.1.0) |
| `/sessions/stats`, `/funnel/stats`, `/search-terms` | shipped (v0.1.0) |
| Backend probe + merge into `ChannelPerformanceSnapshot` | shipped (v0.1.0) |
| Settings admin page | planned (v0.2) |
| GDPR/LGPD exporter + eraser hooks | planned (v0.2) |
| Variation-change `view_item` re-fire | planned (v0.2) |
| WP.org Plugin Directory submission | planned (v0.2) |
| Optional GeoIP via MaxMind GeoLite2 | planned (v1.1) |
| WP multisite support | planned (v1.1) |
| Realtime webhook push to Imans | planned (v2.0) |

---

## License

GPL v2 or later. See [LICENSE](LICENSE).

---

## About

Built by [Imans](https://imans.ai) to give WooCommerce merchants the same depth of analytics that platforms like Shopify expose natively. If you want the dashboard side of this — funnel charts, channel-level conversion, cohort retention — connect your store to [Imans](https://imans.ai).
