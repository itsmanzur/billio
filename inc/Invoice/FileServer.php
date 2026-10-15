<?php
/**
 * Shared "stream a PDF to the browser" helper used by both the admin and
 * customer download endpoints, after each has done its own authorization
 * check.
 *
 * @package Billio
 */

namespace Billio\Invoice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FileServer {

	/**
	 * Output a PDF file as a forced download and terminate the request.
	 *
	 * @param string $absolute_path  Absolute filesystem path to the PDF.
	 * @param string $download_name  Filename offered to the browser.
	 * @return void
	 */
	public static function stream( $absolute_path, $download_name ) {
		if ( ! Generator::pdf_exists( $absolute_path ) ) {
			wp_die( esc_html__( 'Invoice not found.', 'billio' ), '', array( 'response' => 404 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- disposition preference; authorization and nonce verified by caller endpoint.
		$disposition = ! empty( $_GET['preview'] ) ? 'inline' : 'attachment';
		self::send_headers( $download_name, filesize( $absolute_path ), $disposition );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a download; WP_Filesystem has no direct-output equivalent and would require loading the whole PDF into memory.
		readfile( $absolute_path );
		exit;
	}

	/**
	 * Output raw PDF bytes (never touching disk) as a forced download and
	 * terminate the request — used for documents like packing slips that
	 * carry customer PII and have no reason to be persisted.
	 *
	 * @param string $bytes         Raw PDF bytes.
	 * @param string $download_name Filename offered to the browser.
	 * @return void
	 */
	public static function stream_bytes( $bytes, $download_name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- disposition preference; authorization and nonce verified by caller endpoint.
		$disposition = ! empty( $_GET['preview'] ) ? 'inline' : 'attachment';
		self::send_headers( $download_name, strlen( $bytes ), $disposition );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw binary PDF content, not HTML.
		exit;
	}

	/**
	 * Send the common download headers, after clearing any open output
	 * buffers — a stray notice or whitespace earlier in the request would
	 * otherwise end up prefixed onto the PDF bytes, corrupting the file.
	 *
	 * @param string $download_name Filename offered to the browser.
	 * @param int    $length        Content-Length value.
	 * @param string $disposition   Content-Disposition ('attachment' or 'inline').
	 * @return void
	 */
	private static function send_headers( $download_name, $length, $disposition = 'attachment' ) {
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$filename = sanitize_file_name( $download_name );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'X-Content-Type-Options: nosniff' );
		// Plain filename= for old clients, plus RFC 5987 filename*= so a
		// non-ASCII number prefix (e.g. Bengali) survives intact.
		header(
			'Content-Disposition: ' . ( 'inline' === $disposition ? 'inline' : 'attachment' )
			. '; filename="' . str_replace( '"', '', $filename ) . '"'
			. "; filename*=UTF-8''" . rawurlencode( $filename )
		);
		header( 'Content-Length: ' . $length );
	}
}
