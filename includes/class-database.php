<?php
/**
 * Database access layer.
 *
 * Percentiles are computed from value histograms built in SQL, so dashboard
 * queries return a few hundred rows instead of every raw sample.
 *
 * @package Web_Vitals_Monitor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database service.
 */
final class Web_Vitals_Database {
	/**
	 * Histogram bucket sizes: [milliseconds, CLS units].
	 */
	private const BUCKETS_FINE   = array( 10, 0.001 );
	private const BUCKETS_COARSE = array( 25, 0.005 );

	/**
	 * Raw sample table name.
	 */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'web_vitals';
	}

	/**
	 * Daily aggregate table name.
	 */
	public function daily_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'web_vitals_daily';
	}

	/**
	 * Create database tables.
	 */
	public function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = $this->table();
		$daily_table     = $this->daily_table();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			metric_name VARCHAR(12) NOT NULL,
			metric_value DOUBLE NOT NULL DEFAULT 0,
			rating VARCHAR(24) NOT NULL DEFAULT '',
			metric_id VARCHAR(96) NOT NULL DEFAULT '',
			url_hash CHAR(64) NOT NULL DEFAULT '',
			page_path VARCHAR(512) NOT NULL DEFAULT '',
			page_type VARCHAR(64) NOT NULL DEFAULT 'other',
			device_type VARCHAR(24) NOT NULL DEFAULT 'unknown',
			browser VARCHAR(64) NOT NULL DEFAULT 'unknown',
			browser_version VARCHAR(32) NOT NULL DEFAULT '',
			os VARCHAR(64) NOT NULL DEFAULT 'unknown',
			connection_type VARCHAR(32) NOT NULL DEFAULT 'unknown',
			viewport_width INT UNSIGNED NOT NULL DEFAULT 0,
			viewport_height INT UNSIGNED NOT NULL DEFAULT 0,
			navigation_type VARCHAR(64) NOT NULL DEFAULT 'unknown',
			language VARCHAR(24) NOT NULL DEFAULT '',
			attribution_data LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY metric_uid (metric_id,metric_name),
			KEY created_at (created_at),
			KEY metric_created (metric_name,created_at),
			KEY url_created (url_hash,created_at)
		) {$charset_collate};";

		$daily_sql = "CREATE TABLE {$daily_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			summary_date DATE NOT NULL,
			metric_name VARCHAR(12) NOT NULL,
			page_path VARCHAR(512) NOT NULL DEFAULT '',
			device_type VARCHAR(24) NOT NULL DEFAULT 'all',
			p50 DOUBLE NOT NULL DEFAULT 0,
			p75 DOUBLE NOT NULL DEFAULT 0,
			p90 DOUBLE NOT NULL DEFAULT 0,
			good_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			ni_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			poor_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			sample_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY summary_key (summary_date,metric_name,page_path(190),device_type),
			KEY metric_date (metric_name,summary_date)
		) {$charset_collate};";

		dbDelta( $sql );
		dbDelta( $daily_sql );
	}

	/**
	 * Migrate an existing schema, then run dbDelta.
	 *
	 * @param string $from Previously installed DB version ('' for a fresh install).
	 */
	public function upgrade( string $from ): void {
		global $wpdb;

		$table = $this->table();

		// Detect the v1 schema directly: v1 uninstall removed wvm_db_version even when tables were kept.
		if ( ( '' === $from || version_compare( $from, '2', '<' ) ) && $this->table_exists() && ! $this->index_exists( 'metric_uid' ) ) {
			// v1 stored every report of a metric instance as a new row, so CLS/INP
			// were counted more than once per page view. Keep only the latest report.
			$wpdb->query( "UPDATE {$table} SET metric_id = CONCAT('legacy-', id) WHERE metric_id = ''" );

			if ( ! $this->index_exists( 'wvm_tmp_uid' ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD INDEX wvm_tmp_uid (metric_id, metric_name)" );
			}
			$wpdb->query(
				"DELETE older FROM {$table} older
				INNER JOIN {$table} newer
					ON older.metric_id = newer.metric_id AND older.metric_name = newer.metric_name AND older.id < newer.id"
			);

			// Single-column indexes on low-cardinality columns slowed every insert without helping any query.
			foreach ( array( 'wvm_tmp_uid', 'metric_name', 'url_hash', 'device_type', 'rating', 'url_metric' ) as $index ) {
				if ( $this->index_exists( $index ) ) {
					$wpdb->query( "ALTER TABLE {$table} DROP INDEX `{$index}`" );
				}
			}
		}

		$this->create_tables();
	}

	/**
	 * Insert metric samples. A repeated report of the same metric instance
	 * (same web-vitals id) updates the row instead of adding a new one.
	 *
	 * @param array<int,array<string,mixed>> $rows Validated rows.
	 * @return int Number of rows written.
	 */
	public function insert_metrics( array $rows ): int {
		global $wpdb;

		if ( empty( $rows ) ) {
			return 0;
		}

		$now          = current_time( 'mysql', true );
		$placeholders = array();
		$values       = array();

		foreach ( $rows as $data ) {
			$placeholders[] = '(%s,%f,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%s,%s,%s,%s)';
			array_push(
				$values,
				$data['metric_name'],
				$data['metric_value'],
				$data['rating'],
				$data['metric_id'],
				hash( 'sha256', $data['page_path'] ),
				$data['page_path'],
				$data['page_type'],
				$data['device_type'],
				$data['browser'],
				$data['browser_version'],
				$data['os'],
				$data['connection_type'],
				$data['viewport_width'],
				$data['viewport_height'],
				$data['navigation_type'],
				$data['language'],
				(string) $data['attribution_data'],
				$now
			);
		}

		$sql = "INSERT INTO {$this->table()}
			(metric_name, metric_value, rating, metric_id, url_hash, page_path, page_type, device_type, browser, browser_version, os, connection_type, viewport_width, viewport_height, navigation_type, language, attribution_data, created_at)
			VALUES " . implode( ',', $placeholders ) . '
			ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value), rating = VALUES(rating), attribution_data = VALUES(attribution_data)';

		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		return false === $result ? 0 : count( $rows );
	}

	/**
	 * Build a normalized filter set used by every read query.
	 *
	 * @param int    $days Date window.
	 * @param string $device Optional device type.
	 * @param string $path Optional exact page path.
	 * @return array<string,mixed>
	 */
	public function build_filters( int $days, string $device = '', string $path = '' ): array {
		$days = max( 1, min( 365, $days ) );

		return array(
			'days'     => $days,
			'since'    => gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) ),
			'until'    => '',
			'device'   => in_array( $device, array( 'mobile', 'tablet', 'desktop' ), true ) ? $device : '',
			'path'     => $path,
			'url_hash' => '' === $path ? '' : hash( 'sha256', $path ),
		);
	}

	/**
	 * Return dashboard summary.
	 *
	 * @param array<string,mixed> $filters Filters from build_filters().
	 * @return array<string,mixed>
	 */
	public function dashboard_data( array $filters ): array {
		$previous          = $filters;
		$previous['until'] = $filters['since'];
		$previous['since'] = gmdate( 'Y-m-d H:i:s', strtotime( $filters['since'] . ' UTC' ) - ( DAY_IN_SECONDS * $filters['days'] ) );

		return array(
			'overview'    => $this->metric_overview( $filters ),
			'previous'    => $this->metric_overview( $previous ),
			'trend'       => $this->trend_data( $filters ),
			'urls'        => '' === $filters['url_hash'] ? $this->top_urls( $filters ) : array(),
			'devices'     => $this->dimension_summary( 'device_type', $filters ),
			'browsers'    => $this->dimension_summary( 'browser', $filters ),
			'pageTypes'   => $this->dimension_summary( 'page_type', $filters ),
			'health'      => $this->collector_health(),
			'thresholds'  => Web_Vitals_Config::thresholds(),
			'filters'     => array(
				'days'   => $filters['days'],
				'device' => $filters['device'],
				'path'   => $filters['path'],
			),
			'generatedAt' => current_time( 'mysql', true ),
		);
	}

	/**
	 * Metric overview for cards.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array<int,array<string,mixed>>
	 */
	public function metric_overview( array $filters ): array {
		$histogram = $this->histogram( $filters, Web_Vitals_Config::metrics(), null, self::BUCKETS_FINE );
		$output    = array();

		foreach ( Web_Vitals_Config::metrics() as $metric ) {
			$output[] = array( 'metric' => $metric ) + self::stats( $metric, $histogram[''][ $metric ] ?? array() );
		}

		return $output;
	}

	/**
	 * Daily P75 trend for the Core Web Vitals. Completed days come from the
	 * aggregate table; only days without aggregates are computed from raw rows.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array<int,array<string,mixed>>
	 */
	public function trend_data( array $filters ): array {
		global $wpdb;

		$since_date = substr( $filters['since'], 0, 10 );
		$today      = gmdate( 'Y-m-d' );
		$points     = array();

		if ( '' === $filters['url_hash'] ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT summary_date, metric_name, p75, sample_count, good_count
					FROM {$this->daily_table()}
					WHERE page_path = '' AND device_type = %s AND summary_date >= %s AND summary_date < %s AND metric_name IN ('LCP','INP','CLS')",
					'' === $filters['device'] ? 'all' : $filters['device'],
					$since_date,
					$today
				),
				ARRAY_A
			);

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$count = (int) $row['sample_count'];
				$points[ $row['summary_date'] ][ $row['metric_name'] ] = array(
					'p75'     => (float) $row['p75'],
					'samples' => $count,
					'good'    => $count ? round( (int) $row['good_count'] / $count * 100, 1 ) : 0,
				);
			}
		}

		$first_missing = null;
		for ( $day = strtotime( $since_date . ' UTC' ); $day <= strtotime( $today . ' UTC' ); $day += DAY_IN_SECONDS ) {
			if ( ! isset( $points[ gmdate( 'Y-m-d', $day ) ] ) ) {
				$first_missing = gmdate( 'Y-m-d', $day );
				break;
			}
		}

		if ( null !== $first_missing ) {
			$raw_filters          = $filters;
			$raw_filters['since'] = max( $filters['since'], $first_missing . ' 00:00:00' );
			$histogram            = $this->histogram( $raw_filters, Web_Vitals_Config::core_metrics(), 'DATE(created_at)', self::BUCKETS_COARSE );

			foreach ( $histogram as $date => $metrics ) {
				if ( isset( $points[ $date ] ) ) {
					continue;
				}
				foreach ( $metrics as $metric => $buckets ) {
					$stats                       = self::stats( $metric, $buckets );
					$points[ $date ][ $metric ] = array(
						'p75'     => $stats['p75'],
						'samples' => $stats['samples'],
						'good'    => $stats['good'],
					);
				}
			}
		}

		ksort( $points );
		$output = array();
		foreach ( $points as $date => $metrics ) {
			$output[] = array( 'date' => (string) $date ) + $metrics;
		}

		return $output;
	}

	/**
	 * URLs with the most Poor Core Web Vitals samples, with per-metric P75.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array<int,array<string,mixed>>
	 */
	public function top_urls( array $filters, int $limit = 25 ): array {
		global $wpdb;

		list( $where, $args ) = $this->where( $filters );
		$args[]               = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url_hash, MAX(page_path) AS page_path, COUNT(*) AS samples,
					SUM(rating = 'poor') AS poor_count, SUM(rating <> 'good') AS slow_count
				FROM {$this->table()}
				WHERE {$where} AND metric_name IN ('LCP','INP','CLS')
				GROUP BY url_hash
				ORDER BY poor_count DESC, slow_count DESC, samples DESC
				LIMIT %d",
				$args
			),
			ARRAY_A
		);

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$hist_filters            = $filters;
		$hist_filters['in_urls'] = array_column( $rows, 'url_hash' );
		$histogram               = $this->histogram( $hist_filters, Web_Vitals_Config::core_metrics(), 'url_hash', self::BUCKETS_COARSE );

		$output = array();
		foreach ( $rows as $row ) {
			$item = array(
				'page_path'  => (string) $row['page_path'],
				'samples'    => (int) $row['samples'],
				'poor_count' => (int) $row['poor_count'],
			);
			$item += $this->core_p75( $histogram[ $row['url_hash'] ] ?? array() );
			$output[] = $item;
		}

		return $output;
	}

	/**
	 * P75 per Core Web Vital for one dimension (device, browser, page type).
	 *
	 * @param string              $dimension Safe column name.
	 * @param array<string,mixed> $filters Filters.
	 * @return array<int,array<string,mixed>>
	 */
	public function dimension_summary( string $dimension, array $filters, int $limit = 10 ): array {
		if ( ! in_array( $dimension, array( 'device_type', 'browser', 'page_type' ), true ) ) {
			return array();
		}

		$histogram = $this->histogram( $filters, Web_Vitals_Config::core_metrics(), $dimension, self::BUCKETS_COARSE );
		$output    = array();

		foreach ( $histogram as $label => $metrics ) {
			$output[] = array( 'label' => (string) $label ) + $this->core_p75( $metrics );
		}

		usort(
			$output,
			static function ( array $a, array $b ): int {
				return $b['samples'] <=> $a['samples'];
			}
		);

		return array_slice( $output, 0, $limit );
	}

	/**
	 * Recent samples at or above a value, with decoded attribution, for the advisor.
	 *
	 * @param string              $metric Metric name.
	 * @param array<string,mixed> $filters Filters.
	 * @param float               $min_value Lower bound (usually the P75).
	 * @param int                 $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function attribution_samples( string $metric, array $filters, float $min_value, int $limit = 1000 ): array {
		global $wpdb;

		if ( ! in_array( $metric, Web_Vitals_Config::metrics(), true ) ) {
			return array();
		}

		list( $where, $args ) = $this->where( $filters );
		array_push( $args, $metric, $min_value, $limit );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT metric_value, device_type, page_path, page_type, attribution_data
				FROM {$this->table()}
				WHERE {$where} AND metric_name = %s AND metric_value >= %f AND attribution_data <> ''
				ORDER BY created_at DESC
				LIMIT %d",
				$args
			),
			ARRAY_A
		);

		$output = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$attribution = json_decode( (string) $row['attribution_data'], true );
			if ( ! is_array( $attribution ) ) {
				continue;
			}
			$row['metric_value'] = (float) $row['metric_value'];
			$row['attribution']  = $attribution;
			unset( $row['attribution_data'] );
			$output[] = $row;
		}

		return $output;
	}

	/**
	 * Recent samples of one page (filters must contain url_hash), all metrics,
	 * with decoded attribution, for the page checklist.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array<int,array<string,mixed>>
	 */
	public function page_samples( array $filters, int $limit = 1500 ): array {
		global $wpdb;

		if ( empty( $filters['url_hash'] ) ) {
			return array();
		}

		list( $where, $args ) = $this->where( $filters );
		$args[]               = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT metric_name, metric_value, device_type, attribution_data, created_at
				FROM {$this->table()}
				WHERE {$where}
				ORDER BY created_at DESC
				LIMIT %d",
				$args
			),
			ARRAY_A
		);

		$output = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$attribution = '' === (string) $row['attribution_data'] ? array() : json_decode( (string) $row['attribution_data'], true );
			$output[]    = array(
				'metric' => (string) $row['metric_name'],
				'value'  => (float) $row['metric_value'],
				'device' => (string) $row['device_type'],
				'a'      => is_array( $attribution ) ? $attribution : array(),
				'time'   => (int) strtotime( $row['created_at'] . ' UTC' ),
			);
		}

		return $output;
	}

	/**
	 * Page-view count per navigation type (reload, back-forward-cache, prerender...).
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array<string,int>
	 */
	public function navigation_summary( array $filters ): array {
		global $wpdb;

		list( $where, $args ) = $this->where( $filters );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT metric_name, navigation_type, COUNT(*) AS samples
				FROM {$this->table()}
				WHERE {$where} AND metric_name IN ('LCP','FCP')
				GROUP BY metric_name, navigation_type",
				$args
			),
			ARRAY_A
		);

		$by_metric = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$by_metric[ $row['metric_name'] ][ (string) $row['navigation_type'] ] = (int) $row['samples'];
		}

		return $by_metric['LCP'] ?? $by_metric['FCP'] ?? array();
	}

	/**
	 * Health details.
	 *
	 * @return array<string,mixed>
	 */
	public function collector_health(): array {
		global $wpdb;

		$table = $this->table();
		$last  = $wpdb->get_var( "SELECT MAX(created_at) FROM {$table}" );
		$today = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
				gmdate( 'Y-m-d 00:00:00' )
			)
		);

		// COUNT(*) on a large InnoDB table is a full index scan; the estimate is enough here.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);

		return array(
			'collector'       => $last && strtotime( $last . ' UTC' ) > time() - DAY_IN_SECONDS ? 'active' : ( $last ? 'stale' : 'waiting' ),
			'last_received'   => $last,
			'samples_total'   => $total,
			'samples_today'   => $today,
			'rest_api'        => 'healthy',
			'database'        => $this->table_exists() ? 'healthy' : 'missing',
			'cron_scheduled'  => (bool) wp_next_scheduled( 'wvm_cleanup_event' ),
			'last_aggregated' => (string) get_option( 'wvm_last_aggregated', '' ),
		);
	}

	/**
	 * Check primary table existence.
	 */
	public function table_exists(): bool {
		global $wpdb;
		return $this->table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->table() ) ) );
	}

	/**
	 * Build daily aggregate rows (all devices + per device) for one UTC date.
	 *
	 * @param string $date Date in Y-m-d format.
	 */
	public function aggregate_day( string $date ): int {
		global $wpdb;

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return 0;
		}

		$filters = array(
			'since'    => $date . ' 00:00:00',
			'until'    => gmdate( 'Y-m-d 00:00:00', strtotime( $date . ' UTC' ) + DAY_IN_SECONDS ),
			'device'   => '',
			'url_hash' => '',
		);

		$by_device = $this->histogram( $filters, Web_Vitals_Config::metrics(), 'device_type', self::BUCKETS_FINE );
		$scopes    = array( 'all' => array() );

		foreach ( $by_device as $device => $metrics ) {
			foreach ( $metrics as $metric => $buckets ) {
				if ( in_array( $device, array( 'mobile', 'tablet', 'desktop' ), true ) ) {
					$scopes[ $device ][ $metric ] = $buckets;
				}
				$scopes['all'][ $metric ] = self::merge_buckets( $scopes['all'][ $metric ] ?? array(), $buckets );
			}
		}

		$written = 0;
		foreach ( $scopes as $device => $metrics ) {
			foreach ( $metrics as $metric => $buckets ) {
				$stats  = self::stats( $metric, $buckets );
				$result = $wpdb->replace(
					$this->daily_table(),
					array(
						'summary_date' => $date,
						'metric_name'  => $metric,
						'page_path'    => '',
						'device_type'  => $device,
						'p50'          => (float) $stats['median'],
						'p75'          => (float) $stats['p75'],
						'p90'          => (float) $stats['p90'],
						'good_count'   => $stats['counts']['good'],
						'ni_count'     => $stats['counts']['needs-improvement'],
						'poor_count'   => $stats['counts']['poor'],
						'sample_count' => $stats['samples'],
						'updated_at'   => current_time( 'mysql', true ),
					),
					array( '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%d', '%d', '%d', '%s' )
				);

				if ( false !== $result ) {
					++$written;
				}
			}
		}

		return $written;
	}

	/**
	 * Aggregate every completed day that has not been aggregated yet.
	 *
	 * @param int $max_days Upper bound per run.
	 */
	public function aggregate_pending( int $max_days = 31 ): int {
		$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$last      = (string) get_option( 'wvm_last_aggregated', '' );
		$start     = $last ? strtotime( $last . ' UTC' ) + DAY_IN_SECONDS : time() - ( 90 * DAY_IN_SECONDS );
		$done      = 0;

		for ( $day = $start; gmdate( 'Y-m-d', $day ) <= $yesterday && $done < $max_days; $day += DAY_IN_SECONDS ) {
			$date = gmdate( 'Y-m-d', $day );
			$this->aggregate_day( $date );
			update_option( 'wvm_last_aggregated', $date, false );
			++$done;
		}

		return $done;
	}

	/**
	 * Delete expired raw rows in batches until done or the time budget runs out.
	 */
	public function cleanup( int $days, int $limit, int $time_budget = 20 ): int {
		global $wpdb;

		if ( $days <= 0 ) {
			return 0;
		}

		$table   = $this->table();
		$before  = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
		$started = microtime( true );
		$total   = 0;

		do {
			$deleted = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s ORDER BY created_at ASC LIMIT %d", $before, $limit )
			);
			$total  += $deleted;
		} while ( $deleted >= $limit && ( microtime( true ) - $started ) < $time_budget );

		// Daily aggregates are tiny; keep them for long-term trends but not forever.
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$this->daily_table()} WHERE summary_date < %s", gmdate( 'Y-m-d', time() - ( DAY_IN_SECONDS * max( 730, $days ) ) ) )
		);

		return $total;
	}

	/**
	 * Delete all raw and aggregate data.
	 */
	public function delete_all(): int {
		global $wpdb;

		$raw   = (int) $wpdb->query( "TRUNCATE TABLE {$this->table()}" );
		$daily = (int) $wpdb->query( "TRUNCATE TABLE {$this->daily_table()}" );
		delete_option( 'wvm_last_aggregated' );

		return $raw + $daily;
	}

	/**
	 * Stream filtered rows to a callback in chunks, newest first.
	 *
	 * @param array<string,string> $filters Export filters.
	 * @param callable             $callback Receives each row array.
	 * @param int                  $max_rows Hard cap.
	 */
	public function export_rows( array $filters, callable $callback, int $max_rows = 200000 ): int {
		global $wpdb;

		$where = array();
		$args  = array();

		if ( ! empty( $filters['metric'] ) && in_array( $filters['metric'], Web_Vitals_Config::metrics(), true ) ) {
			$where[] = 'metric_name = %s';
			$args[]  = $filters['metric'];
		}

		if ( ! empty( $filters['device'] ) ) {
			$where[] = 'device_type = %s';
			$args[]  = sanitize_key( $filters['device'] );
		}

		if ( ! empty( $filters['browser'] ) ) {
			$where[] = 'browser = %s';
			$args[]  = sanitize_text_field( $filters['browser'] );
		}

		if ( ! empty( $filters['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['from'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $filters['from'] . ' 00:00:00';
		}

		if ( ! empty( $filters['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['to'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $filters['to'] . ' 23:59:59';
		}

		$last_id = PHP_INT_MAX;
		$sent    = 0;
		$chunk   = 5000;

		while ( $sent < $max_rows ) {
			$chunk_where = array_merge( $where, array( 'id < %d' ) );
			$chunk_args  = array_merge( $args, array( $last_id, min( $chunk, $max_rows - $sent ) ) );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, metric_name, metric_value, rating, metric_id, page_path, page_type, device_type, browser, browser_version, os, connection_type, viewport_width, viewport_height, navigation_type, language, attribution_data, created_at
					FROM {$this->table()}
					WHERE " . implode( ' AND ', $chunk_where ) . '
					ORDER BY id DESC
					LIMIT %d',
					$chunk_args
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];
				unset( $row['id'] );
				$callback( $row );
				++$sent;
			}

			if ( count( $rows ) < $chunk ) {
				break;
			}
		}

		return $sent;
	}

	/**
	 * Compute summary statistics from histogram buckets.
	 *
	 * @param string                           $metric Metric name.
	 * @param array<int,array<string,float>>   $buckets Buckets with value, n, total, good, ni, poor.
	 * @return array<string,mixed>
	 */
	public static function stats( string $metric, array $buckets ): array {
		usort(
			$buckets,
			static function ( array $a, array $b ): int {
				return $a['value'] <=> $b['value'];
			}
		);

		$count  = 0;
		$total  = 0.0;
		$counts = array(
			'good'              => 0,
			'needs-improvement' => 0,
			'poor'              => 0,
		);

		foreach ( $buckets as $bucket ) {
			$count                       += (int) $bucket['n'];
			$total                       += (float) $bucket['total'];
			$counts['good']              += (int) $bucket['good'];
			$counts['needs-improvement'] += (int) $bucket['ni'];
			$counts['poor']              += (int) $bucket['poor'];
		}

		$p75 = self::percentile( $buckets, $count, 75 );

		return array(
			'average'           => $count ? $total / $count : null,
			'median'            => self::percentile( $buckets, $count, 50 ),
			'p75'               => $p75,
			'p90'               => self::percentile( $buckets, $count, 90 ),
			'good'              => $count ? round( $counts['good'] / $count * 100, 1 ) : 0,
			'needs_improvement' => $count ? round( $counts['needs-improvement'] / $count * 100, 1 ) : 0,
			'poor'              => $count ? round( $counts['poor'] / $count * 100, 1 ) : 0,
			'counts'            => $counts,
			'samples'           => $count,
			'status'            => null === $p75 ? 'unknown' : Web_Vitals_Config::rating( $metric, $p75 ),
		);
	}

	/**
	 * Nearest-rank percentile over sorted buckets.
	 *
	 * @param array<int,array<string,float>> $buckets Sorted buckets.
	 */
	private static function percentile( array $buckets, int $count, int $percentile ): ?float {
		if ( 0 === $count ) {
			return null;
		}

		$rank       = max( 1, (int) ceil( $percentile / 100 * $count ) );
		$cumulative = 0;
		foreach ( $buckets as $bucket ) {
			$cumulative += (int) $bucket['n'];
			if ( $cumulative >= $rank ) {
				return (float) $bucket['value'];
			}
		}

		return (float) end( $buckets )['value'];
	}

	/**
	 * Merge two bucket lists.
	 *
	 * @param array<int,array<string,float>> $a Buckets.
	 * @param array<int,array<string,float>> $b Buckets.
	 * @return array<int,array<string,float>>
	 */
	private static function merge_buckets( array $a, array $b ): array {
		$merged = array();
		foreach ( array_merge( $a, $b ) as $bucket ) {
			$key = (string) $bucket['value'];
			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = $bucket;
				continue;
			}
			foreach ( array( 'n', 'total', 'good', 'ni', 'poor' ) as $field ) {
				$merged[ $key ][ $field ] += $bucket[ $field ];
			}
		}

		return array_values( $merged );
	}

	/**
	 * Compact P75/status/sample summary for the three Core Web Vitals.
	 *
	 * @param array<string,array<int,array<string,float>>> $metrics Buckets keyed by metric.
	 * @return array<string,mixed>
	 */
	private function core_p75( array $metrics ): array {
		$output  = array();
		$samples = 0;
		foreach ( Web_Vitals_Config::core_metrics() as $metric ) {
			$stats             = self::stats( $metric, $metrics[ $metric ] ?? array() );
			$output[ $metric ] = array(
				'p75'     => $stats['p75'],
				'status'  => $stats['status'],
				'samples' => $stats['samples'],
			);
			$samples           = max( $samples, $stats['samples'] );
		}
		$output['samples'] = $samples;

		return $output;
	}

	/**
	 * Value histogram grouped by an optional dimension and metric.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param string[]            $metrics Metric names (whitelisted).
	 * @param string|null         $group Safe SQL group expression.
	 * @param array{0:int,1:float} $sizes Bucket sizes.
	 * @return array<string,array<string,array<int,array<string,float>>>>
	 */
	private function histogram( array $filters, array $metrics, ?string $group, array $sizes ): array {
		global $wpdb;

		$metrics = array_values( array_intersect( $metrics, Web_Vitals_Config::metrics() ) );
		if ( ! $metrics ) {
			return array();
		}

		list( $where, $args ) = $this->where( $filters );
		$group_select         = $group ? $group : "''";
		$group_by             = $group ? $group . ', ' : '';
		$in                   = implode( ',', array_fill( 0, count( $metrics ), '%s' ) );
		$args                 = array_merge( array( $sizes[1], $sizes[1], $sizes[0], $sizes[0] ), $args, $metrics );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$group_select} AS grp, metric_name,
					CASE WHEN metric_name = 'CLS' THEN ROUND(metric_value / %f) * %f ELSE ROUND(metric_value / %f) * %f END AS bucket,
					COUNT(*) AS n, SUM(metric_value) AS total,
					SUM(rating = 'good') AS good, SUM(rating = 'needs-improvement') AS ni, SUM(rating = 'poor') AS poor
				FROM {$this->table()}
				WHERE {$where} AND metric_name IN ({$in})
				GROUP BY {$group_by}metric_name, bucket",
				$args
			),
			ARRAY_A
		);

		$output = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$output[ (string) $row['grp'] ][ $row['metric_name'] ][] = array(
				'value' => round( (float) $row['bucket'], 'CLS' === $row['metric_name'] ? 4 : 0 ),
				'n'     => (int) $row['n'],
				'total' => (float) $row['total'],
				'good'  => (int) $row['good'],
				'ni'    => (int) $row['ni'],
				'poor'  => (int) $row['poor'],
			);
		}

		return $output;
	}

	/**
	 * WHERE clause and arguments for a filter set.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function where( array $filters ): array {
		$sql  = array( 'created_at >= %s' );
		$args = array( $filters['since'] );

		if ( ! empty( $filters['until'] ) ) {
			$sql[]  = 'created_at < %s';
			$args[] = $filters['until'];
		}

		if ( ! empty( $filters['device'] ) ) {
			$sql[]  = 'device_type = %s';
			$args[] = $filters['device'];
		}

		if ( ! empty( $filters['url_hash'] ) ) {
			$sql[]  = 'url_hash = %s';
			$args[] = $filters['url_hash'];
		}

		if ( ! empty( $filters['in_urls'] ) ) {
			$sql[] = 'url_hash IN (' . implode( ',', array_fill( 0, count( $filters['in_urls'] ), '%s' ) ) . ')';
			$args  = array_merge( $args, array_values( $filters['in_urls'] ) );
		}

		return array( implode( ' AND ', $sql ), $args );
	}

	/**
	 * Check whether an index exists on the raw table.
	 */
	private function index_exists( string $name ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$this->table()} WHERE Key_name = %s", $name ) );
	}
}
