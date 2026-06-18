<?php
/**
 * GET /wp-json/imans-analytics/v1/products/stats
 *
 * Daily per-product / per-variation browsing counts computed on-the-fly
 * from the raw `wp_imans_events` table. Only the `view_item` and
 * `add_to_cart` event types are aggregated — these are the signals the
 * Imans backend needs to attach storefront traffic to individual
 * listings (CHN-3 low-conversion-listing insight).
 *
 * No new table, no schema migration: the existing
 * `idx_event_type (event_type, occurred_at)` index covers the scan.
 *
 * Response mirrors funnel/stats and sessions/stats — one interval per
 * (day × product × variation) with a `subtotals` block — so the backend
 * treats it as just another aggregate source. The extra `product_id` /
 * `variation_id` keys ride alongside `date_start` / `date_end`.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics\Rest;

use Imans\Analytics\Rest_Controller;
use Imans\Analytics\Schema;

defined( 'ABSPATH' ) || exit;

class Products_Stats_Endpoint {

	/**
	 * Event types this endpoint aggregates. Kept narrow on purpose so the
	 * `idx_event_type` index drives the scan and the response stays the
	 * per-listing traffic the backend importer expects.
	 */
	const AGGREGATED_EVENT_TYPES = array( 'view_item', 'add_to_cart' );

	public static function permission( $request ) {
		return Rest_Controller::read_permission( $request );
	}

	public static function handle( $request ) {
		global $wpdb;

		$after  = self::date_param( $request->get_param( 'after' ), '-30 days' );
		$before = self::date_param( $request->get_param( 'before' ), 'tomorrow' );
		$events = Schema::events_table();

		// Inclusive start-of-day, exclusive start of the `before` day, so a
		// caller passing YYYY-MM-DD gets whole-day buckets just like the
		// other read endpoints.
		$start = $after . ' 00:00:00';
		$end   = $before . ' 00:00:00';

		// Group by calendar day × product × variation, pivoting the two
		// tracked event types into their own counts in a single pass.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(occurred_at) AS stat_date,
				        product_id,
				        variation_id,
				        SUM(CASE WHEN event_type = 'view_item'   THEN 1 ELSE 0 END) AS view_item_count,
				        SUM(CASE WHEN event_type = 'add_to_cart' THEN 1 ELSE 0 END) AS add_to_cart_count
				   FROM {$events}
				  WHERE event_type IN ('view_item', 'add_to_cart')
				    AND occurred_at >= %s AND occurred_at < %s
				    AND product_id IS NOT NULL
				  GROUP BY stat_date, product_id, variation_id
				  ORDER BY stat_date ASC, product_id ASC, variation_id ASC",
				$start,
				$end
			), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		$intervals = array();
		foreach ( $rows as $row ) {
			$intervals[] = array(
				'date_start'   => $row['stat_date'] . ' 00:00:00',
				'date_end'     => $row['stat_date'] . ' 23:59:59',
				'product_id'   => (int) $row['product_id'],
				'variation_id' => null === $row['variation_id'] ? 0 : (int) $row['variation_id'],
				'subtotals'    => array(
					'view_item_count'   => (int) $row['view_item_count'],
					'add_to_cart_count' => (int) $row['add_to_cart_count'],
				),
			);
		}

		return new \WP_REST_Response( array( 'intervals' => $intervals ), 200 );
	}

	private static function date_param( $value, $default_relative ) {
		if ( ! $value ) {
			return gmdate( 'Y-m-d', strtotime( $default_relative ) );
		}
		return substr( (string) $value, 0, 10 );
	}
}
