<?php
/**
 * Performance budgets (STANDARDS-AND-COMPATIBILITY §4.4). Override the file with
 * AGENTPORTER_BENCH_BUDGETS (used by the seeded proof).
 *
 * @package AgentPorter\Tests
 */

return array(
	'guard_check_ms' => 5.0,   // Median AgentGuard::check() on real WordPress with the database rate store.
	'redactor_ms'    => 1.0,   // Median Redactor::to_json() on a typical ability input.
	'code_memory_kb' => 2048,  // Memory added by loading every plugin class (per-request code cost).
	'guard_leak_kb'  => 256,   // Memory growth across 500 guard checks (leak detector).
);
