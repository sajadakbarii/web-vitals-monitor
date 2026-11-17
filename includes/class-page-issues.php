<?php
/**
 * Page-specific issue checklist.
 *
 * Detects concrete problems on one URL from that URL's own samples (the LCP
 * image, the element visitors click, the element that shifts, the files that
 * block rendering) and writes a fix for exactly that problem. Each issue has a
 * stable key so it can be ticked off; once ticked, newer samples tell whether
 * the fix worked.
 *
 * Values inside code snippets come from visitor-supplied attribution, so they
 * are whitelisted character by character; a snippet is skipped otherwise.
 *
 * @package Web_Vitals_Monitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page issues service.
 */
final class Web_Vitals_Page_Issues {
	/**
	 * Option holding ticked checklist items: key => {done, by}.
	 */
	public const OPTION = 'wvm_checklist';

	/**
	 * Occurrences needed before something is reported as an issue of a page.
	 */
	private const MIN_OCCURRENCES = 3;

	/**
	 * Samples after a fix needed to call it verified.
	 */
	private const VERIFY_SAMPLES = 5;

	/**
	 * Pages analyzed when no URL is selected.
	 */
	private const MAX_PAGES = 10;

	/**
	 * Optimization plugins with page-specific instructions, by plugin directory.
	 */
	private const TOOLS = array(
		'wp-rocket'       => 'WP Rocket',
		'litespeed-cache' => 'LiteSpeed Cache',
		'perfmatters'     => 'Perfmatters',
		'wp-smushit'      => 'Smush',
		'autoptimize'     => 'Autoptimize',
		'a3-lazy-load'    => 'a3 Lazy Load',
		'w3-total-cache'  => 'W3 Total Cache',
		'flying-press'    => 'FlyingPress',
		'elementor'       => 'Elementor',
	);

	/**
	 * Database service.
	 *
	 * @var Web_Vitals_Database
	 */
	private Web_Vitals_Database $database;

	/**
	 * Advisor (for script ownership).
	 *
	 * @var Web_Vitals_Advisor
	 */
	private Web_Vitals_Advisor $advisor;

	/**
	 * Active optimization plugins (directory => name).
	 *
	 * @var array<string,string>
	 */
	private array $tools = array();

	/**
	 * Site-wide TTFB P75, for comparison.
	 *
	 * @var float|null
	 */
	private ?float $site_ttfb = null;

	/**
	 * Constructor.
	 */
	public function __construct( Web_Vitals_Database $database, Web_Vitals_Settings $settings ) {
		$this->database = $database;
		$this->advisor  = new Web_Vitals_Advisor( $database, $settings );
	}

	/**
	 * Ticked checklist items.
	 *
	 * @return array<string,array{done:int,by:string}>
	 */
	public static function checklist(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Tick or untick an item.
	 */
	public static function set_done( string $key, bool $done ): void {
		$items = self::checklist();
		if ( $done ) {
			$user          = wp_get_current_user();
			$items[ $key ] = array(
				'done' => time(),
				'by'   => $user->exists() ? $user->display_name : '',
			);
			// Keep the option small: forget the oldest ticks beyond 1000.
			if ( count( $items ) > 1000 ) {
				uasort(
					$items,
					static function ( array $a, array $b ): int {
						return $b['done'] <=> $a['done'];
					}
				);
				$items = array_slice( $items, 0, 1000, true );
			}
		} else {
			unset( $items[ $key ] );
		}
		update_option( self::OPTION, $items, false );
	}

	/**
	 * Build the checklist for one URL (filters['path']) or the worst pages.
	 *
	 * @param array<string,mixed> $filters Filters from Web_Vitals_Database::build_filters().
	 * @return array<string,mixed>
	 */
	public function report( array $filters ): array {
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$dir = dirname( (string) $plugin );
			if ( isset( self::TOOLS[ $dir ] ) ) {
				$this->tools[ $dir ] = self::TOOLS[ $dir ];
			}
		}

		$site_filters             = $filters;
		$site_filters['path']     = '';
		$site_filters['url_hash'] = '';
		foreach ( $this->database->metric_overview( $site_filters ) as $row ) {
			if ( 'TTFB' === $row['metric'] ) {
				$this->site_ttfb = $row['p75'];
			}
		}

		if ( '' !== $filters['path'] ) {
			$paths = array( $filters['path'] );
		} else {
			$paths = array();
			foreach ( $this->database->top_urls( $filters, self::MAX_PAGES ) as $row ) {
				$paths[] = $row['page_path'];
			}
		}

		$checklist = self::checklist();
		$pages     = array();

		foreach ( $paths as $path ) {
			$page_filters = $this->database->build_filters( (int) $filters['days'], (string) $filters['device'], $path );
			$samples      = $this->database->page_samples( $page_filters );
			if ( ! $samples ) {
				continue;
			}

			$issues = $this->detect( $path, $samples );
			foreach ( $issues as &$issue ) {
				$issue += $this->progress( $issue, $checklist[ $issue['key'] ] ?? null, $path, $samples );
			}
			unset( $issue );

			$pages[] = array(
				'path'    => $path,
				'samples' => count( $samples ),
				'metrics' => $this->page_metrics( $samples ),
				'issues'  => $issues,
				'done'    => count( array_filter( array_column( $issues, 'done' ) ) ),
			);
		}

		return array(
			'pages'       => $pages,
			'tools'       => array_values( $this->tools ),
			'generatedAt' => current_time( 'mysql', true ),
		);
	}

	/**
	 * Checklist state of one issue, verified against samples newer than the tick.
	 *
	 * @param array<string,mixed>                $issue Issue.
	 * @param array{done:int,by:string}|null     $tick Tick.
	 * @param array<int,array<string,mixed>>     $samples Page samples.
	 * @return array<string,mixed>
	 */
	private function progress( array $issue, ?array $tick, string $path, array $samples ): array {
		if ( null === $tick ) {
			return array(
				'done'   => false,
				'status' => 'open',
			);
		}

		$after = array_values(
			array_filter(
				$samples,
				static function ( array $row ) use ( $tick ): bool {
					return $row['time'] > $tick['done'];
				}
			)
		);

		$metric_after = count(
			array_filter(
				$after,
				static function ( array $row ) use ( $issue ): bool {
					return $row['metric'] === $issue['metric'];
				}
			)
		);

		$still = in_array( $issue['key'], array_column( $this->detect( $path, $after ), 'key' ), true );

		if ( $still ) {
			$status = 'still';
		} elseif ( $metric_after >= self::VERIFY_SAMPLES ) {
			$status = 'verified';
		} else {
			$status = 'awaiting';
		}

		return array(
			'done'         => true,
			'doneAt'       => gmdate( 'Y-m-d H:i', $tick['done'] ),
			'doneBy'       => $tick['by'],
			'status'       => $status,
			'samplesSince' => $metric_after,
		);
	}

