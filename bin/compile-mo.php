<?php
/**
 * Simple PO to MO compiler.
 *
 * Usage: php bin/compile-mo.php [path/to/file.po] [path/to/file.mo]
 */

if ( $argc < 3 ) {
	$po_file = __DIR__ . '/../languages/billio-bn_BD.po';
	$mo_file = __DIR__ . '/../languages/billio-bn_BD.mo';
} else {
	$po_file = $argv[1];
	$mo_file = $argv[2];
}

if ( ! file_exists( $po_file ) ) {
	fwrite( STDERR, "Error: PO file not found: $po_file\n" );
	exit( 1 );
}

$po_content = file_get_contents( $po_file );

// Parse PO entries
$lines   = explode( "\n", $po_content );
$entries = array();
$msgid   = null;
$msgstr  = null;
$state   = null; // 'msgid' or 'msgstr'

foreach ( $lines as $line ) {
	$line = trim( $line );

	if ( '' === $line || strpos( $line, '#' ) === 0 ) {
		if ( null !== $msgid && null !== $msgstr ) {
			$entries[ $msgid ] = $msgstr;
			$msgid  = null;
			$msgstr = null;
			$state  = null;
		}
		continue;
	}

	if ( preg_match( '/^msgid\s+"(.*)"$/', $line, $m ) ) {
		if ( null !== $msgid && null !== $msgstr ) {
			$entries[ $msgid ] = $msgstr;
			$msgstr = null;
		}
		$msgid = stripcslashes( $m[1] );
		$state = 'msgid';
	} elseif ( preg_match( '/^msgstr\s+"(.*)"$/', $line, $m ) ) {
		$msgstr = stripcslashes( $m[1] );
		$state  = 'msgstr';
	} elseif ( preg_match( '/^"(.*)"$/', $line, $m ) ) {
		$unquoted = stripcslashes( $m[1] );
		if ( 'msgid' === $state ) {
			$msgid .= $unquoted;
		} elseif ( 'msgstr' === $state ) {
			$msgstr .= $unquoted;
		}
	}
}

if ( null !== $msgid && null !== $msgstr ) {
	$entries[ $msgid ] = $msgstr;
}

// Remove empty translations except the header entry (empty msgid)
$filtered = array();
foreach ( $entries as $id => $str ) {
	if ( $id === '' || $str !== '' ) {
		$filtered[ $id ] = $str;
	}
}

// Gettext MO requires keys sorted by strcmp
ksort( $filtered, SORT_STRING );

$count = count( $filtered );

// MO header: 7 * 4 = 28 bytes
// Originals table: count * 8 bytes (each: length (4), offset (4))
// Translations table: count * 8 bytes
$header_size       = 28;
$orig_table_offset = $header_size;
$trans_table_offset = $orig_table_offset + ( $count * 8 );
$strings_offset    = $trans_table_offset + ( $count * 8 );

$orig_table  = '';
$trans_table = '';
$orig_heap   = '';
$trans_heap  = '';

$current_orig_offset = $strings_offset;

// Collect original strings heap and build original table
$orig_offsets = array();
foreach ( $filtered as $id => $str ) {
	$len            = strlen( $id );
	$orig_table    .= pack( 'V2', $len, $current_orig_offset );
	$orig_heap     .= $id . "\0";
	$current_orig_offset += $len + 1;
}

$current_trans_offset = $current_orig_offset;

// Collect translated strings heap and build translation table
foreach ( $filtered as $id => $str ) {
	$len            = strlen( $str );
	$trans_table   .= pack( 'V2', $len, $current_trans_offset );
	$trans_heap    .= $str . "\0";
	$current_trans_offset += $len + 1;
}

$mo_data = pack(
	'V7',
	0x950412de,          // Magic
	0,                   // Format revision
	$count,              // Number of strings
	$orig_table_offset,  // Offset of table with original strings
	$trans_table_offset, // Offset of table with translation strings
	0,                   // Size of hashing table
	0                    // Offset of hashing table
);

$mo_data .= $orig_table;
$mo_data .= $trans_table;
$mo_data .= $orig_heap;
$mo_data .= $trans_heap;

file_put_contents( $mo_file, $mo_data );

echo "Compiled " . count( $filtered ) . " strings from $po_file into $mo_file (" . strlen( $mo_data ) . " bytes)\n";
