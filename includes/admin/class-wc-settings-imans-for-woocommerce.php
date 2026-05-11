<?php
/**
 * WC_Settings_Page subclass for the Imans Analytics tab.
 *
 * Lives in the global namespace to match WooCommerce's settings
 * framework. Delegates all real work to ``Imans\Analytics\Admin\Settings_Page``.
 *
 * @package Imans\Analytics
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Settings_Page' ) ) {
	return;
}

class WC_Settings_Imans_For_Woocommerce extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'imans-for-woocommerce';
		$this->label = __( 'Imans Analytics', 'imans-for-woocommerce' );

		parent::__construct();

		add_action(
			'woocommerce_admin_field_imans_analytics_status',
			array( '\Imans\Analytics\Admin\Settings_Page', 'render_status_field' )
		);
	}

	public function get_settings( $current_section = '' ) {
		$settings = \Imans\Analytics\Admin\Settings_Page::build_settings();
		return apply_filters( 'woocommerce_get_settings_' . $this->id, $settings, $current_section );
	}
}
