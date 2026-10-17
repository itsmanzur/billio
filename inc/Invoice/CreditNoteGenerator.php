<?php
/**
 * Builds a credit note PDF for a refund — its own number series (default
 * prefix "CN", see Settings → "Credit note prefix"), separate from
 * invoices. Mirrors Generator's immutability behavior: once issued, the
 * same number and the same cached file are reused rather than silently
 * re-rendered under the same number every time it's downloaded.
 *
 * @package Billio
 */

namespace Billio\Invoice;

use Billio\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CreditNoteGenerator {

	/**
	 * Get the PDF credit note for a refund, generating it only if needed.
	 *
	 * @param \WC_Order_Refund $refund       The refund being documented.
	 * @param \WC_Order        $parent_order The original order the refund belongs to.
	 * @return string Relative path (from the uploads basedir) to the saved PDF.
	 */
	public static function generate( \WC_Order_Refund $refund, \WC_Order $parent_order ) {
		$number = $refund->get_meta( '_billio_credit_note_number' );

		if ( ! $number ) {
			$number = self::assign_number_locked( $refund );
		}

		$relative_path = $refund->get_meta( '_billio_credit_note_pdf_path' );
		if ( $relative_path && Generator::pdf_exists( Generator::get_absolute_path( $relative_path ) ) ) {
			return $relative_path;
		}

		// Locked the same way as Generator::render_and_save_locked() — two
		// concurrent requests for the same missing/not-yet-rendered credit
		// note (e.g. a refund email webhook racing a manual download)
		// would otherwise each write their own orphaned PDF file.
		$pre_lock_path = $relative_path;

		return Generator::with_order_lock(
			$refund->get_id(),
			function () use ( $refund, $parent_order, $number, $pre_lock_path ) {
				$fresh      = wc_get_order( $refund->get_id() );
				$fresh_path = $fresh ? $fresh->get_meta( '_billio_credit_note_pdf_path' ) : '';

				if ( $fresh_path && $fresh_path !== $pre_lock_path && Generator::pdf_exists( Generator::get_absolute_path( $fresh_path ) ) ) {
					$refund->update_meta_data( '_billio_credit_note_pdf_path', $fresh_path );
					return $fresh_path;
				}

				$html = TemplateEngine::render_credit_note( $parent_order, $refund, $number );

				$dompdf = Generator::make_dompdf();
				$dompdf->loadHtml( $html );
				$dompdf->setPaper( Generator::paper_size() );
				$dompdf->render();

				$new_relative_path = self::save( $dompdf->output(), $parent_order, $refund );

				$refund->update_meta_data( '_billio_credit_note_pdf_path', $new_relative_path );
				$refund->save_meta_data();

				/**
				 * Fires after a credit note PDF is freshly rendered and saved
				 * (not when an already-cached file is reused) — the
				 * Dashboard uses this to bust its cached stats/chart
				 * transients.
				 *
				 * @param \WC_Order_Refund $refund The documented refund.
				 */
				do_action( 'billio_credit_note_generated', $refund );

				return $new_relative_path;
			}
		);
	}

	/**
	 * Assign the next credit-note number to a refund, guarded by OrderLock
	 * (keyed by the refund's own ID — a separate lock namespace from order
	 * invoice locks) so a double-click on the credit-note download link
	 * can't issue two numbers for the same refund. Mirrors
	 * Generator::assign_number_locked()'s acquire → re-check-fresh-copy →
	 * assign → release shape.
	 *
	 * @param \WC_Order_Refund $refund The refund being documented.
	 * @return string The assigned (or already-existing) credit note number.
	 */
	private static function assign_number_locked( \WC_Order_Refund $refund ) {
		$lock_token = OrderLock::acquire( $refund->get_id() );
		$attempts   = 0;

		while ( ! $lock_token && $attempts < 20 ) {
			usleep( 150000 );
			++$attempts;

			$existing = self::existing_number_from_db( $refund );
			if ( $existing ) {
				return self::adopt_existing_number( $refund, $existing );
			}

			$lock_token = OrderLock::acquire( $refund->get_id() );
		}

		if ( ! $lock_token ) {
			// Same reasoning as Generator::assign_number_locked(): fail
			// loudly rather than mint a number without the lock's
			// protection. Callers already catch \Throwable.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never echoed/rendered as HTML; only logged by the caller's catch( \Throwable ) block.
			throw new \RuntimeException( sprintf( 'Billio: could not acquire credit note number lock for refund #%d.', $refund->get_id() ) );
		}

		try {
			$existing = self::existing_number_from_db( $refund );
			if ( $existing ) {
				return self::adopt_existing_number( $refund, $existing );
			}

			$number = NumberManager::get_next_number( 'credit_note' );
			$refund->update_meta_data( '_billio_credit_note_number', $number );
			$refund->update_meta_data( '_billio_credit_note_date', time() );
			// Persisted immediately, same reasoning as Generator::assign_number_locked():
			// a render failure afterward must not be able to orphan the number.
			$refund->save_meta_data();

			// Same reasoning as Generator::assign_number_locked(): verify
			// the write actually landed, since save_meta_data() returns
			// void either way, and log for manual reconciliation if not.
			$saved = wc_get_order( $refund->get_id() );
			if ( ! $saved || (string) $saved->get_meta( '_billio_credit_note_number' ) !== (string) $number ) {
				Logger::error(
					sprintf( 'Billio: credit note number %s was assigned to refund #%d but the save did not stick — reconcile manually.', $number, $refund->get_id() )
				);
			}

			return $number;
		} finally {
			OrderLock::release( $refund->get_id(), $lock_token );
		}
	}

	/**
	 * Re-fetch the refund from the database to see if a concurrent request
	 * already assigned it a number.
	 *
	 * @param \WC_Order_Refund $refund The refund being documented.
	 * @return string
	 */
	private static function existing_number_from_db( \WC_Order_Refund $refund ) {
		$fresh = wc_get_order( $refund->get_id() );
		return $fresh ? $fresh->get_meta( '_billio_credit_note_number' ) : '';
	}

	/**
	 * Copy a concurrently-assigned number (and, if the winner already
	 * finished rendering, its PDF path) onto the caller's in-memory
	 * $refund — without the path copy, generate()'s cache check below would
	 * read a stale empty path and re-render a second, orphaned PDF under
	 * the same credit note number. Mirrors Generator::adopt_existing_number().
	 *
	 * @param \WC_Order_Refund $refund   Refund being documented (caller's copy).
	 * @param string           $existing Credit note number found on the fresh DB copy.
	 * @return string
	 */
	private static function adopt_existing_number( \WC_Order_Refund $refund, $existing ) {
		$fresh = wc_get_order( $refund->get_id() );
		$refund->update_meta_data( '_billio_credit_note_number', $existing );

		if ( $fresh ) {
			$refund->update_meta_data( '_billio_credit_note_date', (int) $fresh->get_meta( '_billio_credit_note_date' ) );

			if ( $fresh->get_meta( '_billio_credit_note_pdf_path' ) ) {
				$refund->update_meta_data( '_billio_credit_note_pdf_path', $fresh->get_meta( '_billio_credit_note_pdf_path' ) );
			}
		}

		return $existing;
	}

	/**
	 * Save raw PDF bytes under uploads/billio/credit-notes/{year}/.
	 *
	 * @param string           $pdf_content  Raw PDF bytes from Dompdf::output().
	 * @param \WC_Order        $parent_order The original order.
	 * @param \WC_Order_Refund $refund       The refund.
	 * @return string Relative path from the uploads basedir.
	 */
	private static function save( $pdf_content, \WC_Order $parent_order, \WC_Order_Refund $refund ) {
		$upload_dir = wp_get_upload_dir();
		$year       = wp_date( 'Y' );
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'billio/credit-notes/' . $year;

		wp_mkdir_p( $dir );

		$filename = $parent_order->get_id() . '-' . $refund->get_id() . '-' . wp_generate_password( 24, false, false ) . '.pdf';
		$path     = trailingslashit( $dir ) . $filename;

		$written = file_put_contents( $path, $pdf_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			throw new \RuntimeException( 'Could not write credit note PDF to disk.' );
		}

		return 'billio/credit-notes/' . $year . '/' . $filename;
	}
}
