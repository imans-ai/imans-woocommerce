<?php
/**
 * Daily purger.
 *
 * Two responsibilities, in order:
 *   1. Aggregate the day's search events into wp_imans_search_terms
 *      before raw events get aged out.
 *   2. Delete wp_imans_events rows older than `retention_days` (default 30).
 *
 * Sessions are kept indefinitely so historical analyses can join back
 * to attribution; only the hot events table is aged.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics;

defined( 'ABSPATH' ) || exit;

class Purger {

	public static function run_daily() {
		self::aggregate_search_terms();
		self::purge_old_events();
	}

	private static function aggregate_search_terms() {
		global $wpdb;

		$events_table = Schema::events_table();
		$session_table = Schema::sessions_table();
		$search_table = Schema::search_terms_table();

		$retention_days = max( 1, (int) get_option( Activator::OPTION_RETENTION_DAYS, 30 ) );
		// Aggregate all retained search events on every run; the upsert
		// is idempotent so re-aggregation is safe and cheap.
		$since = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . $retention_days . ' days' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DATE(e.occurred_at) AS stat_date,
					e.search_term AS term,
					COUNT(*) AS c,
					SUM(CASE WHEN s.purchase_order_id IS NOT NULL THEN 1 ELSE 0 END) AS conversion_count
				  FROM {$events_table} e
				  LEFT JOIN {$session_table} s ON s.session_id = e.session_id
				 WHERE e.event_type = 'search'
				   AND e.occurred_at >= %s
				   AND e.search_term IS NOT NULL
				   AND e.search_term <> ''
				 GROUP BY DATE(e.occurred_at), e.search_term",
				$since
			), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"INSERT INTO {$search_table} (stat_date, term, count, conversion_count, no_result_count)
					 VALUES (%s, %s, %d, %d, 0)
					 ON DUPLICATE KEY UPDATE count = VALUES(count), conversion_count = VALUES(conversion_count)",
					$row['stat_date'],
					$row['term'],
					(int) $row['c'],
					(int) $row['conversion_count']
				)
			);
		}
	}

	private static function purge_old_events() {
		global $wpdb;
		$events_table = Schema::events_table();
		$retention_days = max( 1, (int) get_option( Activator::OPTION_RETENTION_DAYS, 30 ) );
		$cutoff = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . $retention_days . ' days' ) );

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"DELETE FROM {$events_table} WHERE occurred_at < %s",
				$cutoff
			)
		);
	}
}
