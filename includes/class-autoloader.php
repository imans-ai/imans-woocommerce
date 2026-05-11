<?php
/**
 * PSR-4-ish autoloader using WordPress class-file naming conventions.
 *
 * Maps `Imans\Analytics\Foo_Bar` to `includes/class-foo-bar.php` and
 * `Imans\Analytics\Rest\Track_Endpoint` to `includes/rest/class-track-endpoint.php`.
 * No Composer runtime dependency — WP.org plugin directory discourages
 * shipping a vendor/ folder for plugins of this size.
 *
 * @package Imans\Analytics
 */

namespace Imans\Analytics;

defined( 'ABSPATH' ) || exit;

class Autoloader {

	const NAMESPACE_PREFIX = 'Imans\\Analytics\\';

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	public static function load( $class ) {
		if ( strpos( $class, self::NAMESPACE_PREFIX ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( self::NAMESPACE_PREFIX ) );
		$parts    = explode( '\\', $relative );
		$class_part = array_pop( $parts );

		$dir_part = '';
		if ( ! empty( $parts ) ) {
			$dir_part = strtolower( implode( '/', $parts ) ) . '/';
		}

		$file_part = 'class-' . strtolower( str_replace( '_', '-', $class_part ) ) . '.php';

		$path = IMANS_ANALYTICS_PLUGIN_DIR . 'includes/' . $dir_part . $file_part;

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
