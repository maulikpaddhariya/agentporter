<?php
/**
 * Constants PHPStan needs to analyse plugin files outside WordPress.
 *
 * @package AgentPorter\Tests
 */

// phpcs:disable -- Static-analysis bootstrap only.
defined( 'ABSPATH' ) || define( 'ABSPATH', '/tmp/wordpress/' );
defined( 'AGENTPORTER_VERSION' ) || define( 'AGENTPORTER_VERSION', '0.0.0' );
defined( 'AGENTPORTER_FILE' ) || define( 'AGENTPORTER_FILE', dirname( __DIR__, 2 ) . '/agentporter.php' );
defined( 'AGENTPORTER_DIR' ) || define( 'AGENTPORTER_DIR', dirname( __DIR__, 2 ) . '/' );
