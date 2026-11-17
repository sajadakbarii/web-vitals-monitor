<?php
/**
 * Dashboard template.
 *
 * @package Web_Vitals_Monitor
 */

defined( 'ABSPATH' ) || exit;

$wvm_dimension_tables = array(
	'wvm-device-table'   => __( 'Device', 'web-vitals-monitor' ),
	'wvm-browser-table'  => __( 'Browser', 'web-vitals-monitor' ),
	'wvm-pagetype-table' => __( 'Page type', 'web-vitals-monitor' ),
);
?>

<div class="wrap wvm-wrap">
	<div class="wvm-header">
		<div>
			<h1><?php esc_html_e( 'Real User Web Vitals', 'web-vitals-monitor' ); ?></h1>
			<p><?php esc_html_e( 'Field data collected from real visitors (not PageSpeed Insights lab data). Ratings use the 75th percentile, like Google Search Console.', 'web-vitals-monitor' ); ?></p>
		</div>
		<div class="wvm-toolbar">
			<label for="wvm-range"><?php esc_html_e( 'Range', 'web-vitals-monitor' ); ?></label>
			<select id="wvm-range">
				<option value="1"><?php esc_html_e( '24 hours', 'web-vitals-monitor' ); ?></option>
				<option value="7" selected><?php esc_html_e( '7 days', 'web-vitals-monitor' ); ?></option>
				<option value="28"><?php esc_html_e( '28 days', 'web-vitals-monitor' ); ?></option>
				<option value="90"><?php esc_html_e( '90 days', 'web-vitals-monitor' ); ?></option>
			</select>
			<label for="wvm-device"><?php esc_html_e( 'Device', 'web-vitals-monitor' ); ?></label>
			<select id="wvm-device">
				<option value=""><?php esc_html_e( 'All devices', 'web-vitals-monitor' ); ?></option>
				<option value="mobile"><?php esc_html_e( 'Mobile', 'web-vitals-monitor' ); ?></option>
				<option value="tablet"><?php esc_html_e( 'Tablet', 'web-vitals-monitor' ); ?></option>
				<option value="desktop"><?php esc_html_e( 'Desktop', 'web-vitals-monitor' ); ?></option>
			</select>
			<button type="button" class="button" id="wvm-refresh"><?php esc_html_e( 'Refresh', 'web-vitals-monitor' ); ?></button>
			<label class="wvm-inline"><input type="checkbox" id="wvm-live"> <?php esc_html_e( 'Auto-refresh', 'web-vitals-monitor' ); ?></label>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wvm-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'web-vitals-monitor' ); ?></a>
		</div>
	</div>

	<?php
	$wvm_settings = get_option( 'wvm_settings', array() );
	if ( is_array( $wvm_settings ) && ! empty( $wvm_settings['exclude_admins'] ) ) :
		?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'Administrator visits are excluded, so browsing the site as an admin will not create Web Vitals rows. Test in a logged-out or private browser window.', 'web-vitals-monitor' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="wvm-filter" id="wvm-filter" hidden></div>

	<div class="wvm-assessment" id="wvm-assessment" aria-live="polite"></div>

	<div class="wvm-grid" id="wvm-overview" aria-live="polite"></div>

	<section class="wvm-panel wvm-suggestions-panel" dir="<?php echo $suggestions_rtl ? 'rtl' : 'ltr'; ?>">
		<div class="wvm-panel-head">
			<h2><?php echo esc_html( $suggestions_text['title'] ); ?></h2>
			<span class="wvm-muted" id="wvm-updated"></span>
		</div>
		<p class="wvm-muted"><?php echo esc_html( $suggestions_text['intro'] ); ?></p>
		<div id="wvm-suggestions"></div>
	</section>

	<section class="wvm-panel wvm-pages-panel" dir="<?php echo $suggestions_rtl ? 'rtl' : 'ltr'; ?>">
		<div class="wvm-panel-head">
			<h2><?php echo esc_html( $suggestions_text['pagesTitle'] ); ?></h2>
		</div>
		<p class="wvm-muted"><?php echo esc_html( $suggestions_text['pagesIntro'] ); ?></p>
		<div id="wvm-pages"></div>
	</section>

	<div class="wvm-layout">
		<section class="wvm-panel">
			<h2><?php esc_html_e( 'Performance Over Time', 'web-vitals-monitor' ); ?></h2>
			<p class="wvm-muted" id="wvm-trend-note"></p>
			<div class="wvm-trend" id="wvm-trend"></div>
		</section>

		<section class="wvm-panel">
			<h2><?php esc_html_e( 'Performance Health', 'web-vitals-monitor' ); ?></h2>
			<div class="wvm-score" id="wvm-score">–</div>
			<p class="wvm-muted"><?php esc_html_e( 'Share of Good experiences across LCP, INP and CLS (Needs Improvement counts half).', 'web-vitals-monitor' ); ?></p>
			<div id="wvm-alerts"></div>
		</section>
	</div>

	<div class="wvm-layout" id="wvm-url-layout">
		<section class="wvm-panel">
			<h2><?php esc_html_e( 'Top Problematic URLs', 'web-vitals-monitor' ); ?></h2>
			<p class="wvm-muted"><?php esc_html_e( 'Sorted by number of Poor samples. Click a URL to filter the whole dashboard and get suggestions for that page.', 'web-vitals-monitor' ); ?></p>
			<div class="wvm-table-wrap">
				<table class="wvm-table" id="wvm-url-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'URL', 'web-vitals-monitor' ); ?></th>
							<th><?php esc_html_e( 'LCP', 'web-vitals-monitor' ); ?></th>
							<th><?php esc_html_e( 'INP', 'web-vitals-monitor' ); ?></th>
							<th><?php esc_html_e( 'CLS', 'web-vitals-monitor' ); ?></th>
							<th><?php esc_html_e( 'Samples', 'web-vitals-monitor' ); ?></th>
							<th><?php esc_html_e( 'Poor', 'web-vitals-monitor' ); ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</section>

		<section class="wvm-panel">
			<h2><?php esc_html_e( 'Collector Health', 'web-vitals-monitor' ); ?></h2>
			<div id="wvm-health"></div>
		</section>
	</div>

	<div class="wvm-columns">
		<?php foreach ( $wvm_dimension_tables as $wvm_table_id => $wvm_label ) : ?>
			<section class="wvm-panel">
				<h2>
					<?php
					/* translators: %s: dimension such as Device or Browser. */
					echo esc_html( sprintf( __( '%s Analysis', 'web-vitals-monitor' ), $wvm_label ) );
					?>
				</h2>
				<div class="wvm-table-wrap">
					<table class="wvm-table" id="<?php echo esc_attr( $wvm_table_id ); ?>">
						<thead>
							<tr>
								<th><?php echo esc_html( $wvm_label ); ?></th>
								<th><?php esc_html_e( 'LCP', 'web-vitals-monitor' ); ?></th>
								<th><?php esc_html_e( 'INP', 'web-vitals-monitor' ); ?></th>
								<th><?php esc_html_e( 'CLS', 'web-vitals-monitor' ); ?></th>
								<th><?php esc_html_e( 'Samples', 'web-vitals-monitor' ); ?></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</section>
		<?php endforeach; ?>
	</div>
</div>
