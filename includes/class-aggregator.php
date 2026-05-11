<?php
/**
 * Hourly aggregator.
 *
 * Recomputes wp_imans_daily_stats rows for today and yesterday from
 * raw events + sessions. Full recompute is fine because a single day
 * fits comfortably in memory for any normal-volume WC store. Uses
 * INSERT … ON DUPLICATE KEY UPDATE so the operation is idempotent.
 *
 * Bucketing rule (per plan doc):
 *   - sessions: counted in the day the session STARTED
 *   - events: counted in the day they OCCURRED
 *
 * Triggered by the `imans_analytics_hourly_roll` cron event.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics;

defined( 'ABSPATH' ) || exit;

class Aggregator {

	public static function roll_hourly() {
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		self::recompute_day( $today );
		self::recompute_day( $yesterday );

		update_option( Activator::OPTION_LAST_AGGREGATION, gmdate( 'Y-m-d H:i:s' ) );
	}

	public static function recompute_day( $date ) {
		global $wpdb;

		$sessions_table     = Schema::sessions_table();
		$events_table       = Schema::events_table();
		$daily_stats_table  = Schema::daily_stats_table();

		$day_start = $date . ' 00:00:00';
		$day_end   = gmdate( 'Y-m-d H:i:s', strtotime( $date . ' 00:00:00 +1 day' ) );

		$session_aggregates = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*)                                   AS sessions,
					COUNT(DISTINCT visitor_id)                 AS unique_visitors,
					SUM(CASE WHEN purchase_order_id IS NOT NULL THEN 1 ELSE 0 END) AS sessions_with_purchase,
					SUM(CASE WHEN page_views_count <= 1 THEN 1 ELSE 0 END) AS bounce_count
				  FROM {$sessions_table}
				 WHERE started_at >= %s AND started_at < %s",
				$day_start,
				$day_end
			), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		$event_counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_type, COUNT(*) AS c
				   FROM {$events_table}
				  WHERE occurred_at >= %s AND occurred_at < %s
				  GROUP BY event_type",
				$day_start,
				$day_end
			), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		$by_type = array(
			'page_view'         => 0,
			'view_item'         => 0,
			'view_item_list'    => 0,
			'add_to_cart'       => 0,
			'remove_from_cart'  => 0,
			'begin_checkout'    => 0,
		);
		foreach ( $event_counts as $row ) {
			if ( isset( $by_type[ $row['event_type'] ] ) ) {
				$by_type[ $row['event_type'] ] = (int) $row['c'];
			}
		}

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"INSERT INTO {$daily_stats_table}
					(stat_date, sessions, sessions_with_purchase, unique_visitors,
					 bounce_count, page_views, view_item_count, view_item_list_count,
					 add_to_cart_count, remove_from_cart_count, begin_checkout_count,
					 updated_at)
				 VALUES (%s, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE
					sessions = VALUES(sessions),
					sessions_with_purchase = VALUES(sessions_with_purchase),
					unique_visitors = VALUES(unique_visitors),
					bounce_count = VALUES(bounce_count),
					page_views = VALUES(page_views),
					view_item_count = VALUES(view_item_count),
					view_item_list_count = VALUES(view_item_list_count),
					add_to_cart_count = VALUES(add_to_cart_count),
					remove_from_cart_count = VALUES(remove_from_cart_count),
					begin_checkout_count = VALUES(begin_checkout_count),
					updated_at = VALUES(updated_at)",
				$date,
				(int) ( $session_aggregates['sessions'] ?? 0 ),
				(int) ( $session_aggregates['sessions_with_purchase'] ?? 0 ),
				(int) ( $session_aggregates['unique_visitors'] ?? 0 ),
				(int) ( $session_aggregates['bounce_count'] ?? 0 ),
				$by_type['page_view'],
				$by_type['view_item'],
				$by_type['view_item_list'],
				$by_type['add_to_cart'],
				$by_type['remove_from_cart'],
				$by_type['begin_checkout'],
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}
}
