<?php
/**
 * GET /wp-json/imans-analytics/v1/search-terms
 *
 * Daily search-term aggregates from `wp_imans_search_terms`.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics\Rest;

use Imans\Analytics\Rest_Controller;
use Imans\Analytics\Schema;

defined( 'ABSPATH' ) || exit;

class Search_Terms_Endpoint {

	public static function permission( $request ) {
		return Rest_Controller::read_permission( $request );
	}

	public static function handle( $request ) {
		global $wpdb;

		$after  = self::date_param( $request->get_param( 'after' ), '-30 days' );
		$before = self::date_param( $request->get_param( 'before' ), 'tomorrow' );
		$table  = Schema::search_terms_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_date, term, count, conversion_count, no_result_count
				   FROM {$table}
				  WHERE stat_date >= %s AND stat_date < %s
				  ORDER BY stat_date ASC, count DESC",
				$after,
				$before
			), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		$terms = array();
		foreach ( $rows as $row ) {
			$terms[] = array(
				'stat_date'        => $row['stat_date'],
				'term'             => $row['term'],
				'count'            => (int) $row['count'],
				'conversion_count' => (int) $row['conversion_count'],
				'no_result_count'  => (int) $row['no_result_count'],
			);
		}

		return new \WP_REST_Response( array( 'terms' => $terms ), 200 );
	}

	private static function date_param( $value, $default_relative ) {
		if ( ! $value ) {
			return gmdate( 'Y-m-d', strtotime( $default_relative ) );
		}
		return substr( (string) $value, 0, 10 );
	}
}
