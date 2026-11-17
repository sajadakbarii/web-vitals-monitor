# Real User Web Vitals Monitor

Professional, lightweight Real User Monitoring for WordPress Core Web Vitals.

## Introduction

Real User Web Vitals Monitor collects field data from real visitors and stores it in custom WordPress database tables. It focuses on LCP, INP, CLS, FCP, and TTFB, then surfaces trends, URL issues, device/browser breakdowns, collector health, export, retention, and diagnostics inside WordPress admin.

This plugin uses the official `web-vitals` package, version `6.0.0`, self-hosted as `assets/js/web-vitals.js`. The included build is the attribution IIFE build so the plugin can run without a build process or an external CDN.

## Features

- Collects LCP, INP, CLS, FCP, and TTFB.
- **Optimization Suggestions**: a rules engine based on the web.dev guidance turns attribution data from the slowest page views (>= P75) into prioritized, WordPress-specific fixes: LCP sub-parts, lazy-loaded or low-priority LCP images, CSS background heroes, INP phases, the plugin/theme/third-party script behind slow interactions (Long Animation Frames), media without dimensions, early vs late layout shifts, slow TTFB breakdown, mobile vs desktop gaps, failing page templates, bfcache and Speculation Rules opportunities.
- **Page-specific checklist**: for each problematic URL, issues are detected from that page's own visits (its LCP image, the element visitors click, the element that shifts, the files that block its rendering, its server response) with a fix written for exactly that issue, including the file names, selectors and the settings of the optimization plugin installed on the site (WP Rocket, LiteSpeed Cache, Perfmatters, Smush, Autoptimize, FlyingPress...), plus copy-ready code where it helps. Items are ticked off per site; newer visits then show "fixed, confirmed" or "still detected".
- The suggestions section is in Persian (RTL) by default, from `languages/web-vitals-monitor-fa_IR.mo`; switch it to the admin language under Settings > Advanced.
- Core Web Vitals assessment (Passed/Failed at P75), comparison with the previous period, good/NI/poor distribution bars.
- Device filter (mobile/tablet/desktop) and URL drill-down for the whole dashboard and its suggestions.
- Daily P75 trend charts with Good/Poor threshold lines.
- ~600 byte inline loader: sampling and bot filtering happen before anything is downloaded; collector scripts load after `load` during idle time.
- Uses `navigator.sendBeacon()` with `fetch(..., keepalive: true)` fallback.
- Sends one request per page view, when the page is hidden. Repeated reports of the same metric instance are upserted, so CLS/INP are never double-counted.
- Stores data in custom tables, not `wp_options` or post meta.
- Tracks URL path, page type, device type, browser, OS, connection type, viewport, navigation type, language, rating, and compact attribution.
- Strips query strings by default to reduce privacy risk.
- Provides sampling, retention, exclusion rules, and debug mode settings.
- Includes dashboard cards, P75/P90/median/average, rating percentages, trend chart, top URLs, device analysis, browser analysis, alerts, and collector health.
- Supports CSV and JSON export.
- Includes delete-all-data and uninstall retention controls.

## Requirements

- WordPress 6.3 or newer.
- PHP 8.1 or newer.
- MySQL or MariaDB supported by current WordPress versions.
- Browser APIs required by `web-vitals` vary by metric. INP, LCP, FCP, and TTFB are broadly available in modern Chrome, Firefox, and Safari. CLS support is currently strongest in Chromium browsers.

## Installation

1. Copy the `web-vitals-monitor` directory to `wp-content/plugins/`.
2. Or zip the `web-vitals-monitor` directory and install it via WordPress -> Plugins -> Add New -> Upload Plugin.
3. Activate "Real User Web Vitals Monitor".
4. Open WordPress -> Web Vitals.

No build process is required for production use.

## Configuration

Open WordPress -> Web Vitals -> Settings.

General:

- Enable monitoring.
- Exclude administrators.
- Exclude logged-in users.
- Set sampling rate.

Metrics:

- Toggle LCP, INP, CLS, FCP, TTFB.
- Toggle compact attribution.

Privacy:

- Strip query strings from URLs.
- Set retention.
- Decide whether uninstall deletes data.

Performance:

- Load strategy: after page load during idle time (default, zero impact) or early (also captures visitors who leave before `load`).
- Optional collector delay.
- Minimum sample size for confidence warnings.

Exclusions:

- Add URL patterns such as `/checkout/*`.
- Add user-agent patterns.

Advanced:

- Cleanup batch size.
- Rate limit per visitor IP, and "behind Cloudflare/CDN" to rate-limit by the real visitor IP.
- Debug mode.

## Database

The plugin creates:

- `{prefix}web_vitals`
- `{prefix}web_vitals_daily`

Primary raw columns include:

- `metric_name`
- `metric_value`
- `rating`
- `metric_id`
- `url_hash`
- `page_path`
- `page_type`
- `device_type`
- `browser`
- `browser_version`
- `os`
- `connection_type`
- `viewport_width`
- `viewport_height`
- `navigation_type`
- `language`
- `attribution_data`
- `created_at`

Indexes are created for metric, date, URL hash, device, rating, and common metric/date and URL/metric lookups.

## REST API

Public collection endpoint:

`POST /wp-json/web-vitals/v1/collect`

Admin endpoints:

- `GET /wp-json/web-vitals/v1/dashboard?days=7&device=mobile&path=/shop/`
- `GET /wp-json/web-vitals/v1/suggestions?days=7&device=&path=`
- `GET /wp-json/web-vitals/v1/diagnostics`

Admin reports are cached for 60 s (suggestions for 10 min); add `fresh=1` to bypass.

The public endpoint:

