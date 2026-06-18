<?php
/**
 * Minimal WordPress class stubs for the test harness.
 *
 * Only the surface the plugin code touches is implemented. WP *functions*
 * (is_user_logged_in, current_user_can, gmdate wrappers, etc.) are mocked
 * per-test with Brain Monkey; these are the *classes* the code instantiates
 * or type-checks against.
 *
 * @package Imans\Analytics\Tests
 */

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Tiny WP_Error stand-in carrying code, message and data.
	 */
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Captures the response body + status so assertions can read them.
	 */
	class WP_REST_Response {
		public $data;
		public $status;

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Bag of query params standing in for WP_REST_Request.
	 */
	class WP_REST_Request {
		private $params;

		public function __construct( array $params = array() ) {
			$this->params = $params;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}
	}
}
