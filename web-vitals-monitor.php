<?php
/**
 * Plugin Name: Real User Web Vitals Monitor
 * Description: Lightweight real-user Core Web Vitals monitoring for WordPress.
 * Version: 1.3.0
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * Author: Sajad Akbari
 * Text Domain: web-vitals-monitor
 * Domain Path: /languages
 *
 * @package Web_Vitals_Monitor
 */

defined( 'ABSPATH' ) || exit;

define( 'WVM_VERSION', '1.3.0' );
define( 'WVM_DB_VERSION', '2' );
define( 'WVM_FILE', __FILE__ );
define( 'WVM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WVM_URL', plugin_dir_url( __FILE__ ) );
define( 'WVM_BASENAME', plugin_basename( __FILE__ ) );

require_once WVM_PATH . 'includes/class-config.php';
require_once WVM_PATH . 'includes/class-database.php';
require_once WVM_PATH . 'includes/class-settings.php';
require_once WVM_PATH . 'includes/class-rest-api.php';
require_once WVM_PATH . 'includes/class-collector.php';
require_once WVM_PATH . 'includes/class-cron.php';
require_once WVM_PATH . 'includes/class-advisor.php';
require_once WVM_PATH . 'includes/class-page-issues.php';
require_once WVM_PATH . 'includes/class-ai.php';
require_once WVM_PATH . 'includes/class-admin.php';

/**
 * Main plugin coordinator.
 */
final class Web_Vitals_Monitor {
	/**
	 * Singleton instance.
	 *
	 * @var Web_Vitals_Monitor|null
	 */
	private static ?Web_Vitals_Monitor $instance = null;

	/**
	 * Database service.
	 *
	 * @var Web_Vitals_Database
	 */
	public Web_Vitals_Database $database;

	/**
	 * Settings service.
	 *
	 * @var Web_Vitals_Settings
	 */
	public Web_Vitals_Settings $settings;

	/**
	 * REST service.
	 *
	 * @var Web_Vitals_REST_API
	 */
	public Web_Vitals_REST_API $rest_api;

	/**
	 * Collector service.
	 *
	 * @var Web_Vitals_Collector
	 */
	public Web_Vitals_Collector $collector;

	/**
	 * Cron service.
	 *
	 * @var Web_Vitals_Cron
	 */
	public Web_Vitals_Cron $cron;

	/**
	 * Admin service.
	 *
	 * @var Web_Vitals_Admin
	 */
	public Web_Vitals_Admin $admin;

	/**
	 * Return singleton instance.
	 */
	public static function instance(): Web_Vitals_Monitor {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Bootstrap services.
	 */
	private function __construct() {
		$this->database  = new Web_Vitals_Database();
		$this->settings  = new Web_Vitals_Settings();
		$this->rest_api  = new Web_Vitals_REST_API( $this->database, $this->settings );
		$this->collector = new Web_Vitals_Collector( $this->settings );
		$this->cron      = new Web_Vitals_Cron( $this->database, $this->settings );
		$this->admin     = new Web_Vitals_Admin( $this->database, $this->settings );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade_database' ) );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'web-vitals-monitor', false, dirname( WVM_BASENAME ) . '/languages' );
	}

	/**
	 * Migrate the schema when its version changes. Runs in admin only, so
	 * frontend requests never pay for the check.
	 */
	public function maybe_upgrade_database(): void {
		$installed = (string) get_option( 'wvm_db_version', '' );
		if ( WVM_DB_VERSION === $installed ) {
			return;
		}

		// Lock so concurrent admin requests do not run the migration twice.
		if ( ! add_option( 'wvm_upgrade_lock', time(), '', false ) ) {
			if ( (int) get_option( 'wvm_upgrade_lock' ) > time() - 10 * MINUTE_IN_SECONDS ) {
				return;
			}
			update_option( 'wvm_upgrade_lock', time(), false );
		}

		$this->database->upgrade( $installed );
		wvm_autoload_options();
		update_option( 'wvm_db_version', WVM_DB_VERSION, true );
		delete_option( 'wvm_upgrade_lock' );
	}
}

/**
 * Settings are read on every frontend page, so they must be autoloaded
 * (v1.0 stored them with autoload off, costing one query per page view).
 */
function wvm_autoload_options(): void {
	if ( function_exists( 'wp_set_options_autoload' ) ) {
		wp_set_options_autoload( array( 'wvm_settings', 'wvm_db_version' ), true );
	}
}

/**
 * Activation handler.
 */
function wvm_activate(): void {
	$database = new Web_Vitals_Database();
	$database->upgrade( (string) get_option( 'wvm_db_version', '' ) );

	if ( false === get_option( 'wvm_settings', false ) ) {
		add_option( 'wvm_settings', Web_Vitals_Config::defaults(), '', true );
	}

	update_option( 'wvm_db_version', WVM_DB_VERSION, true );
	wvm_autoload_options();
	Web_Vitals_Cron::schedule_events();
}

/**
 * Deactivation handler.
 */
function wvm_deactivate(): void {
	Web_Vitals_Cron::clear_events();
}

register_activation_hook( __FILE__, 'wvm_activate' );
register_deactivation_hook( __FILE__, 'wvm_deactivate' );

Web_Vitals_Monitor::instance();
