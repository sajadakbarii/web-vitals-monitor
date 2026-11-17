<?php
/**
 * Optimization advisor.
 *
 * Turns field data into prioritized, WordPress-specific recommendations. Rules
 * follow the web.dev guidance: diagnose at the 75th percentile, break LCP into
 * its four sub-parts, INP into its three phases, and look at when and where
 * layout shifts happen. Attribution is read only from the slow page views
 * (value >= P75), which are the ones that decide whether a metric passes.
 *
 * @package Web_Vitals_Monitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Advisor service.
 */
final class Web_Vitals_Advisor {
	/**
	 * Documentation links.
	 */
	private const DOCS = array(
		'lcp'         => 'https://web.dev/articles/optimize-lcp',
		'lcp-lazy'    => 'https://web.dev/articles/lcp-lazy-loading',
		'priority'    => 'https://web.dev/articles/fetch-priority',
		'inp'         => 'https://web.dev/articles/optimize-inp',
		'inp-field'   => 'https://web.dev/articles/find-slow-interactions-in-the-field',
		'long-tasks'  => 'https://web.dev/articles/optimize-long-tasks',
		'dom-size'    => 'https://web.dev/articles/dom-size-and-interactivity',
		'cls'         => 'https://web.dev/articles/optimize-cls',
		'fonts'       => 'https://web.dev/articles/font-best-practices',
		'ttfb'        => 'https://web.dev/articles/optimize-ttfb',
		'fcp'         => 'https://web.dev/articles/fcp',
		'bfcache'     => 'https://web.dev/articles/bfcache',
		'speculation' => 'https://developer.chrome.com/docs/web-platform/prerender-pages',
		'vitals'      => 'https://web.dev/articles/vitals',
	);

	/**
	 * Database service.
	 *
	 * @var Web_Vitals_Database
	 */
	private Web_Vitals_Database $database;

	/**
	 * Settings service.
	 *
	 * @var Web_Vitals_Settings
	 */
	private Web_Vitals_Settings $settings;

	/**
	 * Collected suggestions.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $suggestions = array();

	/**
	 * Plugin directory => name map.
	 *
	 * @var array<string,string>|null
	 */
	private ?array $plugin_names = null;

	/**
	 * Attribution samples per metric for the current analysis.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $samples = array();

	/**
	 * Constructor.
	 */
	public function __construct( Web_Vitals_Database $database, Web_Vitals_Settings $settings ) {
		$this->database = $database;
		$this->settings = $settings;
	}

	/**
	 * Build suggestions for a filter set.
	 *
	 * @param array<string,mixed> $filters Filters from Web_Vitals_Database::build_filters().
	 * @return array<string,mixed>
	 */
	public function analyze( array $filters ): array {
		$this->suggestions = array();
		$this->samples     = array();

		$overview = array();
		foreach ( $this->database->metric_overview( $filters ) as $row ) {
			$overview[ $row['metric'] ] = $row;
		}

		$min = max( 1, (int) $this->settings->get( 'minimum_sample_size', 25 ) );

		if ( $this->data_quality( $overview, $min ) ) {
			if ( $this->ready( $overview, 'TTFB', $min ) || $this->ready( $overview, 'LCP', $min ) ) {
				$this->server_rules( $overview, $filters );
			}
			if ( $this->ready( $overview, 'LCP', $min ) ) {
				$this->lcp_rules( $overview['LCP'], $filters );
			}
			if ( $this->ready( $overview, 'FCP', $min ) ) {
				$this->fcp_rules( $overview, $filters );
			}
			if ( $this->ready( $overview, 'INP', $min ) ) {
				$this->inp_rules( $overview['INP'], $filters );
			}
			if ( $this->ready( $overview, 'CLS', $min ) ) {
				$this->cls_rules( $overview['CLS'], $filters );
			}
			if ( '' === $filters['path'] ) {
				$this->segment_rules( $filters, $min );
			}
			$this->navigation_rules( $filters, $min );
		}

		$rank = array(
			'critical' => 0,
			'warning'  => 1,
			'info'     => 2,
		);
		usort(
			$this->suggestions,
			static function ( array $a, array $b ) use ( $rank ): int {
				return array( $rank[ $a['severity'] ], -$a['impact'] ) <=> array( $rank[ $b['severity'] ], -$b['impact'] );
			}
		);

		return array(
			'suggestions' => $this->suggestions,
			'generatedAt' => current_time( 'mysql', true ),
		);
	}

	/**
	 * Data availability checks. Returns false when there is nothing to analyze.
	 *
	 * @param array<string,array<string,mixed>> $overview Overview by metric.
	 */
	private function data_quality( array $overview, int $min ): bool {
		$health = $this->database->collector_health();
		$total  = array_sum( array_column( $overview, 'samples' ) );

		if ( 0 === $total ) {
			$this->add(
				array(
					'id'       => 'no-data',
					'severity' => 'info',
					'metric'   => 'setup',
					'title'    => __( 'No field data for this period yet', 'web-vitals-monitor' ),
					'summary'  => __( 'Suggestions are generated from real-user measurements. Once visitors browse the site, recommendations appear here automatically.', 'web-vitals-monitor' ),
					'actions'  => array(
						__( 'Open the site in a private window (administrators are excluded by default) and click around a few pages.', 'web-vitals-monitor' ),
						__( 'Make sure your security plugin, firewall or CDN does not block POST requests to /wp-json/web-vitals/v1/collect.', 'web-vitals-monitor' ),
						__( 'Check that the sampling rate in Settings is above 0%.', 'web-vitals-monitor' ),
					),
				)
			);
			return false;
		}

		if ( 'stale' === $health['collector'] ) {
			$this->add(
				array(
					'id'       => 'collector-stale',
					'severity' => 'warning',
					'metric'   => 'setup',
					'title'    => __( 'No measurements received in the last 24 hours', 'web-vitals-monitor' ),
					'summary'  => __( 'Data was collected before but has stopped arriving. This usually means a cache, optimization or security change is blocking the collector.', 'web-vitals-monitor' ),
					'actions'  => array(
						__( 'Purge the page cache so pages include the current collector loader.', 'web-vitals-monitor' ),
						__( 'If a JavaScript optimization plugin combines or delays scripts, exclude "wvm-loader", web-vitals.js and monitor.js.', 'web-vitals-monitor' ),
						__( 'Confirm POST requests to /wp-json/web-vitals/v1/collect return 201 in the browser Network panel.', 'web-vitals-monitor' ),
					),
				)
			);
		}

		$low = array();
		foreach ( Web_Vitals_Config::core_metrics() as $metric ) {
			$samples = (int) ( $overview[ $metric ]['samples'] ?? 0 );
			if ( $samples > 0 && $samples < $min ) {
				$low[] = $metric;
			}
		}
		if ( $low ) {
			$this->add(
				array(
					'id'       => 'low-samples',
					'severity' => 'info',
					'metric'   => 'setup',
					'title'    => __( 'Not enough samples for a reliable assessment', 'web-vitals-monitor' ),
					/* translators: 1: metric list, 2: minimum sample size. */
					'summary'  => sprintf( __( '%1$s have fewer than %2$d samples in this period, so their P75 can change a lot from day to day.', 'web-vitals-monitor' ), implode( ', ', $low ), $min ),
					'actions'  => array(
						__( 'Select a longer date range.', 'web-vitals-monitor' ),
						__( 'Raise the sampling rate in Settings if it is below 100%.', 'web-vitals-monitor' ),
						__( 'INP only exists when visitors interact (click, tap, type), so it naturally has fewer samples than LCP.', 'web-vitals-monitor' ),
					),
				)
			);
		}

		if ( ! $this->settings->get( 'collect_attribution', 1 ) ) {
			$this->add(
				array(
					'id'       => 'attribution-off',
					'severity' => 'info',
					'metric'   => 'setup',
					'title'    => __( 'Enable attribution for detailed suggestions', 'web-vitals-monitor' ),
					'summary'  => __( 'Without attribution data the advisor cannot tell which element, phase or script causes a slow metric.', 'web-vitals-monitor' ),
					'actions'  => array( __( 'Turn on "Collect compact attribution data" in Settings. It adds a few hundred bytes per page view and no personal data.', 'web-vitals-monitor' ) ),
				)
			);
		}

		return true;
	}

