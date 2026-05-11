<?php
/**
 * GET /wp-json/imans-analytics/v1/funnel/stats
 *
 * Daily funnel-event counts from `wp_imans_daily_stats`.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics\Rest;

use Imans\Analytics\Rest_Controller;
use Imans\Analytics\Schema;

defined( 'ABSPATH' ) || exit;

class Funnel_Stats_Endpoint {

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
				"SELECT stat_date, view_item_count, view_item_list_count,
				        add_to_cart_count, remove_from_cart_count, begin_checkout_count
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
			$intervals[] = array(
				'date_start' => $row['stat_date'] . ' 00:00:00',
				'date_end'   => $row['stat_date'] . ' 23:59:59',
				'subtotals'  => array(
					'view_item_count'        => (int) $row['view_item_count'],
					'view_item_list_count'   => (int) $row['view_item_list_count'],
					'add_to_cart_count'      => (int) $row['add_to_cart_count'],
					'remove_from_cart_count' => (int) $row['remove_from_cart_count'],
					'begin_checkout_count'   => (int) $row['begin_checkout_count'],
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
