<?php
/**
 * PHPUnit bootstrap for the Imans Analytics plugin test harness.
 *
 * No full WordPress install. We mock WP functions with Brain Monkey
 * (Patchwork) and provide the few WP classes our code touches as
 * minimal local stubs. Plugin source is loaded directly from
 * `includes/` so the classes under test resolve without WP's plugin
 * loader.
 *
 * @package Imans\Analytics\Tests
 */

declare( strict_types=1 );

error_reporting( E_ALL & ~E_DEPRECATED );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// The plugin guards every file with `defined( 'ABSPATH' ) || exit;`.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Constants the plugin reads at runtime.
if ( ! defined( 'IMANS_ANALYTICS_PLUGIN_DIR' ) ) {
	define( 'IMANS_ANALYTICS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'IMANS_ANALYTICS_REST_NAMESPACE' ) ) {
	define( 'IMANS_ANALYTICS_REST_NAMESPACE', 'imans-analytics/v1' );
}

require_once __DIR__ . '/stubs/wp-stubs.php';

// Load the plugin classes under test directly (the WP plugin autoloader
// is not booted in the harness).
require_once dirname( __DIR__ ) . '/includes/class-schema.php';
require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
require_once dirname( __DIR__ ) . '/includes/rest/class-products-stats-endpoint.php';
