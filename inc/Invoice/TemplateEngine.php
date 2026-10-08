<?php
/**
 * Renders an order into the invoice HTML that Dompdf then turns into a PDF.
 *
 * @package Billio
 */

namespace Billio\Invoice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TemplateEngine {

	/**
	 * Render the configured invoice template for an order.
	 *
	 * @param \WC_Order $order          Order being invoiced.
	 * @param string    $invoice_number Already-assigned invoice number.
	 * @return string Full HTML document, ready for Dompdf::loadHtml().
	 */
	public static function render( \WC_Order $order, $invoice_number ) {
		$template_file = self::locate_template( get_option( 'billio_template', 'default' ), 'default' );
		$data          = self::prepare_data( $order, $invoice_number );

		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract

		ob_start();
		try {
			include $template_file;
		} finally {
			// Guarantees the buffer is closed (with whatever it captured)
			// even if the included template throws/fatals — otherwise a
			// stray open buffer can bleed its partial content into the
			// next thing that writes to output (e.g. the caller's wp_die()).
			$html = ob_get_clean();
		}

		return BengaliShaper::reorder( $html );
	}

	/**
	 * Render the packing-slip template for an order.
	 *
	 * @param \WC_Order $order Order being packed.
	 * @return string Full HTML document, ready for Dompdf::loadHtml().
	 */
	public static function render_packing_slip( \WC_Order $order ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $order is used by templates/packing-slip.php via PHP's include() scope sharing, not referenced directly in this method.
		$company = array(
			'name' => get_option( 'billio_company_name', get_bloginfo( 'name' ) ),
			'logo' => self::get_logo_data_uri(),
		);

		ob_start();
		try {
			include self::locate_template( 'packing-slip' );
		} finally {
			$html = ob_get_clean();
		}

		return BengaliShaper::reorder( $html );
	}

	/**
	 * Render the credit-note template for a refund.
	 *
	 * @param \WC_Order        $order           The original (parent) order.
	 * @param \WC_Order_Refund $refund          The refund being documented.
	 * @param string           $credit_note_number Already-assigned credit note number.
	 * @return string Full HTML document, ready for Dompdf::loadHtml().
	 */
	public static function render_credit_note( \WC_Order $order, \WC_Order_Refund $refund, $credit_note_number ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $refund and $credit_note_number are used by templates/credit-note.php via include() scope sharing, not referenced directly in this method.
		$company        = array(
			'name'    => get_option( 'billio_company_name', get_bloginfo( 'name' ) ),
			'address' => get_option( 'billio_company_address', '' ),
			'vat'     => get_option( 'billio_company_vat', '' ),
			'logo'    => self::get_logo_data_uri(),
		);
		$invoice_number = $order->get_meta( '_billio_invoice_number' );
		$currency       = $order->get_currency();

		ob_start();
		try {
			include self::locate_template( 'credit-note' );
		} finally {
			$html = ob_get_clean();
		}

		return BengaliShaper::reorder( $html );
	}

	/**
	 * Format a monetary amount as plain text (no markup) for use inside a
	 * template — wc_price() alone returns HTML spans, which templates would
	 * otherwise have to strip themselves.
	 *
	 * @param float  $amount   Amount to format.
	 * @param string $currency Currency code, e.g. "USD" or "BDT".
	 * @return string
	 */
	public static function money( $amount, $currency ) {
		return html_entity_decode(
			wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ),
			ENT_QUOTES,
			'UTF-8'
		);
	}

	/**
	 * Plain-text variation attributes for a line item (e.g. "Color: Red,
	 * Size: M"), for a small sub-line under the item name — WooCommerce
	 * already tracks this per item but templates weren't showing it,
	 * which matters most on the packing slip (fulfillment needs to know
	 * which variation to pick).
	 *
	 * Returns plain text, same convention as money() — templates esc_html()
	 * it themselves.
	 *
	 * @param \WC_Order_Item $item Line item (product or fee).
	 * @return string Empty string if the item has no displayable meta.
	 */
	public static function item_meta( $item ) {
		if ( ! method_exists( $item, 'get_formatted_meta_data' ) ) {
			return '';
		}

		$meta = $item->get_formatted_meta_data();
		if ( empty( $meta ) ) {
			return '';
		}

		$parts = array();
		foreach ( $meta as $meta_item ) {
			$parts[] = wp_strip_all_tags( $meta_item->display_key ) . ': ' . wp_strip_all_tags( $meta_item->display_value );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Resolve a template slug to a file.
	 *
	 * Checks the active theme first (child theme, then parent — exactly
	 * WordPress core's own `locate_template()` lookup, reused here so a
	 * site can override any Billio document by dropping a file at
	 * yourtheme/billio/{slug}.php, the same mechanism WooCommerce itself
	 * uses for template overrides), then the plugin's bundled copy.
	 *
	 * @param string $slug     Template slug, e.g. "default", "modern", "packing-slip", "credit-note".
	 * @param string $fallback Slug to fall back to if $slug isn't found anywhere
	 *                         (e.g. a Pro template selected without Pro active).
	 *                         Only invoice template selection should pass this —
	 *                         packing-slip/credit-note have no sensible fallback.
	 * @return string Absolute path to the template file.
	 */
	private static function locate_template( $slug, $fallback = null ) {
		$slug = sanitize_file_name( $slug );

		$theme_override = \locate_template( array( 'billio/' . $slug . '.php' ) );
		if ( $theme_override ) {
			return $theme_override;
		}

		$plugin_file = BILLIO_PATH . 'templates/' . $slug . '.php';
		if ( is_readable( $plugin_file ) ) {
			return $plugin_file;
		}

		return $fallback ? self::locate_template( $fallback ) : $plugin_file;
	}

	/**
	 * Gather everything a template needs, pre-escaped data is left to the
	 * template itself (same convention as core WP template parts).
	 *
	 * @param \WC_Order $order          Order being invoiced.
	 * @param string    $invoice_number Already-assigned invoice number.
	 * @return array
	 */
	private static function prepare_data( \WC_Order $order, $invoice_number ) {
		$invoice_timestamp = (int) $order->get_meta( '_billio_invoice_date' );

		return array(
			'order'          => $order,
			'invoice_number' => $invoice_number,
			'invoice_date'   => wp_date( get_option( 'date_format' ), $invoice_timestamp ? $invoice_timestamp : time() ),
			'currency'       => $order->get_currency(),
			'company'        => array(
				'name'    => get_option( 'billio_company_name', get_bloginfo( 'name' ) ),
				'address' => get_option( 'billio_company_address', '' ),
				'vat'     => get_option( 'billio_company_vat', '' ),
				'logo'    => self::get_logo_data_uri(),
			),
		);
	}

	/**
	 * Inline the company logo as a base64 data URI so Dompdf never needs
	 * isRemoteEnabled (and the PDF still renders correctly offline).
	 *
	 * @return string Data URI, or '' if no logo is set.
	 */
	private static function get_logo_data_uri() {
		$attachment_id = (int) get_option( 'billio_company_logo_id', 0 );

		if ( ! $attachment_id ) {
			return '';
		}

		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! is_readable( $path ) ) {
			return '';
		}

		$filetype = wp_check_filetype( $path );
		$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : 'image/png';

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contents ) {
			return '';
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $contents ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}
}
