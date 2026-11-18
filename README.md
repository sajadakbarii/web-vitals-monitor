# Real User Web Vitals Monitor

Lightweight Real User Monitoring (RUM) of Core Web Vitals for WordPress, with page-specific fix checklists and an optional AI assistant.

The plugin measures LCP, INP, CLS, FCP and TTFB in real visitors' browsers, stores the results in custom tables, and shows them in WordPress admin at the 75th percentile, the way Google Search Console evaluates them. It then turns the attribution data of slow visits into concrete fixes: site-wide suggestions, and a checklist per URL whose items name the exact image, element, file or setting to change.

It uses the official [`web-vitals`](https://github.com/GoogleChrome/web-vitals) library (v6.0.0, attribution build), self-hosted as `assets/js/web-vitals.js`. No build step, CDN or third-party request is needed.

## Highlights

- **Near-zero frontend cost**: a ~600-byte inline loader samples visitors and filters bots in the browser before anything is downloaded, then loads the collector after `load` during idle time. One beacon per page view.
- **Field data at P75**: Core Web Vitals assessment (Passed / Failed), comparison with the previous period, Good / Needs improvement / Poor distribution, daily P75 trends with threshold lines.
- **Segments**: device filter (mobile / tablet / desktop) and URL drill-down apply to the whole dashboard; device, browser and page-type tables.
- **Optimization Suggestions**: site-wide, prioritized recommendations derived from LCP sub-parts, INP phases, layout-shift timing, TTFB breakdown, device gaps, failing templates, bfcache and prerender usage.
- **Page-specific checklist**: for each problematic URL, issues detected from that page's own visits, each with a fix for exactly that issue (real file names, selectors, sizes, and the settings of the optimization plugin installed on the site), copy-ready code, and verification from newer visits once ticked.
- **Optional AI assistant**: an "Ask AI" button per checklist issue, using the Claude API or any OpenAI-compatible endpoint.
- **Persian (RTL)** suggestions, checklist and AI answers by default, regardless of the admin language.
- Export (CSV / JSON), retention, exclusions, privacy controls, uninstall options.

## Requirements

- WordPress 6.3 or newer.
- PHP 8.1 or newer, with the OpenSSL extension recommended (used to encrypt the AI API key).
- MySQL or MariaDB supported by current WordPress versions.
- For the AI assistant only: outbound HTTPS from the server to the chosen AI service (WordPress `WP_PROXY_*` settings are respected).

Browser support depends on the metric. LCP, FCP, TTFB and INP are measured in current Chrome, Edge, Firefox and Safari; CLS and the most detailed attribution data (Long Animation Frames, render-blocking files) come from Chromium-based browsers.

## Installation

1. Copy the `web-vitals-monitor` directory to `wp-content/plugins/`, or upload a zip of it under **Plugins → Add New → Upload Plugin**.
2. Activate **Real User Web Vitals Monitor**.
3. Open **Web Vitals** in the admin menu.

Administrators are excluded from measurement by default, so test in a logged-out or private window.

### Upgrading

- **Purge the page cache** after updating so cached pages include the current loader. Script URLs carry the plugin version, so browsers and CDNs fetch the new files.
- Upgrading from 1.0 migrates the database on the first admin page load: duplicate CLS/INP rows (one per report of the same page view) are merged into one, and unused indexes are dropped. On very large tables this first load can take a while.

## Dashboard

**Web Vitals** in the admin menu shows, for the selected range (24 hours, 7, 28 or 90 days) and device:

| Section | Content |
|---|---|
| Assessment | Passed when LCP, INP and CLS are all Good at P75; otherwise Failed, or "not enough data" |
| Metric cards | P75 with rating, change vs the previous period, distribution bar, average, median, P90, sample count |
| Optimization Suggestions | Site-wide recommendations (hidden when a single URL is selected) |
| Page-specific checklist | Up to 10 worst URLs, or the selected URL, each with its own issue checklist |
| Performance Over Time | Daily P75 of LCP, INP and CLS with Good / Poor threshold lines |
| Performance Health | Share of Good experiences (Needs improvement counts half) and alerts |
| Top Problematic URLs | Ranked by Poor samples; click a URL to filter the whole dashboard to it |
| Collector Health | Collector status, last sample, samples today / total, cron, last aggregated day |
| Device / Browser / Page type | P75 of each Core Web Vital per segment |

Values with fewer samples than the configured minimum are shown as "not enough data". The dashboard can auto-refresh every minute.

## Optimization Suggestions

Suggestions are produced by fixed rules in `includes/class-advisor.php`, based on the web.dev guidance, from the attribution data of visits at or above the P75 of each metric:

- **LCP** is split into TTFB, resource load delay, resource load duration and element render delay; lazy-loaded LCP images, missing `fetchpriority`, CSS background heroes, heavy or cross-origin images and render delays are flagged.
- **INP** is split into input delay, processing and presentation delay; the scripts running during slow interactions are attributed to a plugin, theme, WordPress core or a third-party host (Long Animation Frames).
- **CLS** is analyzed by when the largest shift happens (during or after load) and whether media lacked dimensions.
- **TTFB** is broken down into redirects, DNS/connection and server time.
- Mobile vs desktop gaps, page templates rated Poor, back/forward cache eligibility and Speculation Rules (prerendering) are checked too.

Each suggestion has a severity, the evidence from your data, steps to fix it, affected URLs and a documentation link.

## Page-specific checklist

For each problematic URL (the 10 with the most Poor samples, or the URL you select), `includes/class-page-issues.php` analyzes that page's own samples and reports an issue only when it was seen in at least 3 visits:

| Issue | Detected when |
|---|---|
| LCP image lazy-loaded | the LCP image had `loading="lazy"` in ≥ 20% of visits |
| LCP background image | the LCP element is a CSS background (LCP not Good) |
| LCP image priority | load delay > max(100 ms, 10% of LCP) and no `fetchpriority="high"` |
| Oversized LCP image | intrinsic width ≥ 1.5× the rendered width (in device pixels) and ≥ 300 px larger |
| Heavy LCP image | ≥ 150 KB (always reported at ≥ 400 KB) |
| Cross-origin LCP image | most LCP images come from another host and loading takes > 30% of LCP |
| Late text / late paint | element render delay > max(200 ms, 15% of LCP) |
| Render-blocking file | a stylesheet or script blocks rendering in ≥ 30% of visits for ≥ 80 ms |
| Slow server response | page TTFB P75 > 800 ms (compared with the site's TTFB to tell a page problem from a server problem) |
| Slow interaction | P75 > 200 ms for one element visitors interact with (top 3 elements) |
| Layout shift | P75 > 0.1 for one element that shifts (top 3 elements) |

Each fix is written for that issue: it uses the file name, selector, measured size or height, the owner of the script or stylesheet, and the exact settings of the optimization plugin active on the site (WP Rocket, LiteSpeed Cache, Perfmatters, Smush, Autoptimize, a3 Lazy Load, W3 Total Cache, FlyingPress, Elementor). Code snippets (PHP for a child theme, CSS, JS, or a `curl` command) are filled in from templates. Attribution data comes from a public endpoint, so a value is placed in a snippet only if it passes a strict character whitelist; otherwise the snippet is omitted.

**Ticking an item** stores who ticked it and when (shared by all administrators). Samples collected after that are checked again:

- **Fixed, confirmed by new visits**: the issue no longer appears in at least 5 newer visits.
- **Still detected**: newer visits still show the issue.
- **Waiting**: not enough new visits yet.

## AI assistant (optional)

Adds an **Ask AI** button to every checklist issue. The model receives that one issue and returns a structured answer: summary, root cause, ordered steps, at most one code snippet, how to verify, a confidence level and caveats.

### Setup

**Web Vitals → Settings → AI Assistant**:

| Field | Notes |
|---|---|
| Enable AI suggestions | Off by default |
| Provider | **Claude (Anthropic API)** or **OpenAI-compatible API** |
| API key | Stored encrypted; leave empty to keep the saved key |
| API address | Claude: empty for `https://api.anthropic.com`, or a relay address. OpenAI-compatible: required, up to `/v1` (for example `https://api.openai.com/v1` or a gateway's address). HTTPS only |
| Model | Claude default `claude-opus-5-5`; for OpenAI-compatible services, the exact model name your provider lists |

Save, then use **Test connection**. Instead of storing the key in the database you can define it in `wp-config.php`:

```php
define( 'WVM_AI_API_KEY', 'your-api-key' );
```

### How it calls the provider

- **Claude**: Messages API (`/v1/messages`, API version `2026-10-21`) with structured JSON output (a fixed JSON schema) and effort set to `medium`. The system prompt, identical for every issue, is marked for prompt caching. On `api.anthropic.com` with a current Opus, Sonnet 5.5 or Fable model, server-side refusal fallback is enabled, so a request wrongly declined by a safety classifier is retried on another model.
- **OpenAI-compatible**: Chat Completions (`/chat/completions`) with JSON mode; the answer's shape is validated by the plugin.
- Requests go through the WordPress HTTP API with a 180-second timeout.

### Data, security and cost

- **Sent per request**: the issue (title, measured evidence, rule-based fix and code), the page path and its P75 values, the titles of the page's other issues, WordPress and PHP versions, and the names and versions of the active theme and plugins. No visitor data, user accounts or content.
- Nothing is sent until **Ask AI** is clicked. The browser sends only the issue's key; the server rebuilds the issue itself.
- Visitor-derived values (selectors, URLs) are marked in the prompt as data to analyze, never instructions. AI output is displayed as plain text, and code is shown with a "review before applying" warning; nothing is executed.
- The API key is encrypted with AES-256-GCM using a key derived from the site's salts, kept in a non-autoloaded option, and never shown again. Changing the salts invalidates it; enter the key again.
- Answers are cached per issue and model for one week (**Ask again** bypasses the cache). Each administrator can make at most 60 requests per hour.

## Settings reference

**Web Vitals → Settings**:

| Setting | Default | Notes |
|---|---|---|
| Enable monitoring | On | |
| Exclude administrators | On | |
| Exclude logged-in users | Off | |
| Sampling rate | 100% | Unsampled visitors download nothing; 10-25% is enough on busy sites |
| Metrics | All five | LCP, INP, CLS, FCP, TTFB |
| Collect compact attribution data | On | Needed for suggestions and the checklist |
| Strip query strings from stored URLs | On | When off, sensitive keys (email, token, password...) are still removed |
| Retention | 90 days | 7, 30, 90, 180, 365 days or forever; daily aggregates are kept for at least 2 years |
| Delete data on uninstall | Off | |
| When to load the collector | After page load, when idle | "Early" also captures visitors who leave before `load` |
| Delay collector start | 0 ms | 0-10000 ms |
| Minimum sample size | 25 | Below this, values are shown as "not enough data" |
| Excluded URL patterns | `/wp-admin/*`, `/cart/*`, `/checkout/*` | One per line, `*` wildcard, full path match |
| Excluded user agents | empty | One per line, case-insensitive substring, `*` wildcard. Crawlers, Lighthouse/PageSpeed and headless browsers are always excluded |
| Optimization Suggestions language | فارسی (Persian) | Or "Same as the admin language" |
| Cleanup batch size | 5000 | 100-50000 rows per delete query |
| Rate limit | 120 / minute per visitor IP | 0 disables it |
| Site is behind Cloudflare or another CDN | Off | Use `CF-Connecting-IP` / `X-Forwarded-For` for rate limiting |
| Debug mode | Off | Logs rejected metrics when `WP_DEBUG` is on |
| AI Assistant | Off | See [AI assistant](#ai-assistant-optional) |

The settings page also has **Export** (CSV or JSON, filtered by metric, device and date, up to 200,000 rows, newest first) and **Delete Data** (type `DELETE` to confirm).

## How data is collected

1. On frontend pages that are not excluded by URL, the plugin prints a small inline script (`wvm-loader`) with its configuration.
2. In the browser, the loader applies sampling and drops bots and excluded user agents; for the rest it injects `web-vitals.js` and `monitor.js` after `load`, during idle time (or immediately, with the "early" strategy). `web-vitals` reads buffered performance entries, so metrics from before it loaded are still measured.
3. `monitor.js` queues each metric with compact attribution and sends a single beacon (`navigator.sendBeacon`, `fetch` with `keepalive` as fallback) when the page is hidden.
4. `POST /wp-json/web-vitals/v1/collect` validates and sanitizes the payload, filters bots, rate-limits, rates each value, and upserts it by metric id, so a later report of the same metric instance (CLS or INP growing after the visitor returns to the tab) replaces the earlier one.
5. A daily WP-Cron job aggregates completed days into the daily table and deletes expired raw rows.

Recorded per sample: metric, value, rating, page path and type, device type (by viewport width), browser and major version, OS, connection type, viewport, navigation type, language, and compact attribution (for example the LCP element and its sub-parts, the interaction target and its phases, the element that shifted).

## REST API

Namespace `web-vitals/v1`. Admin endpoints require the `manage_options` capability and a REST nonce.

| Method | Route | Purpose |
|---|---|---|
| POST | `/collect` | Public collection endpoint |
| GET | `/dashboard` | Dashboard data (cached 60 s) |
| GET | `/suggestions` | Site-wide suggestions (cached 10 min) |
| GET | `/page-issues` | Page checklists (cached 10 min) |
| POST | `/checklist` | Tick or untick an item: `key` (16 hex characters), `done` |
| POST | `/ai-advice` | AI answer for one issue: `key`, `path` |
| GET | `/diagnostics` | Asset, endpoint, database and cron checks |

Report endpoints accept `days` (1-90), `device` (`mobile`, `tablet`, `desktop`), `path` and `fresh=1` (bypass the cache).

`/collect` accepts JSON of at most 16 KB with up to 10 metrics, as `{"c": {shared context}, "m": [metrics]}` (the 1.0 flat array is still accepted). It validates metric names and value ranges, requires a metric id, keeps only allow-listed attribution keys, strips query strings from resource URLs, and uses prepared statements.

## Database

Tables:

- `{prefix}web_vitals`: one row per metric instance. Indexes: `UNIQUE (metric_id, metric_name)` for upserts, `created_at`, `(metric_name, created_at)`, `(url_hash, created_at)`.
- `{prefix}web_vitals_daily`: per day, metric and device (`all`, `mobile`, `tablet`, `desktop`): P50, P75, P90, rating counts and sample count.

Options:

| Option | Content | Autoloaded |
|---|---|---|
| `wvm_settings` | Settings | Yes (read on every frontend page) |
| `wvm_db_version` | Schema version | Yes |
| `wvm_checklist` | Ticked checklist items (at most 1000) | No |
| `wvm_ai` | AI settings and encrypted key | No |
| `wvm_last_aggregated` | Last aggregated day | No |
| `wvm_cache_version` | Report cache version | No |

Short-lived transients: `wvm_rl_*` (rate limiting, when no object cache is present), `wvm_dash_*`, `wvm_tips_*`, `wvm_pages_*` (report caches), `wvm_ai_*` (AI answers and limits).

## Privacy

- Not stored: IP addresses, names, emails, WordPress user IDs, page content or DOM. Rate limiting uses a salted hash of the IP in a transient that expires within minutes.
- Query strings are stripped by default; resource URLs in attribution never keep their query string.
- Attribution values are allow-listed, sanitized and limited to 160 characters.
- Nothing is sent to third parties, except to your chosen AI service when an administrator clicks **Ask AI** (see [AI assistant](#ai-assistant-optional)).

## Performance

Frontend:

- No jQuery, React or chart library; no third-party requests.
- Unsampled visitors and bots download nothing beyond the inline loader.
- Scripts load after `load` during idle time by default; one beacon per page view, no timers.
- The frontend runs no extra database queries (settings are autoloaded).
- Per-visitor decisions do not depend on the cached HTML, so they work behind full-page caches: sampling runs in the browser, and user-agent exclusions run in the browser and again on the server.

Server:

- Percentiles come from SQL histograms (10 ms / 0.001 CLS buckets for overviews, 25 ms / 0.005 for tables and trends), so raw rows are never loaded into PHP.
- Trends read the daily table; only days not aggregated yet are computed from raw rows.
- One multi-row insert per beacon.
- Retention cleanup deletes in batches until done or 20 seconds have passed.
- Rate limiting uses the persistent object cache when available.

## Translations

`languages/web-vitals-monitor-fa_IR.po` holds the Persian translation of the suggestions, checklist and AI sections, and is the source of truth; `languages/web-vitals-monitor-fa_IR.mo` is compiled from it. After editing the `.po`, recompile it with Poedit or `msgfmt -o web-vitals-monitor-fa_IR.mo web-vitals-monitor-fa_IR.po`. Keep placeholders (`%s`, `%1$s`, `%%`) unchanged, and use Latin digits, matching the numbers computed from data.

The rest of the admin interface is translatable with the `web-vitals-monitor` text domain.

## Troubleshooting

No data:

- Make sure monitoring is enabled and the sampling rate is above 0%.
- Test logged out, or in a private window (administrators are excluded by default).
- Check in the browser's Network panel that a POST to `/wp-json/web-vitals/v1/collect` is sent when you leave or hide the page, and returns 201.
- Make sure a security plugin, firewall or CDN does not block that POST request.
- If a JavaScript optimization plugin delays or combines scripts, exclude the inline `wvm-loader` script, `web-vitals.js` and `monitor.js`.
- Purge the page cache after updating the plugin.
- **Collector Health** on the dashboard shows when the last sample arrived; `GET /wp-json/web-vitals/v1/diagnostics` checks assets, endpoint, tables and cron.

Few samples: widen the date range, raise the sampling rate, or reduce exclusions. INP only exists when visitors interact, so it naturally has fewer samples.

Empty checklist: issues need attribution data (on by default) and at least 3 occurrences on the same page. Detail such as the render-blocking file list is only collected by Chromium-based browsers.

AI assistant:

- "API key was rejected": check the key and that the provider matches it.
- "Address or model name was not found": check the API address (OpenAI-compatible addresses end with `/v1`) and the exact model name.
- "Could not reach the AI service": the server cannot reach the host. Use a reachable gateway or relay, or configure `WP_PROXY_HOST` / `WP_PROXY_PORT` in `wp-config.php`.
- Changing the WordPress salts makes the saved key unreadable; enter it again.

## Development

The plugin is build-free.

| Path | Role |
|---|---|
| `web-vitals-monitor.php` | Bootstrap, activation, schema upgrade |
| `includes/class-config.php` | Metrics, thresholds, defaults, settings sanitizer |
| `includes/class-settings.php` | Settings API, suggestions language switching |
| `includes/class-collector.php` | Inline loader and page type detection |
| `includes/class-rest-api.php` | REST routes, payload validation, rate limiting, report caching |
| `includes/class-database.php` | Schema, upserts, histogram percentiles, aggregation, cleanup, export |
| `includes/class-advisor.php` | Site-wide suggestions |
| `includes/class-page-issues.php` | Page checklists, fixes, tick verification |
| `includes/class-ai.php` | AI assistant (Claude and OpenAI-compatible) |
| `includes/class-cron.php` | Daily aggregation and cleanup |
| `includes/class-admin.php` | Admin menu, assets, export, delete, AI connection test |
| `admin/` | Dashboard and settings templates |
| `assets/js/monitor.js` | Frontend collector |
| `assets/js/admin.js` | Dashboard rendering |
| `assets/js/web-vitals.js` | Official `web-vitals@6.0.0` attribution IIFE build |

To update `web-vitals`, replace `assets/js/web-vitals.js` with the attribution IIFE build of the new version from the npm package, update the version passed in `class-collector.php`, and check that the attribution fields read in `monitor.js` still exist.

## Uninstall

Uninstalling always removes the plugin's settings, checklist state, AI settings, cron event and transients. The collected data (both tables, the schema version and the last-aggregated marker) is removed only if **Delete data on uninstall** is enabled; otherwise it is kept for a later reinstall.

## Testing status

Verified for 1.3.0:

- PHP and JavaScript syntax of all files.
- Fixture-based functional tests of the percentile math, settings patterns, suggestion rules, page-issue detection, snippet whitelisting, tick verification, the collector's attribution and beacon behavior, and the AI assistant's request format, response parsing, error handling, caching, limits and key encryption.
- The admin dashboard panels (Persian, RTL), checklist ticking and AI answers rendered in a browser with stubbed API responses.

Not yet verified:

- A full run on a real WordPress site with a real database: activation, the 1.0 → 2 schema migration, collection, dashboard queries and uninstall.
- A live request to the Claude API or an OpenAI-compatible gateway.
- Behavior behind specific cache and CDN stacks (LiteSpeed Cache, WP Rocket, Cloudflare), on very high-traffic sites, and with WooCommerce and page builders.
- WordPress Coding Standards (PHPCS).

## Changelog

### 1.3.0

- New: optional AI assistant for checklist issues (Settings → AI Assistant), with the Claude API (default model `claude-opus-5-5`, structured output, server-side refusal fallback) or any OpenAI-compatible endpoint. Requests use the WordPress HTTP API.
- The AI API key is encrypted at rest in a non-autoloaded option, never displayed again, and can be set with `WVM_AI_API_KEY` in `wp-config.php` instead.
- The issue sent to the AI is rebuilt on the server from its key; visitor-derived values are treated as data in the prompt; AI code is shown as text with a review warning.

### 1.2.0

- New: page-specific checklist with per-issue fixes, instructions for the installed optimization plugins, code snippets, and verification from newer visits.
- New attribution: LCP image intrinsic vs rendered width and size in bytes, the page's render-blocking files, the source and height of the element that shifts.
- Code snippets only contain whitelisted values.

### 1.1.0

- New: Optimization Suggestions, URL drill-down, device filter, Core Web Vitals assessment, previous-period comparison, distribution bars, P75 trend charts, page-type analysis.
- New: Persian translation of the suggestions section.
- New: richer attribution (LCP lazy loading, fetch priority, element type and origin; INP script source from Long Animation Frames; CLS media without dimensions).
- New settings: load strategy, rate limit, trust proxy headers. Export form with filters; streamed CSV/JSON export.
- Performance: inline loader with in-browser sampling, one beacon per page view, autoloaded settings, SQL histogram percentiles, leaner indexes, batched upserts.
- Fixed: CLS and INP were stored again on every report of the same page view; existing duplicates are merged on upgrade.
- Fixed: the health score could never exceed 75; the trend chart mixed units; nearly every URL was flagged.
- Fixed: retention cleanup removed at most 1,000 rows per day; visitors behind a CDN shared one rate-limit bucket; user-agent exclusion did not work behind page caches.
- Fixed: CSV formula injection in exports.

### 1.0.0

- Initial release.
