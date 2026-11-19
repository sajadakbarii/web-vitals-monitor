<?php
/**
 * Dashboard template. Static structure and copy; admin.js fills the data.
 *
 * @package Web_Vitals_Monitor
 */

defined( 'ABSPATH' ) || exit;

$wvm_dir     = $suggestions_rtl ? 'rtl' : 'ltr';
$wvm_lang    = $suggestions_rtl ? 'fa' : '';
$wvm_loading = '<div class="wvm-stack" aria-busy="true"><span class="wvm-skeleton" style="width:60%"></span><span class="wvm-skeleton" style="width:90%"></span><span class="wvm-skeleton" style="width:40%"></span></div>';

$wvm_dimensions = array(
	'wvm-device-table'   => array( __( 'Devices', 'web-vitals-monitor' ), __( 'Device', 'web-vitals-monitor' ) ),
	'wvm-browser-table'  => array( __( 'Browsers', 'web-vitals-monitor' ), __( 'Browser', 'web-vitals-monitor' ) ),
	'wvm-pagetype-table' => array( __( 'Page types', 'web-vitals-monitor' ), __( 'Type', 'web-vitals-monitor' ) ),
);
?>

<div class="wrap wvm-root">
<div class="wvm-dash">

	<header class="wvm-dash-head">
		<div class="wvm-title-row">
			<div>
				<h1 class="wvm-title"><?php esc_html_e( 'Real User Web Vitals', 'web-vitals-monitor' ); ?></h1>
				<p class="wvm-caption"><?php esc_html_e( 'Field data from real visitors, rated at the 75th percentile like Google Search Console. Not lab data.', 'web-vitals-monitor' ); ?></p>
			</div>
			<a class="wvm-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=wvm-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'web-vitals-monitor' ); ?></a>
		</div>

		<div class="wvm-filterbar">
			<span class="wvm-filter-group">
				<span class="wvm-label" id="wvm-range-label"><?php esc_html_e( 'Range', 'web-vitals-monitor' ); ?></span>
				<span class="wvm-segmented" role="group" aria-labelledby="wvm-range-label" id="wvm-range">
					<button type="button" data-days="1" aria-pressed="false"><?php esc_html_e( '24 h', 'web-vitals-monitor' ); ?></button>
					<button type="button" data-days="7" aria-pressed="true"><?php esc_html_e( '7 d', 'web-vitals-monitor' ); ?></button>
					<button type="button" data-days="28" aria-pressed="false"><?php esc_html_e( '28 d', 'web-vitals-monitor' ); ?></button>
					<button type="button" data-days="90" aria-pressed="false"><?php esc_html_e( '90 d', 'web-vitals-monitor' ); ?></button>
				</span>
			</span>
			<span class="wvm-filter-group">
				<span class="wvm-label" id="wvm-device-label"><?php esc_html_e( 'Device', 'web-vitals-monitor' ); ?></span>
				<span class="wvm-segmented" role="group" aria-labelledby="wvm-device-label" id="wvm-device">
					<button type="button" data-device="" aria-pressed="true"><?php esc_html_e( 'All', 'web-vitals-monitor' ); ?></button>
					<button type="button" data-device="mobile" aria-pressed="false"><?php esc_html_e( 'Mobile', 'web-vitals-monitor' ); ?></button>
					<button type="button" data-device="tablet" aria-pressed="false"><?php esc_html_e( 'Tablet', 'web-vitals-monitor' ); ?></button>
					<button type="button" data-device="desktop" aria-pressed="false"><?php esc_html_e( 'Desktop', 'web-vitals-monitor' ); ?></button>
				</span>
			</span>
			<span class="wvm-filterbar-spacer"></span>
			<span class="wvm-updated" id="wvm-updated" aria-live="polite"></span>
			<label class="wvm-toggle"><input type="checkbox" id="wvm-live"> <?php esc_html_e( 'Auto-refresh', 'web-vitals-monitor' ); ?></label>
			<button type="button" class="wvm-btn" id="wvm-refresh"><?php esc_html_e( 'Refresh', 'web-vitals-monitor' ); ?></button>
		</div>

		<div class="wvm-filter" id="wvm-filter" hidden></div>
	</header>

	<section class="wvm-assess is-pending" id="wvm-assessment" aria-live="polite">
		<p class="wvm-assess-status"><span class="wvm-shape wvm-shape--unknown"></span><?php esc_html_e( 'Loading field data…', 'web-vitals-monitor' ); ?></p>
	</section>

	<div class="wvm-grid12" id="wvm-overview"><div class="wvm-panel wvm-span12"><?php echo $wvm_loading; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup. ?></div></div>

	<div class="wvm-grid12" id="wvm-phases" hidden></div>

	<section class="wvm-panel" dir="<?php echo esc_attr( $wvm_dir ); ?>" lang="<?php echo esc_attr( $wvm_lang ); ?>" id="wvm-pages-panel">
		<div class="wvm-panel-head">
			<h2 class="wvm-panel-title" id="wvm-pages-title"><?php echo esc_html( $suggestions_text['pagesTitle'] ); ?></h2>
			<p class="wvm-panel-sub" id="wvm-pages-sub"><?php echo esc_html( $suggestions_text['pagesIntro'] ); ?></p>
			<span id="wvm-pages-progress" hidden></span>
		</div>
		<div id="wvm-pages"><?php echo $wvm_loading; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup. ?></div>
	</section>

	<section class="wvm-panel" dir="<?php echo esc_attr( $wvm_dir ); ?>" lang="<?php echo esc_attr( $wvm_lang ); ?>" id="wvm-suggestions-panel">
		<div class="wvm-panel-head">
			<h2 class="wvm-panel-title"><?php echo esc_html( $suggestions_text['title'] ); ?></h2>
			<p class="wvm-panel-sub"><?php echo esc_html( $suggestions_text['intro'] ); ?></p>
		</div>
		<div id="wvm-suggestions"><?php echo $wvm_loading; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup. ?></div>
	</section>

	<div class="wvm-grid12">
		<section class="wvm-panel wvm-span8">
			<div class="wvm-panel-head">
				<h2 class="wvm-panel-title"><?php esc_html_e( 'Performance over time', 'web-vitals-monitor' ); ?></h2>
				<p class="wvm-panel-sub"><?php esc_html_e( 'Daily P75 over the Good (green) and Poor (red) zones', 'web-vitals-monitor' ); ?></p>
			</div>
			<div class="wvm-trend" id="wvm-trend"></div>
		</section>
		<section class="wvm-panel wvm-span4 wvm-side">
			<div class="wvm-panel-head">
				<h2 class="wvm-panel-title"><?php esc_html_e( 'Collector health', 'web-vitals-monitor' ); ?></h2>
			</div>
			<dl class="wvm-health" id="wvm-health"></dl>
		</section>
	</div>

	<section class="wvm-panel" id="wvm-url-panel">
		<div class="wvm-panel-head">
			<h2 class="wvm-panel-title"><?php esc_html_e( 'Top problematic URLs', 'web-vitals-monitor' ); ?></h2>
			<p class="wvm-panel-sub"><?php esc_html_e( 'Sorted by Poor samples. Select a URL to filter the whole dashboard to it.', 'web-vitals-monitor' ); ?></p>
		</div>
		<div class="wvm-table-wrap">
			<table class="wvm-table" id="wvm-url-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'URL', 'web-vitals-monitor' ); ?></th>
						<th class="is-num">LCP</th>
						<th class="is-num">INP</th>
						<th class="is-num">CLS</th>
						<th class="is-num"><?php esc_html_e( 'Samples', 'web-vitals-monitor' ); ?></th>
						<th class="is-num"><?php esc_html_e( 'Poor', 'web-vitals-monitor' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
	</section>

	<div class="wvm-grid12">
		<?php foreach ( $wvm_dimensions as $wvm_table_id => $wvm_labels ) : ?>
			<section class="wvm-panel wvm-span4">
				<div class="wvm-panel-head"><h2 class="wvm-panel-title"><?php echo esc_html( $wvm_labels[0] ); ?></h2></div>
				<div class="wvm-table-wrap">
					<table class="wvm-table wvm-small-table" id="<?php echo esc_attr( $wvm_table_id ); ?>">
						<thead>
							<tr>
								<th><?php echo esc_html( $wvm_labels[1] ); ?></th>
								<th class="is-num">LCP</th>
								<th class="is-num">INP</th>
								<th class="is-num">CLS</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</section>
		<?php endforeach; ?>
	</div>

</div>
</div>