	/**
	 * Server response time (TTFB) rules.
	 *
	 * @param array<string,array<string,mixed>> $overview Overview by metric.
	 * @param array<string,mixed>               $filters Filters.
	 */
	private function server_rules( array $overview, array $filters ): void {
		$ttfb     = $overview['TTFB'] ?? array();
		$lcp      = $overview['LCP'] ?? array();
		$status   = $ttfb['status'] ?? 'unknown';
		$samples  = $this->samples( 'TTFB', $filters, (float) ( $ttfb['p75'] ?? 0 ) );
		$lcp_rows = $this->samples( 'LCP', $filters, (float) ( $lcp['p75'] ?? 0 ) );

		$share = 0.0;
		if ( $lcp_rows ) {
			$parts = $this->lcp_parts( $lcp_rows );
			$share = $parts['total'] > 0 ? $parts['ttfb'] / $parts['total'] : 0;
		}

		$bad      = array( 'needs-improvement', 'poor' );
		$ttfb_bad = in_array( $status, $bad, true );
		if ( ! $ttfb_bad && ! ( in_array( $lcp['status'] ?? '', $bad, true ) && $share > 0.4 ) ) {
			return;
		}

		$evidence = array();
		if ( null !== ( $ttfb['p75'] ?? null ) ) {
			/* translators: %s: duration. */
			$evidence[] = sprintf( __( 'TTFB P75: %s (good is 800 ms or less).', 'web-vitals-monitor' ), $this->ms( $ttfb['p75'] ) );
		}
		if ( $share > 0 ) {
			/* translators: %s: percentage. */
			$evidence[] = sprintf( __( 'Server response takes %s of the LCP time on slow page views (guideline: about 40%%).', 'web-vitals-monitor' ), $this->pct( $share ) );
		}

		$phases = array(
			'waitingDuration'    => $this->median( $samples, 'waitingDuration' ),
			'dnsDuration'        => $this->median( $samples, 'dnsDuration' ),
			'connectionDuration' => $this->median( $samples, 'connectionDuration' ),
			'requestDuration'    => $this->median( $samples, 'requestDuration' ),
		);
		arsort( $phases );
		$dominant = (string) array_key_first( $phases );

		$actions = array();
		if ( $samples && 'waitingDuration' === $dominant ) {
			$evidence[] = __( 'Most of the time is spent before the request starts: redirects or service worker start-up.', 'web-vitals-monitor' );
			$actions[]  = __( 'Remove redirect chains (http → https → www → trailing slash). Link directly to final URLs, including in menus and ads.', 'web-vitals-monitor' );
		} elseif ( $samples && in_array( $dominant, array( 'dnsDuration', 'connectionDuration' ), true ) ) {
			$evidence[] = __( 'DNS lookup and connection setup dominate: visitors are far from your server or the connection is slow to establish.', 'web-vitals-monitor' );
			$actions[]  = __( 'Put the site behind a CDN with edge locations near your visitors, and enable HTTP/3 and TLS 1.3.', 'web-vitals-monitor' );
			$actions[]  = __( 'Use a fast DNS provider with a long TTL for the site domain.', 'web-vitals-monitor' );
		} else {
			if ( $samples ) {
				$evidence[] = __( 'The server itself is slow to produce the HTML (request duration dominates).', 'web-vitals-monitor' );
			}
			$actions[] = __( 'Enable full-page caching (for example LiteSpeed Cache, WP Rocket, W3 Total Cache or server-level caching) so most visitors get pre-built HTML.', 'web-vitals-monitor' );
			$actions[] = __( 'Add a persistent object cache (Redis or Memcached) to speed up uncached and logged-in requests.', 'web-vitals-monitor' );
			$actions[] = __( 'Run a supported PHP version (8.2+) with OPcache, and find slow plugins with Query Monitor.', 'web-vitals-monitor' );
			$actions[] = __( 'Cache HTML at the CDN edge (e.g. Cloudflare APO or cache rules) if your audience is far from the server.', 'web-vitals-monitor' );
		}
		$actions[] = __( 'Avoid unique tracking parameters in internal links; they bypass the page cache.', 'web-vitals-monitor' );

		$this->add(
			array(
				'id'       => 'server-response',
				'severity' => $this->severity( $ttfb_bad ? $status : (string) $lcp['status'] ),
				'metric'   => 'TTFB',
				'title'    => __( 'Reduce server response time (TTFB)', 'web-vitals-monitor' ),
				'summary'  => __( 'Every millisecond the server needs before sending the first byte is added directly to FCP and LCP.', 'web-vitals-monitor' ),
				'evidence' => $evidence,
				'actions'  => $actions,
				'urls'     => $this->top_values( $samples, 'page_path' ),
				'docs'     => self::DOCS['ttfb'],
				'impact'   => $share,
			)
		);
	}

