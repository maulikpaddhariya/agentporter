#!/usr/bin/env bash
# Builds the release tree in dist/agentporter (files allowed by .distignore, production autoloader,
# built assets). P0-8 adds the zip, inspection and SBOM on top of this.
set -euo pipefail
cd "$(dirname "$0")/.."
target=dist/agentporter
rm -rf dist && mkdir -p "$target"
[ -f assets/build/admin/index.js ] || { echo "Run npm run build first." >&2; exit 1; }
rsync -a --exclude-from=.distignore --exclude=/vendor --exclude=/assets/build/.gitkeep ./ "$target/"
cp composer.json composer.lock "$target/"
composer install --working-dir="$target" --no-dev --no-interaction --no-progress --optimize-autoloader --classmap-authoritative
rm -f "$target/composer.json" "$target/composer.lock"
echo "Built $target"
