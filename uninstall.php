<?php
/**
 * Uninstall: removes AgentPorter's data only if the owner opted in ("Delete all data on uninstall").
 *
 * @package AgentPorter
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Core/Schema.php';
require_once __DIR__ . '/src/Core/Uninstaller.php';

\AgentPorter\Core\Uninstaller::run();
