<?php
/**
 * Main plugin orchestrator.
 *
 * Wires up the REST controller, tracker enqueuer, purchase linker
 * and WP-Cron handlers. Singleton so static hook callbacks can
 * reach instance state if needed.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics;

defined( 'ABSPATH' ) || exit;

class Plugin {

	private static $instance = null;

	public static function boot() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init_hooks();
		}
		return self::$instance;
	}

	private function init_hooks() {
		add_action( 'rest_api_init', array( '\Imans\Analytics\Rest_Controller', 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( '\Imans\Analytics\Tracker_Enqueuer', 'maybe_enqueue' ) );
		add_action( 'woocommerce_thankyou', array( '\Imans\Analytics\Purchase_Linker', 'link_session_to_order' ), 10, 1 );

		add_action( Activator::CRON_HOURLY_ROLL, array( '\Imans\Analytics\Aggregator', 'roll_hourly' ) );
		add_action( Activator::CRON_DAILY_PURGE, array( '\Imans\Analytics\Purger', 'run_daily' ) );

		Privacy::register();

		if ( is_admin() ) {
			add_filter( 'woocommerce_get_settings_pages', array( '\Imans\Analytics\Admin\Settings_Page', 'register' ) );
		}
	}
}
