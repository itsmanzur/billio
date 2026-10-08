<?php
/**
 * Dev tool: regex-scans every .php file for translator-function calls and
 * writes languages/billio.pot. Not shipped in the production zip (see the
 * build's exclude list) — WP-CLI's `wp i18n make-pot` isn't available in
 * every dev environment, this is a zero-dependency stand-in for this plugin's
 * own build process.
 *
 * Usage: php bin/make-pot.php
 */

$root = dirname( __DIR__ );

$functions = array( '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e' );
$pattern   = '/\b(?:' . implode( '|', $functions ) . ')\(\s*((?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")|.*?)\s*,\s*[\'"]billio[\'"]\s*\)/s';

$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);

$strings = array(); // text => array of 'file:line'

foreach ( $files as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}

	$path = str_replace( '\\', '/', $file->getPathname() );
	if ( strpos( $path, '/vendor/' ) !== false || strpos( $path, '/bin/' ) !== false ) {
		continue;
	}

	$contents = file_get_contents( $file->getPathname() );
	$relative = ltrim( str_replace( str_replace( '\\', '/', $root ), '', $path ), '/' );

	if ( ! preg_match_all( $pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}

	foreach ( $matches[1] as $match ) {
		list( $raw, $offset ) = $match;

		$text = $raw;
		if ( '' !== $text && ( $text[0] === '\'' || $text[0] === '"' ) ) {
			$quote = $text[0];
			$text  = substr( $text, 1, -1 );
			$text  = $quote === '\'' ? str_replace( array( '\\\'', '\\\\' ), array( '\'', '\\' ), $text ) : stripcslashes( $text );
		} else {
			// A dynamic (non-literal) first argument — not extractable.
			continue;
		}

		$line                = substr_count( $contents, "\n", 0, $offset ) + 1;
		$strings[ $text ][] = $relative . ':' . $line;
	}
}

ksort( $strings );

$version_file = file_get_contents( $root . '/billio.php' );
preg_match( '/Version:\s*([0-9.]+)/', $version_file, $v_match );
$version = $v_match[1] ?? '0.0.0';

$header = "# Copyright (C) 2026 Billio\n"
	. "# This file is distributed under the GPLv2 or later.\n"
	. "msgid \"\"\n"
	. "msgstr \"\"\n"
	. "\"Project-Id-Version: Billio {$version}\\n\"\n"
	. "\"Report-Msgid-Bugs-To: \\n\"\n"
	. "\"MIME-Version: 1.0\\n\"\n"
	. "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
	. "\"Content-Transfer-Encoding: 8bit\\n\"\n"
	. "\"X-Generator: bin/make-pot.php\\n\"\n\n";

$out = $header;

foreach ( $strings as $text => $locations ) {
	foreach ( $locations as $location ) {
		$out .= '#: ' . $location . "\n";
	}
	$escaped = str_replace( array( '\\', '"', "\n" ), array( '\\\\', '\\"', '\\n' ), $text );
	$out    .= 'msgid "' . $escaped . "\"\n";
	$out    .= "msgstr \"\"\n\n";
}

file_put_contents( $root . '/languages/billio.pot', $out );

echo 'Wrote ' . count( $strings ) . " unique strings to languages/billio.pot\n";
