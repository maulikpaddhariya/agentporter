<?php
/**
 * Minimal WP_Error and wpdb doubles for unit tests (no WordPress loaded).
 *
 * @package AgentPorter\Tests
 */

// phpcs:disable -- Global test doubles matching WordPress class names.

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
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
		public function add_data( $data ) {
			$this->data = $data;
		}
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public $prefix   = 'wp_';
		public $options  = 'wp_options';
		public $inserted = array();
		public $fail     = false;
		public function insert( $table, $data, $format = null ) {
			if ( $this->fail ) {
				return false;
			}
			$this->inserted[] = array( $table, $data, $format );
			return 1;
		}
		public function prepare( $query, ...$args ) {
			return $query;
		}
		public function query( $query ) {
			return 0;
		}
		public function get_var( $query = null ) {
			return null;
		}
		public function get_charset_collate() {
			return '';
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID      = 0;
		public $allcaps = array();
		public function __construct( $id = 0, $allcaps = array() ) {
			$this->ID      = $id;
			$this->allcaps = $allcaps;
		}
	}
}
