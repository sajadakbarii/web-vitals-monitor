<?php
/**
 * Cron jobs.
 *
 * @package Web_Vitals_Monitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cron service.
 */
final class Web_Vitals_Cron {
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

		add_action( 'wvm_cleanup_event', array( $this, 'cleanup' ) );
		add_action( 'admin_init', array( self::class, 'schedule_events' ) );
	}

	/**
	 * Schedule cron events.
	 */
	public static function schedule_events(): void {
		if ( ! wp_next_scheduled( 'wvm_cleanup_event' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wvm_cleanup_event' );
		}
	}

	/**
	 * Clear cron events.
	 */
	public static function clear_events(): void {
		wp_clear_scheduled_hook( 'wvm_cleanup_event' );
	}

	/**
	 * Daily task: aggregate completed days, then remove expired raw rows.
	 */
	public function cleanup(): void {
		$this->database->aggregate_pending();

		$days  = (int) $this->settings->get( 'retention_days', 90 );
		$limit = (int) $this->settings->get( 'cleanup_batch_size', 5000 );
		$this->database->cleanup( $days, $limit );
	}
}