	/**
	 * LCP rules based on the four sub-parts and element details.
	 *
	 * @param array<string,mixed> $lcp LCP overview.
	 * @param array<string,mixed> $filters Filters.
	 */
	private function lcp_rules( array $lcp, array $filters ): void {
		$samples = $this->samples( 'LCP', $filters, (float) $lcp['p75'] );
		if ( ! $samples ) {
			return;
		}

		$status   = (string) $lcp['status'];
		$severity = $this->severity( $status );
		$parts    = $this->lcp_parts( $samples );
		$count    = count( $samples );
		$urls     = $this->top_values( $samples, 'page_path' );
		$targets  = $this->top_values( $samples, 'target', 3 );

		$target_note = array();
		if ( $targets ) {
			/* translators: %s: CSS selectors. */
			$target_note[] = sprintf( __( 'Most common LCP elements: %s', 'web-vitals-monitor' ), implode( ' | ', $targets ) );
		}

		// A lazy-loaded LCP image is always a mistake, even when LCP passes.
		$lazy = $this->share( $samples, 'lazy', 1 );
		if ( $lazy >= 0.1 ) {
			$this->add(
				array(
					'id'       => 'lcp-lazy',
					'severity' => 'good' === $status ? 'warning' : 'critical',
					'metric'   => 'LCP',
					'title'    => __( 'The LCP image is lazy-loaded', 'web-vitals-monitor' ),
					'summary'  => __( 'loading="lazy" makes the browser wait for layout before downloading the most important image on the page.', 'web-vitals-monitor' ),
					'evidence' => array_merge(
						/* translators: %s: percentage. */
						array( sprintf( __( '%s of slow page views had a lazy-loaded LCP image.', 'web-vitals-monitor' ), $this->pct( $lazy ) ) ),
						$target_note
					),
					'actions'  => array(
						__( 'Exclude the hero/featured image from lazy loading. Most optimization plugins (WP Rocket, LiteSpeed Cache, Perfmatters, Smush, a3 Lazy Load) have an "exclude" or "skip first N images" option.', 'web-vitals-monitor' ),
						__( 'For page builders (Elementor, Divi...), disable lazy load on the first section\'s image widget.', 'web-vitals-monitor' ),
						__( 'WordPress core skips lazy loading for the first content image; if a theme outputs the hero image itself, make sure it does not add loading="lazy".', 'web-vitals-monitor' ),
					),
					'urls'     => $urls,
					'docs'     => self::DOCS['lcp-lazy'],
					'impact'   => $lazy,
				)
			);
		}

		$bg = $this->share( $samples, 'element', 'bg-image' );
		if ( $bg >= 0.3 && 'good' !== $status ) {
			$this->add(
				array(
					'id'       => 'lcp-background',
					'severity' => $severity,
					'metric'   => 'LCP',
					'title'    => __( 'The LCP image is a CSS background', 'web-vitals-monitor' ),
					'summary'  => __( 'Background images are only discovered after the CSS is downloaded and applied, so they start loading late.', 'web-vitals-monitor' ),
					/* translators: %s: percentage. */
					'evidence' => array_merge( array( sprintf( __( '%s of slow page views used a background image as LCP.', 'web-vitals-monitor' ), $this->pct( $bg ) ) ), $target_note ),
					'actions'  => array(
						__( 'Use a real <img> element for the hero (with width, height and fetchpriority="high") instead of a section background.', 'web-vitals-monitor' ),
						__( 'If it must stay a background, preload it: <link rel="preload" as="image" href="..." fetchpriority="high"> in the <head>.', 'web-vitals-monitor' ),
					),
					'urls'     => $urls,
					'docs'     => self::DOCS['priority'],
					'impact'   => $bg,
				)
			);
		}

		if ( 'good' === $status || $parts['total'] <= 0 ) {
			return;
		}

		$evidence = array(
			sprintf(
				/* translators: 1-4: durations with share. */
				__( 'Slow page views break down as: TTFB %1$s · load delay %2$s · load duration %3$s · render delay %4$s.', 'web-vitals-monitor' ),
				$this->part( $parts['ttfb'], $parts['total'] ),
				$this->part( $parts['delay'], $parts['total'] ),
				$this->part( $parts['duration'], $parts['total'] ),
				$this->part( $parts['render'], $parts['total'] )
			),
		);

		$image_share = $this->share( $samples, 'element', 'img' ) + $bg;

		if ( $parts['delay'] / $parts['total'] > 0.1 && $image_share > 0.3 ) {
			$no_priority = 0;
			foreach ( $samples as $row ) {
				if ( 'img' === ( $row['attribution']['element'] ?? '' ) && 'high' !== ( $row['attribution']['fetchPriority'] ?? '' ) ) {
					++$no_priority;
				}
			}
			$actions = array(
				__( 'Make the LCP image discoverable in the initial HTML; avoid hero images injected by JavaScript sliders or page-builder animations.', 'web-vitals-monitor' ),
				__( 'Add fetchpriority="high" to the LCP image (only one or two images per page). WordPress 6.3+ does this automatically for the first large content image.', 'web-vitals-monitor' ),
				__( 'Serve the image from the same origin as the page, or add <link rel="preconnect"> for its CDN host.', 'web-vitals-monitor' ),
				__( 'Set fetchpriority="low" on hidden carousel slides so they do not compete with the visible one.', 'web-vitals-monitor' ),
			);
			if ( $no_priority / $count > 0.5 ) {
				/* translators: %s: percentage. */
				$evidence[] = sprintf( __( '%s of slow page views loaded the LCP image without fetchpriority="high".', 'web-vitals-monitor' ), $this->pct( $no_priority / $count ) );
			}
			$this->add(
				array(
					'id'       => 'lcp-load-delay',
					'severity' => $severity,
					'metric'   => 'LCP',
					'title'    => __( 'Start loading the LCP image earlier', 'web-vitals-monitor' ),
					'summary'  => __( 'The browser discovers or prioritizes the LCP resource late. Resource load delay should be close to zero.', 'web-vitals-monitor' ),
					'evidence' => array_merge( $evidence, $target_note ),
					'actions'  => $actions,
					'urls'     => $urls,
					'docs'     => self::DOCS['priority'],
					'impact'   => $parts['delay'] / $parts['total'],
				)
			);
		}

		if ( $parts['duration'] / $parts['total'] > 0.4 && $image_share > 0.3 ) {
			$cross = $this->share( $samples, 'crossOrigin', 1 );
			$extra = array();
			if ( $cross >= 0.5 ) {
				/* translators: %s: percentage. */
				$extra[] = sprintf( __( '%s of LCP images come from another domain, which needs an extra connection.', 'web-vitals-monitor' ), $this->pct( $cross ) );
			}
			$this->add(
				array(
					'id'       => 'lcp-load-duration',
					'severity' => $severity,
					'metric'   => 'LCP',
					'title'    => __( 'Make the LCP image smaller', 'web-vitals-monitor' ),
					'summary'  => __( 'Downloading the LCP image takes a large share of LCP. Fewer bytes means a faster LCP, especially on mobile networks.', 'web-vitals-monitor' ),
					'evidence' => array_merge( $evidence, $extra, $this->top_values( $samples, 'url', 3 ) ? array( sprintf( /* translators: %s: URLs. */ __( 'Largest offenders: %s', 'web-vitals-monitor' ), implode( ' | ', $this->top_values( $samples, 'url', 3 ) ) ) ) : array() ),
					'actions'  => array(
						__( 'Convert hero images to AVIF or WebP (e.g. with an image optimization plugin or your CDN) and compress them.', 'web-vitals-monitor' ),
						__( 'Serve correctly sized images with srcset/sizes; do not send a 2000px image to a 400px phone screen.', 'web-vitals-monitor' ),
						__( 'Use an image CDN close to visitors, with long Cache-Control lifetimes.', 'web-vitals-monitor' ),
						__( 'Reduce how many high-priority files load at the same time as the hero image (fonts, large CSS, other images).', 'web-vitals-monitor' ),
					),
					'urls'     => $urls,
					'docs'     => self::DOCS['lcp'],
					'impact'   => $parts['duration'] / $parts['total'],
				)
			);
		}

		if ( $parts['render'] / $parts['total'] > 0.1 ) {
			$text    = $this->share( $samples, 'element', 'text' );
			$actions = array(
				__( 'Remove render-blocking resources: defer non-critical JavaScript and avoid synchronous scripts in <head>.', 'web-vitals-monitor' ),
				__( 'Generate critical CSS and load the rest asynchronously; remove unused CSS (most optimization plugins offer both).', 'web-vitals-monitor' ),
				__( 'Make sure the LCP element is not hidden by animations, sliders or A/B-testing scripts until JavaScript runs.', 'web-vitals-monitor' ),
				__( 'Break up long JavaScript tasks during load; the browser cannot paint while the main thread is busy.', 'web-vitals-monitor' ),
			);
			if ( $text >= 0.5 ) {
				array_unshift(
					$actions,
					__( 'The LCP element is usually text: use font-display: swap (or optional) for web fonts and preload the main font file, or use a system font.', 'web-vitals-monitor' )
				);
			}
			$this->add(
				array(
					'id'       => 'lcp-render-delay',
					'severity' => $severity,
					'metric'   => 'LCP',
					'title'    => __( 'Remove what delays rendering of the LCP element', 'web-vitals-monitor' ),
					'summary'  => __( 'The LCP resource is ready, but the element is painted much later. Element render delay should be close to zero.', 'web-vitals-monitor' ),
					'evidence' => array_merge( $evidence, $target_note ),
					'actions'  => $actions,
					'urls'     => $urls,
					'docs'     => $text >= 0.5 ? self::DOCS['fonts'] : self::DOCS['lcp'],
					'impact'   => $parts['render'] / $parts['total'],
				)
			);
		}
	}

