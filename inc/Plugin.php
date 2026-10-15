<?php
/**
 * Plugin bootstrap — singleton that wires up every module.
 *
 * @package Billio
 */

namespace Billio;

use Billio\Admin\Dashboard;
use Billio\Admin\DashboardAjax;
use Billio\Admin\DocsPage;
use Billio\Admin\MetaBox;
use Billio\Admin\OrderActions;
use Billio\Admin\ReviewNotice;
use Billio\Frontend\CheckoutTaxId;
use Billio\Frontend\MyAccount;
use Billio\Invoice\EmailAttacher;
use Billio\Settings\SettingsPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		new EmailAttacher();
		new MyAccount();
		new CheckoutTaxId();

		// Unconditional (not inside the is_admin() block below): an invoice
		// is very often generated from a non-admin request — a payment
		// gateway webhook completing an order fires the customer email
		// that attaches it — so the Dashboard's cache-busting must not
		// depend on the Dashboard object actually existing in that request.
		add_action( 'billio_invoice_generated', array( Dashboard::class, 'bust_stats_cache' ) );
		add_action( 'billio_credit_note_generated', array( Dashboard::class, 'bust_stats_cache' ) );
		add_action( Dashboard::DEFERRED_BUST_HOOK, array( Dashboard::class, 'deferred_bust_stats_cache' ) );

		// Fallback for bundled translation files before translate.wordpress.org packs exist.
		add_filter( 'load_textdomain_mofile', array( $this, 'fallback_bundled_mofile' ), 10, 2 );

		// On every request, not just admin ones: after an auto-update the
		// first request is often a frontend checkout or a payment webhook,
		// and migrations must have run before any invoice is generated.
		// A single option read when already up to date.
		Upgrader::maybe_upgrade();

		if ( is_admin() ) {
			$order_actions = new OrderActions();
			$dashboard     = new Dashboard();
			new MetaBox( $order_actions );
			new DashboardAjax( $dashboard );
			new SettingsPage();
			new DocsPage();
			new ReviewNotice();
		}
	}

	/**
	 * Fallback to bundled translation files in languages/ if the core language directory does not contain one yet.
	 *
	 * @param string $mofile Path to the .mo file.
	 * @param string $domain The domain name.
	 * @return string
	 */
	public function fallback_bundled_mofile( $mofile, $domain ) {
		if ( 'billio' === $domain && ! file_exists( $mofile ) ) {
			$bundled = BILLIO_PATH . 'languages/' . basename( $mofile );
			if ( file_exists( $bundled ) ) {
				return $bundled;
			}
		}

		return $mofile;
	}
}