	/**
	 * P75 per Core Web Vital for the page header.
	 *
	 * @param array<int,array<string,mixed>> $samples Samples.
	 * @return array<string,array<string,mixed>>
	 */
	private function page_metrics( array $samples ): array {
		$output = array();
		foreach ( Web_Vitals_Config::core_metrics() as $metric ) {
			$values = $this->values( $this->of( $samples, $metric ) );
			$p75    = $this->p75( $values );
			$output[ $metric ] = array(
				'p75'     => $p75,
				'status'  => null === $p75 ? 'unknown' : Web_Vitals_Config::rating( $metric, $p75 ),
				'samples' => count( $values ),
			);
		}
		return $output;
	}

	/**
	 * Detect all issues of one page.
	 *
	 * @param array<int,array<string,mixed>> $samples Page samples.
	 * @return array<int,array<string,mixed>>
	 */
	private function detect( string $path, array $samples ): array {
		$status = array();
		foreach ( Web_Vitals_Config::metrics() as $metric ) {
			$p75               = $this->p75( $this->values( $this->of( $samples, $metric ) ) );
			$status[ $metric ] = null === $p75 ? 'unknown' : Web_Vitals_Config::rating( $metric, $p75 );
		}

		$issues = array_merge(
			$this->lcp_issues( $path, $this->of( $samples, 'LCP' ), $status ),
			$this->blocking_issues( $path, $this->of( $samples, 'LCP' ), $status ),
			$this->ttfb_issues( $path, $this->of( $samples, 'TTFB' ) ),
			$this->inp_issues( $path, $this->of( $samples, 'INP' ) ),
			$this->cls_issues( $path, $this->of( $samples, 'CLS' ), $status['CLS'] )
		);

		$rank = array(
			'critical' => 0,
			'warning'  => 1,
			'info'     => 2,
		);
		usort(
			$issues,
			static function ( array $a, array $b ) use ( $rank ): int {
				return array( $rank[ $a['severity'] ], -$a['count'] ) <=> array( $rank[ $b['severity'] ], -$b['count'] );
			}
		);

		return $issues;
	}

