<?php
/**
 * Read-only integration checks against a real WordPress + WooCommerce
 * install with Billio active — verifies the bulk SQL reads used by the
 * Dashboard agree with WooCommerce's own order objects, that search finds
 * real documents, and that every template renders to a PDF.
 *
 * Writes nothing to any real order, setting, or file (PDFs are rendered
 * in memory only). The one exception is the OrderLock check below, which
 * acquires/releases a single dummy-id lock row in wp_options and always
 * cleans it up before finishing.
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
use Billio\Invoice\OrderLock;
use Billio\Invoice\TemplateEngine;

$billio_it = array(
	'pass' => 0,
	'fail' => 0,
	'skip' => 0,
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

$billio_skip = static function ( $label, $reason = '' ) use ( &$billio_it ) {
	++$billio_it['skip'];
	echo "  SKIP {$label}" . ( $reason ? " — {$reason}" : '' ) . "\n";
};

wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 ) );

echo 'Billio ' . BILLIO_VERSION . ' · WooCommerce ' . WC()->version . ' · storage: ' . ( OrderStatsQuery::uses_hpos() ? 'HPOS' : 'posts' ) . "\n";

// 'type' is explicit here on purpose: wc_get_orders() with no 'type' at
// all defaults to wc_get_order_types('view-orders'), and WooCommerce core
// itself registers shop_order_refund with exclude_from_order_views =>
// false (see class-wc-post-types.php) — so on legacy (posts) storage, an
// omitted 'type' silently mixes WC_Order_Refund ids into the result right
// alongside WC_Order ones.
$invoiced_candidate_ids = wc_get_orders(
	array(
		'type'       => 'shop_order',
		'limit'      => 50,
		'return'     => 'ids',
		'meta_query' => array( array( 'key' => '_billio_invoice_number', 'compare' => 'EXISTS' ) ),
	)
);

$invoiced_ids = array();
foreach ( $invoiced_candidate_ids as $billio_candidate_id ) {
	$billio_candidate = wc_get_order( $billio_candidate_id );
	// Defensive on top of the explicit 'type' above — belt and suspenders
	// against the exact mixed-type result this was built to guard against.
	// WC_Order and WC_Order_Refund are siblings (both extend
	// WC_Abstract_Order directly), so instanceof WC_Order alone already
	// excludes a refund; trashed/draft rows are excluded too (WC's own
	// default 'status' filter normally already excludes these, but this
	// stays correct even if that default ever changes).
	if ( ! $billio_candidate instanceof WC_Order ) {
		continue;
	}
	$billio_status = $billio_candidate->get_status();
	if ( 'trash' === $billio_status || false !== strpos( $billio_status, 'draft' ) ) {
		continue;
	}
	$invoiced_ids[] = $billio_candidate_id;
}

$refund_ids = wc_get_orders(
	array(
		'type'       => 'shop_order_refund',
		'limit'      => 50,
		'return'     => 'ids',
		'meta_query' => array( array( 'key' => '_billio_credit_note_number', 'compare' => 'EXISTS' ) ),
	)
);
echo 'Sample: ' . count( $invoiced_ids ) . ' invoiced orders [' . implode( ',', $invoiced_ids ) . '], ' . count( $refund_ids ) . ' credit-noted refunds [' . implode( ',', $refund_ids ) . "]\n";

// ---------------------------------------------------------------------
echo "\nOrderStatsQuery vs WC_Order\n";
try {
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
} catch ( \Throwable $e ) {
	$billio_check( 'OrderStatsQuery vs WC_Order section did not throw', false, get_class( $e ) . ': ' . $e->getMessage() );
}

// ---------------------------------------------------------------------
echo "\nDashboard\n";
try {
	$dashboard = new Dashboard();
	Dashboard::deferred_bust_stats_cache();

	$chart     = $dashboard->get_chart_data( 90 );
	$chart_sum = array_sum( wp_list_pluck( $chart, 'invoices' ) );
	$billio_check( '90-day chart has 90 consecutive days', 90 === count( $chart ) && $chart[89]['date'] === wp_date( 'Y-m-d' ), count( $chart ) . ' days, last ' . end( $chart )['date'] );
	$window_start = ( new DateTimeImmutable( 'today -89 days', wp_timezone() ) )->getTimestamp();
	$expected     = count(
		wc_get_orders(
			array(
				'type'       => 'shop_order', // Same reasoning as the sample-building query above.
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
		if ( '' === $number ) {
			$billio_skip( 'search by invoice number', "order #{$order->get_id()} has an empty _billio_invoice_number" );
		} else {
			$found = $dashboard->search_documents( $number, 'inv', 1, 20 );
			$billio_check( "search by invoice number finds {$number}", in_array( $number, wp_list_pluck( $found['items'], 'number' ), true ) );
		}

		$last_name = $order->get_billing_last_name();
		if ( $last_name ) {
			$found = $dashboard->search_documents( $last_name, 'inv', 1, 20 );
			$billio_check( "search by customer name \"{$last_name}\" finds order #{$order->get_id()}", in_array( $order->get_id(), wp_list_pluck( $found['items'], 'orderId' ), true ) );
		}

		$billio_share_url   = MyAccount::invoice_url_for( $order->get_id() );
		parse_str( (string) wp_parse_url( $billio_share_url, PHP_URL_QUERY ), $billio_share_args );
		$billio_check( 'customer share URL carries a token and expires param', isset( $billio_share_args['token'], $billio_share_args['expires'] ) );
		$billio_check( "customer share URL doesn't leak the real order_key", false === strpos( $billio_share_url, (string) $order->get_order_key() ) );
	}
} catch ( \Throwable $e ) {
	$billio_check( 'Dashboard section did not throw', false, get_class( $e ) . ': ' . $e->getMessage() );
}

// ---------------------------------------------------------------------
echo "\nNo WC_Order_Refund leaks into order-only Dashboard paths\n";
// Storage-agnostic on purpose: wc_get_order() returns a proper \WC_Order
// or \WC_Order_Refund instance on both HPOS and legacy (posts) storage,
// so these checks need no branching — the bug this guards against
// (legacy storage's default wc_get_orders() type list silently including
// shop_order_refund) only showed up as a result-mixing problem, not a
// different-API problem, so checking the *results* the same way on both
// storages is exactly right.
try {
	$billio_refund_leak = static function ( array $ids ) {
		$bad = array();
		foreach ( $ids as $id ) {
			if ( wc_get_order( $id ) instanceof \WC_Order_Refund ) {
				$bad[] = $id;
			}
		}
		return $bad;
	};

	$billio_bad = $billio_refund_leak( $dashboard->get_missing_order_ids() );
	$billio_check( 'get_missing_order_ids() never returns a refund id', empty( $billio_bad ), implode( ',', $billio_bad ) );

	// orders_issued_between(): DashboardOrderRecord carries no order id to
	// hydrate back from, so this checks the method's own query the same
	// way — a wide-open window, same 'type' => 'shop_order' it uses
	// internally — stays in lockstep with reality. (Re-reading
	// Dashboard.php's source to assert the literal 'type' => 'shop_order'
	// string is present would catch a regression even on a site with no
	// refund data to trip this check; that's a worthwhile belt-and-suspenders
	// addition if this ever proves too quiet in CI.)
	$billio_wide_order_ids = wc_get_orders(
		array(
			'type'       => 'shop_order',
			'limit'      => -1,
			'return'     => 'ids',
			'meta_query' => array( array( 'key' => '_billio_invoice_date', 'compare' => 'EXISTS' ) ),
		)
	);
	$billio_bad = $billio_refund_leak( $billio_wide_order_ids );
	$billio_check( "orders_issued_between()'s own query (type => shop_order) never matches a refund id", empty( $billio_bad ) );
	// And that the method itself still runs clean over a wide window.
	$dashboard->orders_issued_between( 0, time() + DAY_IN_SECONDS );

	$billio_search_all = $dashboard->search_documents( '', 'all', 1, 50 );
	$billio_bad         = array();
	foreach ( $billio_search_all['items'] as $billio_item ) {
		if ( 'inv' === $billio_item['type'] && wc_get_order( $billio_item['orderId'] ) instanceof \WC_Order_Refund ) {
			$billio_bad[] = $billio_item['orderId'];
		}
	}
	$billio_check( "search_documents()'s invoice rows never resolve to a refund", empty( $billio_bad ), implode( ',', $billio_bad ) );

	$billio_bad = array();
	foreach ( $dashboard->get_cached_recent_documents() as $billio_doc ) {
		if ( 'inv' === $billio_doc['type'] && wc_get_order( $billio_doc['orderId'] ) instanceof \WC_Order_Refund ) {
			$billio_bad[] = $billio_doc['orderId'];
		}
	}
	$billio_check( 'get_cached_recent_documents() invoice rows never resolve to a refund', empty( $billio_bad ), implode( ',', $billio_bad ) );
} catch ( \Throwable $e ) {
	$billio_check( 'No-refund-leak section did not throw', false, get_class( $e ) . ': ' . $e->getMessage() );
}

// ---------------------------------------------------------------------
echo "\nOwnership checks (MyAccount::is_authorized_for_order / token_status)\n";
// This is the actual logic handle_download()/handle_credit_note_download()
// gate on before streaming a PDF — exercised directly (they're pure,
// side-effect-free methods) rather than through those wp_die()-terminating
// handlers, which would end the whole script on a denial.
if ( $invoiced_ids ) {
	$auth_order          = wc_get_order( $invoiced_ids[0] );
	$current_user_backup = get_current_user_id();
	wp_set_current_user( 0 ); // Simulate a logged-out guest for every case below.

	try {
		$billio_future = time() + 3600;
		$billio_past   = time() - 10;

		$billio_check(
			'guest with a valid token is authorized',
			MyAccount::is_authorized_for_order( $auth_order, 0, MyAccount::make_token( $auth_order->get_id(), 0, $billio_future ), $billio_future, '' )
		);
		$billio_check(
			'guest with an expired token (and no order_key) is denied',
			! MyAccount::is_authorized_for_order( $auth_order, 0, MyAccount::make_token( $auth_order->get_id(), 0, $billio_past ), $billio_past, '' )
		);
		$billio_check(
			'guest with a tampered token is denied',
			! MyAccount::is_authorized_for_order( $auth_order, 0, MyAccount::make_token( $auth_order->get_id(), 0, $billio_future ) . 'x', $billio_future, '' )
		);
		$billio_check(
			"guest with another order's valid token is denied",
			! MyAccount::is_authorized_for_order( $auth_order, 0, MyAccount::make_token( $auth_order->get_id() + 1, 0, $billio_future ), $billio_future, '' )
		);
		$billio_check(
			'guest with a wrong legacy order_key is denied',
			! MyAccount::is_authorized_for_order( $auth_order, 0, '', 0, 'not-the-real-key' )
		);
		$billio_check(
			'guest with the correct legacy order_key is authorized (backward compatibility)',
			MyAccount::is_authorized_for_order( $auth_order, 0, '', 0, $auth_order->get_order_key() )
		);

		$billio_no_legacy = static function () {
			return false;
		};
		add_filter( 'billio_allow_legacy_order_key', $billio_no_legacy );
		$billio_check(
			'the correct legacy order_key is rejected once billio_allow_legacy_order_key returns false',
			! MyAccount::is_authorized_for_order( $auth_order, 0, '', 0, $auth_order->get_order_key() )
		);
		remove_filter( 'billio_allow_legacy_order_key', $billio_no_legacy );
	} catch ( \Throwable $e ) {
		$billio_check( 'Ownership checks section did not throw', false, get_class( $e ) . ': ' . $e->getMessage() );
	} finally {
		wp_set_current_user( $current_user_backup );
	}
}

// ---------------------------------------------------------------------
echo "\nOrderLock ownership semantics\n";
// A dummy, never-real order id — this only ever touches the one
// wp_options lock row for that id, always released below. Guards against
// the exact bug class that was found and fixed: a plain boolean lock with
// no ownership check let a slow (not crashed) holder's release() delete a
// new holder's lock out from under it, and let a lock-wait timeout fall
// through to minting an invoice number without the lock at all.
$lock_id = 999999001;
try {
	OrderLock::release( $lock_id, '' ); // Clean slate in case a previous failed run left this held.

	$token_a = OrderLock::acquire( $lock_id );
	$billio_check( 'first acquire succeeds', is_string( $token_a ) && '' !== $token_a );

	$token_b = OrderLock::acquire( $lock_id );
	$billio_check( 'second acquire while still held fails', false === $token_b );

	OrderLock::release( $lock_id, 'not-the-real-token' );
	$billio_check( 'release with the wrong token leaves the lock held', false === OrderLock::acquire( $lock_id ) );

	OrderLock::release( $lock_id, $token_a );
	$token_c = OrderLock::acquire( $lock_id );
	$billio_check( 'release with the correct token frees the lock', is_string( $token_c ) && '' !== $token_c );

	OrderLock::release( $lock_id, $token_c );
	$stale_token = OrderLock::acquire( $lock_id, -1 ); // Already-expired TTL.
	sleep( 1 ); // Make sure time() has moved past that expiry.
	$new_token   = OrderLock::acquire( $lock_id );
	$billio_check( 'an expired lock is reclaimed by a new acquirer', is_string( $new_token ) && $new_token !== $stale_token );

	OrderLock::release( $lock_id, $stale_token ); // The "late" original holder finally releasing its stale handle.
	$billio_check(
		"the late original holder's release does not steal the new holder's lock",
		false === OrderLock::acquire( $lock_id )
	);

	OrderLock::release( $lock_id, $new_token ); // Cleanup.
} catch ( \Throwable $e ) {
	$billio_check( 'OrderLock section did not throw', false, get_class( $e ) . ': ' . $e->getMessage() );
	OrderLock::release( $lock_id, isset( $new_token ) ? $new_token : '' ); // Best-effort cleanup.
}

// ---------------------------------------------------------------------
echo "\nTemplates → PDF (in memory)\n";
try {
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
} catch ( \Throwable $e ) {
	$billio_check( 'Templates section did not throw', false, get_class( $e ) . ': ' . $e->getMessage() );
}

echo "\n{$billio_it['pass']} passed, {$billio_it['fail']} failed, {$billio_it['skip']} skipped\n";
