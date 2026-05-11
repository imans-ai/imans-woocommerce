<?php
/**
 * REST route registration.
 *
 * All `/wp-json/imans-analytics/v1/*` routes register here. The actual
 * handlers live in includes/rest/ — one class per endpoint to keep the
 * permission and validation logic close to the handler.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics;

defined( 'ABSPATH' ) || exit;

class Rest_Controller {

	public static function register_routes() {
		$ns = IMANS_ANALYTICS_REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( '\Imans\Analytics\Rest\Track_Endpoint', 'handle' ),
				'permission_callback' => array( '\Imans\Analytics\Rest\Track_Endpoint', 'permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( '\Imans\Analytics\Rest\Health_Endpoint', 'handle' ),
				'permission_callback' => array( '\Imans\Analytics\Rest\Health_Endpoint', 'permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/sessions/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( '\Imans\Analytics\Rest\Sessions_Stats_Endpoint', 'handle' ),
				'permission_callback' => array( '\Imans\Analytics\Rest\Sessions_Stats_Endpoint', 'permission' ),
				'args'                => self::date_window_args(),
			)
		);

		register_rest_route(
			$ns,
			'/funnel/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( '\Imans\Analytics\Rest\Funnel_Stats_Endpoint', 'handle' ),
				'permission_callback' => array( '\Imans\Analytics\Rest\Funnel_Stats_Endpoint', 'permission' ),
				'args'                => self::date_window_args(),
			)
		);

		register_rest_route(
			$ns,
			'/search-terms',
			array(
				'methods'             => 'GET',
				'callback'            => array( '\Imans\Analytics\Rest\Search_Terms_Endpoint', 'handle' ),
				'permission_callback' => array( '\Imans\Analytics\Rest\Search_Terms_Endpoint', 'permission' ),
				'args'                => self::date_window_args(),
			)
		);
	}

	/**
	 * Standard date-window args for read endpoints. Accepts YYYY-MM-DD
	 * (date-only) which mirrors wc-analytics behaviour and what the
	 * backend pull supplies.
	 */
	public static function date_window_args() {
		return array(
			'after'  => array(
				'description'       => 'Inclusive start date (YYYY-MM-DD).',
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( __CLASS__, 'validate_date' ),
			),
			'before' => array(
				'description'       => 'Exclusive end date (YYYY-MM-DD).',
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( __CLASS__, 'validate_date' ),
			),
		);
	}

	public static function validate_date( $value ) {
		if ( '' === $value || null === $value ) {
			return true;
		}
		$value = (string) $value;
		// Accept full ISO-8601 datetimes too (`YYYY-MM-DDTHH:MM:SS`) by
		// taking just the date portion before validation.
		$date = substr( $value, 0, 10 );
		$ts = strtotime( $date );
		if ( false === $ts ) {
			return new \WP_Error( 'invalid_date', 'Date must be YYYY-MM-DD.' );
		}
		return true;
	}

	/**
	 * Shared permission for read endpoints: WC Basic Auth.
	 *
	 * WooCommerce's REST auth handler (`WC_REST_Authentication`) inspects
	 * the request and sets `current_user_id` if Basic Auth credentials
	 * map to a valid API key with read scope. We accept the request iff
	 * a user has been authenticated AND has `read_private_shop_orders`
	 * (which `read` scope WC keys carry).
	 *
	 * Same auth contract as wc-analytics — so the Imans backend reuses
	 * the consumer_key/secret already on IntegrationCredential.
	 */
	public static function read_permission( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_forbidden',
				'WooCommerce consumer_key/secret required.',
				array( 'status' => 401 )
			);
		}
		if ( ! current_user_can( 'read_private_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'WooCommerce read scope required.',
				array( 'status' => 403 )
			);
		}
		return true;
	}
}
