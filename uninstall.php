<?php
/**
 * Uninstall handler.
 *
 * @package Web_Vitals_Monitor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'wvm_settings', array() );

wp_clear_scheduled_hook( 'wvm_cleanup_event' );

global $wpdb;

// Remove rate-limit and cached report transients.
foreach ( array( 'wvm_rl_', 'wvm_dash_', 'wvm_tips_', 'wvm_pages_', 'wvm_ai_' ) as $prefix ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . $prefix ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
		)
	);
}

if ( is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] ) ) {
	$tables = array(
		$wpdb->prefix . 'web_vitals',
		$wpdb->prefix . 'web_vitals_daily',
	);

	foreach ( $tables as $table ) {
		if ( 0 === strpos( $table, $wpdb->prefix ) ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}

	delete_option( 'wvm_last_aggregated' );
	delete_option( 'wvm_db_version' );
}

foreach ( array( 'wvm_settings', 'wvm_cache_version', 'wvm_upgrade_lock', 'wvm_checklist', 'wvm_ai' ) as $option ) {
	delete_option( $option );
}
