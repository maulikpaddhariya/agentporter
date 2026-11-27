<?php
/**
 * Stand-in for the WordPress test suite's WPDieException in unit tests.
 *
 * @package AgentPorter\Tests
 */

// phpcs:disable -- Global test double matching a WordPress class name.
if ( ! class_exists( 'WPDieException' ) ) {
	class WPDieException extends Exception {
	}
}
