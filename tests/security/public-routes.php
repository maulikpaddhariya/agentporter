<?php
/**
 * Public-route registry: the only AgentPorter REST routes allowed to answer anonymous callers.
 *
 * Format: 'METHOD /namespace/route' => 'Why it is public and what limits it (rate limit, size cap).'
 * Adding an entry needs security review (SECURITY-MODEL §5.4). RouteRegistryTest fails on any open
 * route not listed here, and on any entry that no longer matches a registered route.
 *
 * @package AgentPorter\Tests
 */

return array();
