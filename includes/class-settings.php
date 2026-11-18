<?php
/**
 * Settings API integration.
 *
 * @package Web_Vitals_Monitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings.
 */
final class Web_Vitals_Settings {
	/**
	 * Option name.
	 */
	public const OPTION = 'wvm_settings';

	/**
	 * Per-request cache of merged settings.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'update_option_' . self::OPTION, array( $this, 'flush' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'flush' ) );
	}

	/**
	 * Register Settings API option.
	 */
	public function register(): void {
		register_setting(
			'wvm_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Web_Vitals_Config', 'sanitize_settings' ),
				'default'           => Web_Vitals_Config::defaults(),
			)
		);

		// AI settings hold the API key: a separate option that is never autoloaded on the frontend.
		if ( false === get_option( Web_Vitals_AI::OPTION, false ) ) {
			add_option( Web_Vitals_AI::OPTION, Web_Vitals_AI::defaults(), '', false );
		}
		register_setting(
			'wvm_settings_group',
			Web_Vitals_AI::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Web_Vitals_AI', 'sanitize' ),
				'default'           => Web_Vitals_AI::defaults(),
			)
		);
	}

	/**
	 * Drop the per-request cache after the option changes.
	 */
	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * Get all settings with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$value       = get_option( self::OPTION, array() );
			$this->cache = wp_parse_args( is_array( $value ) ? $value : array(), Web_Vitals_Config::defaults() );
		}

		return $this->cache;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $fallback Optional fallback.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		$settings = $this->all();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Whether the suggestions section is shown in Persian.
	 */
	public function suggestions_in_persian(): bool {
		return 'fa' === $this->get( 'suggestions_language', 'fa' ) || 'fa_IR' === determine_locale();
	}

	/**
	 * Run a callback with the plugin's strings in the suggestions language.
	 * Lets the Optimization Suggestions section be Persian even when the
	 * admin language is not.
	 *
	 * @param callable $callback Callback producing translated output.
	 * @return mixed
	 */
	public function with_suggestions_locale( callable $callback ): mixed {
		$mo = WVM_PATH . 'languages/web-vitals-monitor-fa_IR.mo';
		if ( 'fa' !== $this->get( 'suggestions_language', 'fa' ) || 'fa_IR' === determine_locale() || ! is_readable( $mo ) ) {
			return $callback();
		}

		unload_textdomain( 'web-vitals-monitor', true );
		load_textdomain( 'web-vitals-monitor', $mo, 'fa_IR' );

		try {
			return $callback();
		} finally {
			// Let WordPress load the admin-language strings again on next use.
			unload_textdomain( 'web-vitals-monitor', true );
		}
	}

	/**
	 * Check whether a metric is enabled.
	 */
	public function metric_enabled( string $metric ): bool {
		$key = 'collect_' . strtolower( $metric );
		return (bool) $this->get( $key, 0 );
	}
}
