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
		add_filter( 'woocommerce_email_attachments', array( $this, 'attach' ), 10, 3 );
	}

	/**
	 * Generate (if needed) and attach the invoice PDF for eligible emails.
	 *
	 * Which emails are eligible is user-configurable (Settings → "Send
	 * invoice when order is"), defaulting to just the completed-order
	 * email. The billio_invoice_email_ids filter remains for advanced/Pro
	 * overrides on top of whatever the store picked.
	 *
	 * @param array  $attachments  Existing attachment paths.
	 * @param string $email_id     WC email id, e.g. "customer_completed_order".
	 * @param mixed  $email_object The email's subject object — a WC_Order for order emails.
	 * @return array
	 */
	public function attach( $attachments, $email_id, $email_object ) {
		if ( ! $email_object instanceof \WC_Order ) {
			return $attachments;
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

		if ( is_readable( $absolute_path ) ) {
			$attachments[] = $absolute_path;
		}

		return $attachments;
	}
}
