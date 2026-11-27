<?php
/**
 * SEEDED VIOLATION — never shipped. Echoes request input without escaping (XSS) and builds SQL from
 * it (SQLi). Psalm taint analysis must report both.
 *
 * @package AgentPorter\Tests
 */

declare(strict_types=1);

namespace AgentPorter\Seeded;

final class TaintedOutput {
	public static function render(): void {
		echo $_GET['name']; // phpcs:ignore
	}

	public static function query( \wpdb $wpdb ): void {
		$wpdb->query( "SELECT * FROM wp_posts WHERE post_title = '" . $_GET['title'] . "'" ); // phpcs:ignore
	}
}