	/**
	 * LCP element issues: lazy loading, priority, background image, size, origin, render delay.
	 *
	 * @param array<int,array<string,mixed>> $rows LCP samples.
	 * @param array<string,string>           $status Page status per metric.
	 * @return array<int,array<string,mixed>>
	 */
	private function lcp_issues( string $path, array $rows, array $status ): array {
		if ( count( $rows ) < self::MIN_OCCURRENCES ) {
			return array();
		}

		$issues   = array();
		$severity = $this->severity( $status['LCP'] );
		$page_url = $this->page_url( $path );

		// The same page can have a different LCP element on mobile and desktop.
		foreach ( $this->groups( $rows, array( 'url', 'target' ), 0.25, 2 ) as $group ) {
			$items  = $group['rows'];
			$count  = count( $items );
			$url    = $this->mode( $items, 'url' );
			$target = $this->mode( $items, 'target' );
			$kind   = $this->mode( $items, 'element' );
			$file   = $url ? wp_basename( $url ) : '';
			$label  = $file ? $file : $target;
			$lcp    = (float) $this->median_value( $items );
			$delay  = (float) $this->median( $items, 'resourceLoadDelay' );
			$load   = (float) $this->median( $items, 'resourceLoadDuration' );
			$render = (float) $this->median( $items, 'elementRenderDelay' );
			$where  = $target ? sprintf( /* translators: %s: CSS selector. */ __( 'Element: %s', 'web-vitals-monitor' ), $target ) : '';
			$base   = array_filter(
				array(
					/* translators: 1: element or file, 2: share, 3: duration. */
					sprintf( __( 'LCP element of this page: %1$s (in %2$s of visits, median LCP %3$s)', 'web-vitals-monitor' ), $label, $this->pct( $count / count( $rows ) ), $this->ms( $lcp ) ),
					$where,
				)
			);

			$lazy = $this->share( $items, 'lazy', 1 );
			if ( $lazy >= 0.2 && $file ) {
				$issues[] = $this->issue(
					$path,
					'lcp-lazy',
					$url,
					array(
						'metric'   => 'LCP',
						'severity' => 'good' === $status['LCP'] ? 'warning' : 'critical',
						'count'    => (int) round( $lazy * $count ),
						/* translators: %s: file name. */
						'title'    => sprintf( __( 'Remove lazy loading from the main image "%s"', 'web-vitals-monitor' ), $file ),
						'evidence' => array_merge( $base, array( sprintf( /* translators: %s: share. */ __( 'In %s of visits this image had loading="lazy", so the browser started downloading it only after layout.', 'web-vitals-monitor' ), $this->pct( $lazy ) ) ) ),
						'steps'    => array_merge(
							$this->tool_steps( 'lazy', $file ),
							array(
								/* translators: %s: file name. */
								sprintf( __( 'If the image is printed by the theme or a page builder, remove loading="lazy" from the <img> of "%s" there, or add the snippet below to the child theme\'s functions.php.', 'web-vitals-monitor' ), $file ),
								__( 'Purge the page cache, open this page and check in DevTools (Elements) that the image no longer has loading="lazy".', 'web-vitals-monitor' ),
							)
						),
						'snippet'  => $this->snippet( self::SNIPPET_PRIORITY, array( '{FILE}' => $this->safe_token( $file ) ) ),
						'lang'     => 'php',
						'docs'     => 'https://web.dev/articles/lcp-lazy-loading',
					)
				);
			}

			if ( 'bg-image' === $kind && $file && 'good' !== $status['LCP'] ) {
				$steps = array(
					/* translators: %s: file name. */
					sprintf( __( 'Best fix: replace the section background with an Image element that shows "%s", with width, height and fetchpriority="high".', 'web-vitals-monitor' ), $file ),
					__( 'If it has to stay a background, preload it on this page only with the snippet below.', 'web-vitals-monitor' ),
				);
				if ( isset( $this->tools['elementor'] ) ) {
					$steps[] = __( 'Elementor: under Settings → Features, "Lazy Load Background Images" delays backgrounds; exclude this section or turn the feature off.', 'web-vitals-monitor' );
				}
				$issues[] = $this->issue(
					$path,
					'lcp-bg',
					$url,
					array(
						'metric'   => 'LCP',
						'severity' => $severity,
						'count'    => $count,
						/* translators: %s: file name. */
						'title'    => sprintf( __( 'The main image "%s" is a CSS background and is discovered late', 'web-vitals-monitor' ), $file ),
						'evidence' => array_merge( $base, array( sprintf( /* translators: %s: duration. */ __( 'The browser waits %s before it even starts downloading it (load delay).', 'web-vitals-monitor' ), $this->ms( $delay ) ) ) ),
						'steps'    => $steps,
						'snippet'  => $this->snippet(
							self::SNIPPET_PRELOAD,
							array(
								'{PATH}' => $this->safe_path( $path ),
								'{URL}'  => $this->safe_url( $url ),
							)
						),
						'lang'     => 'php',
						'docs'     => 'https://web.dev/articles/optimize-lcp',
					)
				);
			}

			$priority = $this->share( $items, 'fetchPriority', 'high' );
			if ( 'img' === $kind && $file && $lazy < 0.2 && $priority < 0.5 && 'good' !== $status['LCP'] && $delay > max( 100, 0.1 * $lcp ) ) {
				$issues[] = $this->issue(
					$path,
					'lcp-priority',
					$url,
					array(
						'metric'   => 'LCP',
						'severity' => $severity,
						'count'    => $count,
						/* translators: %s: file name. */
						'title'    => sprintf( __( 'Give the main image "%s" high fetch priority', 'web-vitals-monitor' ), $file ),
						'evidence' => array_merge( $base, array( sprintf( /* translators: 1: duration, 2: share. */ __( 'Download starts %1$s after the HTML arrives; %2$s of visits loaded it without fetchpriority="high".', 'web-vitals-monitor' ), $this->ms( $delay ), $this->pct( 1 - $priority ) ) ) ),
						'steps'    => array(
							/* translators: %s: file name. */
							sprintf( __( 'Add fetchpriority="high" to the <img> of "%s" (only this image on this page).', 'web-vitals-monitor' ), $file ),
							__( 'If the image is inserted with WordPress functions (featured image, content image), the snippet below does it automatically.', 'web-vitals-monitor' ),
							__( 'If a slider or animation shows it with JavaScript, make this first slide plain HTML so the browser finds it in the initial HTML.', 'web-vitals-monitor' ),
						),
						'snippet'  => $this->snippet( self::SNIPPET_PRIORITY, array( '{FILE}' => $this->safe_token( $file ) ) ),
						'lang'     => 'php',
						'docs'     => 'https://web.dev/articles/fetch-priority',
					)
				);
			}

			$natural = $this->median( $items, 'natW' );
			$display = $this->median( $items, 'dispW' );
			if ( $file && $natural && $display && $natural >= 1.5 * $display && $natural - $display >= 300 ) {
				$issues[] = $this->issue(
					$path,
					'lcp-oversized',
					$url,
					array(
						'metric'   => 'LCP',
						'severity' => 'good' === $status['LCP'] ? 'info' : $severity,
						'count'    => $count,
						/* translators: 1: file name, 2: pixels, 3: pixels. */
						'title'    => sprintf( __( 'The image "%1$s" is %2$dpx wide but displayed at about %3$dpx', 'web-vitals-monitor' ), $file, (int) $natural, (int) $display ),
						'evidence' => array_merge( $base, array( sprintf( /* translators: %s: multiplier. */ __( 'Visitors download about %sx more pixels than the screen shows (rendered width already includes screen density).', 'web-vitals-monitor' ), number_format_i18n( ( $natural / $display ) ** 2, 1 ) ) ) ),
						'steps'    => array(
							/* translators: %d: pixels. */
							sprintf( __( 'Upload or crop a version about %dpx wide and use it in this spot.', 'web-vitals-monitor' ), (int) ( ceil( $display / 100 ) * 100 ) ),
							__( 'If the image is inserted with WordPress, make sure the <img> has srcset and a correct sizes attribute (e.g. sizes="100vw" for a full-width hero) so phones pick a smaller file.', 'web-vitals-monitor' ),
							__( 'In a page builder, choose a smaller "Image size" (e.g. Large instead of Full) for this widget.', 'web-vitals-monitor' ),
						),
						'docs'     => 'https://web.dev/articles/serve-responsive-images',
					)
				);
			}

			$bytes = $this->median( $items, 'bytes' );
			if ( $file && $bytes && $bytes >= 150000 && ( 'good' !== $status['LCP'] || $bytes >= 400000 ) ) {
				$ext   = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
				$steps = array();
				if ( in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
					/* translators: %s: file name. */
					$steps[] = sprintf( __( 'Convert "%s" to WebP or AVIF; for photos this usually cuts the size by 30-50%%.', 'web-vitals-monitor' ), $file );
				}
				$steps[] = __( 'Compress it (quality 70-80 is visually lossless for photos) with an image optimization plugin or before uploading.', 'web-vitals-monitor' );
				$steps[] = __( 'For a hero image, aim for under 100-150 KB on mobile.', 'web-vitals-monitor' );
				$issues[] = $this->issue(
					$path,
					'lcp-heavy',
					$url,
					array(
						'metric'   => 'LCP',
						'severity' => 'good' === $status['LCP'] ? 'info' : $severity,
						'count'    => $count,
						/* translators: 1: file name, 2: size. */
						'title'    => sprintf( __( 'The main image "%1$s" weighs %2$s', 'web-vitals-monitor' ), $file, size_format( (int) $bytes ) ),
						'evidence' => array_merge( $base, array( sprintf( /* translators: %s: duration. */ __( 'Downloading it takes %s (median).', 'web-vitals-monitor' ), $this->ms( $load ) ) ) ),
						'steps'    => $steps,
						'docs'     => 'https://web.dev/articles/choose-the-right-image-format',
					)
				);
			}

			$host = $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '';
			if ( $host && $this->share( $items, 'crossOrigin', 1 ) >= 0.5 && 'good' !== $status['LCP'] && $delay + $load > 0.3 * $lcp ) {
				$issues[] = $this->issue(
					$path,
					'lcp-origin',
					$host,
					array(
						'metric'   => 'LCP',
						'severity' => 'info' === $severity ? 'info' : 'warning',
						'count'    => $count,
						/* translators: %s: host name. */
						'title'    => sprintf( __( 'The main image comes from another domain (%s)', 'web-vitals-monitor' ), $host ),
						'evidence' => array_merge( $base, array( __( 'The browser must open a new connection (DNS, TCP, TLS) before downloading it.', 'web-vitals-monitor' ) ) ),
						'steps'    => array(
							/* translators: %s: host name. */
							sprintf( __( 'Preconnect to %s early with the snippet below, or serve the image from your own domain.', 'web-vitals-monitor' ), $host ),
						),
						'snippet'  => $this->snippet( self::SNIPPET_PRECONNECT, array( '{HOST}' => preg_match( '/^[a-z0-9.-]+$/i', $host ) ? $host : '' ) ),
						'lang'     => 'php',
						'docs'     => 'https://web.dev/articles/preconnect-and-dns-prefetch',
					)
				);
			}

			if ( 'good' !== $status['LCP'] && $render > max( 200, 0.15 * $lcp ) ) {
				$text  = 'text' === $kind;
				$ident = $target ? $target : $url;
				$issues[] = $this->issue(
					$path,
					$text ? 'lcp-font' : 'lcp-render',
					$ident,
					array(
						'metric'   => 'LCP',
						'severity' => $severity,
						'count'    => $count,
						'title'    => $text
							/* translators: %s: CSS selector. */
							? sprintf( __( 'The main text "%s" appears late (web font or render-blocking files)', 'web-vitals-monitor' ), $target )
							/* translators: %s: element or file. */
							: sprintf( __( '"%s" is downloaded but shown late', 'web-vitals-monitor' ), $label ),
						'evidence' => array_merge( $base, array( sprintf( /* translators: %s: duration. */ __( 'After everything it needs is ready, the browser still waits %s before painting it (render delay).', 'web-vitals-monitor' ), $this->ms( $render ) ) ) ),
						'steps'    => $text
							? array(
								__( 'Use font-display: swap (or optional) in the @font-face of the font used by this element, so text is shown in a fallback font immediately.', 'web-vitals-monitor' ),
								__( 'Preload the font file used by this heading/paragraph (woff2 only).', 'web-vitals-monitor' ),
								__( 'Fix the render-blocking files listed for this page in this checklist.', 'web-vitals-monitor' ),
							)
							: array(
								/* translators: %s: CSS selector. */
								sprintf( __( 'Check whether "%s" starts hidden (opacity: 0, an entrance animation, a slider that initializes with JavaScript). Remove the entrance animation from this element.', 'web-vitals-monitor' ), $target ? $target : $label ),
								__( 'Fix the render-blocking files listed for this page in this checklist.', 'web-vitals-monitor' ),
							),
						'snippet'  => $text ? "@font-face {\n\tfont-family: \"YourFont\";\n\tsrc: url(\"/path/to/font.woff2\") format(\"woff2\");\n\tfont-display: swap;\n}" : '',
						'lang'     => $text ? 'css' : '',
						'docs'     => $text ? 'https://web.dev/articles/font-best-practices' : 'https://web.dev/articles/optimize-lcp',
					)
				);
			}
		}

		return $issues;
	}