	/**
	 * FCP rules: slow FCP with acceptable TTFB means render-blocking resources.
	 *
	 * @param array<string,array<string,mixed>> $overview Overview by metric.
	 * @param array<string,mixed>               $filters Filters.
	 */
	private function fcp_rules( array $overview, array $filters ): void {
		$fcp = $overview['FCP'];
		if ( 'good' === $fcp['status'] || 'poor' === ( $overview['TTFB']['status'] ?? '' ) ) {
			return;
		}

		$samples = $this->samples( 'FCP', $filters, (float) $fcp['p75'] );
		$gap     = $this->median( $samples, 'firstByteToFCP' );

		$evidence = array(
			/* translators: %s: duration. */
			sprintf( __( 'FCP P75: %s (good is 1.8 s or less).', 'web-vitals-monitor' ), $this->ms( $fcp['p75'] ) ),
		);
		if ( null !== $gap ) {
			/* translators: %s: duration. */
			$evidence[] = sprintf( __( 'On slow page views the browser waits %s between the first byte and the first paint.', 'web-vitals-monitor' ), $this->ms( $gap ) );
		}

		$this->add(
			array(
				'id'       => 'fcp-render-blocking',
				'severity' => 'poor' === $fcp['status'] ? 'warning' : 'info',
				'metric'   => 'FCP',
				'title'    => __( 'Reduce render-blocking CSS and JavaScript', 'web-vitals-monitor' ),
				'summary'  => __( 'The server responds in time, but nothing is painted until stylesheets and synchronous scripts in <head> are downloaded and processed.', 'web-vitals-monitor' ),
				'evidence' => $evidence,
				'actions'  => array(
					__( 'Inline critical CSS and load the full stylesheet asynchronously.', 'web-vitals-monitor' ),
					__( 'Add defer to scripts in <head>, or move them to the footer. Unload plugin assets on pages that do not use them (Perfmatters, Asset CleanUp).', 'web-vitals-monitor' ),
					__( 'Self-host Google Fonts and use font-display: swap.', 'web-vitals-monitor' ),
					__( 'Enable Brotli/Gzip compression and HTTP/2 or HTTP/3 on the server.', 'web-vitals-monitor' ),
				),
				'urls'     => $this->top_values( $samples, 'page_path' ),
				'docs'     => self::DOCS['fcp'],
				'impact'   => 0.2,
			)
		);
	}

