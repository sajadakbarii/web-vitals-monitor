<?php
/**
 * Admin screens and actions.
 *
 * @package Web_Vitals_Monitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin service.
 */
final class Web_Vitals_Admin {
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
	 * Constructor.
	 */
	public function __construct( Web_Vitals_Database $database, Web_Vitals_Settings $settings ) {
		$this->database = $database;
		$this->settings = $settings;

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_wvm_export', array( $this, 'export' ) );
		add_action( 'admin_post_wvm_delete_data', array( $this, 'delete_data' ) );
		add_action( 'admin_post_wvm_ai_test', array( $this, 'ai_test' ) );
		add_filter( 'plugin_action_links_' . WVM_BASENAME, array( $this, 'settings_link' ) );
	}

	/**
	 * Register admin menu.
	 */
	public function menu(): void {
		add_menu_page(
			__( 'Web Vitals', 'web-vitals-monitor' ),
			__( 'Web Vitals', 'web-vitals-monitor' ),
			'manage_options',
			'wvm-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-performance',
			58
		);

		add_submenu_page(
			'wvm-dashboard',
			__( 'Web Vitals Settings', 'web-vitals-monitor' ),
			__( 'Settings', 'web-vitals-monitor' ),
			'manage_options',
			'wvm-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Enqueue admin assets only on plugin screens.
	 */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'wvm-' ) ) {
			return;
		}

		// Fonts are self-hosted so the dashboard never depends on a third-party CDN.
		wp_enqueue_style( 'wvm-fonts', WVM_URL . 'assets/css/fonts.css', array(), WVM_VERSION );
		wp_enqueue_style( 'wvm-tokens', WVM_URL . 'assets/css/tokens.css', array( 'wvm-fonts' ), WVM_VERSION );
		wp_enqueue_style( 'wvm-admin', WVM_URL . 'assets/css/admin.css', array( 'wvm-tokens' ), WVM_VERSION );

		if ( false === strpos( $hook, 'wvm-dashboard' ) ) {
			return;
		}

