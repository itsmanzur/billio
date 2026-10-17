<?php
/**
 * Attaches the generated PDF invoice to the order-complete customer email.
 *
 * @package Billio
 */

namespace Billio\Invoice;

use Billio\Logger;
use Billio\Settings\Fields;

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

		$eligible_email_ids = Fields::email_triggers();
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
			// $email->refund is unset for some trigger paths (e.g. a manual
			// "Resend" that only has the order id, not a specific refund).
			// An order can have more than one refund, so blindly grabbing
			// get_refunds()[0] risked attaching the wrong refund's credit
			// note to the email. Only pick among refunds that already have
			// a credit note number — resending is the only case this
			// fallback is for, and a refund with no number yet was never
			// the one that triggered this email.
			$refund = self::latest_credit_noted_refund( $order );

			if ( $refund ) {
				Logger::info(
					sprintf(
						'Credit note attachment: $email->refund was not set for order #%d; fell back to refund #%d (credit note %s).',
						$order->get_id(),
						$refund->get_id(),
						(string) $refund->get_meta( '_billio_credit_note_number' )
					)
				);
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

	/**
	 * Among an order's refunds, the most recently created one that already
	 * has a credit note number assigned — used only as attach_credit_note()'s
	 * fallback when $email->refund isn't set.
	 *
	 * Public so this selection logic can be unit-tested directly (see
	 * tests/run.php) against plain refund stand-ins, instead of only
	 * indirectly through attach()/attach_credit_note(), which need a real
	 * WC_Email object.
	 *
	 * @param \WC_Order $order Parent order.
	 * @return \WC_Order_Refund|null
	 */
	public static function latest_credit_noted_refund( \WC_Order $order ) {
		$latest    = null;
		$latest_ts = -1;

		foreach ( $order->get_refunds() as $refund ) {
			if ( ! $refund instanceof \WC_Order_Refund || ! $refund->get_meta( '_billio_credit_note_number' ) ) {
				continue;
			}

			$date = $refund->get_date_created();
			$ts   = $date ? $date->getTimestamp() : 0;

			// Ties (same second, or both missing a date) broken by refund
			// id, which WooCommerce always assigns in increasing creation
			// order.
			if ( ! $latest || $ts > $latest_ts || ( $ts === $latest_ts && $refund->get_id() > $latest->get_id() ) ) {
				$latest    = $refund;
				$latest_ts = $ts;
			}
		}

		return $latest;
	}
}
