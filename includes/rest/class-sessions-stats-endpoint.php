<?php
/**
 * GET /wp-json/imans-analytics/v1/sessions/stats
 *
 * Returns daily session aggregates in a shape that mirrors
 * `wc-analytics/reports/revenue/stats` so the Imans backend merger
 * can use the same parsing path.
 *
 * Phase C will fill the actual query against `wp_imans_daily_stats`.
 * For now this is a stub returning empty intervals so the backend
 * probe + merge path can be wired end-to-end against a freshly
 * installed plugin.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics\Rest;

use Imans\Analytics\Rest_Controller;
use Imans\Analytics\Schema;

defined( 'ABSPATH' ) || exit;

class Sessions_Stats_Endpoint {

	public static function permission( $request ) {
		return Rest_Controller::read_permission( $request );
	}

	public static function handle( $request ) {
		global $wpdb;

		$after  = self::date_param( $request->get_param( 'after' ), '-30 days' );
		$before = self::date_param( $request->get_param( 'before' ), 'tomorrow' );
		$table  = Schema::daily_stats_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_date, sessions, sessions_with_purchase, unique_visitors,
				        bounce_count, page_views, add_to_cart_count
				   FROM {$table}
				  WHERE stat_date >= %s AND stat_date < %s
				  ORDER BY stat_date ASC",
				$after,
				$before
			), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		$intervals = array();
		foreach ( $rows as $row ) {
			$sessions = (int) $row['sessions'];
			$sessions_with_purchase = (int) $row['sessions_with_purchase'];
			$bounce_count = (int) $row['bounce_count'];
			$add_to_cart_count = (int) $row['add_to_cart_count'];

			$bounce_rate = $sessions > 0 ? round( $bounce_count / $sessions, 4 ) : 0;
			$conv_rate = $sessions > 0 ? round( $sessions_with_purchase / $sessions, 4 ) : 0;
			$abandon_denom = max( $add_to_cart_count, 1 );
			$abandon_rate = round( ( $add_to_cart_count - $sessions_with_purchase ) / $abandon_denom, 4 );
			if ( $abandon_rate < 0 ) {
				$abandon_rate = 0;
			}

			$intervals[] = array(
				'date_start' => $row['stat_date'] . ' 00:00:00',
				'date_end'   => $row['stat_date'] . ' 23:59:59',
				'subtotals'  => array(
					'sessions'                => $sessions,
					'sessions_with_purchase'  => $sessions_with_purchase,
					'unique_visitors'         => (int) $row['unique_visitors'],
					'bounce_rate'             => $bounce_rate,
					'conversion_rate'         => $conv_rate,
					'cart_abandonment_rate'   => $abandon_rate,
					'page_views'              => (int) $row['page_views'],
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
