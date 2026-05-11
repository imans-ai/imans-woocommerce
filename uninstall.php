<?php
/**
 * Uninstall handler.
 *
 * Runs only when the user clicks "Delete" on the Plugins screen
 * (not on deactivate). Drops every plugin table, deletes every
 * plugin option, and clears any orphaned cron events.
 *
 * @package Imans\Analytics
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-autoloader.php';

if ( ! defined( 'IMANS_ANALYTICS_PLUGIN_DIR' ) ) {
	define( 'IMANS_ANALYTICS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'IMANS_ANALYTICS_SCHEMA_VERSION' ) ) {
	define( 'IMANS_ANALYTICS_SCHEMA_VERSION', 1 );
}

\Imans\Analytics\Autoloader::register();
\Imans\Analytics\Schema::drop_all();

$options = array(
	\Imans\Analytics\Schema::OPTION_SCHEMA_VERSION,
	\Imans\Analytics\Activator::OPTION_TRACKING_ENABLED,
	\Imans\Analytics\Activator::OPTION_RETENTION_DAYS,
	\Imans\Analytics\Activator::OPTION_SAMPLE_RATE,
	\Imans\Analytics\Activator::OPTION_LAST_AGGREGATION,
);

foreach ( $options as $option_name ) {
	delete_option( $option_name );
}

wp_clear_scheduled_hook( \Imans\Analytics\Activator::CRON_HOURLY_ROLL );
wp_clear_scheduled_hook( \Imans\Analytics\Activator::CRON_DAILY_PURGE );
