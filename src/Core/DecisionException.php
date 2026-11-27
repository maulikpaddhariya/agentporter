<?php
/**
 * Marker for exceptions used deliberately as control flow (a decision, not a fault).
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Hook wrappers rethrow these unchanged and do not disable the subsystem.
 */
interface DecisionException extends \Throwable {
}
