<?php
/**
 * Renders an order into the invoice HTML that Dompdf then turns into a PDF.
 *
 * @package Billio
 */

namespace Billio\Invoice;

use Billio\Frontend\CheckoutTaxId;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TemplateEngine {

	/**
	 * Render the configured invoice template for an order.
	 *
	 * @param \WC_Order $order          Order being invoiced.
	 * @param string    $invoice_number Already-assigned invoice number.
	 * @param bool      $for_pdf        True (default) for Dompdf output. Pass false
	 *                                  for HTML shown in a browser (the settings
	 *                                  live preview): browsers shape Bengali
	 *                                  themselves, so BengaliShaper's Dompdf-only
	 *                                  reordering would scramble it there.
	 * @return string Full HTML document, ready for Dompdf::loadHtml().
	 */
	public static function render( \WC_Order $order, $invoice_number, $for_pdf = true ) {
		$template_file = self::locate_template( get_option( 'billio_template', 'default' ), 'default' );
		$data          = self::prepare_data( $order, $invoice_number );

		/**
		 * Filter the data passed into the invoice template.
		 *
		 * Allows third-party plugins and themes to inject custom metadata,
		 * barcodes, tax breakdowns, or company fields without template overrides.
		 *
		 * @param array     $data           Template variables.
		 * @param \WC_Order $order          The invoiced order.
		 * @param string    $invoice_number The assigned invoice number.
		 */
		$data = apply_filters( 'billio_invoice_template_data', $data, $order, $invoice_number );

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

		return $for_pdf ? BengaliShaper::reorder( $html ) : $html;
	}

	/**
	 * Render the packing-slip template for an order.
	 *
	 * @param \WC_Order $order   Order being packed.
	 * @param bool      $for_pdf See render().
	 * @return string Full HTML document, ready for Dompdf::loadHtml().
	 */
	public static function render_packing_slip( \WC_Order $order, $for_pdf = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $order is used by templates/packing-slip.php via PHP's include() scope sharing, not referenced directly in this method.
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

		return $for_pdf ? BengaliShaper::reorder( $html ) : $html;
	}

	/**
	 * Render the credit-note template for a refund.
	 *
	 * @param \WC_Order        $order           The original (parent) order.
	 * @param \WC_Order_Refund $refund          The refund being documented.
	 * @param string           $credit_note_number Already-assigned credit note number.
	 * @param bool             $for_pdf            See render().
	 * @return string Full HTML document, ready for Dompdf::loadHtml().
	 */
	public static function render_credit_note( \WC_Order $order, \WC_Order_Refund $refund, $credit_note_number, $for_pdf = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $refund and $credit_note_number are used by templates/credit-note.php via include() scope sharing, not referenced directly in this method.
		$company               = array(
			'name'    => get_option( 'billio_company_name', get_bloginfo( 'name' ) ),
			'address' => get_option( 'billio_company_address', '' ),
			'vat'     => get_option( 'billio_company_vat', '' ),
			'logo'    => self::get_logo_data_uri(),
		);
		$invoice_number        = $order->get_meta( '_billio_invoice_number' );
		$currency              = $order->get_currency();
		$customer_tax_id       = self::get_customer_tax_id( $order );
		$customer_tax_id_label = get_option( 'billio_tax_id_label', __( 'VAT / Tax / BIN Number', 'billio' ) );
		if ( empty( $customer_tax_id_label ) ) {
			$customer_tax_id_label = __( 'VAT / Tax ID', 'billio' );
		}

		ob_start();
		try {
			include self::locate_template( 'credit-note' );
		} finally {
			$html = ob_get_clean();
		}

		return $for_pdf ? BengaliShaper::reorder( $html ) : $html;
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
	 * Retrieve the customer's tax ID from the order.
	 *
	 * Checks _billio_tax_id first, falling back to _billing_billio_tax_id,
	 * _vat_number, and vat_number for maximum ecosystem compatibility.
	 *
	 * @param \WC_Order $order Order instance.
	 * @return string
	 */
	public static function get_customer_tax_id( \WC_Order $order ) {
		$tax_id = (string) $order->get_meta( '_billio_tax_id' );
		if ( '' === $tax_id ) {
			$tax_id = (string) $order->get_meta( '_billing_billio_tax_id' );
		}
		if ( '' === $tax_id ) {
			// Block checkout stores the field under its own meta key.
			$tax_id = sanitize_text_field( CheckoutTaxId::get_block_value( $order ) );
		}
		if ( '' === $tax_id ) {
			$tax_id = (string) $order->get_meta( '_vat_number' );
			if ( '' === $tax_id ) {
				$tax_id = (string) $order->get_meta( 'vat_number' );
			}
		}
		return $tax_id;
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
		$tax_id_label      = (string) get_option( 'billio_tax_id_label', __( 'VAT / Tax / BIN Number', 'billio' ) );
		if ( empty( $tax_id_label ) ) {
			$tax_id_label = __( 'VAT / Tax ID', 'billio' );
		}

		return array(
			'order'                 => $order,
			'invoice_number'        => $invoice_number,
			'invoice_date'          => wp_date( get_option( 'date_format' ), $invoice_timestamp ? $invoice_timestamp : time() ),
			'currency'              => $order->get_currency(),
			'show_paid_watermark'   => (bool) get_option( 'billio_watermark_paid', false ) && self::is_paid_for_watermark( $order ),
			'customer_tax_id'       => self::get_customer_tax_id( $order ),
			'customer_tax_id_label' => $tax_id_label,
			'company'               => array(
				'name'    => get_option( 'billio_company_name', get_bloginfo( 'name' ) ),
				'address' => get_option( 'billio_company_address', '' ),
				'vat'     => get_option( 'billio_company_vat', '' ),
				'logo'    => self::get_logo_data_uri(),
			),
		);
	}

	/**
	 * Whether the order has really been paid, for the PAID watermark.
	 *
	 * WooCommerce's is_paid() is true for every "processing" order — but a
	 * Cash on Delivery order moves to processing the moment it's placed,
	 * long before any money changes hands, so stamping it PAID would be
	 * wrong. COD only counts as paid once completed (delivered).
	 *
	 * @param \WC_Order $order Order being invoiced.
	 * @return bool
	 */
	private static function is_paid_for_watermark( \WC_Order $order ) {
		$is_paid = ( 'cod' === $order->get_payment_method() )
			? $order->has_status( 'completed' )
			: $order->is_paid();

		/**
		 * Filter whether an invoice gets the PAID watermark (when enabled).
		 *
		 * @param bool      $is_paid Whether the order counts as paid.
		 * @param \WC_Order $order   The invoiced order.
		 */
		return (bool) apply_filters( 'billio_invoice_is_paid', $is_paid, $order );
	}

	/**
	 * @var string|null Cached logo data URI in memory for the current request.
	 */
	private static $logo_data_uri_cache = null;

	/**
	 * Inline the company logo as a base64 data URI so Dompdf never needs
	 * isRemoteEnabled (and the PDF still renders correctly offline).
	 *
	 * Memoized in-memory per request to avoid repeated file reads and base64
	 * encoding across multiple renders in the same PHP lifecycle.
	 *
	 * @return string Data URI, or '' if no logo is set.
	 */
	private static function get_logo_data_uri() {
		if ( null !== self::$logo_data_uri_cache ) {
			return self::$logo_data_uri_cache;
		}

		$attachment_id = (int) get_option( 'billio_company_logo_id', 0 );

		if ( ! $attachment_id ) {
			self::$logo_data_uri_cache = '';
			return '';
		}

		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! is_readable( $path ) ) {
			self::$logo_data_uri_cache = '';
			return '';
		}

		$filetype = wp_check_filetype( $path );
		$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : 'image/png';

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contents ) {
			self::$logo_data_uri_cache = '';
			return '';
		}

		self::$logo_data_uri_cache = 'data:' . $mime . ';base64,' . base64_encode( $contents ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		return self::$logo_data_uri_cache;
	}

	/**
	 * Clear the in-memory logo data URI cache.
	 *
	 * @return void
	 */
	public static function clear_logo_cache() {
		self::$logo_data_uri_cache = null;
	}
}
