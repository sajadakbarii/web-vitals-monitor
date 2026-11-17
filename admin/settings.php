<?php
/**
 * Settings template.
 *
 * @package Web_Vitals_Monitor
 */

defined( 'ABSPATH' ) || exit;

?>

<div class="wrap wvm-wrap">
	<h1><?php esc_html_e( 'Web Vitals Settings', 'web-vitals-monitor' ); ?></h1>

	<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'web-vitals-monitor' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['wvm_deleted'] ) && '1' === $_GET['wvm_deleted'] ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Collected data deleted.', 'web-vitals-monitor' ); ?></p></div>
	<?php elseif ( isset( $_GET['wvm_deleted'] ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Nothing was deleted. Type DELETE to confirm.', 'web-vitals-monitor' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'wvm_settings_group' ); ?>

		<div class="wvm-settings-grid">
			<section class="wvm-panel">
				<h2><?php esc_html_e( 'General', 'web-vitals-monitor' ); ?></h2>
				<p class="wvm-field">
					<label><input type="checkbox" name="wvm_settings[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>> <?php esc_html_e( 'Enable monitoring', 'web-vitals-monitor' ); ?></label>
				</p>
				<p class="wvm-field">
					<label><input type="checkbox" name="wvm_settings[exclude_admins]" value="1" <?php checked( $settings['exclude_admins'] ); ?>> <?php esc_html_e( 'Exclude administrators', 'web-vitals-monitor' ); ?></label>
				</p>
				<p class="wvm-field">
					<label><input type="checkbox" name="wvm_settings[exclude_logged_in]" value="1" <?php checked( $settings['exclude_logged_in'] ); ?>> <?php esc_html_e( 'Exclude logged-in users', 'web-vitals-monitor' ); ?></label>
				</p>
				<p class="wvm-field">
					<label for="sampling_rate"><?php esc_html_e( 'Sampling rate (%)', 'web-vitals-monitor' ); ?></label>
					<input id="sampling_rate" type="number" min="0" max="100" step="0.1" name="wvm_settings[sampling_rate]" value="<?php echo esc_attr( $settings['sampling_rate'] ); ?>">
					<span class="description"><?php esc_html_e( 'Visitors outside the sample download nothing. On high-traffic sites 10-25% is usually plenty.', 'web-vitals-monitor' ); ?></span>
				</p>
			</section>

			<section class="wvm-panel">
				<h2><?php esc_html_e( 'Metrics', 'web-vitals-monitor' ); ?></h2>
				<?php foreach ( Web_Vitals_Config::metrics() as $metric ) : ?>
					<p class="wvm-field">
						<label><input type="checkbox" name="wvm_settings[collect_<?php echo esc_attr( strtolower( $metric ) ); ?>]" value="1" <?php checked( $settings[ 'collect_' . strtolower( $metric ) ] ); ?>> <?php echo esc_html( $metric ); ?></label>
					</p>
				<?php endforeach; ?>
				<p class="wvm-field">
					<label><input type="checkbox" name="wvm_settings[collect_attribution]" value="1" <?php checked( $settings['collect_attribution'] ); ?>> <?php esc_html_e( 'Collect compact attribution data', 'web-vitals-monitor' ); ?></label>
				</p>
			</section>

			<section class="wvm-panel">
				<h2><?php esc_html_e( 'Privacy', 'web-vitals-monitor' ); ?></h2>
				<p><?php esc_html_e( 'No personally identifiable information is collected by default. Full IP addresses, names, emails, and WordPress user IDs are not stored.', 'web-vitals-monitor' ); ?></p>
				<p class="wvm-field">
					<label><input type="checkbox" name="wvm_settings[sanitize_query_string]" value="1" <?php checked( $settings['sanitize_query_string'] ); ?>> <?php esc_html_e( 'Strip query strings from stored URLs', 'web-vitals-monitor' ); ?></label>
				</p>
				<p class="wvm-field">
					<label for="retention_days"><?php esc_html_e( 'Retention', 'web-vitals-monitor' ); ?></label>
					<select id="retention_days" name="wvm_settings[retention_days]">
						<?php foreach ( array( 7, 30, 90, 180, 365, 0 ) as $days ) : ?>
							<option value="<?php echo esc_attr( $days ); ?>" <?php selected( (int) $settings['retention_days'], $days ); ?>>
								<?php echo 0 === $days ? esc_html__( 'Keep forever', 'web-vitals-monitor' ) : esc_html( sprintf( _n( '%d day', '%d days', $days, 'web-vitals-monitor' ), $days ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="wvm-field">
					<label><input type="checkbox" name="wvm_settings[delete_data_on_uninstall]" value="1" <?php checked( $settings['delete_data_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete data on uninstall', 'web-vitals-monitor' ); ?></label>
				</p>
			</section>

			<section class="wvm-panel">
				<h2><?php esc_html_e( 'Performance', 'web-vitals-monitor' ); ?></h2>
				<p class="wvm-field">
					<label for="load_strategy"><?php esc_html_e( 'When to load the collector', 'web-vitals-monitor' ); ?></label>
					<select id="load_strategy" name="wvm_settings[load_strategy]">
						<option value="idle" <?php selected( $settings['load_strategy'], 'idle' ); ?>><?php esc_html_e( 'After page load, when the browser is idle (no impact on page speed)', 'web-vitals-monitor' ); ?></option>
						<option value="early" <?php selected( $settings['load_strategy'], 'early' ); ?>><?php esc_html_e( 'Early, without blocking rendering (also captures visitors who leave before load)', 'web-vitals-monitor' ); ?></option>
					</select>
					<span class="description"><?php esc_html_e( 'web-vitals reads buffered performance entries, so loading late still measures LCP, FCP, TTFB and CLS from the start of the page.', 'web-vitals-monitor' ); ?></span>
				</p>
				<p class="wvm-field">
					<label for="collector_delay"><?php esc_html_e( 'Delay collector start (ms)', 'web-vitals-monitor' ); ?></label>
					<input id="collector_delay" type="number" min="0" max="10000" step="100" name="wvm_settings[collector_delay]" value="<?php echo esc_attr( $settings['collector_delay'] ); ?>">
				</p>
				<p class="wvm-field">
					<label for="minimum_sample_size"><?php esc_html_e( 'Minimum sample size', 'web-vitals-monitor' ); ?></label>
					<input id="minimum_sample_size" type="number" min="1" name="wvm_settings[minimum_sample_size]" value="<?php echo esc_attr( $settings['minimum_sample_size'] ); ?>">
				</p>
			</section>

			<section class="wvm-panel">
				<h2><?php esc_html_e( 'Exclusion Rules', 'web-vitals-monitor' ); ?></h2>
				<p class="wvm-field">
					<label for="excluded_url_patterns"><?php esc_html_e( 'Excluded URL patterns', 'web-vitals-monitor' ); ?></label>
					<textarea id="excluded_url_patterns" rows="6" name="wvm_settings[excluded_url_patterns]"><?php echo esc_textarea( $settings['excluded_url_patterns'] ); ?></textarea>
					<span class="description"><?php esc_html_e( 'One path per line; * matches anything. Example: /checkout/*', 'web-vitals-monitor' ); ?></span>
				</p>
				<p class="wvm-field">
					<label for="excluded_user_agents"><?php esc_html_e( 'Excluded user agents', 'web-vitals-monitor' ); ?></label>
					<textarea id="excluded_user_agents" rows="6" name="wvm_settings[excluded_user_agents]"><?php echo esc_textarea( $settings['excluded_user_agents'] ); ?></textarea>
					<span class="description"><?php esc_html_e( 'One per line, case-insensitive substring; * matches anything. Crawlers, Lighthouse/PageSpeed and headless browsers are always excluded.', 'web-vitals-monitor' ); ?></span>
				</p>
			</section>

			<section class="wvm-panel">
				<h2><?php esc_html_e( 'Advanced', 'web-vitals-monitor' ); ?></h2>
				<p class="wvm-field">
					<label for="suggestions_language"><?php esc_html_e( 'Optimization Suggestions language', 'web-vitals-monitor' ); ?></label>
					<select id="suggestions_language" name="wvm_settings[suggestions_language]">
						<option value="fa" <?php selected( $settings['suggestions_language'], 'fa' ); ?>>فارسی</option>
						<option value="site" <?php selected( $settings['suggestions_language'], 'site' ); ?>><?php esc_html_e( 'Same as the admin language', 'web-vitals-monitor' ); ?></option>
					</select>
				</p>
				<p class="wvm-field">
					<label for="cleanup_batch_size"><?php esc_html_e( 'Cleanup batch size', 'web-vitals-monitor' ); ?></label>
					<input id="cleanup_batch_size" type="number" min="100" max="50000" name="wvm_settings[cleanup_batch_size]" value="<?php echo esc_attr( $settings['cleanup_batch_size'] ); ?>">
				</p>
				<p class="wvm-field">
					<label for="rate_limit"><?php esc_html_e( 'Rate limit (requests per minute per visitor IP)', 'web-vitals-monitor' ); ?></label>
					<input id="rate_limit" type="number" min="0" max="10000" name="wvm_settings[rate_limit]" value="<?php echo esc_attr( $settings['rate_limit'] ); ?>">
					<span class="description"><?php esc_html_e( '0 disables rate limiting. A normal visitor sends one request per page view.', 'web-vitals-monitor' ); ?></span>
				</p>
				<p class="wvm-field">
					<label><input type="checkbox" name="wvm_settings[trust_proxy_headers]" value="1" <?php checked( $settings['trust_proxy_headers'] ); ?>> <?php esc_html_e( 'Site is behind Cloudflare or another CDN/reverse proxy', 'web-vitals-monitor' ); ?></label>
					<span class="description"><?php esc_html_e( 'Uses CF-Connecting-IP / X-Forwarded-For for rate limiting. Without it, all visitors share the proxy IP and may hit the limit together.', 'web-vitals-monitor' ); ?></span>
				</p>
				<p class="wvm-field">
					<label><input type="checkbox" name="wvm_settings[debug_mode]" value="1" <?php checked( $settings['debug_mode'] ); ?>> <?php esc_html_e( 'Debug mode', 'web-vitals-monitor' ); ?></label>
				</p>
			</section>
		</div>

		<?php submit_button( __( 'Save Settings', 'web-vitals-monitor' ) ); ?>
	</form>

	<div class="wvm-settings-grid">
		<section class="wvm-panel">
			<h2><?php esc_html_e( 'Export', 'web-vitals-monitor' ); ?></h2>
			<p><?php esc_html_e( 'Download raw samples (up to 200,000 rows, newest first). Leave a filter empty to include everything.', 'web-vitals-monitor' ); ?></p>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wvm_export">
				<?php wp_nonce_field( 'wvm_export', '_wpnonce', false ); ?>
				<div class="wvm-export-grid">
					<p class="wvm-field">
						<label for="wvm_export_metric"><?php esc_html_e( 'Metric', 'web-vitals-monitor' ); ?></label>
						<select id="wvm_export_metric" name="metric">
							<option value=""><?php esc_html_e( 'All', 'web-vitals-monitor' ); ?></option>
							<?php foreach ( Web_Vitals_Config::metrics() as $metric ) : ?>
								<option value="<?php echo esc_attr( $metric ); ?>"><?php echo esc_html( $metric ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="wvm-field">
						<label for="wvm_export_device"><?php esc_html_e( 'Device', 'web-vitals-monitor' ); ?></label>
						<select id="wvm_export_device" name="device">
							<option value=""><?php esc_html_e( 'All', 'web-vitals-monitor' ); ?></option>
							<option value="mobile"><?php esc_html_e( 'Mobile', 'web-vitals-monitor' ); ?></option>
							<option value="tablet"><?php esc_html_e( 'Tablet', 'web-vitals-monitor' ); ?></option>
							<option value="desktop"><?php esc_html_e( 'Desktop', 'web-vitals-monitor' ); ?></option>
						</select>
					</p>
					<p class="wvm-field">
						<label for="wvm_export_from"><?php esc_html_e( 'From', 'web-vitals-monitor' ); ?></label>
						<input id="wvm_export_from" type="date" name="from">
					</p>
					<p class="wvm-field">
						<label for="wvm_export_to"><?php esc_html_e( 'To', 'web-vitals-monitor' ); ?></label>
						<input id="wvm_export_to" type="date" name="to">
					</p>
				</div>
				<p>
					<button type="submit" class="button button-primary" name="format" value="csv"><?php esc_html_e( 'Export CSV', 'web-vitals-monitor' ); ?></button>
					<button type="submit" class="button" name="format" value="json"><?php esc_html_e( 'Export JSON', 'web-vitals-monitor' ); ?></button>
				</p>
			</form>
		</section>

		<section class="wvm-panel wvm-danger">
			<h2><?php esc_html_e( 'Delete Data', 'web-vitals-monitor' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wvm_delete_data">
				<?php wp_nonce_field( 'wvm_delete_data' ); ?>
				<p><?php esc_html_e( 'Type DELETE to permanently remove all collected samples and aggregates.', 'web-vitals-monitor' ); ?></p>
				<p class="wvm-field">
					<label for="confirm_delete"><?php esc_html_e( 'Confirmation', 'web-vitals-monitor' ); ?></label>
					<input id="confirm_delete" type="text" name="confirm_delete" value="">
				</p>
				<?php submit_button( __( 'Delete all collected data', 'web-vitals-monitor' ), 'delete' ); ?>
			</form>
		</section>
	</div>
</div>
