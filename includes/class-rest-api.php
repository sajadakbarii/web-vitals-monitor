<?php
/**
 * REST API endpoints.
 *
 * @package Web_Vitals_Monitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST API service.
 */
final class Web_Vitals_REST_API {
	/**
	 * Namespace.
	 */
	private const NAMESPACE = 'web-vitals/v1';

	/**
	 * Seconds admin reports are cached.
	 */
	private const DASHBOARD_TTL   = 60;
	private const SUGGESTIONS_TTL = 600;

	/**
	 * Attribution keys accepted per metric. Must match monitor.js.
	 */
	private const ATTRIBUTION_KEYS = array(
		'LCP'  => array( 'target', 'url', 'timeToFirstByte', 'resourceLoadDelay', 'resourceLoadDuration', 'elementRenderDelay', 'element', 'lazy', 'fetchPriority', 'crossOrigin', 'preloaded', 'natW', 'dispW', 'bytes', 'blocking' ),
		'INP'  => array( 'interactionTarget', 'interactionType', 'inputDelay', 'processingDuration', 'presentationDelay', 'loadState', 'totalScriptDuration', 'totalStyleAndLayoutDuration', 'totalPaintDuration', 'scriptSubpart', 'scriptDuration', 'scriptSource', 'scriptInvoker', 'scriptInvokerType' ),
		'CLS'  => array( 'largestShiftTarget', 'largestShiftTime', 'largestShiftValue', 'loadState', 'shiftTag', 'shiftNoDims', 'shiftSrc', 'shiftH' ),
		'FCP'  => array( 'timeToFirstByte', 'firstByteToFCP', 'loadState' ),
		'TTFB' => array( 'waitingDuration', 'cacheDuration', 'dnsDuration', 'connectionDuration', 'requestDuration' ),
	);