		wp_enqueue_script( 'wvm-admin', WVM_URL . 'assets/js/admin.js', array(), WVM_VERSION, array( 'in_footer' => true ) );
		wp_localize_script(
			'wvm-admin',
			'wvmAdmin',
			array(
				'dashboardUrl'   => esc_url_raw( rest_url( 'web-vitals/v1/dashboard' ) ),
				'suggestionsUrl' => esc_url_raw( rest_url( 'web-vitals/v1/suggestions' ) ),
				'pagesUrl'       => esc_url_raw( rest_url( 'web-vitals/v1/page-issues' ) ),
				'checklistUrl'   => esc_url_raw( rest_url( 'web-vitals/v1/checklist' ) ),
				'currentUser'    => wp_get_current_user()->display_name,
				'ai'             => array(
					'enabled' => Web_Vitals_AI::is_ready(),
					'url'     => esc_url_raw( rest_url( 'web-vitals/v1/ai-advice' ) ),
				),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'minimumSamples' => (int) $this->settings->get( 'minimum_sample_size', 25 ),
				'adminsExcluded' => (bool) $this->settings->get( 'exclude_admins', 1 ),
				'thresholds'     => Web_Vitals_Config::thresholds(),
				'i18n'           => $this->i18n(),
				'suggestions'    => array(
					'rtl'  => $this->settings->suggestions_in_persian() || is_rtl(),
					'i18n' => $this->settings->with_suggestions_locale( array( $this, 'suggestions_i18n' ) ),
				),
			)
		);
	}

	/**
	 * Strings of the Optimization Suggestions panel, in the suggestions language.
	 *
	 * @return array<string,string>
	 */
	public function suggestions_i18n(): array {
		return array(
			'title'             => __( 'Site-wide suggestions', 'web-vitals-monitor' ),
			'intro'             => __( 'Based on the slowest visits across the site (75th percentile and above).', 'web-vitals-monitor' ),
			'critical'          => __( 'Critical', 'web-vitals-monitor' ),
			'warning'           => __( 'Warning', 'web-vitals-monitor' ),
			'info'              => __( 'Opportunity', 'web-vitals-monitor' ),
			'evidence'          => __( 'What the data shows', 'web-vitals-monitor' ),
			'actions'           => __( 'How to fix it', 'web-vitals-monitor' ),
			'affectedUrls'      => __( 'Most affected URLs', 'web-vitals-monitor' ),
			'learnMore'         => __( 'Learn more', 'web-vitals-monitor' ),
			'noSuggestions'     => __( 'Nothing to fix right now: all Core Web Vitals are Good for this selection.', 'web-vitals-monitor' ),
			'suggestionsFailed' => __( 'Unable to load suggestions.', 'web-vitals-monitor' ),
			'analyzing'         => __( 'Analyzing field data…', 'web-vitals-monitor' ),
			'viewUrl'           => __( 'Analyze this URL', 'web-vitals-monitor' ),
			'pagesTitle'        => __( 'Page-specific checklist', 'web-vitals-monitor' ),
			'pagesIntro'        => __( 'Issues detected on each URL from its own visits, each with a fix for exactly that issue. Tick an item once you have fixed it; new visits then show whether the fix worked.', 'web-vitals-monitor' ),
			/* translators: 1: done items, 2: all items. */
			'progress'          => __( '%1$d of %2$d done', 'web-vitals-monitor' ),
			'details'           => __( 'Details and fix', 'web-vitals-monitor' ),
			'code'              => __( 'Code', 'web-vitals-monitor' ),
			'copy'              => __( 'Copy', 'web-vitals-monitor' ),
			'copied'            => __( 'Copied', 'web-vitals-monitor' ),
			'awaiting'          => __( 'Done, waiting for new visits to verify', 'web-vitals-monitor' ),
			'verified'          => __( 'Fixed, confirmed by new visits', 'web-vitals-monitor' ),
			'still'             => __( 'Ticked, but new visits still show this issue', 'web-vitals-monitor' ),
			/* translators: 1: date, 2: user name. */
			'doneBy'            => __( 'ticked %1$s by %2$s', 'web-vitals-monitor' ),
			'noIssues'          => __( 'No specific issues detected on this page in this period.', 'web-vitals-monitor' ),
			'noPages'           => __( 'No pages with enough data yet.', 'web-vitals-monitor' ),
			'pagesFailed'       => __( 'Unable to load the page checklist.', 'web-vitals-monitor' ),
			'saveFailed'        => __( 'Could not save. Please try again.', 'web-vitals-monitor' ),
			'onlyThisPage'      => __( 'Show only this page', 'web-vitals-monitor' ),
			'pageChecklistTitle' => __( 'Checklist for this page', 'web-vitals-monitor' ),
			/* translators: %d: number of hidden items. */
			'showMore'          => __( 'Show %d more', 'web-vitals-monitor' ),
			'askAi'             => __( 'Ask AI for a fix tailored to this issue', 'web-vitals-monitor' ),
			'aiThinking'        => __( 'The AI is analyzing this issue (this can take up to a minute)…', 'web-vitals-monitor' ),
			'aiFailed'          => __( 'The AI request failed.', 'web-vitals-monitor' ),
			'aiTitle'           => __( 'AI analysis', 'web-vitals-monitor' ),
			'aiCause'           => __( 'Root cause', 'web-vitals-monitor' ),
			'aiVerify'          => __( 'How to verify', 'web-vitals-monitor' ),
			'aiCaveats'         => __( 'Caveats', 'web-vitals-monitor' ),
			/* translators: %s: high, medium or low. */
			'aiConfidence'      => __( 'Confidence: %s', 'web-vitals-monitor' ),
			'high'              => __( 'high', 'web-vitals-monitor' ),
			'medium'            => __( 'medium', 'web-vitals-monitor' ),
			'low'               => __( 'low', 'web-vitals-monitor' ),
			/* translators: %s: date. */
			'aiCached'          => __( 'Saved answer from %s', 'web-vitals-monitor' ),
			'aiRegenerate'      => __( 'Ask again', 'web-vitals-monitor' ),
			'aiWarning'         => __( 'Generated by AI: review it before applying, and test any code on a staging copy first.', 'web-vitals-monitor' ),
		);
	}

	/**
	 * Strings used by admin.js.
	 *
	 * @return array<string,string>
	 */
	private function i18n(): array {
		return array(
			'insufficient'       => __( 'Not enough samples yet for a reliable P75.', 'web-vitals-monitor' ),
			'noData'             => __( 'No real-user data received yet.', 'web-vitals-monitor' ),
			'loadFailed'         => __( 'Unable to load dashboard data.', 'web-vitals-monitor' ),
			'emptyTitle'         => __( 'No field data yet', 'web-vitals-monitor' ),
			'emptyText'          => __( 'Data appears as soon as real visitors load your pages. Visits by administrators are not measured unless you change that in Settings.', 'web-vitals-monitor' ),
			'median'             => __( 'Median', 'web-vitals-monitor' ),
			'p90'                => __( 'P90', 'web-vitals-monitor' ),
			'samples'            => __( 'Samples', 'web-vitals-monitor' ),
			'good'               => __( 'Good', 'web-vitals-monitor' ),
			'needs-improvement'  => __( 'Needs improvement', 'web-vitals-monitor' ),
			'poor'               => __( 'Poor', 'web-vitals-monitor' ),
			'unknown'            => __( 'Not enough data', 'web-vitals-monitor' ),
			'diagnostic'         => __( 'diagnostic', 'web-vitals-monitor' ),
			'nameLCP'            => __( 'Largest Contentful Paint', 'web-vitals-monitor' ),
			'nameINP'            => __( 'Interaction to Next Paint', 'web-vitals-monitor' ),
			'nameCLS'            => __( 'Cumulative Layout Shift', 'web-vitals-monitor' ),
			'nameFCP'            => __( 'First Contentful Paint', 'web-vitals-monitor' ),
			'nameTTFB'           => __( 'Time to First Byte', 'web-vitals-monitor' ),
			/* translators: %s: the selected range, e.g. "7 days". */
			'vsPrevious'         => __( 'vs previous %s', 'web-vitals-monitor' ),
			'span1'              => __( '24 hours', 'web-vitals-monitor' ),
			'span7'              => __( '7 days', 'web-vitals-monitor' ),
			'span28'             => __( '28 days', 'web-vitals-monitor' ),
			'span90'             => __( '90 days', 'web-vitals-monitor' ),
			'allDevices'         => __( 'All devices', 'web-vitals-monitor' ),
			'device_mobile'      => __( 'Mobile', 'web-vitals-monitor' ),
			'device_tablet'      => __( 'Tablet', 'web-vitals-monitor' ),
			'device_desktop'     => __( 'Desktop', 'web-vitals-monitor' ),
			'device_unknown'     => __( 'Unknown', 'web-vitals-monitor' ),
			/* translators: 1: value, 2: rating, 3: Good threshold, 4: Poor threshold. */
			'meterLabel'         => __( 'P75 %1$s, rated %2$s. Good up to %3$s, Poor above %4$s.', 'web-vitals-monitor' ),
			'passed'             => __( 'Core Web Vitals assessment: Passed', 'web-vitals-monitor' ),
			'failed'             => __( 'Core Web Vitals assessment: Failed', 'web-vitals-monitor' ),
			'pending'            => __( 'Core Web Vitals assessment: Not enough data', 'web-vitals-monitor' ),
			'pagepassed'         => __( 'This page passes Core Web Vitals', 'web-vitals-monitor' ),
			'pagefailed'         => __( 'This page fails Core Web Vitals', 'web-vitals-monitor' ),
			'pagepending'        => __( 'Not enough data yet to assess this page', 'web-vitals-monitor' ),
			'assessmentNote'     => __( 'Passes when LCP, INP and CLS are all Good at the 75th percentile.', 'web-vitals-monitor' ),
			/* translators: 1: device, 2: range, 3: number of page views. */
			'assessScope'        => __( '%1$s · last %2$s · %3$s page views.', 'web-vitals-monitor' ),
			/* translators: %s: number of page views. */
			'pageViews'          => __( '%s page views', 'web-vitals-monitor' ),
			'whereLCP'           => __( 'Where LCP time goes', 'web-vitals-monitor' ),
			'whereINP'           => __( 'Where INP time goes', 'web-vitals-monitor' ),
			'phasesNote'         => __( 'Median of the slow visits (P75 and above). Highlighted parts take more than their usual share.', 'web-vitals-monitor' ),
			'phase_ttfb'         => __( 'Time to first byte', 'web-vitals-monitor' ),
			'phase_loadDelay'    => __( 'Resource load delay', 'web-vitals-monitor' ),
			'phase_loadDuration' => __( 'Resource load duration', 'web-vitals-monitor' ),
			'phase_renderDelay'  => __( 'Element render delay', 'web-vitals-monitor' ),
			'phase_inputDelay'   => __( 'Input delay', 'web-vitals-monitor' ),
			'phase_processing'   => __( 'Processing duration', 'web-vitals-monitor' ),
			'phase_presentation' => __( 'Presentation delay', 'web-vitals-monitor' ),
			'noTrend'            => __( 'No trend data yet.', 'web-vitals-monitor' ),
			/* translators: 1: metric, 2: number of days. */
			'trendLabel'         => __( '%1$s daily P75 over %2$d days with enough data', 'web-vitals-monitor' ),
			'collector'          => __( 'Collector', 'web-vitals-monitor' ),
			'lastReceived'       => __( 'Last received (UTC)', 'web-vitals-monitor' ),
			'samplesToday'       => __( 'Samples today', 'web-vitals-monitor' ),
			'samplesTotal'       => __( 'Samples total (approx.)', 'web-vitals-monitor' ),
			'adminVisits'        => __( 'Admin visits', 'web-vitals-monitor' ),
			'excluded'           => __( 'excluded', 'web-vitals-monitor' ),
			'measured'           => __( 'measured', 'web-vitals-monitor' ),
			'cron'               => __( 'Cron scheduled', 'web-vitals-monitor' ),
			'lastAggregated'     => __( 'Last aggregated day', 'web-vitals-monitor' ),
			'yes'                => __( 'yes', 'web-vitals-monitor' ),
			'no'                 => __( 'no', 'web-vitals-monitor' ),
			'active'             => __( 'active', 'web-vitals-monitor' ),
			'stale'              => __( 'no data in 24h', 'web-vitals-monitor' ),
			'waiting'            => __( 'waiting', 'web-vitals-monitor' ),
			'filteredBy'         => __( 'Showing data for', 'web-vitals-monitor' ),
			'clearFilter'        => __( 'Show all URLs', 'web-vitals-monitor' ),
			/* translators: %s: time. */
			'updated'            => __( 'Updated %s', 'web-vitals-monitor' ),
		);
	}

	/**
	 * Render dashboard.
	 */
	public function render_dashboard(): void {
		$suggestions_text = $this->settings->with_suggestions_locale( array( $this, 'suggestions_i18n' ) );
		$suggestions_rtl  = $this->settings->suggestions_in_persian() || is_rtl();
		require WVM_PATH . 'admin/dashboard.php';
	}

	/**
	 * Render settings.
	 */
	public function render_settings(): void {
		$settings = $this->settings->all();
		require WVM_PATH . 'admin/settings.php';
	}

	/**
	 * Add settings link.
	 *
	 * @param array<int,string> $links Links.
	 * @return array<int,string>
	 */
	public function settings_link( array $links ): array {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=wvm-settings' ) ) . '">' . esc_html__( 'Settings', 'web-vitals-monitor' ) . '</a>';
		return $links;
	}

	/**
	 * Export CSV or JSON.
	 */
	public function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized request.', 'web-vitals-monitor' ) );
		}

		check_admin_referer( 'wvm_export' );

		$format  = isset( $_GET['format'] ) && 'json' === $_GET['format'] ? 'json' : 'csv';
		$filters = array();
		foreach ( array( 'metric', 'device', 'browser', 'from', 'to' ) as $key ) {
			$filters[ $key ] = sanitize_text_field( wp_unslash( (string) ( $_GET[ $key ] ?? '' ) ) );
		}

		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=web-vitals-export.json' );
			$first = true;
			fwrite( $output, '[' );
			$this->database->export_rows(
				$filters,
				static function ( array $row ) use ( $output, &$first ): void {
					fwrite( $output, ( $first ? '' : ',' ) . wp_json_encode( $row ) );
					$first = false;
				}
			);
			fwrite( $output, ']' );
			fclose( $output );
			exit;
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=web-vitals-export.csv' );

		$header = false;
		$this->database->export_rows(
			$filters,
			static function ( array $row ) use ( $output, &$header ): void {
				if ( ! $header ) {
					fputcsv( $output, array_keys( $row ) );
					$header = true;
				}
				// Neutralize spreadsheet formulas in visitor-controlled text.
				$row = array_map(
					static function ( $value ) {
						return is_string( $value ) && '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
					},
					$row
				);
				fputcsv( $output, $row );
			}
		);
		fclose( $output );

		exit;
	}

	/**
	 * Send a test request to the configured AI service.
	 */
	public function ai_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized request.', 'web-vitals-monitor' ) );
		}

		check_admin_referer( 'wvm_ai_test' );

		$result  = ( new Web_Vitals_AI() )->test();
		$message = is_wp_error( $result ) ? $result->get_error_message() : '';
		set_transient( 'wvm_ai_test_' . get_current_user_id(), array( 'ok' => ! is_wp_error( $result ), 'message' => $message ), MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'admin.php?page=wvm-settings#wvm-ai' ) );
		exit;
	}

	/**
	 * Delete collected data.
	 */
	public function delete_data(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized request.', 'web-vitals-monitor' ) );
		}

		check_admin_referer( 'wvm_delete_data' );

		if ( isset( $_POST['confirm_delete'] ) && 'DELETE' === strtoupper( sanitize_text_field( wp_unslash( $_POST['confirm_delete'] ) ) ) ) {
			$this->database->delete_all();
			Web_Vitals_REST_API::bump_cache();
			wp_safe_redirect( add_query_arg( 'wvm_deleted', '1', admin_url( 'admin.php?page=wvm-settings' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'wvm_deleted', '0', admin_url( 'admin.php?page=wvm-settings' ) ) );
		exit;
	}
}
