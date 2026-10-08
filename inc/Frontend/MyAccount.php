<?php
/**
 * Customer-facing invoice download: a button on My Account > Orders and on
 * the single order view, backed by an ownership-checked admin-post handler.
 *
 * @package Billio
 */

namespace Billio\Frontend;

use Billio\Invoice\FileServer;
use Billio\Invoice\Generator;
use Billio\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MyAccount {

	public function __construct() {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_order_list_action' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_view_order_link' ) );
		add_action( 'admin_post_billio_download', array( $this, 'handle_download' ) );
	}

	/**
	 * Add a "Download invoice" button to each eligible row in
	 * My Account > Orders.
	 *
	 * @param array     $actions Existing action buttons (url/name pairs).
	 * @param \WC_Order $order   The row's order.
	 * @return array
	 */
	public function add_order_list_action( $actions, $order ) {
		if ( ! $order->get_meta( '_billio_invoice_number' ) ) {
			return $actions;
		}

		$actions['billio_invoice'] = array(
			'url'  => $this->download_url( $order ),
			'name' => esc_html__( 'Download invoice', 'billio' ),
		);

		return $actions;
	}

	/**
	 * Add a "Download invoice" link under the single order view table.
	 *
	 * @param \WC_Order $order Order being viewed.
	 * @return void
	 */
	public function render_view_order_link( $order ) {
		if ( ! $order->get_meta( '_billio_invoice_number' ) ) {
			return;
		}

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( $this->download_url( $order ) ),
			esc_html__( 'Download invoice', 'billio' )
		);
	}

	/**
	 * Build the download URL for an order.
	 *
	 * Deliberately no nonce: this is a read-only GET that changes no state,
	 * and the real authorization is the ownership check in handle_download()
	 * (order's customer === logged-in user). A nonce here would only add a
	 * 12–24h expiry — a customer who leaves their My Account tab open
	 * overnight would hit a confusing "link expired" page for no actual
	 * security benefit.
	 *
	 * @param \WC_Order $order Order to link to.
	 * @return string
	 */
	private function download_url( \WC_Order $order ) {
		return admin_url( 'admin-post.php?action=billio_download&order_id=' . $order->get_id() );
	}

	/**
	 * admin-post handler: stream the invoice PDF to its owning customer.
	 *
	 * @return void
	 */
	public function handle_download() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please log in to download your invoice.', 'billio' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- deliberately no nonce (see download_url()'s doc comment): this is a read-only GET, and the ownership check below is the real authorization.
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() ) {
			wp_die( esc_html__( 'You do not have permission to access this invoice.', 'billio' ), '', array( 'response' => 403 ) );
		}

		// Never let a customer's own request mint a brand-new invoice number
		// (e.g. by hitting this URL on a pending/failed/cancelled order that
		// was never actually invoiced) — only serve a number that already
		// exists. Generating on demand is still fine for admins (see
		// OrderActions/MetaBox), just not from the customer-facing path.
		if ( ! $order->get_meta( '_billio_invoice_number' ) ) {
			wp_die( esc_html__( 'No invoice is available for this order yet.', 'billio' ), '', array( 'response' => 404 ) );
		}

		try {
			// Generator::generate() reuses the cached file when present, and
			// self-heals (rebuilds under the same number) if the file has
			// gone missing — no separate existence check needed here.
			$relative_path = Generator::generate( $order );
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf( 'Customer invoice download generation failed for order #%d: %s', $order->get_id(), $e->getMessage() )
			);
			wp_die( esc_html__( 'Could not generate the invoice. Please contact us for help.', 'billio' ), '', array( 'response' => 500 ) );
		}

		FileServer::stream(
			Generator::get_absolute_path( $relative_path ),
			'invoice-' . $order->get_meta( '_billio_invoice_number' ) . '.pdf'
		);
	}
}