- Accepts only POST.
- Limits payload size.
- Accepts up to 10 metrics per request, as `{"c": {shared context}, "m": [metrics]}` (the v1.0 flat array is still accepted).
- Ignores bots, lab tools and excluded user agents server-side, so exclusions work behind full-page caches.
- Validates metric names and values.
- Sanitizes URL paths and attribution.
- Uses prepared database inserts.
- Applies coarse per-IP transient rate limiting without storing full IP addresses in the metrics table.

## Privacy

By default, the plugin does not store:

- Full IP address.
- Name.
- Email.
- WordPress user ID.
- Full DOM or HTML.

Query strings are stripped by default. Attribution values are compact, sanitized, and capped in length.

## Performance

Frontend behavior is deliberately small:

- No jQuery dependency.
- No React dependency.
- No frontend chart library.
- No third-party requests.
- Self-hosted `web-vitals` attribution IIFE build.
- Inline loader decides per page view; unsampled visitors and bots download nothing.
- Scripts are injected after `load` + `requestIdleCallback` by default.
- One beacon per page view, sent on `visibilitychange`/`pagehide`. No timers.
- Settings and DB version are autoloaded; the frontend runs no extra database queries.

Server and database:

- Percentiles are computed from SQL histograms (10 ms / 0.001 CLS buckets), so dashboards never load raw rows into PHP memory.
- One multi-row `INSERT ... ON DUPLICATE KEY UPDATE` per beacon; only the indexes queries actually use.
- Completed days are aggregated into `{prefix}web_vitals_daily` (all devices + per device) for fast trends.
- Retention cleanup deletes in batches until done (20 s budget), so it keeps up on high-traffic sites.
- Rate limiting uses the object cache when available.

Admin dashboard data loads only in plugin admin screens.

## Troubleshooting

If no data appears:

- Confirm monitoring is enabled.
- Confirm administrators are not excluded while you are testing as an admin.
- Check that `/wp-json/web-vitals/v1/collect` is reachable.
- Check page cache or CDN rules that might block REST API POST requests.
- Check Diagnostics on the dashboard.
- Enable Debug Mode only while investigating.

- If a JavaScript optimization plugin delays or combines scripts, exclude the inline `wvm-loader` script, `web-vitals.js` and `monitor.js`.
- After updating, purge the page cache so pages include the new loader.

If samples are low, reduce exclusion rules or increase the sampling rate.

## Development

The plugin is intentionally build-free. The official `web-vitals@6.0.0` attribution IIFE file is included at:

`assets/js/web-vitals.js`

To update it in the future, replace that file with the corresponding official IIFE build from the npm package and update references in the README.

## Uninstall

By default, uninstall keeps collected data. Enable "Delete data on uninstall" before uninstalling if you want the plugin to remove its custom tables and options.

## Changelog

### 1.2.0

- New: page-specific checklist (`GET /wp-json/web-vitals/v1/page-issues`, `POST /wp-json/web-vitals/v1/checklist`) with per-issue fixes, plugin-aware instructions, code snippets and fix verification from newer visits.
- New attribution: LCP image intrinsic vs rendered width, LCP image bytes, render-blocking files of the page, CLS element source and height.
- Snippets only contain whitelisted values (file names, paths, hosts, selectors), since attribution comes from a public endpoint.

### 1.1.0

- New: Optimization Suggestions panel with prioritized, data-driven recommendations, and URL drill-down.
- New: Persian (fa_IR) translation of the suggestions section, shown in Persian regardless of the admin language (setting: "Optimization Suggestions language").
- New: device filter, Core Web Vitals assessment, previous-period comparison, distribution bars, P75 trend charts with thresholds, page-type analysis.
- New: richer attribution (LCP lazy/fetchpriority/element type/cross-origin, INP Long Animation Frame script source, CLS media without dimensions).
- New settings: load strategy, rate limit, trust proxy headers. Export form with filters; streaming CSV/JSON export.
- Performance: inline loader with in-browser sampling; one beacon per page view; autoloaded options; SQL histogram percentiles; leaner indexes; batched upserts.
- Fix: CLS/INP were stored again on every report of the same page view (inflating counts). Existing duplicates are removed on upgrade.
- Fix: health score could never exceed 75; trend chart mixed units; URL status flagged nearly every URL.
- Fix: retention cleanup removed at most 1,000 rows per day; CDN users shared one rate-limit bucket; user-agent exclusion broke behind page caches.
- Fix: CSV formula injection in exports.

### 1.0.0

- Initial production-ready implementation for real-user Core Web Vitals monitoring.

## Final Verification

PASS:

- Architecture uses Browser -> web-vitals -> lightweight JS -> sendBeacon/fetch -> REST API -> custom table -> admin dashboard.
- Uses official `web-vitals@6.0.0` attribution IIFE build.
- Self-hosted JS; no external CDN dependency.
- No frontend jQuery, React, Bootstrap, or chart dependency.
- Uses custom database tables with indexes.
- Provides sampling, retention, exclusion rules, privacy controls, export, delete data, and uninstall behavior.
- Uses WordPress REST API, Settings API, wpdb, dbDelta, WP-Cron, nonces, capability checks, sanitization, validation, escaping, and prepared statements.
- Stores P75, median, P90, averages, ratings, device, browser, URL, and health data in the dashboard.
- Shows insufficient-data warnings based on configurable sample size.

NEEDS REVIEW before production:

- Test activation/deactivation/uninstall on the target WordPress version.
- Test REST collection behind the target cache/CDN stack.
- Test high-volume sites and consider enabling daily aggregates plus lower sampling.
- Test browser support differences, especially CLS outside Chromium and iframe-heavy pages.
- Run PHPCS with WordPress Coding Standards in the deployment environment.
- Test with WooCommerce, Elementor, LiteSpeed Cache, WP Rocket, Cloudflare, PHP 8.1, 8.2, and 8.3.