	/**
	 * INP rules based on the three interaction phases and Long Animation Frame scripts.
	 *
	 * @param array<string,mixed> $inp INP overview.
	 * @param array<string,mixed> $filters Filters.
	 */
	private function inp_rules( array $inp, array $filters ): void {
		if ( 'good' === $inp['status'] ) {
			return;
		}

		$samples = $this->samples( 'INP', $filters, (float) $inp['p75'] );
		if ( ! $samples ) {
			return;
		}

		$severity = $this->severity( (string) $inp['status'] );
		$urls     = $this->top_values( $samples, 'page_path' );
		$phases   = array(
			'input'        => (float) $this->median( $samples, 'inputDelay' ),
			'processing'   => (float) $this->median( $samples, 'processingDuration' ),
			'presentation' => (float) $this->median( $samples, 'presentationDelay' ),
		);
		$total    = array_sum( $phases );

		$evidence = array(
			sprintf(
				/* translators: 1-3: durations with share. */
				__( 'Slow interactions break down as: input delay %1$s · processing %2$s · presentation delay %3$s.', 'web-vitals-monitor' ),
				$this->part( $phases['input'], $total ),
				$this->part( $phases['processing'], $total ),
				$this->part( $phases['presentation'], $total )
			),
		);
		$targets = $this->top_values( $samples, 'interactionTarget', 3 );
		if ( $targets ) {
			/* translators: %s: CSS selectors. */
			$evidence[] = sprintf( __( 'Slowest elements to interact with: %s', 'web-vitals-monitor' ), implode( ' | ', $targets ) );
		}

		$during_load = $this->share( $samples, 'loadState', 'loading' ) + $this->share( $samples, 'loadState', 'dom-interactive' );

		arsort( $phases );
		$dominant = (string) array_key_first( $phases );

		if ( 'input' === $dominant ) {
			$actions = array(
				__( 'Delay non-essential JavaScript until user interaction (the "Delay JS execution" feature of WP Rocket, Perfmatters, LiteSpeed Cache, FlyingPress).', 'web-vitals-monitor' ),
				__( 'Unload plugin scripts on pages that do not need them (sliders, forms, chat widgets, analytics duplicates).', 'web-vitals-monitor' ),
				__( 'Break up long tasks so the main thread can respond to input between them.', 'web-vitals-monitor' ),
				__( 'Load third-party tags (chat, heatmaps, ads, tag managers) after the page is idle.', 'web-vitals-monitor' ),
			);
			if ( $during_load >= 0.4 ) {
				/* translators: %s: percentage. */
				$evidence[] = sprintf( __( '%s of slow interactions happened while the page was still loading, when script evaluation blocks the main thread.', 'web-vitals-monitor' ), $this->pct( $during_load ) );
			}
			$title   = __( 'Free the main thread so input is handled immediately', 'web-vitals-monitor' );
			$summary = __( 'Interactions wait for other JavaScript to finish before their event handlers can even start.', 'web-vitals-monitor' );
			$doc     = self::DOCS['long-tasks'];
		} elseif ( 'processing' === $dominant ) {
			$actions = array(
				__( 'Do less work inside click/tap/key handlers: update the visible UI first, then defer the rest (analytics, saving, recalculation) with setTimeout or scheduler.yield().', 'web-vitals-monitor' ),
				__( 'Avoid layout thrashing: do not read layout properties (offsetHeight, getBoundingClientRect) right after changing styles in the same handler.', 'web-vitals-monitor' ),
				__( 'Debounce handlers on input and scroll; avoid synchronous AJAX and large JSON parsing in handlers.', 'web-vitals-monitor' ),
				__( 'Check the scripts listed in the "scripts behind slow interactions" suggestion and update or replace the plugin responsible.', 'web-vitals-monitor' ),
			);
			$title   = __( 'Speed up event handlers', 'web-vitals-monitor' );
			$summary = __( 'The JavaScript that runs in response to interactions takes too long.', 'web-vitals-monitor' );
			$doc     = self::DOCS['inp'];
		} else {
			$layout = $this->median( $samples, 'totalStyleAndLayoutDuration' );
			if ( null !== $layout && $layout > 0 ) {
				/* translators: %s: duration. */
				$evidence[] = sprintf( __( 'Style and layout work takes %s on a typical slow interaction.', 'web-vitals-monitor' ), $this->ms( $layout ) );
			}
			$actions = array(
				__( 'Reduce DOM size: page builders often nest many wrapper elements. Simplify sections, remove hidden mobile/desktop duplicates, and paginate long lists.', 'web-vitals-monitor' ),
				__( 'Apply content-visibility: auto to long below-the-fold sections so the browser skips rendering them.', 'web-vitals-monitor' ),
				__( 'Avoid re-rendering large chunks of HTML with innerHTML in response to interactions (mega menus, filters, mini carts).', 'web-vitals-monitor' ),
				__( 'Animate with transform and opacity instead of properties that trigger layout.', 'web-vitals-monitor' ),
			);
			$title   = __( 'Reduce rendering work after interactions', 'web-vitals-monitor' );
			$summary = __( 'Handlers finish quickly, but the browser needs a long time to calculate styles, layout and paint the next frame.', 'web-vitals-monitor' );
			$doc     = self::DOCS['dom-size'];
		}

		$this->add(
			array(
				'id'       => 'inp-' . $dominant,
				'severity' => $severity,
				'metric'   => 'INP',
				'title'    => $title,
				'summary'  => $summary,
				'evidence' => $evidence,
				'actions'  => $actions,
				'urls'     => $urls,
				'docs'     => $doc,
				'impact'   => $total > 0 ? $phases[ $dominant ] / $total : 0,
			)
		);

		$this->script_rules( $samples, $severity, $urls );
	}

	/**
	 * Attribute slow interactions to plugins, themes and third parties via LoAF script data.
	 *
	 * @param array<int,array<string,mixed>> $samples INP samples.
	 * @param string                         $severity Severity.
	 * @param string[]                       $urls Affected URLs.
	 */
	private function script_rules( array $samples, string $severity, array $urls ): void {
		$owners = array();
		$with   = 0;

		foreach ( $samples as $row ) {
			$attribution = $row['attribution'];
			if ( ! isset( $attribution['scriptDuration'] ) ) {
				continue;
			}
			++$with;
			$owner = $this->script_owner( (string) ( $attribution['scriptSource'] ?? '' ) );
			if ( ! isset( $owners[ $owner ] ) ) {
				$owners[ $owner ] = array(
					'count'     => 0,
					'durations' => array(),
				);
			}
			++$owners[ $owner ]['count'];
			$owners[ $owner ]['durations'][] = (float) $attribution['scriptDuration'];
		}

		if ( $with < 5 || ! $owners ) {
			return;
		}

		uasort(
			$owners,
			static function ( array $a, array $b ): int {
				return $b['count'] <=> $a['count'];
			}
		);

		$evidence = array();
		foreach ( array_slice( $owners, 0, 5, true ) as $owner => $data ) {
			sort( $data['durations'] );
			$evidence[] = sprintf(
				/* translators: 1: script owner, 2: percentage, 3: duration. */
				__( '%1$s — longest script in %2$s of slow interactions (median %3$s)', 'web-vitals-monitor' ),
				$owner,
				$this->pct( $data['count'] / $with ),
				$this->ms( $data['durations'][ (int) floor( ( count( $data['durations'] ) - 1 ) / 2 ) ] )
			);
		}

		$top = (string) array_key_first( $owners );

		$this->add(
			array(
				'id'       => 'inp-scripts',
				'severity' => $severity,
				'metric'   => 'INP',
				/* translators: %s: script owner such as "Plugin: Elementor". */
				'title'    => sprintf( __( 'Scripts behind slow interactions — top: %s', 'web-vitals-monitor' ), $top ),
				'summary'  => __( 'Long Animation Frame data shows which scripts were running during slow interactions.', 'web-vitals-monitor' ),
				'evidence' => $evidence,
				'actions'  => array(
					__( 'Update the plugins and theme listed above; performance fixes are common in new releases.', 'web-vitals-monitor' ),
					__( 'Disable features of that plugin you do not use, or load its scripts only on pages that need them.', 'web-vitals-monitor' ),
					__( 'If a third-party script is listed, load it with a delay or facade (e.g. click-to-load chat or video embeds).', 'web-vitals-monitor' ),
					__( 'Reproduce with Chrome DevTools > Performance while clicking the elements listed in the INP suggestion.', 'web-vitals-monitor' ),
				),
				'urls'     => $urls,
				'docs'     => self::DOCS['inp-field'],
				'impact'   => $owners[ $top ]['count'] / $with,
			)
		);
	}

