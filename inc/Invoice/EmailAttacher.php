<?php
/**
 * Attaches the generated PDF invoice to the order-complete customer email.
 *
 * @package Billio
 */

namespace Billio\Invoice;

use Billio\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EmailAttacher {

	public function __construct() {
		add_filter( 'woocommerce_email_attachments', array( $this, 'attach' ), 10, 4 );
	}

	/**
	 * Generate (if needed) and attach the invoice or credit note PDF for eligible emails.
	 *
	 * Which emails are eligible for invoices is user-configurable (Settings → "Send
	 * invoice when order is"), defaulting to just the completed-order email.
	 * Customer refund emails automatically attach the credit note PDF for the refund.
	 *
	 * @param array          $attachments  Existing attachment paths.
	 * @param string         $email_id     WC email id, e.g. "customer_completed_order", "customer_refunded_order".
	 * @param mixed          $email_object The email's subject object — a WC_Order for order emails.
	 * @param \WC_Email|null $email        The WC_Email instance (optional in WC, passed as 4th arg).
	 * @return array
	 */
	public function attach( $attachments, $email_id, $email_object, $email = null ) {
		if ( ! $email_object instanceof \WC_Order ) {
			return $attachments;
		}

		$refund_email_ids = apply_filters(
			'billio_credit_note_email_ids',
			array( 'customer_refunded_order', 'customer_partially_refunded_order' )
		);

		if ( in_array( $email_id, $refund_email_ids, true ) ) {
			return $this->attach_credit_note( $attachments, $email_object, $email );
		}

		$eligible_email_ids = get_option( 'billio_email_triggers', array( 'customer_completed_order' ) );
		$eligible_email_ids = apply_filters( 'billio_invoice_email_ids', $eligible_email_ids );

		if ( ! in_array( $email_id, $eligible_email_ids, true ) ) {
			return $attachments;
		}

		try {
			$relative_path = Generator::generate( $email_object );
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf( 'Invoice generation failed for order #%d: %s', $email_object->get_id(), $e->getMessage() )
			);
			return $attachments;
		}

		$absolute_path = Generator::get_absolute_path( $relative_path );

		if ( Generator::pdf_exists( $absolute_path ) ) {
			$attachments[] = $absolute_path;
		}

		return $attachments;
	}

	/**
	 * Generate and attach a credit note PDF to a refund email.
	 *
	 * @param array          $attachments  Existing attachment paths.
	 * @param \WC_Order      $order        The parent order.
	 * @param \WC_Email|null $email        The WC_Email instance.
	 * @return array
	 */
	private function attach_credit_note( array $attachments, \WC_Order $order, $email = null ) {
		$refund = null;

		if ( $email && ! empty( $email->refund ) && $email->refund instanceof \WC_Order_Refund ) {
			$refund = $email->refund;
		}

		if ( ! $refund ) {
			$refunds = $order->get_refunds();
			if ( ! empty( $refunds ) ) {
				$refund = $refunds[0];
			}
		}

		if ( ! $refund instanceof \WC_Order_Refund ) {
			return $attachments;
		}

		try {
			$relative_path = CreditNoteGenerator::generate( $refund, $order );
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf( 'Credit note generation failed for refund #%d (order #%d): %s', $refund->get_id(), $order->get_id(), $e->getMessage() )
			);
			return $attachments;
		}

		$absolute_path = Generator::get_absolute_path( $relative_path );

		if ( Generator::pdf_exists( $absolute_path ) ) {
			$attachments[] = $absolute_path;
		}

		return $attachments;
	}
}
