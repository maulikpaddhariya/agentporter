#!/usr/bin/env bash
# Lints every first-party PHP file with the current PHP binary.
# CI runs this on PHP 8.1 so any PHP 8.2+ syntax fails the build (syntax ceiling).
set -euo pipefail
cd "$(dirname "$0")/.."
echo "Linting with $(php -r 'echo PHP_VERSION;')"
failed=0
while IFS= read -r -d '' file; do
	if ! out=$(php -l "$file" 2>&1); then
		echo "$out"
		failed=1
	fi
done < <(find . -name '*.php' \
	-not -path './vendor/*' -not -path './node_modules/*' -not -path './tools/*/vendor/*' \
	-not -path './tests/seeded/fixtures/*' -not -path './build/*' -not -path './dist/*' -print0)
if [ "$failed" -ne 0 ]; then
	echo "Syntax check failed." >&2
	exit 1
fi
echo "Syntax OK."
