<?php
/**
 * Zero-dependency stand-in for `msgmerge` — this dev environment doesn't
 * have the full gettext-tools package (just the runtime `gettext` binary,
 * no `msgmerge`/`msgfmt`), so this hand-rolls the same job: carry forward
 * existing translations from the old .po that still match a current
 * string, fuzzy-match ones that were only slightly reworded, and leave
 * everything else blank for a human translator — never guessing a
 * translation itself.
 *
 * Usage: php bin/merge-po.php [old.po] [new.pot] [output.po]
 * Defaults to merging languages/billio.pot into languages/billio-bn_BD.po
 * in place.
 *
 * Does NOT compile a .mo — run bin/compile-mo.php yourself once the
 * untranslated/fuzzy strings this reports have been translated.
 *
 * Not shipped in the production zip (see bin/build-zip.ps1).
 */

// phpcs:ignoreFile -- dev-only build tool.

$old_po_path = $argv[1] ?? __DIR__ . '/../languages/billio-bn_BD.po';
$pot_path    = $argv[2] ?? __DIR__ . '/../languages/billio.pot';
$out_path    = $argv[3] ?? $old_po_path;

if ( ! file_exists( $old_po_path ) ) {
	fwrite( STDERR, "Error: old PO not found: $old_po_path\n" );
	exit( 1 );
}
if ( ! file_exists( $pot_path ) ) {
	fwrite( STDERR, "Error: POT not found: $pot_path\n" );
	exit( 1 );
}

/**
 * Parse a .po/.pot file into an ordered list of ['msgid' => ..., 'msgstr' => ...],
 * plus the raw header block (the leading msgid "" / msgstr "..." entry).
 *
 * @param string $path File to parse.
 * @return array{header: string, entries: array<int, array{msgid: string, msgstr: string}>}
 */
function billio_parse_po( $path ) {
	$lines    = explode( "\n", file_get_contents( $path ) );
	$entries  = array();
	$msgid    = null;
	$msgstr   = null;
	$state    = null;
	$header   = '';
	$is_first = true;

	$flush = function () use ( &$entries, &$msgid, &$msgstr, &$header, &$is_first ) {
		if ( null === $msgid ) {
			return;
		}
		if ( $is_first && '' === $msgid ) {
			// The header entry (empty msgid) — keep its msgstr block verbatim.
			$header   = (string) $msgstr;
			$is_first = false;
		} else {
			$entries[] = array( 'msgid' => $msgid, 'msgstr' => (string) $msgstr );
		}
		$msgid  = null;
		$msgstr = null;
	};

	foreach ( $lines as $line ) {
		$trimmed = trim( $line );

		if ( '' === $trimmed || 0 === strpos( $trimmed, '#' ) ) {
			continue; // Comments/blank lines never carry content in this project's PO files.
		}

		if ( preg_match( '/^msgid\s+"(.*)"$/', $trimmed, $m ) ) {
			$flush();
			$msgid = stripcslashes( $m[1] );
			$state = 'msgid';
		} elseif ( preg_match( '/^msgstr\s+"(.*)"$/', $trimmed, $m ) ) {
			$msgstr = stripcslashes( $m[1] );
			$state  = 'msgstr';
		} elseif ( preg_match( '/^"(.*)"$/', $trimmed, $m ) ) {
			$unquoted = stripcslashes( $m[1] );
			if ( 'msgid' === $state ) {
				$msgid .= $unquoted;
			} elseif ( 'msgstr' === $state ) {
				$msgstr .= $unquoted;
			}
		}
	}
	$flush();

	return array( 'header' => $header, 'entries' => $entries );
}

/**
 * Escape a string for a .po "..." literal.
 *
 * @param string $str Raw string.
 * @return string
 */
function billio_po_escape( $str ) {
	return str_replace( array( '\\', '"', "\n" ), array( '\\\\', '\\"', '\\n' ), $str );
}

$old = billio_parse_po( $old_po_path );
$pot = billio_parse_po( $pot_path );

$old_by_msgid = array();
foreach ( $old['entries'] as $entry ) {
	$old_by_msgid[ $entry['msgid'] ] = $entry['msgstr'];
}

$pot_msgids = array();
foreach ( $pot['entries'] as $entry ) {
	$pot_msgids[] = $entry['msgid'];
}
$pot_msgid_set = array_fill_keys( $pot_msgids, true );

// Exact matches: kept as-is, and removed from the fuzzy-matching pool below.
$exact_claimed_old = array();
$unmatched_new     = array();
foreach ( $pot_msgids as $msgid ) {
	if ( isset( $old_by_msgid[ $msgid ] ) && '' !== $old_by_msgid[ $msgid ] ) {
		$exact_claimed_old[ $msgid ] = true;
	} else {
		$unmatched_new[] = $msgid;
	}
}

// Fuzzy pool: old strings that aren't an exact match for ANY current pot
// string (i.e. they were edited or removed), with a non-empty translation
// worth offering as a starting point.
$fuzzy_pool = array();
foreach ( $old_by_msgid as $msgid => $msgstr ) {
	if ( '' === $msgstr || isset( $pot_msgid_set[ $msgid ] ) ) {
		continue;
	}
	$fuzzy_pool[ $msgid ] = $msgstr;
}

/**
 * Similarity percentage between two strings (same formula PHP's
 * similar_text() 4th arg gives): 2 * matching_chars / (len_a + len_b) * 100.
 *
 * @param string $a First string.
 * @param string $b Second string.
 * @return float
 */
