# Architecture

## 1. Architecture

Browser -> inline loader (sampling, bot filter) -> self-hosted `web-vitals@6.0.0` attribution IIFE -> lightweight vanilla collector -> `navigator.sendBeacon()` or fetch keepalive -> WordPress REST API -> custom database tables (raw + daily aggregates) -> WordPress admin dashboard, advisor (suggestions), filters, export, and diagnostics.

The plugin chooses the IIFE attribution build because WordPress plugin uploads should work without npm/build tooling, attribution is useful for diagnosing field issues, and the bundle remains small enough for production RUM.

## 2. Database Schema

Raw table: `{prefix}web_vitals`

Stores metric name/value/rating/id, hashed URL, sanitized path, page type, device, browser, OS, connection, viewport, navigation type, language, compact attribution JSON, and UTC creation time.

Indexes: `UNIQUE (metric_id, metric_name)` for upserts, `created_at`, `(metric_name, created_at)`, `(url_hash, created_at)`. Schema v2 dropped single-column indexes on low-cardinality columns.

Aggregate table: `{prefix}web_vitals_daily`

Stores date, metric, optional page path/device scope, p50, p75, p90, Good/NI/Poor counts, sample count, and update timestamp.

## 3. File Structure

- `web-vitals-monitor.php`: plugin header, constants, bootstrapping, activation/deactivation.
- `includes/class-config.php`: thresholds, metrics, defaults, settings sanitizer.
- `includes/class-database.php`: schema, inserts, summaries, cleanup, export, aggregates.
- `includes/class-rest-api.php`: public collect endpoint and admin API endpoints.
- `includes/class-collector.php`: frontend enqueue, exclusions, page type detection.
- `includes/class-settings.php`: Settings API wrapper.
- `includes/class-cron.php`: retention cleanup and daily aggregation.
- `includes/class-advisor.php`: rules engine that builds optimization suggestions from field data.
- `includes/class-page-issues.php`: per-URL issue detection, fixes, checklist state and fix verification.
- `includes/class-admin.php`: admin menu, assets, export, delete data.
- `admin/dashboard.php`: dashboard template.
- `admin/settings.php`: settings template.
- `assets/js/web-vitals.js`: official self-hosted `web-vitals@6.0.0` attribution IIFE.
- `assets/js/monitor.js`: lightweight frontend collector.
- `assets/js/admin.js`: admin dashboard rendering.
- `assets/css/admin.css`: admin UI styles.
- `languages/web-vitals-monitor-fa_IR.po|.mo`: Persian translation of the suggestions section.
- `uninstall.php`: conditional data removal.

## 4. Data Flow

1. WordPress prints a ~600 byte inline loader in the footer when monitoring and URL exclusions allow it.
2. The loader samples and filters bots in the browser, then injects the scripts after `load` during idle time.
3. `web-vitals` reports each metric (buffered entries cover the time before it loaded).
4. The collector compacts context and attribution and sends one beacon when the page is hidden.
5. REST validates, sanitizes, filters bots, rate-limits, rates the metric, and upserts by metric id.
6. Admin endpoints build SQL histograms for cards, trends, URL analysis and dimension tables; the advisor reads attribution of samples >= P75.
7. WP-Cron aggregates completed days and removes expired raw data in batches.

## 5. Security Model

The public collection endpoint is unauthenticated by design, because real visitors are anonymous. Security relies on small payloads, strict metric/value validation, sanitization, coarse transient rate limiting, no privileged action exposure, and prepared inserts.

Admin endpoints and actions require `manage_options`, WordPress REST nonce or admin nonce checks, and escaped output.

## 6. Performance Strategy

Frontend:

- No CDN.
- No jQuery.
- No React.
- Deferred scripts.
- Small localized config.
- Browser-side sampling.
- Batched sendBeacon/fetch keepalive transport.

Admin:

- Assets load only on plugin screens.
- Native canvas chart avoids admin chart dependency.
- Percentiles come from SQL histograms; raw rows never load into PHP.
- Trends read the daily aggregate table; only missing days hit raw data.
- Reports are cached briefly (60 s dashboard, 10 min suggestions).
- Retention cleanup is batch-based with a time budget.

## 7. Privacy Strategy

No full IP, user ID, name, or email is stored. URL query strings are stripped by default. Attribution is compact and sanitized, with full HTML/DOM excluded. Rate limiting uses a salted transient key and does not write IPs to the metrics table.

## 8. Dashboard Structure

- Overview metric cards for LCP, INP, CLS, FCP, TTFB.
- P75-first status, plus average, median, P90, rating percentages, and sample counts.
- Performance Health score.
- Alerts when Core Web Vitals P75 crosses thresholds.
- Performance-over-time chart.
- Top Problematic URLs.
- Device, Browser and Page-type Analysis (P75 per Core Web Vital).
- Collector Health.
- Optimization Suggestions (critical / warning / opportunity), each with evidence, actions, affected URLs and documentation.
- Device filter and URL drill-down apply to every section.

## 9. REST API Design

Public:

- `POST /wp-json/web-vitals/v1/collect`

Admin:

- `GET /wp-json/web-vitals/v1/dashboard?days=7&device=&path=`
- `GET /wp-json/web-vitals/v1/suggestions?days=7&device=&path=`
- `GET /wp-json/web-vitals/v1/diagnostics`

Errors use `WP_Error` with appropriate HTTP status codes. Admin responses are structured JSON.

## 10. Sampling Strategy

Sampling is configured as a percentage from 0 to 100 and applied by the inline loader before any script is downloaded. Default is 100%.

## 11. Retention Strategy

Retention supports 7, 30, 90, 180, 365, or forever. WP-Cron runs cleanup daily and deletes old rows in configurable batches.

## 12. Scaling Strategy

Dashboard queries return histogram buckets, not raw rows, and trends use daily aggregates, so the dashboard stays fast as data grows. Very high-traffic sites should lower sampling (10-25% is statistically plenty), keep retention bounded, and use a persistent object cache for rate limiting.
