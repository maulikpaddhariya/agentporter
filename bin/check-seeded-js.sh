#!/usr/bin/env bash
# Proves each JavaScript/CSS gate fails on a seeded violation. Exits non-zero if any gate lets one through.
# Fixtures are copied into resources/js/__seeded__/ so the real configs and npm scripts are exercised.
set -uo pipefail
cd "$(dirname "$0")/.."

pass=0
fail=0
ok()  { echo "  PASS: $1"; pass=$((pass + 1)); }
bad() { echo "  FAIL: $1"; fail=$((fail + 1)); }

seeded_dir=resources/js/__seeded__
if [ -e "$seeded_dir" ]; then
	echo "$seeded_dir already exists; refusing to overwrite." >&2
	exit 2
fi
size_backup=$(mktemp)
cp .size-limit.json "$size_backup"
cleanup() { rm -rf "$seeded_dir"; cp "$size_backup" .size-limit.json; rm -f "$size_backup"; }
trap cleanup EXIT
seed() { rm -rf "$seeded_dir"; mkdir -p "$seeded_dir"; for f in "$@"; do mkdir -p "$seeded_dir/$(dirname "$f")"; cp "tests/seeded/js/$f" "$seeded_dir/$f"; done; }

# expect_failure <description> <pattern that proves the right rule fired> <command...>
expect_failure() {
	local description=$1 pattern=$2 output status
	shift 2
	output=$("$@" 2>&1)
	status=$?
	if [ "$status" -ne 0 ] && grep -Eq -- "$pattern" <<<"$output"; then
		ok "$description (exit $status)"
	else
		echo "$output" | tail -20
		bad "$description did not fail as expected (exit $status)"
	fi
}

echo "1. ESLint (no-unsanitized) rejects innerHTML from a variable"
seed unsanitized.ts
expect_failure "ESLint no-unsanitized" "no-unsanitized/property" npx wp-scripts lint-js "$seeded_dir"

echo "2. Prettier rejects unformatted code"
seed unformatted.ts
expect_failure "Prettier --check" "unformatted\.ts" npx prettier --check "$seeded_dir"

echo "3. TypeScript rejects a type error"
seed type-error.ts
expect_failure "tsc --noEmit" "TS2345" npx tsc --noEmit -p tsconfig.json

echo "4. Stylelint rejects an unknown property"
seed invalid.scss
expect_failure "Stylelint" "property-no-unknown" npx wp-scripts lint-style "$seeded_dir/**/*.scss"

echo "5. Jest fails on a failing test"
seed test/failing.test.ts
expect_failure "Jest" "1 failed" npx jest "$seeded_dir"

echo "6. size-limit fails over budget"
rm -rf "$seeded_dir"
if [ ! -f assets/build/admin/index.js ]; then
	npm run -s build >/dev/null 2>&1
fi
printf '[{"name":"seeded","path":"assets/build/admin/index.js","limit":"10 B","gzip":true}]\n' > .size-limit.json
expect_failure "size-limit" "exceeded|Size limit" npx size-limit

echo "7. Playwright + axe fails on inaccessible markup"
if npx playwright --version >/dev/null 2>&1 && [ "${SKIP_BROWSER:-0}" != "1" ]; then
	expect_failure "axe (serious/critical violations)" "image-alt|button-name|label" npx playwright test -c tests/seeded/e2e/playwright.config.ts
else
	bad "Playwright is not installed"
fi

echo
echo "Seeded JS checks: $pass passed, $fail failed."
[ "$fail" -eq 0 ]