	/**
	 * CLS rules based on load state, shifted elements and missing dimensions.
	 *
	 * @param array<string,mixed> $cls CLS overview.
	 * @param array<string,mixed> $filters Filters.
	 */
	private function cls_rules( array $cls, array $filters ): void {
		if ( 'good' === $cls['status'] ) {
			return;
		}

		$samples = array_values(
			array_filter(
				$this->samples( 'CLS', $filters, max( 0.001, (float) $cls['p75'] ) ),
				static function ( array $row ): bool {
					return $row['metric_value'] > 0;
				}
			)
		);
		if ( ! $samples ) {
			return;
		}

		$severity = $this->severity( (string) $cls['status'] );
		$urls     = $this->top_values( $samples, 'page_path' );
		$targets  = $this->top_values( $samples, 'largestShiftTarget', 3 );
		$evidence = array();
		if ( $targets ) {
			/* translators: %s: CSS selectors. */
			$evidence[] = sprintf( __( 'Elements that shift the most: %s', 'web-vitals-monitor' ), implode( ' | ', $targets ) );
		}

		$no_dims = $this->share( $samples, 'shiftNoDims', 1 );
		if ( $no_dims >= 0.2 ) {
			$this->add(
				array(
					'id'       => 'cls-dimensions',
					'severity' => $severity,
					'metric'   => 'CLS',
					'title'    => __( 'Give images, videos and iframes explicit dimensions', 'web-vitals-monitor' ),
					'summary'  => __( 'Media without width and height has no reserved space, so content jumps when it loads.', 'web-vitals-monitor' ),
					/* translators: %s: percentage. */
					'evidence' => array_merge( array( sprintf( __( 'In %s of slow page views the biggest shift involved media without width/height attributes.', 'web-vitals-monitor' ), $this->pct( $no_dims ) ) ), $evidence ),
					'actions'  => array(
						__( 'Add width and height attributes to <img>, <video> and <iframe> (WordPress adds them to content images automatically; check theme templates, logos, sliders and builder widgets).', 'web-vitals-monitor' ),
						__( 'For responsive embeds, reserve space with CSS aspect-ratio (e.g. aspect-ratio: 16 / 9).', 'web-vitals-monitor' ),
						__( 'Make sure lazy-load plugins keep the width/height attributes when they swap in placeholders.', 'web-vitals-monitor' ),
					),
					'urls'     => $urls,
					'docs'     => self::DOCS['cls'],
					'impact'   => $no_dims,
				)
			);
		}

		$early = $this->share( $samples, 'loadState', 'loading' ) + $this->share( $samples, 'loadState', 'dom-interactive' );
		$late  = $this->share( $samples, 'loadState', 'complete' );

		if ( $early >= 0.5 ) {
			$this->add(
				array(
					'id'       => 'cls-early',
					'severity' => $severity,
					'metric'   => 'CLS',
					'title'    => __( 'Fix layout shifts during page load', 'web-vitals-monitor' ),
					'summary'  => __( 'Most large shifts happen while the page is still loading: late CSS, web fonts swapping in, and media without reserved space.', 'web-vitals-monitor' ),
					/* translators: %s: percentage. */
					'evidence' => array_merge( array( sprintf( __( '%s of the largest shifts happened before the page finished loading.', 'web-vitals-monitor' ), $this->pct( $early ) ) ), $evidence ),
					'actions'  => array(
						__( 'Use font-display: optional, or match fallback font metrics with size-adjust / ascent-override so text does not reflow when the web font arrives. Preload the main font.', 'web-vitals-monitor' ),
						__( 'If an optimization plugin loads CSS asynchronously, make sure its critical CSS covers above-the-fold layout (header, menu, hero); otherwise the page reflows when the full CSS applies.', 'web-vitals-monitor' ),
						__( 'Reserve space for the header, logo, and sliders with fixed heights or aspect-ratio.', 'web-vitals-monitor' ),
					),
					'urls'     => $urls,
					'docs'     => self::DOCS['fonts'],
					'impact'   => $early,
				)
			);
		}

		if ( $late >= 0.5 ) {
			$this->add(
				array(
					'id'       => 'cls-late',
					'severity' => $severity,
					'metric'   => 'CLS',
					'title'    => __( 'Reserve space for content injected after load', 'web-vitals-monitor' ),
					'summary'  => __( 'Most large shifts happen after the page has loaded: ads, embeds, cookie or promo banners, lazy-loaded blocks and AJAX content pushing the page down.', 'web-vitals-monitor' ),
					/* translators: %s: percentage. */
					'evidence' => array_merge( array( sprintf( __( '%s of the largest shifts happened after the page finished loading.', 'web-vitals-monitor' ), $this->pct( $late ) ) ), $evidence ),
					'actions'  => array(
						__( 'Give ad slots and embeds a min-height matching their most common size; keep the space even when no ad fills it.', 'web-vitals-monitor' ),
						__( 'Show cookie notices, promo bars and popups as overlays (position: fixed) instead of inserting them at the top of the page.', 'web-vitals-monitor' ),
						__( 'Use skeleton placeholders with fixed height for AJAX-loaded sections (related products, reviews, mini cart).', 'web-vitals-monitor' ),
						__( 'Animate with transform instead of changing height, top or margin.', 'web-vitals-monitor' ),
					),
					'urls'     => $urls,
					'docs'     => self::DOCS['cls'],
					'impact'   => $late,
				)
			);
		}

		if ( $no_dims < 0.2 && $early < 0.5 && $late < 0.5 ) {
			$this->add(
				array(
					'id'       => 'cls-general',
					'severity' => $severity,
					'metric'   => 'CLS',
					'title'    => __( 'Reduce unexpected layout shifts', 'web-vitals-monitor' ),
					'summary'  => __( 'Shifts happen at different moments of the page lifecycle. Start with the elements listed below.', 'web-vitals-monitor' ),
					'evidence' => $evidence,
					'actions'  => array(
						__( 'Reserve space for every element that loads asynchronously (images, ads, embeds, banners).', 'web-vitals-monitor' ),
						__( 'Use font-display: optional or metric-matched fallback fonts.', 'web-vitals-monitor' ),
						__( 'Use the Layout Shift Regions option in Chrome DevTools > Rendering to see shifts as they happen.', 'web-vitals-monitor' ),
					),
					'urls'     => $urls,
					'docs'     => self::DOCS['cls'],
					'impact'   => 0.1,
				)
			);
		}
	}

