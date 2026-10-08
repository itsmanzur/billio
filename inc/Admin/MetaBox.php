<?php
/**
 * Order edit screen: invoice preview meta box + packing slip download.
 *
 * @package Billio
 */

namespace Billio\Admin;

use Billio\Invoice\CreditNoteGenerator;
use Billio\Invoice\FileServer;
use Billio\Invoice\Generator;
use Billio\Invoice\PackingSlipGenerator;
use Billio\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MetaBox {

	const NONCE_ACTION             = 'billio_packing_slip';
	const CREDIT_NOTE_NONCE_ACTION = 'billio_credit_note';

	/**
	 * @var OrderActions Shared renderer for the download/generate/resend buttons.
	 */
	private $order_actions;

	/**
	 * @param OrderActions $order_actions Shared button renderer (avoids duplicating markup/logic).
	 */
	public function __construct( OrderActions $order_actions ) {
		$this->order_actions = $order_actions;
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'admin_post_billio_packing_slip_download', array( $this, 'handle_packing_slip_download' ) );
		add_action( 'admin_post_billio_credit_note_download', array( $this, 'handle_credit_note_download' ) );
	}

	/**
	 * Register the meta box on whichever order-edit screen is active
	 * (legacy post screen or the HPOS "wc-orders" screen).
	 *
	 * @return void
	 */
	public function register() {
		add_meta_box(
			'billio-invoice',
			esc_html__( 'Billio Invoice', 'billio' ),
			array( $this, 'render' ),
			wc_get_page_screen_id( 'shop-order' ),
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box body.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Whatever WP/WC passes for the current screen type.
	 * @return void
	 */
	public function render( $post_or_order ) {
		$order = wc_get_order( $post_or_order );

		if ( ! $order ) {
			esc_html_e( 'Order not found.', 'billio' );
			return;
		}

		$this->order_actions->render_actions( $order );

		echo '<hr style="margin:10px 0;border-top:1px solid #dcdcde;">';

		printf(
			'<a href="%s" class="button button-small" target="_blank" rel="noopener">%s</a>',
			esc_url( $this->packing_slip_url( $order ) ),
			esc_html__( 'Packing Slip', 'billio' )
		);

		$this->render_credit_notes( $order );
	}

	/**
	 * List a download link per refund, when the order has any — each one
	 * generates (lazily, on click, same as the packing slip above) its own
	 * credit note PDF.
	 *
	 * @param \WC_Order $order Order to list refunds for.
	 * @return void
	 */
	private function render_credit_notes( \WC_Order $order ) {
		$refunds = $order->get_refunds();

		if ( empty( $refunds ) ) {
			return;
		}

		echo '<hr style="margin:10px 0;border-top:1px solid #dcdcde;">';
		echo '<div style="font-weight:600;margin-bottom:6px;">' . esc_html__( 'Credit Notes', 'billio' ) . '</div>';

		foreach ( $refunds as $refund ) {
			printf(
				'<div style="display:flex;justify-content:space-between;align-items:center;gap:6px;margin-bottom:4px;"><span>%s</span><a href="%s" class="button button-small" target="_blank" rel="noopener">%s</a></div>',
				wp_kses_post( wc_price( abs( (float) $refund->get_amount() ), array( 'currency' => $order->get_currency() ) ) ),
				esc_url( self::credit_note_url( $order, $refund ) ),
				esc_html__( 'Credit Note', 'billio' )
			);
		}
	}

	/**
	 * Build the nonce-protected packing-slip download URL for an order.
	 *
	 * @param \WC_Order $order Order to link to.
	 * @return string
	 */
	private function packing_slip_url( \WC_Order $order ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=billio_packing_slip_download&order_id=' . $order->get_id() ),
			self::NONCE_ACTION . '_' . $order->get_id()
		);
	}

	/**
	 * admin-post handler: generate (fresh, every time) and stream an order's
	 * packing slip PDF.
	 *
	 * @return void
	 */
	public function handle_packing_slip_download() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		check_admin_referer( self::NONCE_ACTION . '_' . $order_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'billio' ), '', array( 'response' => 403 ) );
		}

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'billio' ), '', array( 'response' => 404 ) );
		}

		try {
			$pdf_bytes = PackingSlipGenerator::generate( $order );
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf( 'Packing slip generation failed for order #%d: %s', $order->get_id(), $e->getMessage() )
			);
			wp_die( esc_html__( 'Could not generate the packing slip.', 'billio' ), '', array( 'response' => 500 ) );
		}

		FileServer::stream_bytes( $pdf_bytes, 'packing-slip-' . $order->get_order_number() . '.pdf' );
	}

	/**
	 * Build the nonce-protected credit-note download URL for a refund.
	 *
	 * Public/static so other admin screens (the Dashboard's recent-documents
	 * table) can reuse it without duplicating the nonce-action convention —
	 * same reasoning as OrderActions::build_download_url().
	 *
	 * @param \WC_Order        $order  The original order.
	 * @param \WC_Order_Refund $refund The refund to link to.
	 * @return string
	 */
	public static function credit_note_url( \WC_Order $order, \WC_Order_Refund $refund ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=billio_credit_note_download&order_id=' . $order->get_id() . '&refund_id=' . $refund->get_id() ),
			self::CREDIT_NOTE_NONCE_ACTION . '_' . $refund->get_id()
		);
	}

	/**
	 * admin-post handler: generate (if needed) and stream a refund's credit
	 * note PDF.
	 *
	 * @return void
	 */
	public function handle_credit_note_download() {
		$order_id  = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$refund_id = isset( $_GET['refund_id'] ) ? absint( $_GET['refund_id'] ) : 0;

		check_admin_referer( self::CREDIT_NOTE_NONCE_ACTION . '_' . $refund_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'billio' ), '', array( 'response' => 403 ) );
		}

		$order  = $order_id ? wc_get_order( $order_id ) : false;
		$refund = $refund_id ? wc_get_order( $refund_id ) : false;

		if ( ! $order || ! $refund instanceof \WC_Order_Refund ) {
			wp_die( esc_html__( 'Credit note not found.', 'billio' ), '', array( 'response' => 404 ) );
		}

		if ( (int) $refund->get_parent_id() !== $order->get_id() ) {
			wp_die( esc_html__( 'Credit note not found.', 'billio' ), '', array( 'response' => 404 ) );
		}

		try {
			$relative_path = CreditNoteGenerator::generate( $refund, $order );
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf( 'Credit note generation failed for refund #%d (order #%d): %s', $refund->get_id(), $order->get_id(), $e->getMessage() )
			);
			wp_die( esc_html__( 'Could not generate the credit note.', 'billio' ), '', array( 'response' => 500 ) );
		}

		FileServer::stream(
			Generator::get_absolute_path( $relative_path ),
			'credit-note-' . $refund->get_meta( '_billio_credit_note_number' ) . '.pdf'
		);
	}
}
