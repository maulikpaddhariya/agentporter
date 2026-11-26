#!/usr/bin/env bash
# Proves each gate actually fails on a seeded violation. Exits non-zero if any gate lets one through.
# Fixtures are copied into src/Seeded/ so the real gates (bin/lint-syntax.sh, the project phpcs.xml.dist
# ruleset, the PHPUnit deprecation gate) are exercised exactly as they run on production code.
set -uo pipefail
cd "$(dirname "$0")/.."

pass=0
fail=0
ok()  { echo "  PASS: $1"; pass=$((pass + 1)); }
bad() { echo "  FAIL: $1"; fail=$((fail + 1)); }

php_minor=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
php_id=$(php -r 'echo PHP_VERSION_ID;')
echo "PHP $php_minor"

seeded_dir=src/Seeded
if [ -e "$seeded_dir" ]; then
	echo "$seeded_dir already exists; refusing to overwrite." >&2
	exit 2
fi
trap 'rm -rf "$seeded_dir"' EXIT
seed() { rm -rf "$seeded_dir"; mkdir -p "$seeded_dir"; for f in "$@"; do cp "tests/seeded/fixtures/$f" "$seeded_dir/$f"; done; }

echo "1. PHP 8.2+ syntax in src/ is rejected"
seed Php82ReadonlyClass.php
if [ "$php_minor" = "8.1" ]; then
	if bin/lint-syntax.sh >/dev/null 2>&1; then
		bad "bin/lint-syntax.sh on PHP 8.1 accepted a readonly class"
	else
		ok "bin/lint-syntax.sh on PHP 8.1 rejects a readonly class"
	fi
else
	echo "  (syntax-ceiling lint needs PHP 8.1; skipped on $php_minor)"
fi
if vendor/bin/phpcs -q --no-cache "$seeded_dir/Php82ReadonlyClass.php" >/dev/null 2>&1; then
	bad "project PHPCS ruleset accepted a readonly class"
else
	ok "project PHPCS ruleset (PHPCompatibilityWP 8.1-) rejects a readonly class"
fi

run_seeded_test() { # $1 = test filter, $2 = description
	local output status
	output=$(vendor/bin/phpunit -c tests/seeded/phpunit.xml.dist --filter "$1" 2>&1)
	status=$?
	if [ "$status" -ne 0 ] && grep -q "AgentPorter deprecation gate" <<<"$output"; then
		ok "$2 failed the run (exit $status)"
	else
		echo "$output"
		bad "$2 did not fail the run (exit $status)"
	fi
}

echo "2. Deprecations raised by plugin code fail the test run"
seed SeededDeprecation.php
run_seeded_test test_runtime_deprecation "runtime deprecation"
run_seeded_test test_swallowed_deprecation "deprecation swallowed by plugin code"
if [ "$php_id" -ge 80400 ]; then
	seed SeededCompileTimeDeprecation.php
	run_seeded_test test_compile_time_deprecation "compile-time deprecation (implicitly nullable parameter)"
else
	echo "  (compile-time implicit-nullable deprecation exists from PHP 8.4; skipped on $php_minor)"
fi

echo
echo "Seeded checks: $pass passed, $fail failed."
[ "$fail" -eq 0 ]
