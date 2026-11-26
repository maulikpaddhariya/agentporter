#!/usr/bin/env bash
# Installs WooCommerce into $WP_CORE_DIR/wp-content/plugins for integration tests.
# Usage: bin/install-woocommerce.sh <latest|latest-1|latest-2|beta|X.Y.Z>
set -euo pipefail
VERSION=${1-latest}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}
DEST="$WP_CORE_DIR/wp-content/plugins"

resolve() {
	# Resolves beta / latest-N (newest patch of the Nth previous minor) from the WordPress.org plugin API.
	curl -fsSL "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=woocommerce&request%5Bfields%5D%5Bversions%5D=1" \
		| php -r '
			$want = $argv[1];
			$data = json_decode( stream_get_contents( STDIN ), true );
			$all  = array_keys( $data["versions"] ?? array() );
			usort( $all, "version_compare" );
			if ( "beta" === $want ) {
				$pre = array_values( array_filter( $all, fn( $v ) => (bool) preg_match( "/-(beta|rc)/i", $v ) ) );
				echo $pre ? end( $pre ) : "latest-stable";
				exit;
			}
			$stable = array_values( array_filter( $all, fn( $v ) => (bool) preg_match( "/^\\d+\\.\\d+\\.\\d+$/", $v ) ) );
			$by_minor = array();
			foreach ( $stable as $v ) {
				$by_minor[ implode( ".", array_slice( explode( ".", $v ), 0, 2 ) ) ] = $v;
			}
			$minors = array_keys( $by_minor );
			usort( $minors, "version_compare" );
			$n = (int) substr( $want, strlen( "latest-" ) );
			$i = count( $minors ) - 1 - $n;
			if ( $i < 0 ) { fwrite( STDERR, "Not enough WooCommerce versions\n" ); exit( 1 ); }
			echo $by_minor[ $minors[ $i ] ];
		' "$1"
}

case "$VERSION" in
	latest) zip_name="woocommerce.latest-stable.zip" ;;
	beta|latest-[0-9]*) VERSION=$(resolve "$VERSION"); zip_name="woocommerce.${VERSION}.zip" ;;
	*) zip_name="woocommerce.${VERSION}.zip" ;;
esac

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
echo "Downloading $zip_name"
curl -fsSL "https://downloads.wordpress.org/plugin/${zip_name}" -o "$tmp/woocommerce.zip"
rm -rf "$DEST/woocommerce"
mkdir -p "$DEST"
unzip -q "$tmp/woocommerce.zip" -d "$DEST"

installed=$(sed -n 's/^[ *]*Version:[[:space:]]*//p' "$DEST/woocommerce/woocommerce.php" | head -1 | tr -d '[:space:]')
echo "Installed WooCommerce $installed; verifying against WordPress.org checksums"
curl -fsSL "https://downloads.wordpress.org/plugin-checksums/woocommerce/${installed}.json" -o "$tmp/checksums.json"
php -r '
	[$dir, $sums] = array_slice( $argv, 1 );
	$data  = json_decode( (string) file_get_contents( $sums ), true );
	$files = $data["files"] ?? array();
	if ( count( $files ) < 100 ) { fwrite( STDERR, "Checksum list missing or too short\n" ); exit( 1 ); }
	$bad = 0;
	foreach ( $files as $path => $hashes ) {
		$file = $dir . "/" . $path;
		$want = (array) ( $hashes["sha256"] ?? array() );
		if ( ! is_file( $file ) || ! in_array( hash_file( "sha256", $file ), $want, true ) ) {
			fwrite( STDERR, "Checksum mismatch: $path\n" );
			++$bad;
		}
	}
	if ( $bad > 0 ) { exit( 1 ); }
	echo "Verified " . count( $files ) . " files.\n";
' "$DEST/woocommerce" "$tmp/checksums.json"
