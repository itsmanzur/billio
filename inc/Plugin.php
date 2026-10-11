<?php
/**
 * Plugin bootstrap — singleton that wires up every module.
 *
 * @package Billio
 */

namespace Billio;

use Billio\Admin\Dashboard;
use Billio\Admin\DashboardAjax;
use Billio\Admin\MetaBox;
use Billio\Admin\OrderActions;
use Billio\Admin\ReviewNotice;
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
		add_action( 'init', array( $this, 'load_textdomain' ) );

		Upgrader::maybe_upgrade();

		new EmailAttacher();
		new MyAccount();

		// Unconditional (not inside the is_admin() block below): an invoice
		// is very often generated from a non-admin request — a payment
		// gateway webhook completing an order fires the customer email
		// that attaches it — so the Dashboard's cache-busting must not
		// depend on the Dashboard object actually existing in that request.
		add_action( 'billio_invoice_generated', array( Dashboard::class, 'bust_stats_cache' ) );
		add_action( 'billio_credit_note_generated', array( Dashboard::class, 'bust_stats_cache' ) );
		add_action( Dashboard::DEFERRED_BUST_HOOK, array( Dashboard::class, 'deferred_bust_stats_cache' ) );

		if ( is_admin() ) {
			$order_actions = new OrderActions();
			$dashboard     = new Dashboard();
			new MetaBox( $order_actions );
			new DashboardAjax( $dashboard );
			new SettingsPage();
			new ReviewNotice();
		}
	}

	/**
	 * Load the .mo translation file for the current locale.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'billio', false, dirname( BILLIO_BASENAME ) . '/languages' );
	}
}
