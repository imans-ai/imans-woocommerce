<?php
/**
 * WooCommerce Settings → Imans Analytics tab.
 *
 * Extends WC's settings framework so the tab nests under WooCommerce
 * → Settings with the same look and feel as the rest of WC's admin.
 * Falls back gracefully (returns the original page list untouched) if
 * the WC settings class isn't available.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics\Admin;

use Imans\Analytics\Activator;
use Imans\Analytics\Schema;

defined( 'ABSPATH' ) || exit;

class Settings_Page {

	/**
	 * Hook target for `woocommerce_get_settings_pages`. Appends our
	 * settings page to WC's list. Bails if WC's settings base class is
	 * missing (e.g. WC deactivated but our plugin still active).
	 */
	public static function register( $pages ) {
		if ( ! class_exists( 'WC_Settings_Page' ) ) {
			return $pages;
		}
		require_once __DIR__ . '/class-wc-settings-imans-for-woocommerce.php';
		$pages[] = new WC_Settings_Imans_For_Woocommerce();
		return $pages;
	}

	/**
	 * Build the WC settings array shown when our tab is active. Kept
	 * here (rather than on the WC_Settings_Page subclass) so the file
	 * that declares the WC subclass can stay short and predictable.
	 */
	public static function build_settings() {
		return array(
			array(
				'title' => __( 'Imans Analytics', 'imans-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'First-party storefront analytics that feed your Imans dashboard. All data stays on this server until Imans pulls daily aggregates.', 'imans-for-woocommerce' ),
				'id'    => 'imans_analytics_section_tracking',
			),
			array(
				'title'         => __( 'Enable tracking', 'imans-for-woocommerce' ),
				'desc'          => __( 'Capture storefront sessions, page views, funnel events and on-site search. Off by default; turn on after you have a privacy notice in place.', 'imans-for-woocommerce' ),
				'id'            => Activator::OPTION_TRACKING_ENABLED,
				'type'          => 'checkbox',
				'default'       => 'no',
				'desc_tip'      => true,
			),
			array(
				'title'    => __( 'Event retention (days)', 'imans-for-woocommerce' ),
				'desc'     => __( 'How long to keep raw events. Daily aggregates are kept forever and are unaffected.', 'imans-for-woocommerce' ),
				'id'       => Activator::OPTION_RETENTION_DAYS,
				'type'     => 'number',
				'default'  => 30,
				'desc_tip' => true,
				'custom_attributes' => array(
					'min'  => 7,
					'max'  => 365,
					'step' => 1,
				),
			),
			array(
				'title'    => __( 'Client-side sample rate (%)', 'imans-for-woocommerce' ),
				'desc'     => __( 'Percentage of storefront visitors to track. 100 sends every visit; lower this only on very high-traffic stores.', 'imans-for-woocommerce' ),
				'id'       => Activator::OPTION_SAMPLE_RATE,
				'type'     => 'number',
				'default'  => 100,
				'desc_tip' => true,
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 100,
					'step' => 1,
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'imans_analytics_section_tracking',
			),
			array(
				'title' => __( 'Status', 'imans-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'imans_analytics_section_status',
			),
			array(
				'type' => 'imans_analytics_status',
				'id'   => 'imans_analytics_status_field',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'imans_analytics_section_status',
			),
		);
	}

	/**
	 * Custom field renderer for the read-only status row. Wired via
	 * `woocommerce_admin_field_imans_analytics_status` in the WC
	 * subclass file.
	 */
	public static function render_status_field() {
		global $wpdb;

		$sessions_table    = Schema::sessions_table();
		$events_table      = Schema::events_table();
		$daily_stats_table = Schema::daily_stats_table();

		$counts = array(
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sessions_table}" ),    // phpcs:ignore WordPress.DB
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" ),       // phpcs:ignore WordPress.DB
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$daily_stats_table}" ),  // phpcs:ignore WordPress.DB
		);

		$last_aggregation = get_option( Activator::OPTION_LAST_AGGREGATION, '' );

		$rows = array(
			__( 'Plugin version', 'imans-for-woocommerce' )       => IMANS_ANALYTICS_VERSION,
			__( 'Schema version', 'imans-for-woocommerce' )       => IMANS_ANALYTICS_SCHEMA_VERSION,
			__( 'Last aggregation (UTC)', 'imans-for-woocommerce' ) => $last_aggregation ? $last_aggregation : __( 'never run yet', 'imans-for-woocommerce' ),
			__( 'Sessions stored', 'imans-for-woocommerce' )      => number_format_i18n( $counts[0] ),
			__( 'Events stored', 'imans-for-woocommerce' )        => number_format_i18n( $counts[1] ),
			__( 'Daily aggregates', 'imans-for-woocommerce' )     => number_format_i18n( $counts[2] ),
		);

		echo '<tr valign="top"><th scope="row"></th><td>';
		echo '<table class="widefat striped" style="max-width: 600px;">';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row" style="width: 220px;">' . esc_html( $label ) . '</th>';
			echo '<td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
		}
		echo '</table>';
		echo '</td></tr>';
	}
}
