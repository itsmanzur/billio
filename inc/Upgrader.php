<?php
/**
 * Version-checked migration runner.
 *
 * register_activation_hook() does NOT re-fire when WordPress updates a
 * plugin in place, so any future schema/option migration has to be driven
 * by comparing the stored version against BILLIO_VERSION on a normal boot
 * instead.
 *
 * No migrations exist yet — 1.0.0 is Billio's first version, so there's
 * nothing to upgrade from. Add a future one here in this shape:
 *
 *     if ( version_compare( $installed, 'X.Y.Z', '<' ) ) {
 *         self::migrate_to_X_Y_Z();
 *     }
 *
 * (with a corresponding private static migrate_to_X_Y_Z() method below,
 * run in the same ascending-version order as the checks above it).
 *
 * @package Billio
 */

namespace Billio;

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

		update_option( self::OPTION_VERSION, BILLIO_VERSION );
	}
}
