<?php
/**
 * Activation / deactivation lifecycle.
 *
 * @package Billio
 */

namespace Billio;

use Billio\Invoice\NumberManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Installer {

	/**
	 * Fired on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_upload_dir();
		self::seed_invoice_sequence();
		// A fresh install is already on the current version — if this were
		// left unset, Upgrader::maybe_upgrade() would see "0" on first boot
		// and think it has every past migration to run.
		update_option( Upgrader::OPTION_VERSION, BILLIO_VERSION );
		set_transient( 'billio_activation_redirect', 1, 30 );
	}

	/**
	 * Fired on plugin deactivation.
	 *
	 * Deliberately does not delete invoice files, numbering sequence, or
	 * settings — only uninstall.php removes data, matching core WP convention.
	 *
	 * @return void
	 */
	public static function deactivate() {}

	/**
	 * Create the private uploads/billio directory and lock it down from
	 * direct web access (Apache via .htaccess; directory listing via index.php).
	 *
	 * @return void
	 */
	private static function create_upload_dir() {
		$upload_dir = wp_get_upload_dir();
		$billio_dir = trailingslashit( $upload_dir['basedir'] ) . 'billio';

		if ( ! file_exists( $billio_dir ) ) {
			wp_mkdir_p( $billio_dir );
		}

		if ( ! file_exists( trailingslashit( $billio_dir ) . '.htaccess' ) ) {
			self::rewrite_htaccess();
		}

		$index = trailingslashit( $billio_dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * (Re)write uploads/billio/.htaccess with the current, correct content.
	 * Public so Upgrader can force a rewrite for installs that already have
	 * an older (Apache-2.4-only) version on disk from before this fix.
	 *
	 * @return void
	 */
	public static function rewrite_htaccess() {
		$billio_dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'billio';
		wp_mkdir_p( $billio_dir );

		// Both Apache 2.4 (mod_authz_core) and 2.2 syntax: a 2.4-only
		// "Require all denied" line 500s on an Apache 2.2 host instead of
		// just denying access.
		$contents = "<IfModule mod_authz_core.c>\n"
			. "\tRequire all denied\n"
			. "</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "\tOrder allow,deny\n"
			. "\tDeny from all\n"
			. "</IfModule>\n";

		file_put_contents( trailingslashit( $billio_dir ) . '.htaccess', $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Seed the atomic invoice-number counter and the separate all-time
	 * stats counter, if they don't exist yet.
	 *
	 * Stored with autoload=no since they're read only at invoice-generation
	 * time (or Dashboard view), never on every page load.
	 *
	 * @return void
	 */
	private static function seed_invoice_sequence() {
		if ( false === get_option( NumberManager::OPTION_SEQUENCE ) ) {
			add_option( NumberManager::OPTION_SEQUENCE, 0, '', false );
		}

		if ( false === get_option( NumberManager::OPTION_TOTAL_COUNT ) ) {
			add_option( NumberManager::OPTION_TOTAL_COUNT, 0, '', false );
		}
	}
}
