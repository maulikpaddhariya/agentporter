#!/usr/bin/env bash
# Proves each gate actually fails on a seeded violation. Exits non-zero if any gate lets one through.
set -uo pipefail
cd "$(dirname "$0")/.."

pass=0
fail=0
ok()  { echo "  PASS: $1"; pass=$((pass + 1)); }
bad() { echo "  FAIL: $1"; fail=$((fail + 1)); }

php_minor=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
echo "PHP $php_minor"

echo "1. PHP 8.2+ syntax is rejected"
if [ "$php_minor" = "8.1" ]; then
	if php -l tests/seeded/fixtures/Php82ReadonlyClass.php >/dev/null 2>&1; then
		bad "php -l on 8.1 accepted a readonly class"
	else
		ok "php -l on 8.1 rejects a readonly class"
	fi
else
	echo "  (php -l check needs PHP 8.1; skipped on $php_minor)"
fi
if vendor/bin/phpcs -q --standard=PHPCompatibilityWP --runtime-set testVersion 8.1- \
	tests/seeded/fixtures/Php82ReadonlyClass.php >/dev/null 2>&1; then
	bad "PHPCompatibilityWP accepted a readonly class"
else
	ok "PHPCompatibilityWP rejects a readonly class"
fi

echo "2. A deprecation raised by plugin code fails the test run"
seeded_dir=src/Seeded
if [ -e "$seeded_dir" ]; then
	echo "  $seeded_dir already exists; refusing to overwrite." >&2
	exit 2
fi
mkdir -p "$seeded_dir"
trap 'rm -rf "$seeded_dir"' EXIT
cp tests/seeded/fixtures/SeededDeprecation.php "$seeded_dir/SeededDeprecation.php"
output=$(vendor/bin/phpunit -c tests/seeded/phpunit.xml.dist 2>&1)
status=$?
if [ "$status" -ne 0 ] && grep -q "AgentPorter deprecation gate" <<<"$output"; then
	ok "seeded deprecation failed the run (exit $status)"
else
	echo "$output"
	bad "seeded deprecation did not fail the run (exit $status)"
fi

echo
echo "Seeded checks: $pass passed, $fail failed."
[ "$fail" -eq 0 ]
