<?php
/**
 * Plugin activation handler.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics;

defined( 'ABSPATH' ) || exit;

class Activator {

	const CRON_HOURLY_ROLL  = 'imans_analytics_hourly_roll';
	const CRON_DAILY_PURGE  = 'imans_analytics_daily_purge';

	const OPTION_TRACKING_ENABLED = 'imans_analytics_tracking_enabled';
	const OPTION_RETENTION_DAYS   = 'imans_analytics_retention_days';
	const OPTION_SAMPLE_RATE      = 'imans_analytics_sample_rate';
	const OPTION_LAST_AGGREGATION = 'imans_analytics_last_aggregation_at';

	public static function activate() {
		Schema::install();
		self::seed_default_options();
		self::schedule_cron_events();
	}

	private static function seed_default_options() {
		add_option( self::OPTION_TRACKING_ENABLED, '0' );
		add_option( self::OPTION_RETENTION_DAYS, 30 );
		add_option( self::OPTION_SAMPLE_RATE, 100 );
		add_option( self::OPTION_LAST_AGGREGATION, '' );
	}

	private static function schedule_cron_events() {
		if ( ! wp_next_scheduled( self::CRON_HOURLY_ROLL ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOURLY_ROLL );
		}
		if ( ! wp_next_scheduled( self::CRON_DAILY_PURGE ) ) {
			wp_schedule_event( self::compute_next_purge_timestamp(), 'daily', self::CRON_DAILY_PURGE );
		}
	}

	/**
	 * Compute the next 02:00 store-time timestamp (per the plan doc).
	 * Falls back to UTC + 2h if site timezone is not set.
	 */
	private static function compute_next_purge_timestamp() {
		$tz = wp_timezone();
		$now = new \DateTimeImmutable( 'now', $tz );
		$target = $now->setTime( 2, 0, 0 );
		if ( $target <= $now ) {
			$target = $target->modify( '+1 day' );
		}
		return $target->getTimestamp();
	}
}
