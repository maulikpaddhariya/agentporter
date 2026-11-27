<?php
/**
 * Uninstall: removes AgentPorter's data only if the owner opted in ("Delete all data on uninstall").
 *
 * @package AgentPorter
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The cleanup code needs PHP 8.1; on an older host the plugin never ran, so there is nothing to clean.
if ( PHP_VERSION_ID < 80100 ) {
	return;
}

require_once __DIR__ . '/src/Core/Schema.php';
require_once __DIR__ . '/src/Core/Uninstaller.php';

\AgentPorter\Core\Uninstaller::run();
