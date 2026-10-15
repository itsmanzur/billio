<?php
/**
 * Read-only integration checks against a real WordPress + WooCommerce
 * install with Billio active — verifies the bulk SQL reads used by the
 * Dashboard agree with WooCommerce's own order objects, that search finds
 * real documents, and that every template renders to a PDF.
 *
 * Writes nothing: no orders, options, files or invoice numbers are
 * created (PDFs are rendered in memory only).
 *
 * Usage (WP-CLI):  wp eval-file wp-content/plugins/billio/tests/integration.php
 *
 * Not shipped in the production zip (see bin/build-zip.ps1).
 *
 * @package Billio
 */

// phpcs:ignoreFile -- dev-only test script.

if ( ! defined( 'ABSPATH' ) || ! defined( 'BILLIO_VERSION' ) ) {
	echo "Run inside WordPress with Billio active, e.g. wp eval-file wp-content/plugins/billio/tests/integration.php\n";
	return;
}

use Billio\Admin\Dashboard;
use Billio\Admin\OrderStatsQuery;
use Billio\Frontend\MyAccount;
use Billio\Invoice\Generator;
use Billio\Invoice\TemplateEngine;

$billio_it = array(
	'pass' => 0,
	'fail' => 0,
);

$billio_check = static function ( $label, $ok, $detail = '' ) use ( &$billio_it ) {
	if ( $ok ) {
		++$billio_it['pass'];
		echo "  ok   {$label}\n";
	} else {
		++$billio_it['fail'];
		echo "  FAIL {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	}
};

wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 ) );

echo 'Billio ' . BILLIO_VERSION . ' · WooCommerce ' . WC()->version . ' · storage: ' . ( OrderStatsQuery::uses_hpos() ? 'HPOS' : 'posts' ) . "\n";

$invoiced_ids = wc_get_orders(
	array(
		'limit'      => 50,
		'return'     => 'ids',
		'meta_query' => array( array( 'key' => '_billio_invoice_number', 'compare' => 'EXISTS' ) ),
	)
);
$refund_ids   = wc_get_orders(
	array(
		'type'       => 'shop_order_refund',
		'limit'      => 50,
		'return'     => 'ids',
		'meta_query' => array( array( 'key' => '_billio_credit_note_number', 'compare' => 'EXISTS' ) ),
	)
);
echo 'Sample: ' . count( $invoiced_ids ) . ' invoiced orders, ' . count( $refund_ids ) . " credit-noted refunds\n";

// ---------------------------------------------------------------------
echo "\nOrderStatsQuery vs WC_Order\n";
$fields = OrderStatsQuery::order_fields( $invoiced_ids );
$dates  = OrderStatsQuery::meta_map( $invoiced_ids, '_billio_invoice_date' );
$billio_check( 'a field row for every invoiced order', count( $fields ) === count( $invoiced_ids ), count( $fields ) . ' of ' . count( $invoiced_ids ) );
$mismatch = array();
foreach ( $invoiced_ids as $id ) {
	$order = wc_get_order( $id );
	if ( ! isset( $fields[ $id ] ) ) {
		$mismatch[] = "#{$id} missing";
		continue;
	}
	if ( $fields[ $id ]['status'] !== $order->get_status() ) {
		$mismatch[] = "#{$id} status {$fields[$id]['status']} vs {$order->get_status()}";
	}
	if ( abs( $fields[ $id ]['total'] - (float) $order->get_total() ) > 0.001 ) {
		$mismatch[] = "#{$id} total {$fields[$id]['total']} vs {$order->get_total()}";
	}
	if ( $fields[ $id ]['currency'] !== $order->get_currency() ) {
		$mismatch[] = "#{$id} currency";
	}
	if ( (int) ( $dates[ $id ] ?? 0 ) !== (int) $order->get_meta( '_billio_invoice_date' ) ) {
		$mismatch[] = "#{$id} invoice date";
	}
}
$billio_check( 'status / total / currency / invoice date all match', empty( $mismatch ), implode( '; ', array_slice( $mismatch, 0, 5 ) ) );

$amounts  = OrderStatsQuery::meta_map( $refund_ids, '_refund_amount' );
$mismatch = array();
foreach ( $refund_ids as $id ) {
	$refund = wc_get_order( $id );
	if ( abs( (float) ( $amounts[ $id ] ?? 0 ) - (float) $refund->get_amount() ) > 0.001 ) {
		$mismatch[] = "#{$id}";
	}
}
$billio_check( 'refund amounts match', empty( $mismatch ), implode( ', ', $mismatch ) );