function billio_similarity( $a, $b ) {
	if ( '' === $a || '' === $b ) {
		return 0.0;
	}
	similar_text( $a, $b, $percent );
	return $percent;
}

const BILLIO_FUZZY_THRESHOLD = 55.0; // Generous but not silly — msgmerge's own default is comparably loose.

// Greedy best-match assignment: for every still-unmatched new string, find
// its best-scoring candidate in the fuzzy pool above the threshold, then
// claim that candidate so it can't also be reused for a different string.
$fuzzy_assignments = array(); // new msgid => [old msgid, old msgstr, score]
$candidates        = array_keys( $fuzzy_pool );

usort(
	$candidates,
	static function () {
		return 0; // Stable order; real ranking happens per-$new below.
	}
);

foreach ( $unmatched_new as $new_msgid ) {
	$best_score = 0.0;
	$best_old   = null;

	foreach ( $fuzzy_pool as $old_msgid => $old_msgstr ) {
		$score = billio_similarity( $new_msgid, $old_msgid );
		if ( $score > $best_score ) {
			$best_score = $score;
			$best_old   = $old_msgid;
		}
	}

	if ( null !== $best_old && $best_score >= BILLIO_FUZZY_THRESHOLD ) {
		$fuzzy_assignments[ $new_msgid ] = array(
			'old_msgid'  => $best_old,
			'old_msgstr' => $fuzzy_pool[ $best_old ],
			'score'      => $best_score,
		);
		unset( $fuzzy_pool[ $best_old ] ); // Claimed — not reusable for another string.
	}
}

// ---------------------------------------------------------------------
// Build the merged header: carry the old header's fields, but refresh
// Project-Id-Version and POT-Creation-Date from the freshly-regenerated
// .pot so the .po doesn't drift out of sync with it again.
// ---------------------------------------------------------------------

// $old['header']/$pot['header'] are the msgstr value for the empty-msgid
// entry, already stripcslashes()'d by billio_parse_po() — the literal
// "\n" that separated each header field in the source .po is by this
// point a real newline character, not the two-character sequence "\n".
$old_header_lines = explode( "\n", $old['header'] );
$pot_header_lines = explode( "\n", $pot['header'] );

function billio_header_field( array $lines, $field ) {
	foreach ( $lines as $line ) {
		if ( 0 === strpos( $line, $field . ':' ) ) {
			return trim( substr( $line, strlen( $field ) + 1 ) );
		}
	}
	return null;
}

$pot_version  = billio_header_field( $pot_header_lines, 'Project-Id-Version' );
$pot_creation = billio_header_field( $pot_header_lines, 'POT-Creation-Date' );

$merged_header_lines = array();
foreach ( $old_header_lines as $line ) {
	if ( '' === trim( $line ) ) {
		continue;
	}
	if ( null !== $pot_version && 0 === strpos( $line, 'Project-Id-Version:' ) ) {
		$merged_header_lines[] = 'Project-Id-Version: ' . $pot_version;
		continue;
	}
	if ( null !== $pot_creation && 0 === strpos( $line, 'POT-Creation-Date:' ) ) {
		$merged_header_lines[] = 'POT-Creation-Date: ' . $pot_creation;
		continue;
	}
	$merged_header_lines[] = $line;
}

// ---------------------------------------------------------------------
// Write the merged .po.
// ---------------------------------------------------------------------

$out  = "# Copyright (C) 2026 Billio\n";
$out .= "# This file is distributed under the GPLv2 or later.\n";
$out .= "msgid \"\"\n";
$out .= "msgstr \"\"\n";
foreach ( $merged_header_lines as $line ) {
	$out .= '"' . $line . "\\n\"\n";
}
$out .= "\n";

$untranslated = array();
$fuzzy        = array();

foreach ( $pot_msgids as $msgid ) {
	$is_fuzzy = isset( $fuzzy_assignments[ $msgid ] );
	$msgstr   = '';

	if ( isset( $old_by_msgid[ $msgid ] ) && '' !== $old_by_msgid[ $msgid ] ) {
		$msgstr = $old_by_msgid[ $msgid ];
	} elseif ( $is_fuzzy ) {
		$msgstr = $fuzzy_assignments[ $msgid ]['old_msgstr'];
		$fuzzy[] = array(
			'msgid'     => $msgid,
			'old_msgid' => $fuzzy_assignments[ $msgid ]['old_msgid'],
			'score'     => $fuzzy_assignments[ $msgid ]['score'],
		);
	} else {
		$untranslated[] = $msgid;
	}

	if ( $is_fuzzy ) {
		$out .= "#, fuzzy\n";
	}
	$out .= 'msgid "' . billio_po_escape( $msgid ) . "\"\n";
	$out .= 'msgstr "' . billio_po_escape( $msgstr ) . "\"\n\n";
}

file_put_contents( $out_path, $out );

// ---------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------

$kept = count( $pot_msgids ) - count( $untranslated ) - count( $fuzzy );

echo "Merged into: $out_path\n";
echo count( $pot_msgids ) . " total strings — {$kept} kept (exact match), " . count( $fuzzy ) . " fuzzy, " . count( $untranslated ) . " untranslated.\n";

if ( $fuzzy ) {
	echo "\nFUZZY (reused a similar old translation — please review):\n";
	foreach ( $fuzzy as $f ) {
		printf( "  [%.0f%%] \"%s\"\n        was: \"%s\"\n", $f['score'], $f['msgid'], $f['old_msgid'] );
	}
}

if ( $untranslated ) {
	echo "\nUNTRANSLATED:\n";
	foreach ( $untranslated as $msgid ) {
		echo '  "' . $msgid . "\"\n";
	}
}
