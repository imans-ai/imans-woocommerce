<?php
/**
 * Plugin deactivation handler.
 *
 * Unschedules cron events. Does NOT drop tables or delete options —
 * that's reserved for uninstall.php so the merchant can deactivate
 * temporarily without losing analytics history.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics;

defined( 'ABSPATH' ) || exit;

class Deactivator {

	public static function deactivate() {
		wp_clear_scheduled_hook( Activator::CRON_HOURLY_ROLL );
		wp_clear_scheduled_hook( Activator::CRON_DAILY_PURGE );
	}
}
