<?php
/**
 * Validates readme.txt against the WordPress.org readme rules and the plugin header.
 *
 * Usage: php bin/validate-readme.php [readme.txt] [agentporter.php]
 * Exits 1 and lists every problem when the readme is invalid.
 *
 * @package AgentPorter
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPressVIPMinimum.Performance.FetchingRemoteData, WordPressVIPMinimum.Functions.StripTags, Generic.CodeAnalysis.ForLoopWithTestFunctionCall -- CLI tool.

$agentporter_root   = dirname( __DIR__ );
$agentporter_readme = $argv[1] ?? $agentporter_root . '/readme.txt';
$agentporter_plugin = $argv[2] ?? $agentporter_root . '/agentporter.php';

/**
 * Validates and returns the list of problems.
 *
 * @param string $readme Readme contents.
 * @param string $plugin Main plugin file contents.
 * @return list<string>
 */
function agentporter_readme_problems( string $readme, string $plugin ): array {
	$problems = array();
	$lines    = preg_split( '/\R/', $readme );
	if ( ! preg_match( '/^=== .+ ===$/', trim( (string) ( $lines[0] ?? '' ) ) ) ) {
		$problems[] = 'First line must be the plugin name as "=== Name ===".';
	}

	$header = array();
	$i      = 1;
	for ( $count = count( $lines ); $i < $count && '' !== trim( (string) $lines[ $i ] ); $i++ ) {
		if ( preg_match( '/^([A-Za-z ]+):\s*(.*)$/', (string) $lines[ $i ], $m ) ) {
			$header[ strtolower( trim( $m[1] ) ) ] = trim( $m[2] );
		}
	}
	foreach ( array( 'contributors', 'tags', 'requires at least', 'tested up to', 'requires php', 'stable tag', 'license', 'license uri' ) as $field ) {
		if ( '' === ( $header[ $field ] ?? '' ) ) {
			$problems[] = "Missing header field: $field.";
		}
	}

	$short = '';
	for ( $count = count( $lines ); $i < $count; $i++ ) {
		if ( '' !== trim( (string) $lines[ $i ] ) ) {
			$short = trim( (string) $lines[ $i ] );
			break;
		}
	}
	if ( '' === $short || str_starts_with( $short, '==' ) ) {
		$problems[] = 'Missing short description after the header.';
	} elseif ( mb_strlen( $short ) > 150 ) {
		$problems[] = 'Short description is longer than 150 characters.';
	} elseif ( strip_tags( $short ) !== $short ) {
		$problems[] = 'Short description must be plain text.';
	}

	$tags = array_filter( array_map( 'trim', explode( ',', $header['tags'] ?? '' ) ) );
	if ( count( $tags ) > 5 ) {
		$problems[] = 'More than 5 tags (WordPress.org ignores the rest).';
	}

	preg_match_all( '/^\s*\*\s*(Version|Requires at least|Requires PHP|License):\s*(\S+)/mi', $plugin, $pm, PREG_SET_ORDER );
	$plugin_header = array();
	foreach ( $pm as $m ) {
		$plugin_header[ strtolower( $m[1] ) ] = $m[2];
	}
	$stable = $header['stable tag'] ?? '';
	if ( 'trunk' === strtolower( $stable ) ) {
		$problems[] = 'Stable tag must be a version, not trunk.';
	} elseif ( isset( $plugin_header['version'] ) && $stable !== $plugin_header['version'] ) {
		$problems[] = "Stable tag $stable does not match plugin Version {$plugin_header['version']}.";
	}
	if ( preg_match( "/define\\(\\s*'AGENTPORTER_VERSION',\\s*'([^']+)'/", $plugin, $cm ) && $stable !== $cm[1] ) {
		$problems[] = "Stable tag $stable does not match AGENTPORTER_VERSION {$cm[1]}.";
	}
	foreach ( array( 'requires at least', 'requires php' ) as $field ) {
		if ( isset( $plugin_header[ $field ] ) && ( $header[ $field ] ?? '' ) !== $plugin_header[ $field ] ) {
			$problems[] = ucfirst( $field ) . ' differs from the plugin header.';
		}
	}
	foreach ( array( 'requires at least', 'tested up to', 'requires php' ) as $field ) {
		if ( isset( $header[ $field ] ) && ! preg_match( '/^\d+\.\d+(\.\d+)?$/', $header[ $field ] ) ) {
			$problems[] = ucfirst( $field ) . ' must be a version number.';
		}
	}
	if ( isset( $header['tested up to'], $header['requires at least'] ) && version_compare( $header['tested up to'], $header['requires at least'], '<' ) ) {
		$problems[] = 'Tested up to is lower than Requires at least.';
	}
	if ( isset( $header['license'] ) && ! preg_match( '/GPL|MIT|Apache-2|BSD|LGPL|MPL-2/i', $header['license'] ) ) {
		$problems[] = 'License must be GPL-compatible.';
	}
	if ( isset( $header['license'], $plugin_header['license'] ) && $header['license'] !== $plugin_header['license'] ) {
		$problems[] = 'License differs from the plugin header.';
	}

	foreach ( array( 'Description', 'Installation', 'Frequently Asked Questions', 'Changelog' ) as $section ) {
		if ( ! preg_match( '/^== ' . preg_quote( $section, '/' ) . ' ==$/m', $readme ) ) {
			$problems[] = "Missing section: == $section ==.";
		}
	}
	if ( '' !== $stable && ! preg_match( '/^= ' . preg_quote( $stable, '/' ) . ' =$/m', $readme ) ) {
		$problems[] = "Changelog has no entry for $stable.";
	}
	if ( strlen( $readme ) > 10240 * 2 ) {
		$problems[] = 'readme.txt is larger than 20 KB.';
	}
	return $problems;
}

$agentporter_problems = agentporter_readme_problems( (string) file_get_contents( $agentporter_readme ), (string) file_get_contents( $agentporter_plugin ) );
if ( array() === $agentporter_problems ) {
	echo "readme.txt is valid.\n";
	exit( 0 );
}
fwrite( STDERR, "readme.txt problems:\n - " . implode( "\n - ", $agentporter_problems ) . "\n" );
exit( 1 );
