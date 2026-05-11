<?php
/**
 * GET /wp-json/imans-analytics/v1/health
 *
 * Plugin presence probe for the Imans backend. Returns plugin version,
 * schema version, tracking state, last aggregation timestamp, retention
 * setting, and row counts for the 3 main tables.
 *
 * Auth: WooCommerce consumer_key/secret via HTTP Basic Auth (same as
 * wc-analytics). The shared read_permission delegates to WP's
 * `current_user_can` after WC's REST auth has run.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics\Rest;

use Imans\Analytics\Activator;
use Imans\Analytics\Rest_Controller;
use Imans\Analytics\Schema;

defined( 'ABSPATH' ) || exit;

class Health_Endpoint {

	public static function permission( $request ) {
		return Rest_Controller::read_permission( $request );
	}

	public static function handle( $request ) {
		global $wpdb;

		$sessions_table     = Schema::sessions_table();
		$events_table       = Schema::events_table();
		$daily_stats_table  = Schema::daily_stats_table();

		$counts = array(
			'sessions'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sessions_table}" ), // phpcs:ignore WordPress.DB
			'events'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" ),   // phpcs:ignore WordPress.DB
			'daily_stats' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$daily_stats_table}" ), // phpcs:ignore WordPress.DB
		);

		$payload = array(
			'plugin_version'        => IMANS_ANALYTICS_VERSION,
			'schema_version'        => IMANS_ANALYTICS_SCHEMA_VERSION,
			'tracking_enabled'      => '1' === (string) get_option( Activator::OPTION_TRACKING_ENABLED, '0' ),
			'last_aggregation_at'   => get_option( Activator::OPTION_LAST_AGGREGATION, '' ) ?: null,
			'retention_days'        => (int) get_option( Activator::OPTION_RETENTION_DAYS, 30 ),
			'sample_rate'           => (int) get_option( Activator::OPTION_SAMPLE_RATE, 100 ),
			'row_counts'            => $counts,
		);

		return new \WP_REST_Response( $payload, 200 );
	}
}