	/**
	 * Render-blocking files seen on this page (from the LCP report).
	 *
	 * @param array<int,array<string,mixed>> $rows LCP samples.
	 * @param array<string,string>           $status Page status per metric.
	 * @return array<int,array<string,mixed>>
	 */
	private function blocking_issues( string $path, array $rows, array $status ): array {
		if ( 'good' === $status['LCP'] && in_array( $status['FCP'], array( 'good', 'unknown' ), true ) ) {
			return array();
		}

		$files = array();
		$with  = 0;
		foreach ( $rows as $row ) {
			$list = (string) ( $row['a']['blocking'] ?? '' );
			if ( '' === $list ) {
				continue;
			}
			++$with;
			foreach ( explode( ';', $list ) as $item ) {
				$parts                = explode( '|', $item );
				$files[ $parts[0] ][] = (float) ( $parts[1] ?? 0 );
			}
		}

		if ( $with < self::MIN_OCCURRENCES ) {
			return array();
		}

		$candidates = array();
		foreach ( $files as $url => $durations ) {
			sort( $durations );
			$median = $durations[ (int) floor( ( count( $durations ) - 1 ) / 2 ) ];
			if ( count( $durations ) / $with >= 0.3 && $median >= 80 ) {
				$candidates[ $url ] = array( $median, count( $durations ) );
			}
		}
		uasort(
			$candidates,
			static function ( array $a, array $b ): int {
				return $b[0] <=> $a[0];
			}
		);

		$severity = $this->severity( 'poor' === $status['LCP'] || 'poor' === $status['FCP'] ? 'poor' : 'needs-improvement' );
		$issues   = array();

		foreach ( array_slice( $candidates, 0, 3, true ) as $url => $data ) {
			$file  = wp_basename( (string) $url );
			$owner = $this->advisor->script_owner( (string) $url );
			$css   = str_ends_with( strtolower( $file ), '.css' );
			$steps = array(
				/* translators: %s: owner such as "Plugin: Elementor". */
				sprintf( __( 'This file belongs to %s.', 'web-vitals-monitor' ), $owner ),
			);
			if ( $css ) {
				$steps   = array_merge( $steps, $this->tool_steps( 'css', $file ) );
				$steps[] = __( 'If this stylesheet is not needed for the top of this page, load it asynchronously with the snippet below (keep it blocking if the header/hero depends on it, or you will cause layout shifts).', 'web-vitals-monitor' );
				$steps[] = __( 'If its plugin is not used on this page at all, unload it here with an asset manager (Perfmatters Script Manager, Asset CleanUp).', 'web-vitals-monitor' );
			} else {
				$steps   = array_merge( $steps, $this->tool_steps( 'defer_js', $file ) );
				$steps[] = __( 'Or add defer to this one script with the snippet below, then test that its feature still works.', 'web-vitals-monitor' );
				$steps[] = __( 'If its plugin is not used on this page at all, unload it here with an asset manager (Perfmatters Script Manager, Asset CleanUp).', 'web-vitals-monitor' );
			}

			$issues[] = $this->issue(
				$path,
				'blocking',
				(string) $url,
				array(
					'metric'   => 'LCP',
					'severity' => $severity,
					'count'    => $data[1],
					'title'    => $css
						/* translators: %s: file name. */
						? sprintf( __( 'Stylesheet "%s" blocks the first paint of this page', 'web-vitals-monitor' ), $file )
						/* translators: %s: file name. */
						: sprintf( __( 'Script "%s" blocks the first paint of this page', 'web-vitals-monitor' ), $file ),
					'evidence' => array(
						/* translators: 1: URL, 2: duration, 3: share. */
						sprintf( __( '%1$s takes %2$s to load and blocks rendering in %3$s of measured visits.', 'web-vitals-monitor' ), $url, $this->ms( $data[0] ), $this->pct( $data[1] / $with ) ),
					),
					'steps'    => $steps,
					'snippet'  => $this->snippet( $css ? self::SNIPPET_ASYNC_CSS : self::SNIPPET_DEFER, array( '{FILE}' => $this->safe_token( $file ) ) ),
					'lang'     => 'php',
					'docs'     => 'https://web.dev/articles/render-blocking-resources',
				)
			);
		}

		return $issues;
	}

	/**
	 * Slow server response for this page.
	 *
	 * @param array<int,array<string,mixed>> $rows TTFB samples.
	 * @return array<int,array<string,mixed>>
	 */
	private function ttfb_issues( string $path, array $rows ): array {
		$p75 = $this->p75( $this->values( $rows ) );
		if ( count( $rows ) < self::MIN_OCCURRENCES || null === $p75 || $p75 <= 800 ) {
			return array();
		}

		$evidence = array(
			/* translators: %s: duration. */
			sprintf( __( 'TTFB of this page (P75): %s; good is 800 ms or less.', 'web-vitals-monitor' ), $this->ms( $p75 ) ),
		);
		$steps    = array();

		if ( $this->site_ttfb && $p75 > 2 * $this->site_ttfb ) {
			/* translators: %s: duration. */
			$evidence[] = sprintf( __( 'The rest of the site responds in %s, so this page specifically is slow: it is probably not served from the page cache.', 'web-vitals-monitor' ), $this->ms( $this->site_ttfb ) );
			$steps[]    = __( 'Check whether this URL is excluded from the page cache (cache plugin exclusions, WooCommerce cart/checkout/account pages, pages with forms or nonces).', 'web-vitals-monitor' );
			$steps[]    = __( 'Run the command below twice: if the second response is still slow or has no cache HIT header, the page is not cached.', 'web-vitals-monitor' );
			$steps[]    = __( 'Open the page with Query Monitor active (logged in) to find slow database queries or HTTP API calls on this template.', 'web-vitals-monitor' );
		} else {
			$steps[] = __( 'The whole site responds slowly; see the "Reduce server response time" site-wide suggestion (page cache, object cache, PHP version, CDN).', 'web-vitals-monitor' );
			$steps[] = __( 'Run the command below to measure this page from the server side.', 'web-vitals-monitor' );
		}

		$request = $this->median( $rows, 'requestDuration' );
		if ( $request ) {
			/* translators: %s: duration. */
			$evidence[] = sprintf( __( 'Of this, the server takes about %s to generate the page.', 'web-vitals-monitor' ), $this->ms( $request ) );
		}


		return array(
			$this->issue(
				$path,
				'ttfb',
				$path,
				array(
					'metric'   => 'TTFB',
					'severity' => $this->severity( Web_Vitals_Config::rating( 'TTFB', $p75 ) ),
					'count'    => count( $rows ),
					'title'    => __( 'This page responds slowly from the server', 'web-vitals-monitor' ),
					'evidence' => $evidence,
					'steps'    => $steps,
					'snippet'  => $this->snippet( self::SNIPPET_CURL, array( '{URL}' => $this->safe_url( $this->page_url( $path ) ) ) ),
					'lang'     => 'bash',
					'docs'     => 'https://web.dev/articles/optimize-ttfb',
				)
			),
		);
	}