	/**
	 * Device and page-type segmentation rules.
	 *
	 * @param array<string,mixed> $filters Filters.
	 */
	private function segment_rules( array $filters, int $min ): void {
		if ( '' === $filters['device'] ) {
			$devices = array();
			foreach ( $this->database->dimension_summary( 'device_type', $filters ) as $row ) {
				$devices[ $row['label'] ] = $row;
			}

			if ( isset( $devices['mobile'], $devices['desktop'] ) ) {
				$gaps = array();
				foreach ( Web_Vitals_Config::core_metrics() as $metric ) {
					$mobile  = $devices['mobile'][ $metric ];
					$desktop = $devices['desktop'][ $metric ];
					if ( $mobile['samples'] >= $min && $desktop['samples'] >= $min && 'good' !== $mobile['status'] && 'good' === $desktop['status'] ) {
						/* translators: 1: metric, 2: mobile value, 3: desktop value. */
						$gaps[] = sprintf( __( '%1$s: mobile %2$s vs desktop %3$s', 'web-vitals-monitor' ), $metric, $this->format( $metric, $mobile['p75'] ), $this->format( $metric, $desktop['p75'] ) );
					}
				}

				if ( $gaps ) {
					$this->add(
						array(
							'id'       => 'mobile-gap',
							'severity' => 'warning',
							'metric'   => 'device',
							'title'    => __( 'Mobile visitors have a much worse experience', 'web-vitals-monitor' ),
							'summary'  => __( 'Google evaluates mobile and desktop separately. These metrics pass on desktop but fail on mobile:', 'web-vitals-monitor' ),
							'evidence' => $gaps,
							'actions'  => array(
								__( 'Use the device filter above to see mobile-only suggestions.', 'web-vitals-monitor' ),
								__( 'Serve smaller images to phones with srcset/sizes, and avoid loading desktop-only sections that are just hidden with CSS.', 'web-vitals-monitor' ),
								__( 'Phones have slower CPUs: reduce JavaScript, especially sliders, animations and page-builder widgets above the fold.', 'web-vitals-monitor' ),
								__( 'Test with Chrome DevTools CPU throttling (4x) and a "Slow 4G" network profile.', 'web-vitals-monitor' ),
							),
							'impact'   => 0.5,
						)
					);
				}
			}
		}

		$failing = array();
		foreach ( $this->database->dimension_summary( 'page_type', $filters ) as $row ) {
			foreach ( Web_Vitals_Config::core_metrics() as $metric ) {
				if ( $row[ $metric ]['samples'] >= $min && 'poor' === $row[ $metric ]['status'] ) {
					/* translators: 1: page type, 2: metric, 3: value. */
					$failing[] = sprintf( __( '%1$s — %2$s %3$s', 'web-vitals-monitor' ), $row['label'], $metric, $this->format( $metric, $row[ $metric ]['p75'] ) );
				}
			}
		}

		if ( $failing ) {
			$this->add(
				array(
					'id'       => 'page-types',
					'severity' => 'warning',
					'metric'   => 'template',
					'title'    => __( 'Some page templates are rated Poor', 'web-vitals-monitor' ),
					'summary'  => __( 'Pages that share a template usually share the same problem, so fixing the template fixes all of them at once.', 'web-vitals-monitor' ),
					'evidence' => array_slice( $failing, 0, 6 ),
					'actions'  => array(
						__( 'Open the slowest URL of that template from the "Top Problematic URLs" table to see its specific suggestions.', 'web-vitals-monitor' ),
						__( 'Look for template-specific widgets: product galleries, related-post sliders, comment sections, archive filters.', 'web-vitals-monitor' ),
					),
					'impact'   => 0.4,
				)
			);
		}
	}

	/**
	 * Back/forward cache and prerendering opportunities.
	 *
	 * @param array<string,mixed> $filters Filters.
	 */
	private function navigation_rules( array $filters, int $min ): void {
		$types = $this->database->navigation_summary( $filters );
		$total = array_sum( $types );
		if ( $total < $min ) {
			return;
		}

		$back     = (int) ( $types['back-forward'] ?? 0 );
		$restored = (int) ( $types['back-forward-cache'] ?? 0 );
		if ( ( $back + $restored ) / $total >= 0.03 && $restored < $back ) {
			$this->add(
				array(
					'id'       => 'bfcache',
					'severity' => 'info',
					'metric'   => 'navigation',
					'title'    => __( 'Make pages eligible for the back/forward cache', 'web-vitals-monitor' ),
					'summary'  => __( 'Pages restored from the bfcache appear instantly with no loading and no layout shifts. Many of your back/forward navigations reload the page instead.', 'web-vitals-monitor' ),
					'evidence' => array(
						/* translators: 1: percentage, 2: percentage. */
						sprintf( __( 'Back/forward navigations: %1$s of page views, only %2$s of them restored from the bfcache.', 'web-vitals-monitor' ), $this->pct( ( $back + $restored ) / $total ), $this->pct( $restored / max( 1, $back + $restored ) ) ),
					),
					'actions'  => array(
						__( 'Do not send Cache-Control: no-store for regular pages (some caching, security and membership plugins add it everywhere).', 'web-vitals-monitor' ),
						__( 'Remove "unload" event listeners; old jQuery plugins and some analytics scripts still use them. Use "pagehide" instead.', 'web-vitals-monitor' ),
						__( 'Check eligibility in Chrome DevTools > Application > Back/forward cache > "Test back/forward cache".', 'web-vitals-monitor' ),
					),
					'docs'     => self::DOCS['bfcache'],
					'impact'   => ( $back + $restored ) / $total,
				)
			);
		}

		if ( empty( $types['prerender'] ) ) {
			$this->add(
				array(
					'id'       => 'speculation',
					'severity' => 'info',
					'metric'   => 'navigation',
					'title'    => __( 'Make next-page navigations instant with Speculation Rules', 'web-vitals-monitor' ),
					'summary'  => __( 'No page views were prerendered. Prerendered pages load in the background before the click, so LCP is close to zero.', 'web-vitals-monitor' ),
					'actions'  => array(
						__( 'WordPress 6.8+ prefetches links with conservative eagerness by default. Install the official "Speculative Loading" plugin to switch to prerender with moderate eagerness.', 'web-vitals-monitor' ),
						__( 'Exclude cart, checkout, logout and other state-changing URLs from speculation.', 'web-vitals-monitor' ),
					),
					'docs'     => self::DOCS['speculation'],
					'impact'   => 0.05,
				)
			);
		}
	}

	/**
	 * Slow samples (value >= threshold) with attribution, fetched once per metric.
	 *
	 * @param string              $metric Metric.
	 * @param array<string,mixed> $filters Filters.
	 * @param float               $min_value Usually the metric's P75.
	 * @return array<int,array<string,mixed>>
	 */
	private function samples( string $metric, array $filters, float $min_value ): array {
		if ( ! isset( $this->samples[ $metric ] ) ) {
			$this->samples[ $metric ] = $this->database->attribution_samples( $metric, $filters, $min_value );
		}

		return $this->samples[ $metric ];
	}

