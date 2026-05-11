<?php
/**
 * Links a completed purchase back to the session it originated from.
 *
 * Fires on `woocommerce_thankyou` (the order-received page). Reads the
 * session cookie set by the tracker and writes `purchase_order_id` onto
 * the matching session row. The hourly aggregator then counts those
 * sessions toward `sessions_with_purchase`.
 *
 * If the visitor cleared cookies between add_to_cart and thankyou,
 * the session won't be linked — that's a known limitation that matches
 * GA4 / Shopify behaviour.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics;

defined( 'ABSPATH' ) || exit;

class Purchase_Linker {

	const SESSION_COOKIE = 'imans_sid';

	public static function link_session_to_order( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}

		if ( empty( $_COOKIE[ self::SESSION_COOKIE ] ) ) {
			return;
		}

		$session_id = self::sanitize_uuid( wp_unslash( $_COOKIE[ self::SESSION_COOKIE ] ) );
		if ( '' === $session_id ) {
			return;
		}

		global $wpdb;
		$table = Schema::sessions_table();

		$wpdb->update(
			$table,
			array( 'purchase_order_id' => $order_id ),
			array( 'session_id' => $session_id ),
			array( '%d' ),
			array( '%s' )
		);
	}

	private static function sanitize_uuid( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( strlen( $value ) > 36 ) {
			return '';
		}
		if ( ! preg_match( '/^[a-f0-9\-]{8,36}$/i', $value ) ) {
			return '';
		}
		return strtolower( $value );
	}
}