	/**
	 * Slow interactions, one issue per element visitors interact with.
	 *
	 * @param array<int,array<string,mixed>> $rows INP samples.
	 * @return array<int,array<string,mixed>>
	 */
	private function inp_issues( string $path, array $rows ): array {
		$issues = array();
		$groups = array();
		foreach ( $rows as $row ) {
			$target = (string) ( $row['a']['interactionTarget'] ?? '' );
			if ( '' !== $target ) {
				$groups[ $target ][] = $row;
			}
		}

		$ranked = array();
		foreach ( $groups as $target => $items ) {
			$p75 = $this->p75( $this->values( $items ) );
			if ( count( $items ) >= self::MIN_OCCURRENCES && $p75 > 200 ) {
				$ranked[ $target ] = array( $p75 * count( $items ), $p75, $items );
			}
		}
		uasort(
			$ranked,
			static function ( array $a, array $b ): int {
				return $b[0] <=> $a[0];
			}
		);

		foreach ( array_slice( $ranked, 0, 3, true ) as $target => $data ) {
			list( , $p75, $items ) = $data;
			$target                = (string) $target;
			$phases                = array(
				'input'        => (float) $this->median( $items, 'inputDelay' ),
				'processing'   => (float) $this->median( $items, 'processingDuration' ),
				'presentation' => (float) $this->median( $items, 'presentationDelay' ),
			);
			arsort( $phases );
			$dominant = (string) array_key_first( $phases );
			$type     = $this->mode( $items, 'interactionType' );
			$source   = $this->mode( $items, 'scriptSource' );
			$invoker  = $this->mode( $items, 'scriptInvoker' );
			$owner    = '' !== $source ? $this->advisor->script_owner( $source ) : '';
			$loading  = $this->share( $items, 'loadState', 'loading' ) + $this->share( $items, 'loadState', 'dom-interactive' );

			$evidence = array(
				/* translators: 1: count, 2: duration. */
				sprintf( __( '%1$d slow interactions with this element; P75 response time %2$s (good is 200 ms or less).', 'web-vitals-monitor' ), count( $items ), $this->ms( $p75 ) ),
				/* translators: 1-3: durations. */
				sprintf( __( 'Waiting before the handler starts: %1$s · handler code: %2$s · drawing the result: %3$s.', 'web-vitals-monitor' ), $this->ms( $phases['input'] ), $this->ms( $phases['processing'] ), $this->ms( $phases['presentation'] ) ),
			);
			if ( $owner ) {
				/* translators: 1: owner, 2: file name, 3: function or handler. */
				$evidence[] = sprintf( __( 'Longest script during these interactions: %1$s — %2$s %3$s', 'web-vitals-monitor' ), $owner, wp_basename( $source ), $invoker ? '(' . $invoker . ')' : '' );
			}

			$steps   = array();
			$snippet = '';
			if ( 'input' === $dominant ) {
				if ( $loading >= 0.4 ) {
					/* translators: %s: share. */
					$evidence[] = sprintf( __( '%s of these interactions happened while the page was still loading.', 'web-vitals-monitor' ), $this->pct( $loading ) );
				}
				$steps[] = __( 'The click is not slow by itself: other scripts keep the main thread busy, so the click waits. Reduce the JavaScript that runs while this page loads.', 'web-vitals-monitor' );
				$steps   = array_merge( $steps, $this->tool_steps( 'delay_js', '' ) );
				if ( $owner ) {
					/* translators: %s: owner. */
					$steps[] = sprintf( __( 'Start with %s: it was running when the click arrived. Unload or delay it on this page if it is not needed immediately.', 'web-vitals-monitor' ), $owner );
				}
			} elseif ( 'processing' === $dominant ) {
				if ( $owner && $this->is_third_party( $source ) ) {
					/* translators: %s: owner. */
					$steps[] = sprintf( __( 'The slow handler is third-party code (%s). Load it later, or replace it with a lighter alternative.', 'web-vitals-monitor' ), $owner );
				} elseif ( $owner ) {
					/* translators: 1: owner, 2: file name. */
					$steps[] = sprintf( __( 'The slow handler is in %1$s (%2$s). Update it; if it persists, report this slow interaction to its developer with the element name above.', 'web-vitals-monitor' ), $owner, wp_basename( $source ) );
				}
				/* translators: 1: CSS selector, 2: duration. */
				$steps[] = sprintf( __( 'If the handler of "%1$s" is your own code: it runs for %2$s. Show the visual change first, then yield to the browser before the heavy work (snippet below).', 'web-vitals-monitor' ), $target, $this->ms( $phases['processing'] ) );
				$steps[] = __( 'Do not read layout (offsetHeight, getBoundingClientRect) right after changing styles in this handler.', 'web-vitals-monitor' );
				$snippet = strtr( self::SNIPPET_YIELD, array( '{EVENT}' => 'keyboard' === $type ? 'keydown' : 'click' ) );
			} else {
				/* translators: 1: CSS selector, 2: duration. */
				$steps[] = sprintf( __( 'After "%1$s" is used, the page needs %2$s to draw the result: the update changes too much of the page at once.', 'web-vitals-monitor' ), $target, $this->ms( $phases['presentation'] ) );
				$steps[] = __( 'Update only the part of the page that changes instead of re-rendering a large block (menus, filters, mini cart).', 'web-vitals-monitor' );
				$steps[] = __( 'Reduce the DOM size of this page: remove hidden duplicate sections and nested wrappers created by the page builder.', 'web-vitals-monitor' );
				$steps[] = __( 'Add content-visibility: auto to long sections below the fold of this page.', 'web-vitals-monitor' );
			}

			$issues[] = $this->issue(
				$path,
				'inp',
				$target,
				array(
					'metric'   => 'INP',
					'severity' => $this->severity( Web_Vitals_Config::rating( 'INP', $p75 ) ),
					'count'    => count( $items ),
					/* translators: 1: CSS selector, 2: duration. */
					'title'    => sprintf( __( 'Interacting with "%1$s" responds in %2$s', 'web-vitals-monitor' ), $target, $this->ms( $p75 ) ),
					'evidence' => $evidence,
					'steps'    => $steps,
					'snippet'  => $snippet,
					'lang'     => '' === $snippet ? '' : 'js',
					'docs'     => 'https://web.dev/articles/optimize-inp',
				)
			);
		}

		return $issues;
	}