	/**
	 * Navigation types reported by web-vitals.
	 */
	private const NAVIGATION_TYPES = array( 'navigate', 'reload', 'back-forward', 'back-forward-cache', 'prerender', 'restore', 'soft-navigation' );

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

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/collect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'collect' ),
				'permission_callback' => '__return_true',
			)
		);

		$report_args = array(
			'days'   => array(
				'type'              => 'integer',
				'default'           => 7,
				'sanitize_callback' => 'absint',
			),
			'device' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
			'path'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'fresh'  => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/dashboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'dashboard' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $report_args,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/suggestions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'suggestions' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $report_args,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/page-issues',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'page_issues' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $report_args,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/checklist',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'checklist' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'key'  => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-f0-9]{16}$',
					),
					'done' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ai-advice',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ai_advice' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array_merge(
					$report_args,
					array(
						'key' => array(
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^[a-f0-9]{16}$',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/diagnostics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'diagnostics' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Admin route permission.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Invalidate cached admin reports (e.g. after deleting data).
	 */
	public static function bump_cache(): void {
		update_option( 'wvm_cache_version', (int) get_option( 'wvm_cache_version', 1 ) + 1, false );
	}

	/**
	 * Public collection endpoint.
	 */
	public function collect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->settings->get( 'enabled', 1 ) ) {
			return new WP_REST_Response( array( 'accepted' => 0 ), 202 );
		}

		$body = $request->get_body();
		if ( strlen( $body ) > 16384 ) {
			return new WP_Error( 'wvm_payload_too_large', __( 'Payload is too large.', 'web-vitals-monitor' ), array( 'status' => 413 ) );
		}

		// Checked here rather than when rendering the page, so it stays correct behind full-page caches.
		if ( $this->is_excluded_agent( (string) $request->get_header( 'user_agent' ) ) ) {
			return new WP_REST_Response( array( 'accepted' => 0 ), 202 );
		}

		if ( ! $this->rate_limit_ok() ) {
			return new WP_Error( 'wvm_rate_limited', __( 'Too many requests.', 'web-vitals-monitor' ), array( 'status' => 429 ) );
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'wvm_invalid_json', __( 'Invalid JSON payload.', 'web-vitals-monitor' ), array( 'status' => 400 ) );
		}

		// Current collector sends {c: shared context, m: [metrics]}; v1.0 sent a flat list.
		if ( isset( $payload['m'] ) && is_array( $payload['m'] ) ) {
			$context = isset( $payload['c'] ) && is_array( $payload['c'] ) ? $payload['c'] : array();
			$items   = $payload['m'];
		} else {
			$context = array();
			$items   = isset( $payload[0] ) ? $payload : array( $payload );
		}

		if ( count( $items ) > 10 ) {
			return new WP_Error( 'wvm_too_many_metrics', __( 'Too many metrics in one request.', 'web-vitals-monitor' ), array( 'status' => 400 ) );
		}

		$rows = array();
		foreach ( $items as $item ) {
			$prepared = $this->prepare_metric( is_array( $item ) ? array_merge( $context, $item ) : array() );
			if ( is_wp_error( $prepared ) ) {
				$this->debug_log( 'Rejected metric: ' . $prepared->get_error_code() );
				continue;
			}
			$rows[] = $prepared;
		}

		$accepted = $this->database->insert_metrics( $rows );

		return new WP_REST_Response( array( 'accepted' => $accepted ), $accepted > 0 ? 201 : 400 );
	}

	/**
	 * Dashboard data endpoint.
	 */
	public function dashboard( WP_REST_Request $request ): WP_REST_Response {
		$filters = $this->request_filters( $request );

		$data = $this->cached(
			'dash',
			$filters,
			(bool) $request->get_param( 'fresh' ),
			self::DASHBOARD_TTL,
			function () use ( $filters ) {
				return $this->database->dashboard_data( $filters );
			}
		);

		return new WP_REST_Response( $data );
	}

	/**
	 * Optimization suggestions endpoint.
	 */
	public function suggestions( WP_REST_Request $request ): WP_REST_Response {
		$filters = $this->request_filters( $request );

		$data = $this->cached(
			'tips',
			$filters,
			(bool) $request->get_param( 'fresh' ),
			self::SUGGESTIONS_TTL,
			function () use ( $filters ) {
				$advisor = new Web_Vitals_Advisor( $this->database, $this->settings );
				return $this->settings->with_suggestions_locale(
					static function () use ( $advisor, $filters ) {
						return $advisor->analyze( $filters );
					}
				);
			}
		);

		return new WP_REST_Response( $data );
	}

	/**
	 * Page-specific issue checklist endpoint.
	 */
	public function page_issues( WP_REST_Request $request ): WP_REST_Response {
		$filters = $this->request_filters( $request );

		$data = $this->cached(
			'pages',
			$filters,
			(bool) $request->get_param( 'fresh' ),
			self::SUGGESTIONS_TTL,
			function () use ( $filters ) {
				$issues = new Web_Vitals_Page_Issues( $this->database, $this->settings );
				return $this->settings->with_suggestions_locale(
					static function () use ( $issues, $filters ) {
						return $issues->report( $filters );
					}
				);
			}
		);

		return new WP_REST_Response( $data );
	}

	/**
	 * Ask the AI assistant about one checklist issue. The issue is rebuilt on
	 * the server from the key; issue content sent by the browser is not trusted.
	 */
	public function ai_advice( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$filters = $this->request_filters( $request );
		if ( '' === $filters['path'] ) {
			return new WP_Error( 'wvm_ai_path', 'Missing page path.', array( 'status' => 400 ) );
		}

		$report = $this->cached(
			'pages',
			$filters,
			false,
			self::SUGGESTIONS_TTL,
			function () use ( $filters ) {
				$issues = new Web_Vitals_Page_Issues( $this->database, $this->settings );
				return $this->settings->with_suggestions_locale(
					static function () use ( $issues, $filters ) {
						return $issues->report( $filters );
					}
				);
			}
		);

		$key = (string) $request->get_param( 'key' );
		foreach ( (array) ( $report['pages'] ?? array() ) as $page ) {
			foreach ( (array) $page['issues'] as $issue ) {
				if ( $issue['key'] !== $key ) {
					continue;
				}
				$ai     = new Web_Vitals_AI();
				$fresh  = (bool) $request->get_param( 'fresh' );
				$answer = $this->settings->with_suggestions_locale(
					function () use ( $ai, $issue, $page, $fresh ) {
						return $ai->advise( $issue, $page, $this->settings->suggestions_in_persian(), $fresh );
					}
				);
				return is_wp_error( $answer ) ? $answer : new WP_REST_Response( $answer );
			}
		}

		return new WP_Error( 'wvm_ai_issue', 'Issue not found for this page and period.', array( 'status' => 404 ) );
	}

	/**
	 * Tick or untick a checklist item.
	 */
	public function checklist( WP_REST_Request $request ): WP_REST_Response {
		Web_Vitals_Page_Issues::set_done( (string) $request->get_param( 'key' ), (bool) $request->get_param( 'done' ) );
		// Cached checklists include tick state, so drop them.
		self::bump_cache();

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Diagnostics endpoint.
	 */
	public function diagnostics(): WP_REST_Response {
		$health = $this->database->collector_health();

		return new WP_REST_Response(
			array(
				'web_vitals_asset' => file_exists( WVM_PATH . 'assets/js/web-vitals.js' ),
				'collector_asset'  => file_exists( WVM_PATH . 'assets/js/monitor.js' ),
				'collector'        => (bool) $this->settings->get( 'enabled', 1 ),
				'rest_endpoint'    => rest_url( self::NAMESPACE . '/collect' ),
				'database'         => $health['database'],
				'cron'             => $health['cron_scheduled'],
				'last_metric'      => $health['last_received'],
			)
		);
	}

	/**
	 * Normalize report filters from a request.
	 *
	 * @return array<string,mixed>
	 */
	private function request_filters( WP_REST_Request $request ): array {
		$path = (string) $request->get_param( 'path' );

		return $this->database->build_filters(
			max( 1, min( 90, (int) $request->get_param( 'days' ) ) ),
			(string) $request->get_param( 'device' ),
			'' === $path ? '' : $this->sanitize_path( $path )
		);
	}

	/**
	 * Short-lived cache for admin reports.
	 *
	 * @param string              $prefix Cache prefix.
	 * @param array<string,mixed> $filters Filters.
	 * @param bool                $fresh Skip cached copy.
	 * @param int                 $ttl Seconds.
	 * @param callable            $build Builder.
	 * @return mixed
	 */
	private function cached( string $prefix, array $filters, bool $fresh, int $ttl, callable $build ): mixed {
		$key = 'wvm_' . $prefix . '_' . md5(
			wp_json_encode(
				array(
					$filters['days'],
					$filters['device'],
					$filters['path'],
					(int) get_option( 'wvm_cache_version', 1 ),
					determine_locale(),
					$this->settings->get( 'suggestions_language', 'fa' ),
				)
			)
		);

		if ( ! $fresh ) {
			$data = get_transient( $key );
			if ( false !== $data ) {
				return $data;
			}
		}

		$data = $build();
		set_transient( $key, $data, $ttl );

		return $data;
	}

	/**
	 * Validate and sanitize one metric.
	 *
	 * @param array<string,mixed> $item Raw item.
	 * @return array<string,mixed>|WP_Error
	 */
	private function prepare_metric( array $item ): array|WP_Error {
		$name = strtoupper( sanitize_key( (string) ( $item['name'] ?? '' ) ) );
		if ( ! in_array( $name, Web_Vitals_Config::metrics(), true ) || ! $this->settings->metric_enabled( $name ) ) {
			return new WP_Error( 'invalid_metric' );
		}

		$value = $item['value'] ?? null;
		if ( ! is_numeric( $value ) || (float) $value < 0 || (float) $value > 120000 ) {
			return new WP_Error( 'invalid_value' );
		}

		if ( 'CLS' === $name && (float) $value > 10 ) {
			return new WP_Error( 'invalid_cls' );
		}

		// The id is the dedupe key: later reports of the same metric instance overwrite earlier ones.
		$metric_id = substr( (string) preg_replace( '/[^A-Za-z0-9._-]/', '', (string) ( $item['id'] ?? '' ) ), 0, 96 );
		if ( '' === $metric_id ) {
			return new WP_Error( 'invalid_id' );
		}

		$page_path = $this->sanitize_path( (string) ( $item['path'] ?? '/' ) );
		if ( '' === $page_path ) {
			$page_path = '/';
		}

		$attribution = array();
		if ( $this->settings->get( 'collect_attribution', 1 ) && isset( $item['attribution'] ) && is_array( $item['attribution'] ) ) {
			$attribution = $this->sanitize_attribution( $name, $item['attribution'] );
		}

		$navigation_type = sanitize_key( (string) ( $item['navigationType'] ?? '' ) );

		return array(
			'metric_name'      => $name,
			'metric_value'     => (float) $value,
			'rating'           => Web_Vitals_Config::rating( $name, (float) $value ),
			'metric_id'        => $metric_id,
			'page_path'        => $page_path,
			'page_type'        => substr( sanitize_key( (string) ( $item['pageType'] ?? 'other' ) ), 0, 64 ),
			'device_type'      => $this->sanitize_enum( (string) ( $item['device'] ?? 'unknown' ), array( 'mobile', 'tablet', 'desktop', 'unknown' ) ),
			'browser'          => substr( sanitize_text_field( (string) ( $item['browser'] ?? 'unknown' ) ), 0, 64 ),
			'browser_version'  => substr( sanitize_text_field( (string) ( $item['browserVersion'] ?? '' ) ), 0, 32 ),
			'os'               => substr( sanitize_text_field( (string) ( $item['os'] ?? 'unknown' ) ), 0, 64 ),
			'connection_type'  => substr( sanitize_key( (string) ( $item['connection'] ?? 'unknown' ) ), 0, 32 ),
			'viewport_width'   => min( 20000, absint( $item['vw'] ?? 0 ) ),
			'viewport_height'  => min( 20000, absint( $item['vh'] ?? 0 ) ),
			'navigation_type'  => in_array( $navigation_type, self::NAVIGATION_TYPES, true ) ? $navigation_type : 'unknown',
			'language'         => substr( sanitize_text_field( (string) ( $item['language'] ?? '' ) ), 0, 24 ),
			'attribution_data' => $attribution ? wp_json_encode( $attribution ) : '',
		);
	}

	/**
	 * Sanitize URL path and strip sensitive query strings.
	 */
	private function sanitize_path( string $path ): string {
		$parts = wp_parse_url( $path );
		$clean = '/';

		if ( is_array( $parts ) && ! empty( $parts['path'] ) ) {
			$clean = '/' . ltrim( sanitize_text_field( $parts['path'] ), '/' );
		} elseif ( ! is_array( $parts ) ) {
			$clean = '/' . ltrim( sanitize_text_field( $path ), '/' );
		}

		if ( ! $this->settings->get( 'sanitize_query_string', 1 ) && is_array( $parts ) && ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			$allowed = array_diff_key(
				$query,
				array_flip( array( 'email', 'e-mail', 'user', 'username', 'token', 'key', 'password', 'pass', 'auth', 'session', 'nonce' ) )
			);
			$allowed = array_filter( $allowed, 'is_scalar' );
			if ( $allowed ) {
				$clean .= '?' . http_build_query( array_map( 'sanitize_text_field', $allowed ) );
			}
		}

		return substr( $clean, 0, 512 );
	}

	/**
	 * Sanitize enum.
	 *
	 * @param string   $value Raw value.
	 * @param string[] $allowed Allowed values.
	 */
	private function sanitize_enum( string $value, array $allowed ): string {
		$value = sanitize_key( $value );
		return in_array( $value, $allowed, true ) ? $value : 'unknown';
	}

	/**
	 * Keep attribution small and non-sensitive.
	 *
	 * @param string              $metric Metric name.
	 * @param array<string,mixed> $raw Raw attribution.
	 * @return array<string,string|float|int>
	 */
	private function sanitize_attribution( string $metric, array $raw ): array {
		$output = array();
		foreach ( self::ATTRIBUTION_KEYS[ $metric ] ?? array() as $key ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			$value = $raw[ $key ];
			if ( is_bool( $value ) ) {
				$output[ $key ] = $value ? 1 : 0;
			} elseif ( is_numeric( $value ) ) {
				$output[ $key ] = round( (float) $value, 'largestShiftValue' === $key ? 4 : 1 );
			} elseif ( 'blocking' === $key && is_string( $value ) ) {
				$output[ $key ] = $this->sanitize_blocking( $value );
			} elseif ( is_string( $value ) ) {
				if ( in_array( $key, array( 'url', 'scriptSource', 'shiftSrc' ), true ) ) {
					$value = $this->strip_query( $value );
				}
				$output[ $key ] = substr( sanitize_text_field( wp_strip_all_tags( $value ) ), 0, 160 );
			}
		}

		return $output;
	}

	/**
	 * Render-blocking list "url|ms;url|ms" -> same format, query strings removed, max 4 entries.
	 */
	private function sanitize_blocking( string $value ): string {
		$items = array();
		foreach ( array_slice( explode( ';', $value ), 0, 4 ) as $item ) {
			$parts = explode( '|', $item );
			$url   = substr( sanitize_text_field( $this->strip_query( $parts[0] ) ), 0, 160 );
			if ( '' !== $url ) {
				$items[] = $url . '|' . absint( $parts[1] ?? 0 );
			}
		}

		return implode( ';', $items );
	}

	/**
	 * Drop query string and fragment from a resource URL.
	 */
	private function strip_query( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$host = isset( $parts['host'] ) ? ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] : '';
		return $host . ( $parts['path'] ?? '' );
	}

	/**
	 * Whether a user agent belongs to a bot, lab tool, or a configured exclusion.
	 */
	private function is_excluded_agent( string $user_agent ): bool {
		if ( '' === $user_agent || preg_match( '/' . Web_Vitals_Config::BOT_PATTERN . '/i', $user_agent ) ) {
			return true;
		}

		$custom = Web_Vitals_Config::patterns_to_regex( (string) $this->settings->get( 'excluded_user_agents', '' ), false );

		return '' !== $custom && (bool) preg_match( '/' . $custom . '/i', $user_agent );
	}

	/**
	 * Fixed-window per-client rate limit. Uses the object cache when available.
	 */
	private function rate_limit_ok(): bool {
		$limit = (int) $this->settings->get( 'rate_limit', 120 );
		if ( $limit <= 0 ) {
			return true;
		}

		$key = 'wvm_rl_' . substr( hash( 'sha256', $this->client_ip() . wp_salt( 'nonce' ) ), 0, 32 ) . '_' . (int) floor( time() / MINUTE_IN_SECONDS );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'wvm', 2 * MINUTE_IN_SECONDS );
			return (int) wp_cache_incr( $key, 1, 'wvm' ) <= $limit;
		}

		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Client IP for rate limiting. Behind a CDN every visitor shares REMOTE_ADDR,
	 * so proxy headers can be trusted when the site owner enables it.
	 */
	private function client_ip(): string {
		$remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );

		if ( $this->settings->get( 'trust_proxy_headers', 0 ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
				if ( empty( $_SERVER[ $header ] ) ) {
					continue;
				}
				$candidate = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}

		return (string) apply_filters( 'wvm_client_ip', $remote );
	}

	/**
	 * Debug logger.
	 */
	private function debug_log( string $message ): void {
		if ( $this->settings->get( 'debug_mode', 0 ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Web Vitals Monitor] ' . sanitize_text_field( $message ) );
		}
	}
}
