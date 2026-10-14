<?php
/**
 * Customer-facing invoice download: a button on My Account > Orders and on
 * the single order view, backed by an ownership-checked admin-post handler.
 *
 * @package Billio
 */

namespace Billio\Frontend;

use Billio\Invoice\CreditNoteGenerator;
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
		add_action( 'woocommerce_thankyou', array( $this, 'render_thankyou_button' ), 15 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_post_billio_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_nopriv_billio_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_billio_customer_credit_note', array( $this, 'handle_credit_note_download' ) );
		add_action( 'admin_post_nopriv_billio_customer_credit_note', array( $this, 'handle_credit_note_download' ) );
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
	 * Add a "Download invoice" (and credit note, if refunded) link under the
	 * single order view table.
	 *
	 * @param \WC_Order $order Order being viewed.
	 * @return void
	 */
	public function render_view_order_link( $order ) {
		if ( $order->get_meta( '_billio_invoice_number' ) ) {
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( $this->download_url( $order ) ),
				esc_html__( 'Download invoice', 'billio' )
			);
		}

		$refunds = $order->get_refunds();
		foreach ( $refunds as $refund ) {
			if ( $refund->get_meta( '_billio_credit_note_number' ) ) {
				printf(
					'<p><a class="button" href="%s">%s (%s)</a></p>',
					esc_url( $this->credit_note_download_url( $order, $refund ) ),
					esc_html__( 'Download credit note', 'billio' ),
					esc_html( $refund->get_meta( '_billio_credit_note_number' ) )
				);
			}
		}
	}

	/**
	 * Enqueue frontend stylesheet on Thank You and View Order pages.
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		if ( ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
			|| ( function_exists( 'is_view_order_page' ) && is_view_order_page() ) ) {
			wp_enqueue_style(
				'billio-frontend',
				BILLIO_URL . 'assets/css/frontend.css',
				array(),
				BILLIO_VERSION
			);
		}
	}

	/**
	 * Render a prominent "Download invoice" banner and button on the checkout
	 * Thank You (order-received) screen.
	 *
	 * @param int $order_id Newly placed order ID.
	 * @return void
	 */
	public function render_thankyou_button( $order_id ) {
		if ( ! get_option( 'billio_show_thankyou_download', true ) ) {
			return;
		}

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			return;
		}

		$is_eligible = (bool) $order->get_meta( '_billio_invoice_number' )
			|| $order->is_paid()
			|| $order->has_status( array( 'processing', 'completed' ) );

		if ( ! $is_eligible ) {
			return;
		}

		printf(
			'<div class="billio-thankyou-wrap">' .
			'<div class="billio-thankyou-content"><strong class="billio-thankyou-title">%s</strong><span class="billio-thankyou-desc">%s</span></div>' .
			'<a class="button billio-thankyou-btn" href="%s">%s</a>' .
			'</div>',
			esc_html__( 'Need your invoice?', 'billio' ),
			esc_html__( 'Download a PDF copy of your official order invoice.', 'billio' ),
			esc_url( $this->download_url( $order ) ),
			esc_html__( 'Download invoice (PDF)', 'billio' )
		);
	}

	/**
	 * Build the download URL for an order.
	 *
	 * Includes order_key so both logged-in customers and guests (on the
	 * thank you / view order screen) can securely access their invoice.
	 *
	 * @param \WC_Order $order Order to link to.
	 * @return string
	 */
	private function download_url( \WC_Order $order ) {
		return add_query_arg(
			array(
				'action'    => 'billio_download',
				'order_id'  => $order->get_id(),
				'order_key' => $order->get_order_key(),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Build the customer download URL for a credit note.
	 *
	 * @param \WC_Order        $order  Order the refund belongs to.
	 * @param \WC_Order_Refund $refund The refund.
	 * @return string
	 */
	private function credit_note_download_url( \WC_Order $order, \WC_Order_Refund $refund ) {
		return add_query_arg(
			array(
				'action'    => 'billio_customer_credit_note',
				'order_id'  => $order->get_id(),
				'refund_id' => $refund->get_id(),
				'order_key' => $order->get_order_key(),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * admin-post handler: stream the invoice PDF to its owning customer or guest with valid order_key.
	 *
	 * @return void
	 */
	public function handle_download() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET; authorized by ownership, admin capability, or valid order_key check below.
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'billio' ), '', array( 'response' => 404 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';

		$is_owner      = is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id();
		$is_admin      = current_user_can( 'manage_woocommerce' );
		$has_valid_key = ! empty( $order_key ) && hash_equals( (string) $order->get_order_key(), (string) $order_key );

		if ( ! $is_owner && ! $is_admin && ! $has_valid_key ) {
			if ( ! is_user_logged_in() ) {
				wp_die( esc_html__( 'Please log in to download your invoice.', 'billio' ), '', array( 'response' => 403 ) );
			}
			wp_die( esc_html__( 'You do not have permission to access this invoice.', 'billio' ), '', array( 'response' => 403 ) );
		}

		// Only serve an existing invoice, or generate one on demand if the order
		// is paid / processing / completed. Never mint an invoice for
		// pending/failed/cancelled orders from the customer path.
		$is_eligible = (bool) $order->get_meta( '_billio_invoice_number' )
			|| $order->is_paid()
			|| $order->has_status( array( 'processing', 'completed' ) );

		if ( ! $is_eligible ) {
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

	/**
	 * admin-post handler: stream the credit note PDF to its owning customer.
	 *
	 * @return void
	 */
	public function handle_credit_note_download() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET; authorized by ownership, admin capability, or valid order_key check below.
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$refund_id = isset( $_GET['refund_id'] ) ? absint( $_GET['refund_id'] ) : 0;
		$order     = $order_id ? wc_get_order( $order_id ) : false;
		$refund    = $refund_id ? wc_get_order( $refund_id ) : false;

		if ( ! $order || ! $refund instanceof \WC_Order_Refund || (int) $refund->get_parent_id() !== $order->get_id() ) {
			wp_die( esc_html__( 'Credit note not found.', 'billio' ), '', array( 'response' => 404 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';

		$is_owner      = is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id();
		$is_admin      = current_user_can( 'manage_woocommerce' );
		$has_valid_key = ! empty( $order_key ) && hash_equals( (string) $order->get_order_key(), (string) $order_key );

		if ( ! $is_owner && ! $is_admin && ! $has_valid_key ) {
			if ( ! is_user_logged_in() ) {
				wp_die( esc_html__( 'Please log in to download your credit note.', 'billio' ), '', array( 'response' => 403 ) );
			}
			wp_die( esc_html__( 'You do not have permission to access this credit note.', 'billio' ), '', array( 'response' => 403 ) );
		}

		if ( ! $refund->get_meta( '_billio_credit_note_number' ) ) {
			wp_die( esc_html__( 'No credit note is available for this refund yet.', 'billio' ), '', array( 'response' => 404 ) );
		}

		try {
			$relative_path = CreditNoteGenerator::generate( $refund, $order );
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf( 'Customer credit note download generation failed for refund #%d: %s', $refund->get_id(), $e->getMessage() )
			);
			wp_die( esc_html__( 'Could not generate the credit note. Please contact us for help.', 'billio' ), '', array( 'response' => 500 ) );
		}

		FileServer::stream(
			Generator::get_absolute_path( $relative_path ),
			'credit-note-' . $refund->get_meta( '_billio_credit_note_number' ) . '.pdf'
		);
	}
}
