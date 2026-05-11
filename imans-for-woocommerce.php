<?php
/**
 * Plugin Name:       Imans Analytics for WooCommerce
 * Plugin URI:        https://imans.ai/integrations/woocommerce
 * Description:       Captures first-party storefront traffic, sessions, funnel events and search terms that WooCommerce core cannot see, and exposes them to the Imans analytics dashboard via authenticated REST endpoints. All data stays on your server; Imans pulls aggregates on the same daily schedule it already uses for wc-analytics.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Imans
 * Author URI:        https://imans.ai
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       imans-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 *
 * @package Imans\Analytics
 */

defined( 'ABSPATH' ) || exit;

define( 'IMANS_ANALYTICS_VERSION', '0.1.0' );
define( 'IMANS_ANALYTICS_SCHEMA_VERSION', 1 );
define( 'IMANS_ANALYTICS_PLUGIN_FILE', __FILE__ );
define( 'IMANS_ANALYTICS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'IMANS_ANALYTICS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'IMANS_ANALYTICS_REST_NAMESPACE', 'imans-analytics/v1' );

require_once IMANS_ANALYTICS_PLUGIN_DIR . 'includes/class-autoloader.php';
\Imans\Analytics\Autoloader::register();

register_activation_hook( __FILE__, array( '\Imans\Analytics\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Imans\Analytics\Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( '\Imans\Analytics\Plugin', 'boot' ) );
