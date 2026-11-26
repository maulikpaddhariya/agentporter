#!/usr/bin/env bash
# Installs WordPress core and the WordPress PHPUnit test library for integration tests.
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]
#   wp-version: "latest" (default), "nightly"/"trunk", "X.Y" (latest X.Y.Z) or "X.Y.Z".
#
# Environment:
#   WP_CORE_DIR   default: $TMPDIR/wordpress
#   WP_TESTS_DIR  default: $TMPDIR/wordpress-tests-lib
#   DB_ENGINE     "mysql" (default) or "sqlite" (local runs without a MySQL server)
#
# Sources are the official GitHub mirrors: WordPress/WordPress (built core) and
# WordPress/wordpress-develop (test library), pinned to the same tag.
set -euo pipefail

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]" >&2
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-127.0.0.1}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}
DB_ENGINE=${DB_ENGINE:-mysql}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}

CORE_REPO=https://github.com/WordPress/WordPress
DEVELOP_REPO=https://github.com/WordPress/wordpress-develop

resolve_ref() {
	local wanted=$1 tags
	case "$wanted" in
		nightly|trunk)
			echo "trunk"
			return
			;;
	esac
	tags=$(git ls-remote --tags --refs "$DEVELOP_REPO" | awk -F/ '{print $3}' | grep -E '^[0-9]+\.[0-9]+(\.[0-9]+)?$' | sort -V)
	case "$wanted" in
		latest)
			echo "$tags" | tail -1
			;;
		*.*.*)
			echo "$tags" | grep -qx "$wanted" || { echo "Unknown WordPress version $wanted" >&2; exit 1; }
			echo "$wanted"
			;;
		*)
			local match
			match=$(echo "$tags" | grep -E "^${wanted//./\\.}(\.[0-9]+)?$" | tail -1 || true)
			[ -n "$match" ] || { echo "Unknown WordPress version $wanted" >&2; exit 1; }
			echo "$match"
			;;
	esac
}

REF=$(resolve_ref "$WP_VERSION")
echo "WordPress: requested '$WP_VERSION', using '$REF'"

install_wp() {
	if [ -f "$WP_CORE_DIR/wp-includes/version.php" ]; then
		echo "Core already present in $WP_CORE_DIR"
		return
	fi
	rm -rf "$WP_CORE_DIR"
	local core_ref=$REF
	# The built-core mirror calls trunk "master".
	[ "$core_ref" = "trunk" ] && core_ref=master
	git -c advice.detachedHead=false clone -q --depth 1 --branch "$core_ref" "$CORE_REPO" "$WP_CORE_DIR"
	rm -rf "$WP_CORE_DIR/.git"
}

install_test_suite() {
	if [ -f "$WP_TESTS_DIR/includes/functions.php" ]; then
		echo "Test library already present in $WP_TESTS_DIR"
	else
		local src
		src=$(mktemp -d)
		git -c advice.detachedHead=false clone -q --depth 1 --filter=blob:none --sparse --branch "$REF" "$DEVELOP_REPO" "$src"
		git -C "$src" sparse-checkout set tests/phpunit/includes tests/phpunit/data
		mkdir -p "$WP_TESTS_DIR"
		cp -R "$src/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
		cp -R "$src/tests/phpunit/data" "$WP_TESTS_DIR/data"
		cp "$src/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config-sample.php"
		rm -rf "$src"
	fi

	local config="$WP_TESTS_DIR/wp-tests-config.php"
	cp "$WP_TESTS_DIR/wp-tests-config-sample.php" "$config"
	local core_dir
	core_dir=$(cd "$WP_CORE_DIR" && pwd)
	php -r '
		[$file, $abspath, $name, $user, $pass, $host] = array_slice($argv, 1);
		$c = file_get_contents($file);
		$c = str_replace("dirname( __FILE__ ) . \x27/src/\x27", var_export(rtrim($abspath, "/") . "/", true), $c);
		$c = str_replace("__DIR__ . \x27/src/\x27", var_export(rtrim($abspath, "/") . "/", true), $c);
		$c = str_replace("youremptytestdbnamehere", $name, $c);
		$c = str_replace("yourusernamehere", $user, $c);
		$c = str_replace("yourpasswordhere", $pass, $c);
		$c = preg_replace("/define\(\s*\x27DB_HOST\x27,\s*\x27[^\x27]*\x27\s*\);/", "define( \x27DB_HOST\x27, " . var_export($host, true) . " );", $c);
		file_put_contents($file, $c);
	' "$config" "$core_dir" "$DB_NAME" "$DB_USER" "$DB_PASS" "$DB_HOST"
	grep -q "$core_dir" "$config" || { echo "Failed to set ABSPATH in $config" >&2; exit 1; }
}

install_db() {
	if [ "$DB_ENGINE" = "sqlite" ]; then
		local dropin="$WP_CORE_DIR/wp-content/db.php"
		if [ ! -f "$dropin" ]; then
			local plugin="$WP_CORE_DIR/wp-content/plugins/sqlite-database-integration"
			rm -rf "$plugin"
			git clone -q --depth 1 https://github.com/WordPress/sqlite-database-integration "$plugin"
			# The plugin lives at the repo root in older releases and under packages/ in newer ones.
			local impl
			impl=$(dirname "$(find "$plugin" -name db.copy -not -path '*/tests/*' | head -1)")
			sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$impl#" -e "s#{SQLITE_PLUGIN}#${impl#"$WP_CORE_DIR/wp-content/plugins/"}/load.php#" \
				"$impl/db.copy" > "$dropin"
		fi
		echo "Using SQLite drop-in (local runs only; CI uses MySQL/MariaDB)."
		return
	fi
	if [ "$SKIP_DB_CREATE" = "true" ]; then
		return
	fi
	local host=${DB_HOST%%:*} port="" extra=()
	if [[ "$DB_HOST" == *:* ]]; then
		port=${DB_HOST#*:}
	fi
	if [ -n "$port" ]; then
		if [[ "$port" =~ ^[0-9]+$ ]]; then
			extra=(--host="$host" --port="$port" --protocol=tcp)
		else
			extra=(--socket="$port")
		fi
	elif [ -n "$host" ]; then
		extra=(--host="$host" --protocol=tcp)
	fi
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" "${extra[@]}" 2>/dev/null \
		|| mysql --user="$DB_USER" --password="$DB_PASS" "${extra[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`"
}

install_wp
install_test_suite
install_db
echo "Done. WP_CORE_DIR=$WP_CORE_DIR WP_TESTS_DIR=$WP_TESTS_DIR"
