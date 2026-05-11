<?php
/**
 * Custom table definitions for Imans Analytics.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics;

defined( 'ABSPATH' ) || exit;

class Schema {

	const OPTION_SCHEMA_VERSION = 'imans_analytics_schema_version';

	public static function table_name( $suffix ) {
		global $wpdb;
		return $wpdb->prefix . 'imans_' . $suffix;
	}

	public static function sessions_table() {
		return self::table_name( 'sessions' );
	}

	public static function events_table() {
		return self::table_name( 'events' );
	}

	public static function daily_stats_table() {
		return self::table_name( 'daily_stats' );
	}

	public static function search_terms_table() {
		return self::table_name( 'search_terms' );
	}

	/**
	 * Run dbDelta to create or upgrade all custom tables.
	 *
	 * Called from Activator::activate(). Safe to call repeatedly —
	 * dbDelta diffs the schema and only emits the ALTER statements needed.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$sessions        = self::sessions_table();
		$events          = self::events_table();
		$daily_stats     = self::daily_stats_table();
		$search_terms    = self::search_terms_table();

		$schemas = array();

		$schemas[] = "CREATE TABLE {$sessions} (
			session_id CHAR(36) NOT NULL,
			visitor_id CHAR(36) NOT NULL,
			started_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			landing_page TEXT NULL,
			referrer TEXT NULL,
			utm_source VARCHAR(255) NULL,
			utm_medium VARCHAR(255) NULL,
			utm_campaign VARCHAR(255) NULL,
			utm_content VARCHAR(255) NULL,
			utm_term VARCHAR(255) NULL,
			device_type VARCHAR(16) NULL,
			country CHAR(2) NULL,
			page_views_count INT UNSIGNED NOT NULL DEFAULT 0,
			has_add_to_cart TINYINT(1) NOT NULL DEFAULT 0,
			has_begin_checkout TINYINT(1) NOT NULL DEFAULT 0,
			purchase_order_id BIGINT UNSIGNED NULL,
			ip_hash CHAR(64) NULL,
			PRIMARY KEY  (session_id),
			KEY idx_started_at (started_at),
			KEY idx_purchase_order (purchase_order_id),
			KEY idx_visitor (visitor_id)
		) {$charset_collate};";

		$schemas[] = "CREATE TABLE {$events} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id CHAR(36) NOT NULL,
			event_type VARCHAR(32) NOT NULL,
			occurred_at DATETIME NOT NULL,
			page_url TEXT NULL,
			product_id BIGINT UNSIGNED NULL,
			variation_id BIGINT UNSIGNED NULL,
			quantity INT NULL,
			search_term VARCHAR(255) NULL,
			extra LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY idx_session (session_id, occurred_at),
			KEY idx_event_type (event_type, occurred_at),
			KEY idx_occurred_at (occurred_at)
		) {$charset_collate};";

		$schemas[] = "CREATE TABLE {$daily_stats} (
			stat_date DATE NOT NULL,
			sessions INT UNSIGNED NOT NULL DEFAULT 0,
			sessions_with_purchase INT UNSIGNED NOT NULL DEFAULT 0,
			unique_visitors INT UNSIGNED NOT NULL DEFAULT 0,
			bounce_count INT UNSIGNED NOT NULL DEFAULT 0,
			page_views INT UNSIGNED NOT NULL DEFAULT 0,
			view_item_count INT UNSIGNED NOT NULL DEFAULT 0,
			view_item_list_count INT UNSIGNED NOT NULL DEFAULT 0,
			add_to_cart_count INT UNSIGNED NOT NULL DEFAULT 0,
			remove_from_cart_count INT UNSIGNED NOT NULL DEFAULT 0,
			begin_checkout_count INT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (stat_date)
		) {$charset_collate};";

		$schemas[] = "CREATE TABLE {$search_terms} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			stat_date DATE NOT NULL,
			term VARCHAR(255) NOT NULL,
			count INT UNSIGNED NOT NULL DEFAULT 0,
			conversion_count INT UNSIGNED NOT NULL DEFAULT 0,
			no_result_count INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_date_term (stat_date, term),
			KEY idx_stat_date (stat_date)
		) {$charset_collate};";

		foreach ( $schemas as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::OPTION_SCHEMA_VERSION, IMANS_ANALYTICS_SCHEMA_VERSION );
	}

	/**
	 * Drop every plugin table. Called from uninstall.php only.
	 */
	public static function drop_all() {
		global $wpdb;

		$tables = array(
			self::events_table(),
			self::sessions_table(),
			self::daily_stats_table(),
			self::search_terms_table(),
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}
	}
}
