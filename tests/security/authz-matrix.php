<?php
/**
 * Authorization matrix: which non-administrator roles may use each AgentPorter REST route.
 *
 * Format: 'METHOD /namespace/route' => array( 'role', … ). Routes not listed allow administrators only;
 * AuthzMatrixTest expects 401/403 for anonymous, subscriber, contributor, author, editor (and
 * customer / shop_manager when WooCommerce is active) on every route unless listed here.
 *
 * @package AgentPorter\Tests
 */

return array();
