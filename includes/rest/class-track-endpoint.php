<?php
/**
 * POST /wp-json/imans-analytics/v1/track
 *
 * Internal tracker ingestion. Accepts a small JSON payload from the
 * front-end tracker, inserts events into wp_imans_events and upserts
 * the session in wp_imans_sessions.
 *
 * Security:
 *   - WP nonce required (X-WP-Nonce or _wpnonce). Cached pages may
 *     present an expired nonce; in that case we reject with 403 and
 *     the client should refresh the page.
 *   - Per-IP rate limit using a transient counter (60 events/min default).
 *   - JSON schema validation; no event field is reflected unsanitized.
 *   - IP addresses are SHA-256 hashed before storage.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics\Rest;

use Imans\Analytics\Schema;
use Imans\Analytics\Tracker_Enqueuer;

defined( 'ABSPATH' ) || exit;

class Track_Endpoint {

	const RATE_LIMIT_PER_MIN = 60;
	const MAX_EVENTS_PER_REQUEST = 20;
	const ALLOWED_EVENT_TYPES = array(
		'page_view',
		'view_item',
		'view_item_list',
		'add_to_cart',
		'remove_from_cart',
		'begin_checkout',
		'search',
	);

	public static function permission( $request ) {
		$nonce = $request->get_header( 'x-wp-nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}
		if ( ! $nonce || ! wp_verify_nonce( $nonce, Tracker_Enqueuer::NONCE_ACTION ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'Invalid or expired nonce.',
				array( 'status' => 403 )
			);
		}
		return true;
	}

	public static function handle( $request ) {
		$ip = self::get_client_ip();
		if ( self::is_rate_limited( $ip ) ) {
			return new \WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_payload' ), 400 );
		}

		$session_id = self::sanitize_uuid( $body['session_id'] ?? '' );
		$visitor_id = self::sanitize_uuid( $body['visitor_id'] ?? '' );
		$events     = $body['events'] ?? array();
		$session_meta = is_array( $body['session_meta'] ?? null ) ? $body['session_meta'] : array();

		if ( ! $session_id || ! $visitor_id || ! is_array( $events ) ) {
			return new \WP_REST_Response( array( 'error' => 'missing_fields' ), 400 );
		}
		if ( count( $events ) > self::MAX_EVENTS_PER_REQUEST ) {
			return new \WP_REST_Response( array( 'error' => 'too_many_events' ), 400 );
		}

		global $wpdb;

		self::upsert_session( $wpdb, $session_id, $visitor_id, $session_meta, $ip );
		$inserted = self::insert_events( $wpdb, $session_id, $events );

		return new \WP_REST_Response( null, 204 );
	}

	private static function upsert_session( $wpdb, $session_id, $visitor_id, $meta, $ip ) {
		$now = current_time( 'mysql', true );
		$table = Schema::sessions_table();

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT session_id, page_views_count FROM {$table} WHERE session_id = %s", $session_id ) // phpcs:ignore WordPress.DB
		);

		if ( $existing ) {
			$wpdb->update(
				$table,
				array( 'last_seen_at' => $now ),
				array( 'session_id' => $session_id ),
				array( '%s' ),
				array( '%s' )
			);
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'session_id'        => $session_id,
				'visitor_id'        => $visitor_id,
				'started_at'        => $now,
				'last_seen_at'      => $now,
				'landing_page'      => self::sanitize_url( $meta['landing_page'] ?? '' ),
				'referrer'          => self::sanitize_url( $meta['referrer'] ?? '' ),
				'utm_source'        => self::sanitize_short( $meta['utm_source'] ?? '' ),
				'utm_medium'        => self::sanitize_short( $meta['utm_medium'] ?? '' ),
				'utm_campaign'      => self::sanitize_short( $meta['utm_campaign'] ?? '' ),
				'utm_content'       => self::sanitize_short( $meta['utm_content'] ?? '' ),
				'utm_term'          => self::sanitize_short( $meta['utm_term'] ?? '' ),
				'device_type'       => self::sanitize_device( $meta['device_type'] ?? '' ),
				'country'           => null,
				'page_views_count'  => 0,
				'has_add_to_cart'   => 0,
				'has_begin_checkout' => 0,
				'purchase_order_id' => null,
				'ip_hash'           => self::hash_ip( $ip ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
		);
	}

	private static function insert_events( $wpdb, $session_id, $events ) {
		$table = Schema::events_table();
		$session_table = Schema::sessions_table();
		$inserted = 0;
		$has_add_to_cart = false;
		$has_begin_checkout = false;
		$page_views_delta = 0;

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$event_type = isset( $event['event_type'] ) ? (string) $event['event_type'] : '';
			if ( ! in_array( $event_type, self::ALLOWED_EVENT_TYPES, true ) ) {
				continue;
			}
			$occurred_at = isset( $event['occurred_at'] ) ? self::sanitize_datetime( $event['occurred_at'] ) : current_time( 'mysql', true );

			$wpdb->insert(
				$table,
				array(
					'session_id'   => $session_id,
					'event_type'   => $event_type,
					'occurred_at'  => $occurred_at,
					'page_url'     => self::sanitize_url( $event['page_url'] ?? '' ),
					'product_id'   => isset( $event['product_id'] ) ? max( 0, (int) $event['product_id'] ) ?: null : null,
					'variation_id' => isset( $event['variation_id'] ) ? max( 0, (int) $event['variation_id'] ) ?: null : null,
					'quantity'     => isset( $event['quantity'] ) ? (int) $event['quantity'] : null,
					'search_term'  => isset( $event['search_term'] ) ? self::sanitize_short( $event['search_term'] ) : null,
					'extra'        => null,
				),
				array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
			);
			$inserted++;

			if ( 'page_view' === $event_type ) {
				$page_views_delta++;
			}
			if ( 'add_to_cart' === $event_type ) {
				$has_add_to_cart = true;
			}
			if ( 'begin_checkout' === $event_type ) {
				$has_begin_checkout = true;
			}
		}

		if ( $page_views_delta > 0 || $has_add_to_cart || $has_begin_checkout ) {
			$set = array();
			$args = array();
			if ( $page_views_delta > 0 ) {
				$set[] = 'page_views_count = page_views_count + %d';
				$args[] = $page_views_delta;
			}
			if ( $has_add_to_cart ) {
				$set[] = 'has_add_to_cart = 1';
			}
			if ( $has_begin_checkout ) {
				$set[] = 'has_begin_checkout = 1';
			}
			$args[] = $session_id;
			$sql = 'UPDATE ' . $session_table . ' SET ' . implode( ', ', $set ) . ' WHERE session_id = %s';
			$wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB
		}

		return $inserted;
	}

	/* ------------------------------ helpers ------------------------------ */

	private static function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		// Honor X-Forwarded-For only if it's a trusted reverse proxy; defer
		// that to a future setting. For now stick to REMOTE_ADDR to keep
		// rate-limiting accurate behind well-configured fronts.
		return $ip;
	}

	private static function hash_ip( $ip ) {
		if ( '' === $ip ) {
			return null;
		}
		$salt = wp_salt( 'auth' );
		return hash( 'sha256', $salt . '|' . $ip );
	}

	private static function is_rate_limited( $ip ) {
		if ( '' === $ip ) {
			return false;
		}
		$key = 'imans_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_PER_MIN ) {
			return true;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return false;
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

	private static function sanitize_url( $value ) {
		$value = is_string( $value ) ? $value : '';
		$value = esc_url_raw( $value );
		return substr( $value, 0, 2000 );
	}

	private static function sanitize_short( $value ) {
		$value = is_string( $value ) ? $value : '';
		$value = sanitize_text_field( $value );
		return substr( $value, 0, 255 );
	}

	private static function sanitize_device( $value ) {
		$value = is_string( $value ) ? strtolower( $value ) : '';
		if ( ! in_array( $value, array( 'desktop', 'mobile', 'tablet' ), true ) ) {
			return null;
		}
		return $value;
	}

	private static function sanitize_datetime( $value ) {
		$value = is_string( $value ) ? $value : '';
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return current_time( 'mysql', true );
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