// ---------------------------------------------------------------------
echo "\nDashboard\n";
$dashboard = new Dashboard();
Dashboard::deferred_bust_stats_cache();

$chart     = $dashboard->get_chart_data( 90 );
$chart_sum = array_sum( wp_list_pluck( $chart, 'invoices' ) );
$billio_check( '90-day chart has 90 consecutive days', 90 === count( $chart ) && $chart[89]['date'] === wp_date( 'Y-m-d' ), count( $chart ) . ' days, last ' . end( $chart )['date'] );
$window_start = ( new DateTimeImmutable( 'today -89 days', wp_timezone() ) )->getTimestamp();
$expected     = count(
	wc_get_orders(
		array(
			'limit'      => -1,
			'return'     => 'ids',
			'meta_query' => array(
				array(
					'key'     => '_billio_invoice_date',
					'value'   => array( $window_start, time() ),
					'compare' => 'BETWEEN',
					'type'    => 'NUMERIC',
				),
			),
		)
	)
);
$billio_check( 'chart invoice count matches a direct query', $chart_sum === $expected, "chart {$chart_sum} vs query {$expected}" );

$browse = $dashboard->search_documents( '', 'all', 1, 20 );
$billio_check( 'browse lists documents with URLs', empty( $browse['items'] ) || ( isset( $browse['items'][0]['downloadUrl'], $browse['items'][0]['shareUrl'] ) && ! isset( $browse['items'][0]['orderKey'] ) ) );
$sorted = true;
for ( $i = 1; $i < count( $browse['items'] ); $i++ ) {
	if ( $browse['items'][ $i ]['dateTimestamp'] > $browse['items'][ $i - 1 ]['dateTimestamp'] ) {
		$sorted = false;
	}
}
$billio_check( 'browse is newest-first', $sorted );

if ( $invoiced_ids ) {
	$order  = wc_get_order( $invoiced_ids[0] );
	$number = $order->get_meta( '_billio_invoice_number' );
	$found  = $dashboard->search_documents( $number, 'inv', 1, 20 );
	$billio_check( "search by invoice number finds {$number}", in_array( $number, wp_list_pluck( $found['items'], 'number' ), true ) );

	$last_name = $order->get_billing_last_name();
	if ( $last_name ) {
		$found = $dashboard->search_documents( $last_name, 'inv', 1, 20 );
		$billio_check( "search by customer name \"{$last_name}\" finds order #{$order->get_id()}", in_array( $order->get_id(), wp_list_pluck( $found['items'], 'orderId' ), true ) );
	}

	$billio_check( 'customer share URL carries the order key', false !== strpos( MyAccount::invoice_url_for( $order->get_id(), $order->get_order_key() ), $order->get_order_key() ) );
}

// ---------------------------------------------------------------------
echo "\nTemplates → PDF (in memory)\n";
$billio_pdf = static function ( $html ) {
	$dompdf = Generator::make_dompdf();
	$dompdf->loadHtml( $html );
	$dompdf->setPaper( Generator::paper_size() );
	$dompdf->render();
	return $dompdf->output();
};

$order = $invoiced_ids ? wc_get_order( $invoiced_ids[0] ) : Billio\Invoice\SampleOrder::make();
foreach ( array( 'default', 'modern' ) as $template ) {
	$filter = static function () use ( $template ) {
		return $template;
	};
	add_filter( 'pre_option_billio_template', $filter );
	$html = TemplateEngine::render( $order, (string) $order->get_meta( '_billio_invoice_number' ) ?: 'TEST-1' );
	remove_filter( 'pre_option_billio_template', $filter );
	$billio_check( "{$template} invoice includes the line-items partial", false !== strpos( $html, 'class="totals"' ) );
	$billio_check( "{$template} invoice renders to a PDF", 0 === strpos( $billio_pdf( $html ), '%PDF' ) );
}
$billio_check( 'packing slip renders to a PDF', 0 === strpos( $billio_pdf( TemplateEngine::render_packing_slip( $order ) ), '%PDF' ) );
if ( $refund_ids ) {
	$refund = wc_get_order( $refund_ids[0] );
	$parent = wc_get_order( $refund->get_parent_id() );
	$billio_check( 'credit note renders to a PDF', 0 === strpos( $billio_pdf( TemplateEngine::render_credit_note( $parent, $refund, (string) $refund->get_meta( '_billio_credit_note_number' ) ) ), '%PDF' ) );
}

echo "\n{$billio_it['pass']} passed, {$billio_it['fail']} failed\n";
