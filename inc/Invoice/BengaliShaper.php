<?php
/**
 * Best-effort visual reordering for Bengali text rendered by Dompdf.
 *
 * Dompdf has no complex-text-shaping engine (no HarfBuzz): it draws Unicode
 * codepoints in logical storage order. Bengali's pre-base dependent vowel
 * signs (ি ে ৈ) are stored AFTER the consonant they belong to but must be
 * drawn BEFORE it — without shaping, "রহিম" (Rahim) comes out as "রহমি".
 * This moves each pre-base vowel sign in front of the full consonant
 * cluster it modifies (handling conjuncts, e.g. a virama-joined cluster,
 * not just a single consonant) so the rendered PDF reads correctly.
 *
 * The two-part vowels ো (O) and ৌ (AU) are each a single codepoint that
 * draws as two visual strokes (one before the consonant, one after). Left
 * untouched, Dompdf has no glyph for the precomposed form in most fonts and
 * draws a dotted-circle placeholder instead. These are canonically
 * decomposed first (ো → ে + া, ৌ → ে + ৗ) so the resulting ে is just
 * another pre-base vowel sign the reorder pass already handles correctly,
 * and the trailing া/ৗ naturally stays in place after the consonant.
 *
 * This is a scoped, well-known workaround for non-shaping renderers — it
 * does not implement full Bengali/Indic shaping. Conjunct consonant
 * clusters (ক্ষ, দ্দ, ম্ম, ম্প, …) still render with a visible hasant
 * instead of a proper stacked/ligature glyph, and reph (the র্ in "কর্ম")
 * is not repositioned — both require real OpenType GSUB shaping (HarfBuzz),
 * which Dompdf does not have. Fixing that would mean a different rendering
 * engine (e.g. mPDF), not a workaround here.
 *
 * @package Billio
 */

namespace Billio\Invoice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BengaliShaper {

	/**
	 * Bengali consonant block (includes nukta letters) and the three
	 * pre-base dependent vowel signs, as a reusable regex fragment.
	 */
	const CONSONANT = '[\x{0995}-\x{09B9}\x{09DC}-\x{09DF}]';
	const VIRAMA    = '\x{09CD}';
	const PRE_BASE  = '[\x{09BF}\x{09C7}\x{09C8}]'; // ি ে ৈ

	/**
	 * Reorder pre-base vowel signs in a block of HTML.
	 *
	 * Safe to run over full HTML markup: the pattern only matches Bengali
	 * consonant/vowel codepoints, so tags, entities, and other scripts are
	 * left untouched.
	 *
	 * @param string $html HTML about to be handed to Dompdf.
	 * @return string
	 */
	public static function reorder( $html ) {
		// Fast-path: if the document contains no Bengali Unicode bytes
		// (U+0980 to U+09FF: \xE0\xA6 or \xE0\xA7 in UTF-8), bail out immediately
		// without running string replacements or regex over the entire document.
		if ( false === strpos( $html, "\xe0\xa6" ) && false === strpos( $html, "\xe0\xa7" ) ) {
			return $html;
		}

		$html = self::decompose_two_part_vowels( $html );

		$pattern = '/((?:' . self::CONSONANT . self::VIRAMA . ')*' . self::CONSONANT . ')(' . self::PRE_BASE . ')/u';

		return preg_replace( $pattern, '$2$1', $html );
	}

	/**
	 * Canonically decompose ো and ৌ into their two-part form so the reorder
	 * pass above can reposition the pre-base half correctly.
	 *
	 * @param string $html HTML about to be reordered.
	 * @return string
	 */
	private static function decompose_two_part_vowels( $html ) {
		return str_replace(
			array( "\u{09CB}", "\u{09CC}" ),       // ো, ৌ
			array( "\u{09C7}\u{09BE}", "\u{09C7}\u{09D7}" ), // ে+া, ে+ৗ
			$html
		);
	}
}
