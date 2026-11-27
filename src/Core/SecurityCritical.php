<?php
/**
 * Marker for subsystems that guard agent access.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * If a security-critical subsystem fails (at boot or at runtime), the plugin enters lockdown:
 * {@see Faults::is_locked_down()} turns true and AgentGuard denies all agent access for the rest
 * of the request. Fail closed, never open.
 */
interface SecurityCritical extends Subsystem {
}
