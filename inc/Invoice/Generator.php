<?php
/**
 * Builds the invoice PDF for an order via Dompdf and saves it to the
 * protected uploads/billio directory.
 *
 * @package Billio
 */

namespace Billio\Invoice;

use Dompdf\Dompdf;
use Dompdf\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Generator {

	/**
	 * Get the PDF invoice for an order, generating it only if needed.
	 *
	 * Reuses the existing invoice number if one was already assigned, and
	 * reuses the existing PDF file if it's still on disk — invoices are
	 * immutable once issued (same number, same content) rather than being
	 * silently re-rendered (and re-saved as a new orphaned file) every time
	 * someone downloads or an email re-sends. If the cached file is missing
	 * (restored backup, migration), it self-heals by rebuilding it under the
	 * same number instead of 404ing forever.
	 *
	 * @param \WC_Order $order Order to invoice.
	 * @return string Relative path (from the uploads basedir) to the saved PDF.
	 */
	public static function generate( \WC_Order $order ) {
		$invoice_number = $order->get_meta( '_billio_invoice_number' );

		if ( ! $invoice_number ) {
			$invoice_number = self::assign_number_locked( $order );
		}

		$relative_path = $order->get_meta( '_billio_invoice_pdf_path' );
		if ( $relative_path && file_exists( self::get_absolute_path( $relative_path ) ) ) {
			return $relative_path;
		}

		return self::render_and_save( $order, $invoice_number );
	}

	/**
	 * Force a fresh render under the order's existing invoice number (or
	 * assign one first, if somehow missing) — for an explicit admin
	 * "Regenerate" action, e.g. after fixing company info. Never reissues a
	 * new number for an already-numbered order.
	 *
	 * @param \WC_Order $order Order to re-render.
	 * @return string Relative path (from the uploads basedir) to the saved PDF.
	 */
	public static function regenerate( \WC_Order $order ) {
		$invoice_number = $order->get_meta( '_billio_invoice_number' );

		if ( ! $invoice_number ) {
			$invoice_number = self::assign_number_locked( $order );
		}

		return self::render_and_save( $order, $invoice_number );
	}

	/**
	 * Assign the next sequential number to an order, guarded by OrderLock
	 * so two near-simultaneous requests for the same order can't each
	 * consume a different number. Persists the number immediately (before
	 * any PDF rendering is attempted) so a render failure afterward can
	 * never orphan a consumed number — closes the gap risk for stores that
	 * need gapless sequential invoice numbering.
	 *
	 * @param \WC_Order $order Order being invoiced.
	 * @return string The assigned (or already-existing, from a concurrent
	 *                request that finished first) invoice number.
	 */
	private static function assign_number_locked( \WC_Order $order ) {
		$locked   = OrderLock::acquire( $order->get_id() );
		$attempts = 0;

		// Someone else is already assigning this order a number — wait for
		// them rather than risk a double-assignment (bounded: ~3s, then
		// fail open so a stuck lock can never permanently block invoicing).
		while ( ! $locked && $attempts < 20 ) {
			usleep( 150000 );
			++$attempts;

			$existing = self::existing_number_from_db( $order );
			if ( $existing ) {
				return self::adopt_existing_number( $order, $existing );
			}

			$locked = OrderLock::acquire( $order->get_id() );
		}

		try {
			$existing = self::existing_number_from_db( $order );
			if ( $existing ) {
				return self::adopt_existing_number( $order, $existing );
			}

			$invoice_number = NumberManager::get_next_number();
			$order->update_meta_data( '_billio_invoice_number', $invoice_number );
			$order->update_meta_data( '_billio_invoice_date', time() );
			$order->save_meta_data();

			return $invoice_number;
		} finally {
			if ( $locked ) {
				OrderLock::release( $order->get_id() );
			}
		}
	}

	/**
	 * Re-fetch the order from the database to see if a concurrent request
	 * already assigned it a number — the caller's in-memory $order object
	 * wouldn't reflect a change made by a different PHP process.
	 *
	 * @param \WC_Order $order Order being invoiced.
	 * @return string
	 */
	private static function existing_number_from_db( \WC_Order $order ) {
		$fresh = wc_get_order( $order->get_id() );
		return $fresh ? $fresh->get_meta( '_billio_invoice_number' ) : '';
	}

	/**
	 * Copy a concurrently-assigned number/date onto the caller's order object.
	 *
	 * @param \WC_Order $order    Order being invoiced (caller's copy).
	 * @param string    $existing Invoice number found on the fresh DB copy.
	 * @return string
	 */
	private static function adopt_existing_number( \WC_Order $order, $existing ) {
		$fresh = wc_get_order( $order->get_id() );
		$order->update_meta_data( '_billio_invoice_number', $existing );
		$order->update_meta_data( '_billio_invoice_date', $fresh ? (int) $fresh->get_meta( '_billio_invoice_date' ) : time() );

		// Also adopt the PDF path if the winning process already finished
		// rendering by the time we got here — without this, generate()'s
		// file_exists() check below reads the stale (empty) path still on
		// this in-memory $order and re-renders a second, orphaned PDF file
		// under the same invoice number.
		if ( $fresh && $fresh->get_meta( '_billio_invoice_pdf_path' ) ) {
			$order->update_meta_data( '_billio_invoice_pdf_path', $fresh->get_meta( '_billio_invoice_pdf_path' ) );
		}

		return $existing;
	}

	/**
	 * Render the template and save the resulting PDF.
	 *
	 * @param \WC_Order $order          Order to invoice.
	 * @param string    $invoice_number Already-assigned invoice number.
	 * @return string Relative path (from the uploads basedir) to the saved PDF.
	 */
	private static function render_and_save( \WC_Order $order, $invoice_number ) {
		$html = TemplateEngine::render( $order, $invoice_number );

		$dompdf = self::make_dompdf();
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( self::paper_size() );
		$dompdf->render();

		$old_relative_path = $order->get_meta( '_billio_invoice_pdf_path' );
		$relative_path     = self::save( $dompdf->output(), $order );

		$order->update_meta_data( '_billio_invoice_pdf_path', $relative_path );
		$order->save_meta_data();

		// Regenerating (or self-healing a missing file) always writes a new,
		// differently-named file — without this, every regenerate leaves the
		// previous PDF orphaned on disk forever.
		if ( $old_relative_path && $old_relative_path !== $relative_path ) {
			$old_absolute_path = self::get_absolute_path( $old_relative_path );
			if ( file_exists( $old_absolute_path ) ) {
				wp_delete_file( $old_absolute_path );
			}
		}

		/**
		 * Fires after an invoice PDF is (re)rendered and saved — the
		 * Dashboard uses this to bust its cached stats/chart transients.
		 * Fires on regenerate too (not just first-generate); that's a
		 * harmless extra cache recompute, never stale/incorrect data.
		 *
		 * @param \WC_Order $order The invoiced order.
		 */
		do_action( 'billio_invoice_generated', $order );

		return $relative_path;
	}

	/**
	 * Build a configured Dompdf instance with the Bangla-capable font
	 * registered, PHP execution inside rendered HTML disabled, and no
	 * remote fetches allowed (all assets must be embedded, e.g. base64).
	 *
	 * Public so other document types (e.g. PackingSlipGenerator) share the
	 * exact same font/security configuration instead of duplicating it.
	 *
	 * @return Dompdf
	 */
	public static function make_dompdf() {
		$cache_dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'billio/font-cache';
		wp_mkdir_p( $cache_dir );

		$options = new Options();
		$options->setIsRemoteEnabled( false );
		$options->setIsPhpEnabled( false );
		$options->setDefaultFont( 'Noto Sans Bengali' );
		$options->setFontDir( $cache_dir );
		$options->setFontCache( $cache_dir );
		$options->setTempDir( $cache_dir );
		// Local file reads (our bundled fonts, via registerFont()) are
		// rejected unless their realpath falls under one of these roots.
		$options->setChroot( array( BILLIO_PATH, wp_get_upload_dir()['basedir'] ) );

		$dompdf = new Dompdf( $options );
		self::register_fonts( $dompdf );

		return $dompdf;
	}

	/**
	 * Register the bundled Noto Sans Bengali faces so Bangla company
	 * names/addresses render correctly instead of as missing-glyph boxes.
	 *
	 * Dompdf skips re-converting a face if its font cache entry already
	 * exists, so calling this on every request is cheap after the first.
	 *
	 * @param Dompdf $dompdf Dompdf instance to register fonts into.
	 * @return void
	 */
	private static function register_fonts( Dompdf $dompdf ) {
		$font_metrics = $dompdf->getFontMetrics();

		$font_metrics->registerFont(
			array(
				'family' => 'Noto Sans Bengali',
				'style'  => 'normal',
				'weight' => 'normal',
			),
			self::to_file_uri( BILLIO_PATH . 'fonts/NotoSansBengali-Regular.ttf' )
		);

		$font_metrics->registerFont(
			array(
				'family' => 'Noto Sans Bengali',
				'style'  => 'normal',
				'weight' => 'bold',
			),
			self::to_file_uri( BILLIO_PATH . 'fonts/NotoSansBengali-Bold.ttf' )
		);
	}

	/**
	 * Convert a filesystem path to a file:// URI.
	 *
	 * Dompdf's FontMetrics::registerFont() runs its $remoteFile argument
	 * through parse_url() to resolve a protocol; a raw Windows path such as
	 * "C:\...\font.ttf" gets its drive letter misread as the URL scheme
	 * ("C"), which isn't in the allowed-protocols list, so the font is
	 * silently rejected and Dompdf falls back to a core font with no Bangla
	 * glyphs. A proper file:// URI parses correctly on every platform.
	 *
	 * Deliberately two slashes, not three: Dompdf's Options::validateLocalUri()
	 * resolves the local path by naively stripping the literal "file://"
	 * prefix and passing the remainder straight to realpath(). On Windows, a
	 * standard three-slash file:// URI would leave a leading "/" before the
	 * drive letter (e.g. "/C:/..."), which realpath() can't resolve. Since
	 * *nix paths already start with "/", "file://" + path is correct on both
	 * platforms without special-casing.
	 *
	 * @param string $path Absolute filesystem path.
	 * @return string
	 */
	private static function to_file_uri( $path ) {
		return 'file://' . str_replace( '\\', '/', $path );
	}

	/**
	 * Save raw PDF bytes under uploads/billio/{year}/, with an unguessable
	 * filename suffix. This is defense in depth alongside the .htaccess
	 * deny-all — the .htaccess rule only works on Apache, so on Nginx (which
	 * ignores .htaccess entirely) the random suffix is the only thing
	 * standing between a guessed order ID and the file; 24 characters keeps
	 * that practically unguessable even though files are always served
	 * through FileServer's authenticated stream, never linked directly.
	 *
	 * @param string    $pdf_content Raw PDF bytes from Dompdf::output().
	 * @param \WC_Order $order       Order the invoice belongs to.
	 * @return string Relative path from the uploads basedir.
	 */
	private static function save( $pdf_content, \WC_Order $order ) {
		$upload_dir = wp_get_upload_dir();
		$year       = wp_date( 'Y' );
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'billio/' . $year;

		wp_mkdir_p( $dir );

		$filename = $order->get_id() . '-' . wp_generate_password( 24, false, false ) . '.pdf';
		$path     = trailingslashit( $dir ) . $filename;

		file_put_contents( $path, $pdf_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return 'billio/' . $year . '/' . $filename;
	}

	/**
	 * Resolve a relative invoice path (as stored in order meta) to an
	 * absolute filesystem path.
	 *
	 * @param string $relative_path Relative path from the uploads basedir.
	 * @return string
	 */
	public static function get_absolute_path( $relative_path ) {
		$base_dir       = trailingslashit( wp_get_upload_dir()['basedir'] );
		$candidate      = $base_dir . $relative_path;
		$real_base_dir  = realpath( $base_dir );
		$real_candidate = realpath( $candidate );

		// Defense in depth: confirm the resolved path actually stays inside
		// the uploads directory before handing it to FileServer's readfile().
		// Meta is only ever written by this plugin's own code today, but a
		// future bug/migration/corrupted row shouldn't be able to turn into
		// a path-traversal read. On any resolution failure (e.g. the file is
		// simply missing), fall through to the raw concatenation — it will
		// harmlessly fail FileServer's existing is_readable() check.
		if ( $real_base_dir && $real_candidate && 0 !== strpos( $real_candidate, $real_base_dir ) ) {
			return $base_dir; // Deliberately not readable as a file — fails is_readable() safely.
		}

		return $candidate;
	}

	/**
	 * The configured Dompdf paper size — shared by every document type
	 * (invoices, packing slips, credit notes) instead of each hardcoding
	 * its own.
	 *
	 * @return string 'A4' or 'Letter'.
	 */
	public static function paper_size() {
		return get_option( 'billio_paper_size', 'A4' );
	}
}
