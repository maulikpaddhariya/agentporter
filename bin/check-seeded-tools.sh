#!/usr/bin/env bash
# Proves each analysis/test gate fails on a seeded violation (P0-5: "each tool fails CI on a seeded
# violation"). Usage: bin/check-seeded-tools.sh <tool>...   tools: phpstan psalm rector semgrep readme
# security bench infection actionlint plugin-check (plugin-check needs PLUGIN_DIR, the installed copy).
set -uo pipefail
cd "$(dirname "$0")/.."

pass=0
fail=0
ok()  { echo "  PASS: $1"; pass=$((pass + 1)); }
bad() { echo "  FAIL: $1"; fail=$((fail + 1)); }

seeded_dir=src/Seeded
cleanup() { rm -rf "$seeded_dir"; }
trap cleanup EXIT
seed() { cleanup; mkdir -p "$seeded_dir"; for f in "$@"; do cp "tests/seeded/fixtures/$f" "$seeded_dir/$f"; done; }

# expect_failure <description> <pattern proving the right check fired> <command...>
expect_failure() {
	local description=$1 pattern=$2 output status
	shift 2
	output=$("$@" 2>&1)
	status=$?
	if [ "$status" -ne 0 ] && grep -Eq -- "$pattern" <<<"$output"; then
		ok "$description (exit $status)"
	else
		echo "$output" | tail -25
		bad "$description did not fail as expected (exit $status)"
	fi
}

semgrep_cmd() { SEMGREP_ENABLE_VERSION_CHECK=0 semgrep --metrics=off --disable-version-check --error --config .semgrep.yml "$@"; }
psalm_bin() { if [ -x tools/psalm/vendor/bin/psalm ]; then echo tools/psalm/vendor/bin/psalm; else echo "${PSALM_BIN:-psalm}"; fi; }

for tool in "$@"; do
	case "$tool" in
		phpstan)
			echo "PHPStan level 8 rejects a wrong return type"
			seed PhpstanTypeError.php
			expect_failure "PHPStan" "PhpstanTypeError\.php|should return int but returns string" vendor/bin/phpstan analyse --no-progress --memory-limit=1G --error-format=raw ;;
		psalm)
			echo "Psalm taint analysis rejects request data echoed unescaped"
			seed TaintedOutput.php
			expect_failure "Psalm taint" "TaintedHtml|TaintedTextWithQuotes" $(psalm_bin) --no-progress --no-cache --taint-analysis ;;
		rector)
			echo "Rector dry run rejects code its PHP 8.1 sets would change"
			seed RectorUpgrade.php
			expect_failure "Rector --dry-run" "RectorUpgrade\.php" vendor/bin/rector process --dry-run --no-progress-bar ;;
		semgrep)
			echo "Semgrep rejects banned functions, open routes and unguarded abilities"
			seed SemgrepViolations.php
			output=$(semgrep_cmd --json "$seeded_dir" 2>/dev/null)
			missing=""
			for rule in banned-functions backticks variable-include write-code-or-config no-raw-secret-compare rest-route-without-permission-callback public-permission-callback ability-without-guard ajax-nopriv; do
				grep -q "agentporter-$rule" <<<"$output" || missing="$missing $rule"
			done
			if [ -z "$missing" ] && ! semgrep_cmd "$seeded_dir" >/dev/null 2>&1; then ok "Semgrep (all 9 rules fire, exit non-zero)"; else bad "Semgrep rules that did not fire:${missing:- (exit code was 0)}"; fi ;;
		readme)
			echo "readme validator rejects an invalid readme"
			expect_failure "readme validator" "Stable tag must be a version|More than 5 tags|Missing short description" php bin/validate-readme.php tests/seeded/readme/readme.txt agentporter.php ;;
		security)
			echo "Security suite rejects an unregistered open route and a route any user can call"
			expect_failure "public-route registry" "seeded-open.*not in tests/security/public-routes.php" env AGENTPORTER_SEED_ROUTES=1 vendor/bin/phpunit -c phpunit-security.xml.dist --filter RouteRegistryTest
			expect_failure "authz matrix" "seeded-weak as subscriber returned" env AGENTPORTER_SEED_ROUTES=1 vendor/bin/phpunit -c phpunit-security.xml.dist --filter AuthzMatrixTest ;;
		bench)
			echo "Bench harness rejects runs over budget"
			expect_failure "bench budgets" "guard_check_ms budget|redactor_ms budget|code_memory_kb budget" env AGENTPORTER_BENCH_BUDGETS=tests/seeded/bench/budgets.php vendor/bin/phpunit -c phpunit-bench.xml.dist ;;
		infection)
			echo "Infection rejects a test suite below the minimum MSI"
			expect_failure "Infection min MSI" "lower than the required|MSI.*lower|below the minimum" bash -c 'cd tests/seeded/infection && ../../../tools/infection/vendor/bin/infection -c infection.json5 --no-progress --threads=2' ;;
		actionlint)
			echo "actionlint rejects a broken workflow"
			expect_failure "actionlint" "does-not-exist|not-an-input|potentially untrusted" ${ACTIONLINT:-actionlint} -no-color tests/seeded/workflows/broken.yml ;;
		plugin-check)
			echo "Plugin Check rejects unescaped output"
			: "${PLUGIN_DIR:?set PLUGIN_DIR to the installed plugin directory}"
			cp tests/seeded/plugin-check/seeded-violation.php "$PLUGIN_DIR/seeded-violation.php"
			output=$(wp plugin check agentporter --format=json 2>&1)
			rm -f "$PLUGIN_DIR/seeded-violation.php"
			if grep -q 'seeded-violation.php' <<<"$output" && grep -q '"type":"ERROR"' <<<"$output"; then ok "Plugin Check"; else echo "$output" | tail -20; bad "Plugin Check did not report the seeded error"; fi ;;
		*) echo "Unknown tool: $tool" >&2; exit 2 ;;
	esac
done

echo
echo "Seeded tool checks: $pass passed, $fail failed."
[ "$fail" -eq 0 ]
