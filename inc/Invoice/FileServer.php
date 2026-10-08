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
		if ( ! is_readable( $absolute_path ) ) {
			wp_die( esc_html__( 'Invoice not found.', 'billio' ), '', array( 'response' => 404 ) );
		}

		self::send_headers( $download_name, filesize( $absolute_path ) );

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
		self::send_headers( $download_name, strlen( $bytes ) );
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
	 * @return void
	 */
	private static function send_headers( $download_name, $length ) {
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $download_name ) . '"' );
		header( 'Content-Length: ' . $length );
	}
}
