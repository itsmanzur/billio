<?php
/**
 * Builds a packing slip PDF for an order — items + shipping address, no
 * prices (standard convention: fulfillment staff don't need values).
 *
 * Unlike invoices, packing slips carry no sequential number and nothing
 * about them needs to stay stable across downloads, so they're never
 * written to disk — they're rendered fresh and streamed straight from
 * memory on every request. This also avoids permanently accumulating
 * customer name/address/phone in files under uploads/ with no cleanup.
 *
 * @package Billio
 */

namespace Billio\Invoice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PackingSlipGenerator {

	/**
	 * Generate a packing slip PDF for an order.
	 *
	 * @param \WC_Order $order Order to generate a packing slip for.
	 * @return string Raw PDF bytes.
	 */
	public static function generate( \WC_Order $order ) {
		$html = TemplateEngine::render_packing_slip( $order );

		$dompdf = Generator::make_dompdf();
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( Generator::paper_size() );
		$dompdf->render();

		return $dompdf->output();
	}
}
