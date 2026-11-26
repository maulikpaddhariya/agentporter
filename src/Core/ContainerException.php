<?php
/**
 * Container errors.
 *
 * @package AgentPorter
 */

declare(strict_types=1);

namespace AgentPorter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a service is unknown, misconfigured or circular.
 */
final class ContainerException extends \RuntimeException {
}