	/**
	 * Layout shifts, one issue per element that shifts.
	 *
	 * @param array<int,array<string,mixed>> $rows CLS samples.
	 * @return array<int,array<string,mixed>>
	 */
	private function cls_issues( string $path, array $rows, string $status ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			$target = (string) ( $row['a']['largestShiftTarget'] ?? '' );
			if ( '' !== $target && $row['value'] >= 0.01 ) {
				$groups[ $target ][] = $row;
			}
		}

		$ranked = array();
		foreach ( $groups as $target => $items ) {
			$p75 = $this->p75( $this->values( $items ) );
			if ( count( $items ) >= self::MIN_OCCURRENCES && ( $p75 > 0.1 || ( 'good' !== $status && count( $items ) / max( 1, count( $rows ) ) >= 0.3 ) ) ) {
				$ranked[ $target ] = array( $p75 * count( $items ), $p75, $items );
			}
		}
		uasort(
			$ranked,
			static function ( array $a, array $b ): int {
				return $b[0] <=> $a[0];
			}
		);

		$issues = array();
		foreach ( array_slice( $ranked, 0, 3, true ) as $target => $data ) {
			list( , $p75, $items ) = $data;
			$target                = (string) $target;
			$tag                   = $this->mode( $items, 'shiftTag' );
			$src                   = $this->mode( $items, 'shiftSrc' );
			$height                = $this->median( $items, 'shiftH' );
			$early                 = $this->share( $items, 'loadState', 'loading' ) + $this->share( $items, 'loadState', 'dom-interactive' );
			$no_dims               = $this->share( $items, 'shiftNoDims', 1 );
			$selector              = $this->safe_selector( $target );

			$evidence = array(
				/* translators: 1: count, 2: score. */
				sprintf( __( 'This element caused the largest shift in %1$d visits; P75 shift score %2$s (good is 0.1 or less).', 'web-vitals-monitor' ), count( $items ), number_format_i18n( (float) $p75, 3 ) ),
				$early >= 0.5
					/* translators: %s: share. */
					? sprintf( __( 'It moves while the page is loading (%s of cases).', 'web-vitals-monitor' ), $this->pct( $early ) )
					/* translators: %s: share. */
					: sprintf( __( 'It moves after the page has loaded (%s of cases): something is inserted or resized later.', 'web-vitals-monitor' ), $this->pct( 1 - $early ) ),
			);

			$snippet = '';
			$lang    = '';
			if ( $no_dims >= 0.5 ) {
				$name    = $src ? wp_basename( $src ) : $tag;
				/* translators: 1: tag, 2: file name. */
				$title   = sprintf( __( 'Add width and height to the <%1$s> "%2$s"', 'web-vitals-monitor' ), $tag, $name );
				$steps   = array(
					/* translators: 1: tag, 2: file name. */
					sprintf( __( 'Add width and height attributes to this <%1$s> ("%2$s") with its real proportions; CSS can still make it responsive.', 'web-vitals-monitor' ), $tag, $name ),
					__( 'If you cannot edit its HTML (widget, embed, ad), reserve its space with the CSS below.', 'web-vitals-monitor' ),
				);
				if ( $selector && $height ) {
					$snippet = $selector . " {\n\tmin-height: " . (int) $height . "px; /* or aspect-ratio: width / height */\n}";
					$lang    = 'css';
				}
			} elseif ( $early >= 0.5 && in_array( $tag, array( 'p', 'h1', 'h2', 'h3', 'h4', 'span', 'a', 'li', 'button' ), true ) ) {
				/* translators: %s: CSS selector. */
				$title   = sprintf( __( 'Text in "%s" jumps when the web font loads', 'web-vitals-monitor' ), $target );
				$steps   = array(
					__( 'Use font-display: optional for the font of this text, or make the fallback font the same size with size-adjust so swapping does not move it.', 'web-vitals-monitor' ),
					__( 'Preload the woff2 file of this font so it is ready before the first paint.', 'web-vitals-monitor' ),
				);
				$snippet = "@font-face {\n\tfont-family: \"YourFont Fallback\";\n\tsrc: local(\"Arial\");\n\tsize-adjust: 105%; /* tune until the text no longer moves */\n}";
				$lang    = 'css';
			} else {
				/* translators: %s: CSS selector. */
				$title = sprintf( __( 'Reserve space for "%s"', 'web-vitals-monitor' ), $target );
				$steps = array(
					$early >= 0.5
						/* translators: %s: CSS selector. */
						? sprintf( __( '"%s" gets its size only after CSS or JavaScript loads. Give it its final height from the start with the CSS below.', 'web-vitals-monitor' ), $target )
						/* translators: %s: CSS selector. */
						: sprintf( __( 'Content is inserted into or above "%s" after load (banner, ad, notice, AJAX block). Reserve its final height with the CSS below.', 'web-vitals-monitor' ), $target ),
					__( 'If it is a notice or banner, show it as an overlay (position: fixed) instead of pushing the page down.', 'web-vitals-monitor' ),
				);
				if ( $selector && $height ) {
					$snippet = $selector . " {\n\tmin-height: " . (int) $height . "px;\n}";
					$lang    = 'css';
				}
			}

			if ( $height ) {
				/* translators: %d: pixels. */
				$evidence[] = sprintf( __( 'Its final height is about %dpx.', 'web-vitals-monitor' ), (int) $height );
			}

			$issues[] = $this->issue(
				$path,
				'cls',
				$target,
				array(
					'metric'   => 'CLS',
					'severity' => $this->severity( Web_Vitals_Config::rating( 'CLS', (float) $p75 ) ),
					'count'    => count( $items ),
					'title'    => $title,
					'evidence' => $evidence,
					'steps'    => $steps,
					'snippet'  => $snippet,
					'lang'     => $lang,
					'docs'     => 'https://web.dev/articles/optimize-cls',
				)
			);
		}

