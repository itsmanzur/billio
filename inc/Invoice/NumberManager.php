<?php
/**
 * Race-condition-safe sequential numbering, shared by invoices and credit
 * notes (each its own "series", with its own counter and prefix).
 *
 * @package Billio
 */

namespace Billio\Invoice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NumberManager {

	/**
	 * wp_options row holding the invoice series' raw integer counter (or,
	 * when billio_number_reset_yearly is on, the per-year counter is this
	 * key suffixed with the year). Seeded to 0 on activation by
	 * Installer::activate(), autoload=no.
	 */
	const OPTION_SEQUENCE = 'billio_invoice_seq';

	/**
	 * A separate, always-incrementing counter used only for the "Invoices
	 * (all-time)" stat (Dashboard, setup checklist, ReviewNotice) — kept
	 * distinct from OPTION_SEQUENCE so enabling yearly reset doesn't also
	 * reset what the stats report. Only the 'invoice' series increments
	 * this; credit notes are a different document type and shouldn't
	 * inflate the invoice count.
	 */
	const OPTION_TOTAL_COUNT = 'billio_invoice_total_count';

	/**
	 * Atomically increment the counter for a numbering series and return a
	 * formatted number.
	 *
	 * Uses a single UPDATE ... LAST_INSERT_ID() statement so two documents
	 * issued in the same instant can never be assigned the same number — no
	 * PHP-level lock, transaction, or custom table required.
	 *
	 * @param string $series 'invoice' (default) or 'credit_note'.
	 * @return string e.g. "INV-2026-0001".
	 */
	public static function get_next_number( $series = 'invoice' ) {
		$option_key = self::sequence_option_key( $series, (bool) get_option( 'billio_number_reset_yearly' ) );
		$sequence   = self::increment( $option_key );

		if ( null === $sequence ) {
			// Safety net: the counter row is missing (first use of a new
			// series/year, or it was deleted manually). Create it and
			// retry once rather than silently failing to number the document.
			add_option( $option_key, 0, '', false );
			$sequence = self::increment( $option_key );
		}

		// We wrote directly via $wpdb, bypassing the options API cache, so
		// the cached value of this option is now stale — drop it.
		wp_cache_delete( $option_key, 'options' );

		if ( 'invoice' === $series ) {
			self::increment_total_count();
		}

		return self::format( (int) $sequence, $series );
	}

	/**
	 * The wp_options key holding a series' counter, accounting for the
	 * yearly-reset setting.
	 *
	 * Public so SettingsPage can compute the *new* key when the yearly-reset
	 * setting is being turned on, in order to seed it from the current
	 * all-time counter instead of letting it self-heal at 0 (which would
	 * collide with already-issued numbers for the current year).
	 *
	 * @param string $series       'invoice' or 'credit_note'.
	 * @param bool   $reset_yearly Whether to use the per-year key form.
	 * @return string
	 */
	public static function sequence_option_key( $series, $reset_yearly ) {
		$base = ( 'invoice' === $series ) ? self::OPTION_SEQUENCE : 'billio_' . $series . '_seq';

		return $reset_yearly ? $base . '_' . wp_date( 'Y' ) : $base;
	}

	/**
	 * Run the atomic increment and return the new value, or null if no row
	 * was updated (counter option doesn't exist).
	 *
	 * @param string $option_key Counter option name.
	 * @return int|null
	 */
	private static function increment( $option_key ) {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1) WHERE option_name = %s",
				$option_key
			)
		);

		if ( ! $updated ) {
			return null;
		}

		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	}

	/**
	 * Bump the always-on, never-reset "how many invoices total" stat
	 * counter, atomically (same upsert-and-increment pattern, collapsed
	 * into one statement since — unlike the sequence counter above — we
	 * don't need the resulting value back).
	 *
	 * @return void
	 */
	private static function increment_total_count() {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				self::OPTION_TOTAL_COUNT
			)
		);

		wp_cache_delete( self::OPTION_TOTAL_COUNT, 'options' );
	}

	/**
	 * Format a raw sequence integer into the customer-facing document number.
	 *
	 * @param int    $sequence Raw counter value.
	 * @param string $series   'invoice' or 'credit_note'.
	 * @return string
	 */
	private static function format( $sequence, $series ) {
		$prefix  = ( 'invoice' === $series )
			? get_option( 'billio_number_prefix', 'INV' )
			: get_option( 'billio_credit_note_prefix', 'CN' );
		$year    = wp_date( 'Y' );
		$padding = max( 1, min( 10, (int) get_option( 'billio_number_padding', 4 ) ) );
		$padded  = str_pad( (string) $sequence, $padding, '0', STR_PAD_LEFT );
		$format  = get_option( 'billio_number_format', '{PREFIX}-{YEAR}-{NUMBER}' );

		$result = strtr(
			$format,
			array(
				'{PREFIX}' => $prefix,
				'{YEAR}'   => $year,
				'{NUMBER}' => $padded,
			)
		);

		// If a custom format forgot {NUMBER} entirely, every document in the
		// same year/prefix would render as the exact same string even
		// though the underlying counter is still atomic — append the raw
		// number as a fallback so the *displayed* number stays unique too.
		if ( false === strpos( $format, '{NUMBER}' ) ) {
			$result .= '-' . $padded;
		}

		return $result;
	}

	/**
	 * Format a sample number for the settings-page live preview, without
	 * touching any counter — same prefix/format/padding option reads as a
	 * real number, so the preview genuinely reflects what will be issued.
	 *
	 * @param int    $sequence Sample sequence value to render (default 1).
	 * @param string $series   'invoice' or 'credit_note'.
	 * @return string
	 */
	public static function preview_number( $sequence = 1, $series = 'invoice' ) {
		return self::format( $sequence, $series );
	}

	/**
	 * Preview the number that WOULD be assigned next, without reserving or
	 * incrementing anything — reads the real counter's current value, adds
	 * 1, and formats it through the same formatter a real assignment uses.
	 * Used by the Dashboard's "Next invoice number" display.
	 *
	 * @param string $series 'invoice' or 'credit_note'.
	 * @return string
	 */
	public static function peek_next_number( $series = 'invoice' ) {
		$option_key = self::sequence_option_key( $series, (bool) get_option( 'billio_number_reset_yearly' ) );
		$current    = (int) get_option( $option_key, 0 );

		return self::format( $current + 1, $series );
	}
}
