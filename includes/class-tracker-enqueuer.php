<?php
/**
 * Front-end tracker enqueuer.
 *
 * Decides whether to load the tracker on the current page. Bails early
 * on admin / AJAX / REST requests, when tracking is disabled, when
 * `DNT=1` is present, when the `imans_optout` cookie is set, or when
 * a known consent plugin is active without consent (the tracker JS
 * does the final consent check at runtime).
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics;

defined( 'ABSPATH' ) || exit;

class Tracker_Enqueuer {

	const SCRIPT_HANDLE = 'imans-analytics-tracker';
	const NONCE_ACTION  = 'imans_analytics_track';
	const OPTOUT_COOKIE = 'imans_optout';

	public static function maybe_enqueue() {
		if ( is_admin() || self::is_internal_request() ) {
			return;
		}

		if ( ! self::is_tracking_enabled() ) {
			return;
		}

		if ( self::has_optout_signal() ) {
			return;
		}

		$src = IMANS_ANALYTICS_PLUGIN_URL . 'assets/js/tracker.js';

		wp_register_script(
			self::SCRIPT_HANDLE,
			$src,
			array(),
			IMANS_ANALYTICS_VERSION,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'imansAnalyticsConfig',
			array(
				'endpoint'      => rest_url( IMANS_ANALYTICS_REST_NAMESPACE . '/track' ),
				'nonce'         => wp_create_nonce( self::NONCE_ACTION ),
				'sampleRate'    => (int) get_option( Activator::OPTION_SAMPLE_RATE, 100 ),
				'consentMode'   => self::detect_consent_mode(),
				'isProductPage' => function_exists( 'is_product' ) && is_product(),
				'isCheckoutPage'=> function_exists( 'is_checkout' ) && is_checkout() && ! ( function_exists( 'is_order_received_page' ) && is_order_received_page() ),
				'searchTerm'    => is_search() ? get_search_query( false ) : '',
				'productId'     => self::current_product_id(),
				'siteUrl'       => home_url(),
			)
		);

		wp_enqueue_script( self::SCRIPT_HANDLE );
	}

	private static function is_tracking_enabled() {
		return '1' === (string) get_option( Activator::OPTION_TRACKING_ENABLED, '0' );
	}

	private static function is_internal_request() {
		if ( wp_doing_ajax() ) {
			return true;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		return false;
	}

	private static function has_optout_signal() {
		if ( ! empty( $_SERVER['HTTP_DNT'] ) && '1' === (string) $_SERVER['HTTP_DNT'] ) {
			return true;
		}
		if ( isset( $_COOKIE[ self::OPTOUT_COOKIE ] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Tell the tracker which consent regime to honor at runtime.
	 *
	 * 'wp_consent' is the only mode auto-detected server-side because the
	 * WP Consent API exposes a stable function. CookieYes / Complianz /
	 * IMANS_ANALYTICS_HAS_CONSENT detection happens client-side in tracker.js
	 * where the global / events actually live.
	 */
	private static function detect_consent_mode() {
		if ( function_exists( 'wp_has_consent' ) ) {
			return 'wp_consent';
		}
		return 'auto';
	}

	private static function current_product_id() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return 0;
		}
		$id = get_queried_object_id();
		return $id ? (int) $id : 0;
	}
}
