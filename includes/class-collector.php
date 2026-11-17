<?php
/**
 * Frontend collector integration.
 *
 * Instead of enqueuing scripts on every page, a ~600 byte inline loader decides
 * in the browser (sampling, bots, excluded user agents) whether this page view
 * is measured at all, and only then injects the collector scripts — by default
 * after the load event, during idle time, so they never compete with the page.
 *
 * @package Web_Vitals_Monitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collector service.
 */
final class Web_Vitals_Collector {
	/**
	 * Inline loader. Receives the config object as `c`.
	 */
	private const LOADER = <<<'JS'
(function(w,d,c){var n=navigator,ua=n.userAgent||'';
if(!c||n.webdriver||Math.random()*100>=c.rate||new RegExp(c.bots,'i').test(ua)||(c.ua&&new RegExp(c.ua,'i').test(ua)))return;
function load(){c.src.forEach(function(s){var e=d.createElement('script');e.src=s;e.async=false;d.head.appendChild(e);});}
function idle(){setTimeout(function(){w.requestIdleCallback?requestIdleCallback(load,{timeout:3000}):load();},c.delay);}
if(c.early){setTimeout(load,c.delay);}else if(d.readyState==='complete'){idle();}else{w.addEventListener('load',idle);}
})(window,document,window.wvmConfig);
JS;

	/**
	 * Settings service.
	 *
	 * @var Web_Vitals_Settings
	 */
	private Web_Vitals_Settings $settings;

	/**
	 * Constructor.
	 */
	public function __construct( Web_Vitals_Settings $settings ) {
		$this->settings = $settings;
		add_action( 'wp_footer', array( $this, 'print_loader' ), 99 );
	}

	/**
	 * Print config and loader in the footer.
	 */
	public function print_loader(): void {
		if ( ! $this->should_collect() ) {
			return;
		}

		$config = array(
			'endpoint'      => esc_url_raw( rest_url( 'web-vitals/v1/collect' ) ),
			'rate'          => (float) $this->settings->get( 'sampling_rate', 100 ),
			'delay'         => (int) $this->settings->get( 'collector_delay', 0 ),
			'early'         => 'early' === $this->settings->get( 'load_strategy', 'idle' ),
			'bots'          => Web_Vitals_Config::BOT_PATTERN,
			'ua'            => Web_Vitals_Config::patterns_to_regex( (string) $this->settings->get( 'excluded_user_agents', '' ), false ),
			'src'           => array(
				add_query_arg( 'ver', '6.0.0', WVM_URL . 'assets/js/web-vitals.js' ),
				add_query_arg( 'ver', WVM_VERSION, WVM_URL . 'assets/js/monitor.js' ),
			),
			'path'          => $this->current_path(),
			'pageType'      => $this->page_type(),
			'sanitizeQuery' => (bool) $this->settings->get( 'sanitize_query_string', 1 ),
			'metrics'       => array(
				'LCP'  => $this->settings->metric_enabled( 'LCP' ),
				'INP'  => $this->settings->metric_enabled( 'INP' ),
				'CLS'  => $this->settings->metric_enabled( 'CLS' ),
				'FCP'  => $this->settings->metric_enabled( 'FCP' ),
				'TTFB' => $this->settings->metric_enabled( 'TTFB' ),
			),
			'attribution'   => (bool) $this->settings->get( 'collect_attribution', 1 ),
		);

		wp_print_inline_script_tag(
			'window.wvmConfig=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ";\n" . self::LOADER,
			array( 'id' => 'wvm-loader' )
		);
	}

	/**
	 * Determine if collector should run. Per-visitor checks that would break
	 * under page caching (sampling, user agent) happen in the browser instead.
	 */
	private function should_collect(): bool {
		if ( is_admin() || ! $this->settings->get( 'enabled', 1 ) || is_feed() || is_robots() || is_preview() || is_customize_preview() ) {
			return false;
		}

		if ( (float) $this->settings->get( 'sampling_rate', 100 ) <= 0 ) {
			return false;
		}

		if ( $this->settings->get( 'exclude_admins', 1 ) && current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( $this->settings->get( 'exclude_logged_in', 0 ) && is_user_logged_in() ) {
			return false;
		}

		$excluded = Web_Vitals_Config::patterns_to_regex( (string) $this->settings->get( 'excluded_url_patterns', '' ), true );
		if ( '' !== $excluded && preg_match( '/' . $excluded . '/i', $this->current_path() ) ) {
			return false;
		}

		return (bool) apply_filters( 'wvm_should_collect', true );
	}

	/**
	 * Return sanitized current path.
	 */
	private function current_path(): string {
		$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
		return $path ? sanitize_text_field( $path ) : '/';
	}

	/**
	 * Detect page type using WordPress APIs and optional WooCommerce conditionals.
	 */
	private function page_type(): string {
		if ( is_front_page() ) {
			return 'homepage';
		}
		if ( is_home() ) {
			return 'blog_home';
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			return 'product_category';
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return 'shop';
		}
		if ( is_search() ) {
			return 'search';
		}
		if ( is_404() ) {
			return '404';
		}
		if ( is_singular( 'post' ) ) {
			return 'single_post';
		}
		if ( is_page() ) {
			return 'page';
		}
		if ( is_singular() ) {
			return 'single_' . sanitize_key( (string) get_post_type() );
		}
		if ( is_archive() ) {
			return 'archive';
		}

		return 'other';
	}
}
