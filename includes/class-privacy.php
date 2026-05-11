<?php
/**
 * WP Privacy integration — GDPR / LGPD / CCPA hooks.
 *
 * Honest framing: this plugin stores pseudonymous data only — random
 * UUIDs and SHA-256-hashed IPs. None of it is directly linkable to a
 * named person or email address from inside the plugin. Strict GDPR
 * reading still considers pseudonymous data "personal" if it CAN be
 * re-identified by combining with another data source, so we register
 * the WP Privacy hooks anyway and provide an extension point for sites
 * that bridge email → visitor_id via another system (CRM, marketing
 * automation, custom code).
 *
 * Sites with no such bridge will see empty exporter / eraser results
 * for any email — which is correct: from this plugin's perspective,
 * "personal" data tied to an email simply doesn't exist.
 *
 * Bridge filter:
 *
 *     // Resolve email -> list of visitor_ids known to other systems.
 *     add_filter( 'imans_analytics_visitor_ids_for_email',
 *         function( $ids, $email ) {
 *             $ids[] = your_crm_lookup( $email ); // returns UUID
 *             return array_filter( $ids );
 *         }, 10, 2 );
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics;

defined( 'ABSPATH' ) || exit;

class Privacy {

	const GROUP_ID    = 'imans-for-woocommerce';
	const GROUP_LABEL = 'Imans Analytics';

	public static function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters['imans-for-woocommerce'] = array(
			'exporter_friendly_name' => __( 'Imans Analytics', 'imans-for-woocommerce' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['imans-for-woocommerce'] = array(
			'eraser_friendly_name' => __( 'Imans Analytics', 'imans-for-woocommerce' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	/**
	 * Export all sessions + events tied to the visitor_ids that other
	 * plugins resolve from the requesting email. Returns a WP-formatted
	 * exporter response so the privacy tool stitches it into the user's
	 * data export zip.
	 */
	public static function export_data( $email_address, $page = 1 ) {
		$visitor_ids = self::visitor_ids_for_email( $email_address );
		$items       = array();

		if ( ! empty( $visitor_ids ) ) {
			global $wpdb;
			$sessions_table = Schema::sessions_table();
			$placeholders   = implode( ', ', array_fill( 0, count( $visitor_ids ), '%s' ) );
			$rows           = $wpdb->get_results(
				$wpdb->prepare( "SELECT session_id, visitor_id, started_at, last_seen_at, landing_page, referrer, utm_source, utm_medium, utm_campaign, device_type, country, purchase_order_id FROM {$sessions_table} WHERE visitor_id IN ({$placeholders})", $visitor_ids ), // phpcs:ignore WordPress.DB
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$data = array();
				foreach ( $row as $key => $value ) {
					if ( null === $value || '' === $value ) {
						continue;
					}
					$data[] = array(
						'name'  => self::humanize_field( $key ),
						'value' => (string) $value,
					);
				}
				$items[] = array(
					'group_id'    => self::GROUP_ID,
					'group_label' => self::GROUP_LABEL,
					'item_id'     => 'imans-session-' . $row['session_id'],
					'data'        => $data,
				);
			}
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erase all sessions + events tied to the resolved visitor_ids.
	 * Returns the WP-formatted eraser response.
	 */
	public static function erase_data( $email_address, $page = 1 ) {
		$visitor_ids   = self::visitor_ids_for_email( $email_address );
		$items_removed = 0;
		$items_retained = false;
		$messages       = array();

		if ( ! empty( $visitor_ids ) ) {
			global $wpdb;
			$sessions_table = Schema::sessions_table();
			$events_table   = Schema::events_table();
			$placeholders   = implode( ', ', array_fill( 0, count( $visitor_ids ), '%s' ) );

			$session_ids = $wpdb->get_col(
				$wpdb->prepare( "SELECT session_id FROM {$sessions_table} WHERE visitor_id IN ({$placeholders})", $visitor_ids ) // phpcs:ignore WordPress.DB
			);

			if ( ! empty( $session_ids ) ) {
				$session_placeholders = implode( ', ', array_fill( 0, count( $session_ids ), '%s' ) );
				$events_deleted = (int) $wpdb->query(
					$wpdb->prepare( "DELETE FROM {$events_table} WHERE session_id IN ({$session_placeholders})", $session_ids ) // phpcs:ignore WordPress.DB
				);
				$sessions_deleted = (int) $wpdb->query(
					$wpdb->prepare( "DELETE FROM {$sessions_table} WHERE visitor_id IN ({$placeholders})", $visitor_ids ) // phpcs:ignore WordPress.DB
				);
				$items_removed = $events_deleted + $sessions_deleted;
			}
		} else {
			$messages[] = __( 'No Imans Analytics records linked to this email. Pseudonymous data without an email bridge cannot be matched.', 'imans-for-woocommerce' );
		}

		return array(
			'items_removed'  => $items_removed > 0,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Apply the bridge filter so other plugins can resolve
	 * email → visitor_ids. Without a hook, this returns [].
	 */
	private static function visitor_ids_for_email( $email_address ) {
		$ids = apply_filters( 'imans_analytics_visitor_ids_for_email', array(), $email_address );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$ids = array_filter(
			array_map( 'strval', $ids ),
			function ( $id ) {
				return '' !== $id && preg_match( '/^[a-f0-9\-]{8,36}$/i', $id );
			}
		);
		return array_values( array_unique( $ids ) );
	}

	private static function humanize_field( $key ) {
		$map = array(
			'session_id'        => __( 'Session ID', 'imans-for-woocommerce' ),
			'visitor_id'        => __( 'Visitor ID', 'imans-for-woocommerce' ),
			'started_at'        => __( 'Session started', 'imans-for-woocommerce' ),
			'last_seen_at'      => __( 'Last seen', 'imans-for-woocommerce' ),
			'landing_page'      => __( 'Landing page', 'imans-for-woocommerce' ),
			'referrer'          => __( 'Referrer', 'imans-for-woocommerce' ),
			'utm_source'        => __( 'UTM source', 'imans-for-woocommerce' ),
			'utm_medium'        => __( 'UTM medium', 'imans-for-woocommerce' ),
			'utm_campaign'      => __( 'UTM campaign', 'imans-for-woocommerce' ),
			'device_type'       => __( 'Device type', 'imans-for-woocommerce' ),
			'country'           => __( 'Country', 'imans-for-woocommerce' ),
			'purchase_order_id' => __( 'Linked order ID', 'imans-for-woocommerce' ),
		);
		return $map[ $key ] ?? $key;
	}
}
