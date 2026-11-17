<?php
/**
 * Central configuration for thresholds and defaults.
 *
 * @package Web_Vitals_Monitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Configuration helper.
 */
final class Web_Vitals_Config {
	/**
	 * User agents that never produce real-user data (crawlers, lab tools, headless browsers).
	 */
	public const BOT_PATTERN = 'bot|crawl|spider|slurp|lighthouse|pagespeed|headless|gtmetrix|pingdom|uptime|phantomjs|puppeteer|playwright|selenium';

	/**
	 * Supported metric names.
	 *
	 * @return string[]
	 */
	public static function metrics(): array {
		return array( 'LCP', 'INP', 'CLS', 'FCP', 'TTFB' );
	}

	/**
	 * The three Core Web Vitals. FCP and TTFB are supporting (diagnostic) metrics.
	 *
	 * @return string[]
	 */
	public static function core_metrics(): array {
		return array( 'LCP', 'INP', 'CLS' );
	}

	/**
	 * Core thresholds. Milliseconds for LCP, INP, FCP, TTFB; score for CLS.
	 *
	 * @return array<string,array{good:float,poor:float}>
	 */
	public static function thresholds(): array {
		return array(
			'LCP'  => array(
				'good' => 2500,
				'poor' => 4000,
			),
			'INP'  => array(
				'good' => 200,
				'poor' => 500,
			),
			'CLS'  => array(
				'good' => 0.1,
				'poor' => 0.25,
			),
			'FCP'  => array(
				'good' => 1800,
				'poor' => 3000,
			),
			'TTFB' => array(
				'good' => 800,
				'poor' => 1800,
			),
		);
	}

	/**
	 * Default plugin settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'                  => 1,
			'exclude_admins'           => 1,
			'exclude_logged_in'        => 0,
			'sampling_rate'            => 100,
			'retention_days'           => 90,
			'delete_data_on_uninstall' => 0,
			'collect_lcp'              => 1,
			'collect_inp'              => 1,
			'collect_cls'              => 1,
			'collect_fcp'              => 1,
			'collect_ttfb'             => 1,
			'collect_attribution'      => 1,
			'sanitize_query_string'    => 1,
			'minimum_sample_size'      => 25,
			'debug_mode'               => 0,
			'cleanup_batch_size'       => 5000,
			'excluded_url_patterns'    => "/wp-admin/*\n/cart/*\n/checkout/*",
			'excluded_user_agents'     => '',
			'collector_delay'          => 0,
			'load_strategy'            => 'idle',
			'rate_limit'               => 120,
			'trust_proxy_headers'      => 0,
			'suggestions_language'     => 'fa',
		);
	}

	/**
	 * Return a metric rating.
	 */
	public static function rating( string $metric, float $value ): string {
		$thresholds = self::thresholds();

		if ( ! isset( $thresholds[ $metric ] ) ) {
			return 'unknown';
		}

		if ( $value <= $thresholds[ $metric ]['good'] ) {
			return 'good';
		}

		if ( $value <= $thresholds[ $metric ]['poor'] ) {
			return 'needs-improvement';
		}

		return 'poor';
	}

	/**
	 * Convert newline-separated glob patterns (`*` wildcard) into one regex body.
	 *
	 * @param string $patterns Raw patterns.
	 * @param bool   $anchored Whether each pattern must match the whole value.
	 */
	public static function patterns_to_regex( string $patterns, bool $anchored ): string {
		$parts = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $patterns ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$part    = str_replace( '\*', '.*', preg_quote( $line, '/' ) );
			$parts[] = $anchored ? '^' . $part . '$' : $part;
		}

		return implode( '|', $parts );
	}

	/**
	 * Sanitize and merge settings.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( array $input ): array {
		$defaults = self::defaults();
		$output   = array();

		$checkboxes = array(
			'enabled',
			'exclude_admins',
			'exclude_logged_in',
			'delete_data_on_uninstall',
			'collect_lcp',
			'collect_inp',
			'collect_cls',
			'collect_fcp',
			'collect_ttfb',
			'collect_attribution',
			'sanitize_query_string',
			'debug_mode',
			'trust_proxy_headers',
		);

		foreach ( $checkboxes as $key ) {
			$output[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$output['sampling_rate']       = max( 0, min( 100, (float) ( $input['sampling_rate'] ?? $defaults['sampling_rate'] ) ) );
		$output['retention_days']      = (int) ( $input['retention_days'] ?? $defaults['retention_days'] );
		$output['minimum_sample_size'] = max( 1, (int) ( $input['minimum_sample_size'] ?? $defaults['minimum_sample_size'] ) );
		$output['cleanup_batch_size']  = max( 100, min( 50000, (int) ( $input['cleanup_batch_size'] ?? $defaults['cleanup_batch_size'] ) ) );
		$output['collector_delay']     = max( 0, min( 10000, (int) ( $input['collector_delay'] ?? $defaults['collector_delay'] ) ) );
		$output['rate_limit']          = max( 0, min( 10000, (int) ( $input['rate_limit'] ?? $defaults['rate_limit'] ) ) );
		$output['load_strategy']       = in_array( $input['load_strategy'] ?? '', array( 'idle', 'early' ), true ) ? $input['load_strategy'] : $defaults['load_strategy'];

		$output['suggestions_language'] = in_array( $input['suggestions_language'] ?? '', array( 'fa', 'site' ), true ) ? $input['suggestions_language'] : $defaults['suggestions_language'];

		$valid_retention = array( 7, 30, 90, 180, 365, 0 );
		if ( ! in_array( $output['retention_days'], $valid_retention, true ) ) {
			$output['retention_days'] = $defaults['retention_days'];
		}

		$output['excluded_url_patterns'] = sanitize_textarea_field( (string) ( $input['excluded_url_patterns'] ?? '' ) );
		$output['excluded_user_agents']  = sanitize_textarea_field( (string) ( $input['excluded_user_agents'] ?? '' ) );

		return wp_parse_args( $output, $defaults );
	}
}
