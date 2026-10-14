<?php
/**
 * Version-checked migration runner.
 *
 * register_activation_hook() does NOT re-fire when WordPress updates a
 * plugin in place, so any future schema/option migration has to be driven
 * by comparing the stored version against BILLIO_VERSION on a normal boot
 * instead.
 *
 * @package Billio
 */

namespace Billio;

use Billio\Invoice\NumberManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Upgrader {

	const OPTION_VERSION = 'billio_version';

	/**
	 * Run any pending migrations, then record the current version.
	 *
	 * Cheap no-op in the common case: a single option read when already
	 * up to date.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::OPTION_VERSION, '0' );

		if ( version_compare( $installed, BILLIO_VERSION, '>=' ) ) {
			return;
		}

		if ( version_compare( $installed, '1.0.1', '<' ) ) {
			self::migrate_to_1_0_1();
		}

		if ( version_compare( $installed, '1.1.0', '<' ) ) {
			self::migrate_to_1_1_0();
		}

		if ( version_compare( $installed, '1.2.2', '<' ) ) {
			self::migrate_to_1_2_2();
		}

		if ( version_compare( $installed, '1.2.3', '<' ) ) {
			self::migrate_to_1_2_3();
		}

		if ( version_compare( $installed, '1.3.0', '<' ) ) {
			self::migrate_to_1_3_0();
		}

		update_option( self::OPTION_VERSION, BILLIO_VERSION );
	}

	/**
	 * 1.0.1: the uploads/billio/.htaccess written by earlier versions used
	 * Apache-2.4-only syntax, which 500s (instead of just denying access)
	 * on an Apache 2.2 host. Re-run Installer's dual-syntax version over it.
	 *
	 * @return void
	 */
	private static function migrate_to_1_0_1() {
		Installer::rewrite_htaccess();
	}

	/**
	 * 1.1.0: invoice numbering split into a yearly-resettable sequence
	 * (NumberManager::OPTION_SEQUENCE) and a separate, never-resetting
	 * stats counter (NumberManager::OPTION_TOTAL_COUNT). Existing installs
	 * need the new counter seeded from their current sequence value, or
	 * the Dashboard/checklist/review-notice stats would suddenly read 0.
	 *
	 * @return void
	 */
	private static function migrate_to_1_1_0() {
		if ( false === get_option( NumberManager::OPTION_TOTAL_COUNT ) ) {
			$current_sequence = (int) get_option( NumberManager::OPTION_SEQUENCE, 0 );
			add_option( NumberManager::OPTION_TOTAL_COUNT, $current_sequence, '', false );
		}
	}

	/**
	 * 1.2.2: Seed any missing settings defaults (e.g. billio_show_thankyou_download
	 * and billio_watermark_paid) into wp_options with autoload yes, so saving an
	 * unchecked state persists properly instead of no-opping.
	 *
	 * @return void
	 */
	private static function migrate_to_1_2_2() {
		Installer::seed_default_options();
	}

	/**
	 * 1.2.3: Clean up any old logo data URI transients from wp_options.
	 *
	 * @return void
	 */
	private static function migrate_to_1_2_3() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_billio_logo_uri_%' OR option_name LIKE '_transient_timeout_billio_logo_uri_%'"
		);
	}

	/**
	 * 1.3.0: Seed B2B customer tax ID setting defaults into wp_options.
	 *
	 * @return void
	 */
	private static function migrate_to_1_3_0() {
		Installer::seed_default_options();
	}
}