	/**
	 * Median LCP sub-parts of slow page views.
	 *
	 * @param array<int,array<string,mixed>> $samples LCP samples.
	 * @return array<string,float>
	 */
	private function lcp_parts( array $samples ): array {
		$parts = array(
			'ttfb'     => (float) $this->median( $samples, 'timeToFirstByte' ),
			'delay'    => (float) $this->median( $samples, 'resourceLoadDelay' ),
			'duration' => (float) $this->median( $samples, 'resourceLoadDuration' ),
			'render'   => (float) $this->median( $samples, 'elementRenderDelay' ),
		);
		$parts['total'] = array_sum( $parts );

		return $parts;
	}

	/**
	 * Store a suggestion with defaults.
	 *
	 * @param array<string,mixed> $suggestion Suggestion.
	 */
	private function add( array $suggestion ): void {
		$this->suggestions[] = wp_parse_args(
			$suggestion,
			array(
				'evidence' => array(),
				'actions'  => array(),
				'urls'     => array(),
				'docs'     => self::DOCS['vitals'],
				'impact'   => 0,
			)
		);
	}

	/**
	 * Whether a metric has enough samples to analyze.
	 *
	 * @param array<string,array<string,mixed>> $overview Overview by metric.
	 */
	private function ready( array $overview, string $metric, int $min ): bool {
		return isset( $overview[ $metric ] ) && $overview[ $metric ]['samples'] >= $min && null !== $overview[ $metric ]['p75'];
	}

	/**
	 * Map a metric rating to a suggestion severity.
	 */
	private function severity( string $status ): string {
		return match ( $status ) {
			'poor'              => 'critical',
			'needs-improvement' => 'warning',
			default             => 'info',
		};
	}

	/**
	 * Median of a numeric attribution field.
	 *
	 * @param array<int,array<string,mixed>> $samples Samples.
	 */
	private function median( array $samples, string $key ): ?float {
		$values = array();
		foreach ( $samples as $row ) {
			if ( isset( $row['attribution'][ $key ] ) && is_numeric( $row['attribution'][ $key ] ) ) {
				$values[] = (float) $row['attribution'][ $key ];
			}
		}
		if ( ! $values ) {
			return null;
		}
		sort( $values );
		return $values[ (int) floor( ( count( $values ) - 1 ) / 2 ) ];
	}

	/**
	 * Share of samples whose attribution field equals a value.
	 *
	 * @param array<int,array<string,mixed>> $samples Samples.
	 * @param mixed                          $value Expected value.
	 */
	private function share( array $samples, string $key, mixed $value ): float {
		if ( ! $samples ) {
			return 0.0;
		}
		$hits = 0;
		foreach ( $samples as $row ) {
			if ( isset( $row['attribution'][ $key ] ) && $row['attribution'][ $key ] == $value ) { // phpcs:ignore Universal.Operators.StrictComparisons -- 1 vs 1.0.
				++$hits;
			}
		}
		return $hits / count( $samples );
	}

	/**
	 * Most frequent values of a row field or attribution field.
	 *
	 * @param array<int,array<string,mixed>> $samples Samples.
	 * @return string[]
	 */
	private function top_values( array $samples, string $key, int $limit = 5 ): array {
		$counts = array();
		foreach ( $samples as $row ) {
			$value = $row[ $key ] ?? $row['attribution'][ $key ] ?? '';
			if ( is_string( $value ) && '' !== $value ) {
				$counts[ $value ] = ( $counts[ $value ] ?? 0 ) + 1;
			}
		}
		arsort( $counts );
		return array_map( 'strval', array_keys( array_slice( $counts, 0, $limit, true ) ) );
	}

	/**
	 * Describe who owns a script URL: plugin, theme, core or a third party.
	 */
	public function script_owner( string $url ): string {
		if ( '' === $url ) {
			return __( 'Inline or unattributed script', 'web-vitals-monitor' );
		}

		$path        = (string) wp_parse_url( $url, PHP_URL_PATH );
		$host        = (string) wp_parse_url( $url, PHP_URL_HOST );
		$site_host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$plugin_path = rtrim( (string) wp_parse_url( plugins_url(), PHP_URL_PATH ), '/' ) . '/';
		$theme_path  = rtrim( (string) wp_parse_url( get_theme_root_uri(), PHP_URL_PATH ), '/' ) . '/';

		if ( '' !== $host && $host !== $site_host && ! str_ends_with( $host, '.' . $site_host ) ) {
			/* translators: %s: host name. */
			return sprintf( __( 'Third-party: %s', 'web-vitals-monitor' ), $host );
		}
		if ( str_starts_with( $path, $plugin_path ) ) {
			$slug = strtok( substr( $path, strlen( $plugin_path ) ), '/' );
			/* translators: %s: plugin name. */
			return sprintf( __( 'Plugin: %s', 'web-vitals-monitor' ), $this->plugin_name( (string) $slug ) );
		}
		if ( str_starts_with( $path, $theme_path ) ) {
			$slug  = (string) strtok( substr( $path, strlen( $theme_path ) ), '/' );
			$theme = wp_get_theme( $slug );
			/* translators: %s: theme name. */
			return sprintf( __( 'Theme: %s', 'web-vitals-monitor' ), $theme->exists() ? $theme->get( 'Name' ) : $slug );
		}
		if ( str_contains( $path, '/wp-includes/' ) ) {
			return __( 'WordPress core', 'web-vitals-monitor' );
		}

		/* translators: %s: file name. */
		return sprintf( __( 'Site script: %s', 'web-vitals-monitor' ), wp_basename( $path ) );
	}

	/**
	 * Human name for a plugin directory.
	 */
	private function plugin_name( string $slug ): string {
		if ( null === $this->plugin_names ) {
			$this->plugin_names = array();
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( get_plugins() as $file => $data ) {
				$this->plugin_names[ dirname( $file ) ] = (string) $data['Name'];
			}
		}

		return $this->plugin_names[ $slug ] ?? $slug;
	}

	/**
	 * Format a metric value.
	 */
	private function format( string $metric, ?float $value ): string {
		if ( null === $value ) {
			return 'n/a';
		}
		return 'CLS' === $metric ? number_format_i18n( $value, 3 ) : $this->ms( $value );
	}

	/**
	 * Format milliseconds.
	 */
	private function ms( float $value ): string {
		/* translators: %s: seconds. */
		return $value >= 1000 ? sprintf( __( '%s s', 'web-vitals-monitor' ), number_format_i18n( $value / 1000, 2 ) ) : sprintf( /* translators: %s: milliseconds. */ __( '%s ms', 'web-vitals-monitor' ), number_format_i18n( $value ) );
	}

	/**
	 * Format a 0..1 share as a percentage.
	 */
	private function pct( float $share ): string {
		return number_format_i18n( $share * 100 ) . '%';
	}

	/**
	 * Duration with its share of a total.
	 */
	private function part( float $value, float $total ): string {
		return $this->ms( $value ) . ( $total > 0 ? ' (' . $this->pct( $value / $total ) . ')' : '' );
	}
}