		return $issues;
	}

	/**
	 * Instructions for the optimization plugins active on this site.
	 *
	 * @param string $action lazy|css|defer_js|delay_js.
	 * @param string $file File name the instruction is about.
	 * @return string[]
	 */
	private function tool_steps( string $action, string $file ): array {
		$steps = array();
		foreach ( array_keys( $this->tools ) as $tool ) {
			$step = match ( $tool . ':' . $action ) {
				/* translators: %s: file name. */
				'wp-rocket:lazy'         => sprintf( __( 'WP Rocket: Settings → Media → LazyLoad → add "%s" to "Excluded images or iframes".', 'web-vitals-monitor' ), $file ),
				'wp-rocket:css'          => __( 'WP Rocket: Settings → File Optimization → enable "Remove Unused CSS".', 'web-vitals-monitor' ),
				/* translators: %s: file name. */
				'wp-rocket:defer_js'     => sprintf( __( 'WP Rocket: Settings → File Optimization → enable "Load JavaScript deferred" (make sure "%s" is not in its exclusions).', 'web-vitals-monitor' ), $file ),
				'wp-rocket:delay_js'     => __( 'WP Rocket: Settings → File Optimization → enable "Delay JavaScript execution".', 'web-vitals-monitor' ),
				/* translators: %s: file name. */
				'litespeed-cache:lazy'   => sprintf( __( 'LiteSpeed Cache: Page Optimization → Media Settings → add "%s" to "Lazy Load Image Excludes".', 'web-vitals-monitor' ), $file ),
				'litespeed-cache:css'    => __( 'LiteSpeed Cache: Page Optimization → CSS Settings → enable "Generate UCSS" and "Load CSS Asynchronously".', 'web-vitals-monitor' ),
				'litespeed-cache:defer_js' => __( 'LiteSpeed Cache: Page Optimization → JS Settings → set "Load JS Deferred" to "Deferred".', 'web-vitals-monitor' ),
				'litespeed-cache:delay_js' => __( 'LiteSpeed Cache: Page Optimization → JS Settings → set "Load JS Deferred" to "Delayed".', 'web-vitals-monitor' ),
				/* translators: %s: file name. */
				'perfmatters:lazy'       => sprintf( __( 'Perfmatters: Lazy Loading → add "%s" to "Exclude from Lazy Loading" (or raise "Exclude Leading Images").', 'web-vitals-monitor' ), $file ),
				'perfmatters:css'        => __( 'Perfmatters: Assets → CSS → enable "Remove Unused CSS".', 'web-vitals-monitor' ),
				'perfmatters:defer_js'   => __( 'Perfmatters: Assets → JavaScript → enable "Defer JavaScript".', 'web-vitals-monitor' ),
				'perfmatters:delay_js'   => __( 'Perfmatters: Assets → JavaScript → enable "Delay JavaScript".', 'web-vitals-monitor' ),
				/* translators: %s: file name. */
				'wp-smushit:lazy'        => sprintf( __( 'Smush: Lazy Load → Exclusions → add "%s".', 'web-vitals-monitor' ), $file ),
				/* translators: %s: file name. */
				'autoptimize:lazy'       => sprintf( __( 'Autoptimize: Images → add "%s" to the lazy-load exclusions.', 'web-vitals-monitor' ), $file ),
				'autoptimize:css'        => __( 'Autoptimize: JS, CSS & HTML → enable "Eliminate render-blocking CSS".', 'web-vitals-monitor' ),
				'autoptimize:defer_js'   => __( 'Autoptimize: JS, CSS & HTML → enable "Optimize JavaScript Code" with "Do not aggregate but defer".', 'web-vitals-monitor' ),
				/* translators: %s: file name. */
				'a3-lazy-load:lazy'      => sprintf( __( 'a3 Lazy Load: Settings → add the class or file name "%s" to the exclusions.', 'web-vitals-monitor' ), $file ),
				/* translators: %s: file name. */
				'w3-total-cache:lazy'    => sprintf( __( 'W3 Total Cache: Performance → User Experience → add "%s" to the lazy load "Exclude words".', 'web-vitals-monitor' ), $file ),
				/* translators: %s: file name. */
				'flying-press:lazy'      => sprintf( __( 'FlyingPress: Images → raise "Exclude above fold images" or exclude "%s".', 'web-vitals-monitor' ), $file ),
				'flying-press:css'       => __( 'FlyingPress: CSS → enable "Remove unused CSS".', 'web-vitals-monitor' ),
				'flying-press:defer_js'  => __( 'FlyingPress: JavaScript → enable "Defer JavaScript".', 'web-vitals-monitor' ),
				'flying-press:delay_js'  => __( 'FlyingPress: JavaScript → enable "Delay JavaScript".', 'web-vitals-monitor' ),
				default                  => '',
			};
			if ( '' !== $step ) {
				$steps[] = $step;
			}
		}

		return $steps;
	}

	/**
	 * Code templates. Placeholders are replaced only with whitelisted values.
	 */
	private const SNIPPET_PRIORITY = <<<'PHP'
// Child theme functions.php: main image of this page, no lazy loading, high priority.
add_filter( 'wp_get_loading_optimization_attributes', function ( $attrs, $tag, $attr ) {
	if ( 'img' === $tag && str_contains( $attr['src'] ?? '', '{FILE}' ) ) {
		unset( $attrs['loading'] );
		$attrs['fetchpriority'] = 'high';
	}
	return $attrs;
}, 10, 3 );
PHP;

	private const SNIPPET_PRELOAD = <<<'PHP'
// Child theme functions.php: preload the main image of {PATH} only.
add_action( 'wp_head', function () {
	if ( '{PATH}' === wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ) {
		echo '<link rel="preload" as="image" href="{URL}" fetchpriority="high">';
	}
}, 1 );
PHP;

	private const SNIPPET_PRECONNECT = <<<'PHP'
// Child theme functions.php: open the connection to {HOST} early.
add_filter( 'wp_resource_hints', function ( $urls, $type ) {
	if ( 'preconnect' === $type ) {
		$urls[] = array( 'href' => 'https://{HOST}', 'crossorigin' );
	}
	return $urls;
}, 10, 2 );
PHP;

	private const SNIPPET_DEFER = <<<'PHP'
// Child theme functions.php: load {FILE} with defer.
add_filter( 'script_loader_tag', function ( $tag, $handle, $src ) {
	if ( str_contains( $src, '{FILE}' ) && ! str_contains( $tag, ' defer' ) ) {
		$tag = str_replace( ' src=', ' defer src=', $tag );
	}
	return $tag;
}, 10, 3 );
PHP;

	private const SNIPPET_ASYNC_CSS = <<<'PHP'
// Child theme functions.php: load {FILE} without blocking rendering.
add_filter( 'style_loader_tag', function ( $html, $handle, $href ) {
	if ( str_contains( $href, '{FILE}' ) ) {
		$html = str_replace( "media='all'", "media='print' onload=\"this.media='all'\"", $html );
	}
	return $html;
}, 10, 3 );
PHP;

	private const SNIPPET_YIELD = <<<'JS'
element.addEventListener('{EVENT}', async () => {
	showFeedback(); // the visible change first
	await (globalThis.scheduler?.yield?.() ?? new Promise((resolve) => setTimeout(resolve, 0)));
	doHeavyWork(); // runs after the next paint
});
JS;

	private const SNIPPET_CURL = <<<'SH'
curl -s -o /dev/null -w "TTFB: %{time_starttransfer}s\n" "{URL}"
curl -sI "{URL}" | grep -iE "cache|age|x-litespeed|cf-cache-status"
SH;

	/**
	 * Fill a code template; empty when any value failed its whitelist.
	 *
	 * @param array<string,string> $values Placeholder => whitelisted value.
	 */
	private function snippet( string $template, array $values ): string {
		foreach ( $values as $value ) {
			if ( '' === $value ) {
				return '';
			}
		}
		return strtr( $template, $values );
	}

	/**
	 * Store common fields and the stable key of an issue.
	 *
	 * @param array<string,mixed> $data Issue data.
	 * @return array<string,mixed>
	 */
	private function issue( string $path, string $type, string $ident, array $data ): array {
		$issue = array_merge(
			array(
				'evidence' => array(),
				'steps'    => array(),
				'snippet'  => '',
				'lang'     => '',
				'docs'     => '',
				'count'    => 0,
			),
			$data
		);

		$issue['key']  = substr( md5( $path . '|' . $type . '|' . $ident ), 0, 16 );
		$issue['type'] = $type;
		if ( '' === $issue['snippet'] ) {
			$issue['lang'] = '';
		}

		return $issue;
	}

	/**
	 * Groups of samples by the first non-empty attribution field, largest first.
	 *
	 * @param array<int,array<string,mixed>> $rows Samples.
	 * @param string[]                       $keys Fields to group by, in order of preference.
	 * @return array<int,array{rows:array<int,array<string,mixed>>}>
	 */
	private function groups( array $rows, array $keys, float $min_share, int $limit ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			$id = '';
			foreach ( $keys as $key ) {
				if ( ! empty( $row['a'][ $key ] ) && is_string( $row['a'][ $key ] ) ) {
					$id = $row['a'][ $key ];
					break;
				}
			}
			if ( '' !== $id ) {
				$groups[ $id ][] = $row;
			}
		}

		uasort(
			$groups,
			static function ( array $a, array $b ): int {
				return count( $b ) <=> count( $a );
			}
		);

		$output = array();
		foreach ( $groups as $items ) {
			if ( count( $items ) >= self::MIN_OCCURRENCES && count( $items ) / count( $rows ) >= $min_share ) {
				$output[] = array( 'rows' => $items );
			}
		}

		return array_slice( $output, 0, $limit );
	}

	/**
	 * Samples of one metric.
	 *
	 * @param array<int,array<string,mixed>> $samples Samples.
	 * @return array<int,array<string,mixed>>
	 */
	private function of( array $samples, string $metric ): array {
		return array_values(
			array_filter(
				$samples,
				static function ( array $row ) use ( $metric ): bool {
					return $row['metric'] === $metric;
				}
			)
		);
	}

	/**
	 * Metric values of samples.
	 *
	 * @param array<int,array<string,mixed>> $rows Samples.
	 * @return float[]
	 */
	private function values( array $rows ): array {
		return array_map( 'floatval', array_column( $rows, 'value' ) );
	}

	/**
	 * Nearest-rank 75th percentile.
	 *
	 * @param float[] $values Values.
	 */
	private function p75( array $values ): ?float {
		if ( ! $values ) {
			return null;
		}
		sort( $values );
		return $values[ max( 0, (int) ceil( 0.75 * count( $values ) ) - 1 ) ];
	}

	/**
	 * Median metric value of samples.
	 *
	 * @param array<int,array<string,mixed>> $rows Samples.
	 */
	private function median_value( array $rows ): ?float {
		$values = $this->values( $rows );
		if ( ! $values ) {
			return null;
		}
		sort( $values );
		return $values[ (int) floor( ( count( $values ) - 1 ) / 2 ) ];
	}

	/**
	 * Median of a numeric attribution field.
	 *
	 * @param array<int,array<string,mixed>> $rows Samples.
	 */
	private function median( array $rows, string $key ): ?float {
		$values = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['a'][ $key ] ) && is_numeric( $row['a'][ $key ] ) ) {
				$values[] = (float) $row['a'][ $key ];
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
	 * @param array<int,array<string,mixed>> $rows Samples.
	 * @param mixed                          $value Value.
	 */
	private function share( array $rows, string $key, mixed $value ): float {
		if ( ! $rows ) {
			return 0.0;
		}
		$hits = 0;
		foreach ( $rows as $row ) {
			if ( isset( $row['a'][ $key ] ) && $row['a'][ $key ] == $value ) { // phpcs:ignore Universal.Operators.StrictComparisons -- 1 vs 1.0.
				++$hits;
			}
		}
		return $hits / count( $rows );
	}

	/**
	 * Most frequent string value of an attribution field.
	 *
	 * @param array<int,array<string,mixed>> $rows Samples.
	 */
	private function mode( array $rows, string $key ): string {
		$counts = array();
		foreach ( $rows as $row ) {
			$value = $row['a'][ $key ] ?? '';
			if ( is_string( $value ) && '' !== $value ) {
				$counts[ $value ] = ( $counts[ $value ] ?? 0 ) + 1;
			}
		}
		arsort( $counts );
		return (string) ( array_key_first( $counts ) ?? '' );
	}

	/**
	 * Map a rating to a severity.
	 */
	private function severity( string $status ): string {
		return match ( $status ) {
			'poor'              => 'critical',
			'needs-improvement' => 'warning',
			default             => 'info',
		};
	}

	/**
	 * Whether a script URL is served from another domain than the site.
	 */
	private function is_third_party( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return '' !== $host && $host !== $site && ! str_ends_with( $host, '.' . $site );
	}

	/**
	 * Absolute URL of a page path.
	 */
	private function page_url( string $path ): string {
		return home_url( $path );
	}

	/**
	 * A file name usable inside a snippet string, or ''.
	 */
	private function safe_token( string $value ): string {
		return preg_match( '/^[A-Za-z0-9._-]{1,120}$/', $value ) ? $value : '';
	}

	/**
	 * A URL path usable inside a snippet string, or ''.
	 */
	private function safe_path( string $value ): string {
		return preg_match( '#^/[A-Za-z0-9/._~%-]{0,200}$#', $value ) ? $value : '';
	}

	/**
	 * An http(s) URL usable inside a snippet string, or ''.
	 */
	private function safe_url( string $value ): string {
		return preg_match( '#^https?://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9/._~%-]*)?$#', $value ) ? $value : '';
	}

	/**
	 * A CSS selector usable in a snippet, or ''.
	 */
	private function safe_selector( string $value ): string {
		return preg_match( '/^[A-Za-z0-9 #.>_:-]{1,160}$/', $value ) ? $value : '';
	}

	/**
	 * Format milliseconds.
	 */
	private function ms( ?float $value ): string {
		$value = (float) $value;
		/* translators: %s: seconds. */
		return $value >= 1000 ? sprintf( __( '%s s', 'web-vitals-monitor' ), number_format_i18n( $value / 1000, 2 ) ) : sprintf( /* translators: %s: milliseconds. */ __( '%s ms', 'web-vitals-monitor' ), number_format_i18n( $value ) );
	}

	/**
	 * Format a 0..1 share as a percentage.
	 */
	private function pct( float $share ): string {
		return number_format_i18n( $share * 100 ) . '%';
	}
}
