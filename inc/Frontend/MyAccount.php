<?php
/**
 * Customer-facing invoice download: a button on My Account > Orders and on
 * the single order view, backed by an ownership-checked admin-post handler.
 *
 * @package Billio
 */

namespace Billio\Frontend;

use Billio\Invoice\CreditNoteGenerator;
use Billio\Invoice\FileServer;
use Billio\Invoice\Generator;
use Billio\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MyAccount {

	/**
	 * token_status() outcomes — see that method's docblock.
	 */
	const TOKEN_ABSENT  = 'absent';
	const TOKEN_INVALID = 'invalid';
	const TOKEN_EXPIRED = 'expired';
	const TOKEN_VALID   = 'valid';

	public function __construct() {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_order_list_action' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_view_order_link' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'render_thankyou_button' ), 15 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_post_billio_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_nopriv_billio_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_billio_customer_credit_note', array( $this, 'handle_credit_note_download' ) );
		add_action( 'admin_post_nopriv_billio_customer_credit_note', array( $this, 'handle_credit_note_download' ) );
	}

	/**
	 * Add a "Download invoice" button to each eligible row in
	 * My Account > Orders.
	 *
	 * @param array     $actions Existing action buttons (url/name pairs).
	 * @param \WC_Order $order   The row's order.
	 * @return array
	 */
	public function add_order_list_action( $actions, $order ) {
		if ( ! $order->get_meta( '_billio_invoice_number' ) ) {
			return $actions;
		}

		$actions['billio_invoice'] = array(
			'url'  => $this->download_url( $order ),
			'name' => esc_html__( 'Download invoice', 'billio' ),
		);

		return $actions;
	}

	/**
	 * Add a "Download invoice" (and credit note, if refunded) link under the
	 * single order view table.
	 *
	 * @param \WC_Order $order Order being viewed.
	 * @return void
	 */
	public function render_view_order_link( $order ) {
		if ( $order->get_meta( '_billio_invoice_number' ) ) {
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( $this->download_url( $order ) ),
				esc_html__( 'Download invoice', 'billio' )
			);
		}

		$refunds = $order->get_refunds();
		foreach ( $refunds as $refund ) {
			if ( $refund->get_meta( '_billio_credit_note_number' ) ) {
				printf(
					'<p><a class="button" href="%s">%s (%s)</a></p>',
					esc_url( $this->credit_note_download_url( $order, $refund ) ),
					esc_html__( 'Download credit note', 'billio' ),
					esc_html( $refund->get_meta( '_billio_credit_note_number' ) )
				);
			}
		}
	}

	/**
	 * Enqueue frontend stylesheet on Thank You and View Order pages.
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		if ( ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
			|| ( function_exists( 'is_view_order_page' ) && is_view_order_page() ) ) {
			wp_enqueue_style(
				'billio-frontend',
				BILLIO_URL . 'assets/css/frontend.css',
				array(),
				BILLIO_VERSION
			);
		}
	}

	/**
	 * Render a prominent "Download invoice" banner and button on the checkout
	 * Thank You (order-received) screen.
	 *
	 * @param int $order_id Newly placed order ID.
	 * @return void
	 */
	public function render_thankyou_button( $order_id ) {
		if ( ! get_option( 'billio_show_thankyou_download', true ) ) {
			return;
		}

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			return;
		}

		if ( ! self::customer_download_eligible( $order ) ) {
			return;
		}

		printf(
			'<div class="billio-thankyou-wrap">' .
			'<div class="billio-thankyou-content"><strong class="billio-thankyou-title">%s</strong><span class="billio-thankyou-desc">%s</span></div>' .
			'<a class="button billio-thankyou-btn" href="%s">%s</a>' .
			'</div>',
			esc_html__( 'Need your invoice?', 'billio' ),
			esc_html__( 'Download a PDF copy of your official order invoice.', 'billio' ),
			esc_url( $this->download_url( $order ) ),
			esc_html__( 'Download invoice (PDF)', 'billio' )
		);
	}

	/**
	 * Whether a customer-facing invoice download for this order may
	 * proceed: either one's already been issued, or — if the "Customer
	 * download generates invoice on demand" setting is on (the default,
	 * matching Billio's original behavior) — the order is paid/processing/
	 * completed, so generating one now on the customer's request is
	 * reasonable. Shared by handle_download() (the actual gate before
	 * minting a number) and render_thankyou_button() (so a button that
	 * would just 404 is never shown in the first place).
	 *
	 * The admin-side generate/regenerate actions (OrderActions, Dashboard)
	 * are intentionally not gated by this setting at all — it's a policy
	 * choice about on-demand generation from a customer's own download
	 * request specifically, not a general restriction on when invoices
	 * can be created.
	 *
	 * Public so this can be unit-tested directly (see tests/run.php).
	 *
	 * @param \WC_Order $order Order to check.
	 * @return bool
	 */
	public static function customer_download_eligible( \WC_Order $order ) {
		if ( $order->get_meta( '_billio_invoice_number' ) ) {
			return true;
		}

		if ( ! (bool) get_option( 'billio_customer_ondemand_generate', true ) ) {
			return false;
		}

		return $order->is_paid() || $order->has_status( array( 'processing', 'completed' ) );
	}

	/**
	 * Build the download URL for an order.
	 *
	 * @param \WC_Order $order Order to link to.
	 * @return string
	 */
	private function download_url( \WC_Order $order ) {
		return self::invoice_url_for( $order->get_id() );
	}

	/**
	 * Customer-facing invoice URL from a plain order ID — authorized by a
	 * signed, expiring token (see make_token()), so it works for the
	 * customer (logged in or guest) it's shared with, without carrying the
	 * order's real order_key. Public/static so the Dashboard's share links
	 * (WhatsApp, Copy link) can hand customers a URL they can actually
	 * open, unlike the admin-only, per-admin-nonce download URL.
	 *
	 * @param int $order_id Order ID.
	 * @return string Raw (not HTML-escaped) URL.
	 */
	public static function invoice_url_for( $order_id ) {
		return self::signed_url( 'billio_download', (int) $order_id, 0 );
	}

	/**
	 * Customer-facing credit note URL from plain IDs — see invoice_url_for().
	 *
	 * @param int $order_id  Parent order ID.
	 * @param int $refund_id Refund ID.
	 * @return string Raw (not HTML-escaped) URL.
	 */
	public static function credit_note_url_for( $order_id, $refund_id ) {
		return self::signed_url( 'billio_customer_credit_note', (int) $order_id, (int) $refund_id );
	}

	/**
	 * Build the customer download URL for a credit note.
	 *
	 * @param \WC_Order        $order  Order the refund belongs to.
	 * @param \WC_Order_Refund $refund The refund.
	 * @return string
	 */
	private function credit_note_download_url( \WC_Order $order, \WC_Order_Refund $refund ) {
		return self::credit_note_url_for( $order->get_id(), $refund->get_id() );
	}

	/**
	 * Build a token-authorized admin-post URL for an order/refund pair.
	 *
	 * @param string $action    admin-post action name.
	 * @param int    $order_id  Order ID.
	 * @param int    $refund_id Refund ID, or 0 for an invoice (no refund).
	 * @return string Raw (not HTML-escaped) URL.
	 */
	private static function signed_url( $action, $order_id, $refund_id ) {
		$expires = self::compute_expiry();

		$args = array(
			'action'   => $action,
			'order_id' => $order_id,
			'token'    => self::make_token( $order_id, $refund_id, $expires ),
			'expires'  => $expires,
		);

		if ( $refund_id ) {
			$args['refund_id'] = $refund_id;
		}

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * The expiry timestamp to sign a newly-generated link with, per the
	 * "Customer link validity" setting (days; 0 = never expires, encoded
	 * here as the sentinel timestamp 0 rather than a far-future date, so
	 * token_status() has one unambiguous way to recognize "no expiry").
	 *
	 * @return int Unix timestamp, or 0 for no expiry.
	 */
	private static function compute_expiry() {
		$days = (int) get_option( 'billio_customer_link_validity_days', 30 );

		return $days > 0 ? time() + ( $days * DAY_IN_SECONDS ) : 0;
	}

	/**
	 * Sign an order id + refund id + expiry with an HMAC keyed on this
	 * site's own auth salt — unguessable without server-side knowledge,
	 * and (unlike the WooCommerce order_key this replaces) never reused
	 * for anything else WooCommerce itself authorizes with that key, so a
	 * leaked Billio download link can't be used against anything beyond
	 * Billio's own documents for that exact order/refund/expiry.
	 *
	 * Public (alongside token_status()) so tests can construct a token
	 * with a deliberately-past $expires — the only way to exercise the
	 * "expired" path without actually waiting for a real one to expire.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID, or 0 for an invoice.
	 * @param int $expires   Unix timestamp, or 0 for no expiry.
	 * @return string Hex-encoded HMAC-SHA256.
	 */
	public static function make_token( $order_id, $refund_id, $expires ) {
		return hash_hmac( 'sha256', $order_id . '|' . $refund_id . '|' . $expires, wp_salt( 'auth' ) );
	}

	/**
	 * Classify a submitted token: absent (no token param at all — the
	 * caller should fall back to the legacy order_key check), invalid
	 * (present but doesn't match, or matches a different order/refund/
	 * expiry than claimed), expired (signature checks out, but its expiry
	 * has passed), or valid.
	 *
	 * Public so this can be unit-tested directly (see tests/run.php)
	 * without needing a real HTTP request.
	 *
	 * @param int    $order_id  Order ID being accessed.
	 * @param int    $refund_id Refund ID being accessed, or 0 for an invoice.
	 * @param string $token     Submitted token, unvalidated.
	 * @param mixed  $expires   Submitted expires value, unvalidated.
	 * @return string One of self::TOKEN_ABSENT, self::TOKEN_INVALID, self::TOKEN_EXPIRED, self::TOKEN_VALID.
	 */
	public static function token_status( $order_id, $refund_id, $token, $expires ) {
		if ( '' === (string) $token ) {
			return self::TOKEN_ABSENT;
		}

		$expires  = (int) $expires;
		$expected = self::make_token( (int) $order_id, (int) $refund_id, $expires );

		if ( ! hash_equals( $expected, (string) $token ) ) {
			return self::TOKEN_INVALID;
		}

		if ( 0 !== $expires && time() > $expires ) {
			return self::TOKEN_EXPIRED;
		}

		return self::TOKEN_VALID;
	}

	/**
	 * Whether the current request is authorized to access an order's (or
	 * one of its refund's) documents: the logged-in customer who placed
	 * it, a store admin, a valid signed token, or — for links generated
	 * before this token scheme existed — the order's own legacy order_key
	 * (disable via the `billio_allow_legacy_order_key` filter).
	 *
	 * Pure / side-effect-free on purpose — unlike the handle_*() methods
	 * below, which wp_die() (terminating the request) on failure, this
	 * just returns a bool, so the actual ownership-check logic can be
	 * exercised directly in tests without needing to simulate a real
	 * terminated HTTP request.
	 *
	 * @param \WC_Order $order     Order being accessed.
	 * @param int       $refund_id Refund ID being accessed, or 0 for an invoice.
	 * @param string    $token     Submitted token, unvalidated.
	 * @param mixed     $expires   Submitted expires value, unvalidated.
	 * @param string    $order_key Submitted legacy order_key, unvalidated.
	 * @return bool
	 */
	public static function is_authorized_for_order( \WC_Order $order, $refund_id, $token, $expires, $order_key ) {
		$is_owner = is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id();
		$is_admin = current_user_can( 'manage_woocommerce' );

		if ( $is_owner || $is_admin ) {
			return true;
		}

		if ( self::TOKEN_VALID === self::token_status( $order->get_id(), $refund_id, $token, $expires ) ) {
			return true;
		}

		/**
		 * Whether a pre-token-scheme link (authorized by the raw WooCommerce
		 * order_key) still works. Default true for backward compatibility —
		 * links already sent out in old emails/pages must keep working.
		 * Set to false to require every link to carry a valid signed token.
		 *
		 * @param bool $allow Whether to accept a valid legacy order_key.
		 */
		if ( ! apply_filters( 'billio_allow_legacy_order_key', true ) ) {
			return false;
		}

		return ! empty( $order_key ) && hash_equals( (string) $order->get_order_key(), (string) $order_key );
	}

	/**
	 * admin-post handler: stream the invoice PDF to its owning customer or guest with valid order_key.
	 *
	 * @return void
	 */
	public function handle_download() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET; authorized by ownership, admin capability, or valid order_key check below.
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		// wc_get_order() also resolves refund IDs — a WC_Order_Refund has no
		// customer/order key, and would fatal further down.
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'billio' ), '', array( 'response' => 404 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$expires = isset( $_GET['expires'] ) ? sanitize_text_field( wp_unslash( $_GET['expires'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- legacy pre-token links only; validated by hash_equals() against the real order_key below.
		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';

		if ( ! self::is_authorized_for_order( $order, 0, $token, $expires, $order_key ) ) {
			if ( self::TOKEN_EXPIRED === self::token_status( $order->get_id(), 0, $token, $expires ) ) {
				wp_die( esc_html__( 'This link has expired. Please log in or contact us.', 'billio' ), '', array( 'response' => 403 ) );
			}
			if ( ! is_user_logged_in() ) {
				wp_die( esc_html__( 'Please log in to download your invoice.', 'billio' ), '', array( 'response' => 403 ) );
			}
			wp_die( esc_html__( 'You do not have permission to access this invoice.', 'billio' ), '', array( 'response' => 403 ) );
		}

		// Only serve an existing invoice, or (if the "Customer download
		// generates invoice on demand" setting allows it) generate one for
		// a paid/processing/completed order. Never mint an invoice for
		// pending/failed/cancelled orders from the customer path.
		if ( ! self::customer_download_eligible( $order ) ) {
			wp_die( esc_html__( 'No invoice is available for this order yet.', 'billio' ), '', array( 'response' => 404 ) );
		}

		$billio_had_number = (bool) $order->get_meta( '_billio_invoice_number' );

		try {
			// Generator::generate() reuses the cached file when present, and
			// self-heals (rebuilds under the same number) if the file has
			// gone missing — no separate existence check needed here.
			$relative_path = Generator::generate( $order );
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf( 'Customer invoice download generation failed for order #%d: %s', $order->get_id(), $e->getMessage() )
			);
			wp_die( esc_html__( 'Could not generate the invoice. Please contact us for help.', 'billio' ), '', array( 'response' => 500 ) );
		}

		// Only true the first time: an invoice number didn't exist before
		// this request but does now — i.e. this exact request is what
		// triggered the on-demand mint. Logged so an admin can audit how
		// many invoices are being generated by customer requests rather
		// than the usual email trigger.
		if ( ! $billio_had_number ) {
			Logger::info(
				sprintf( 'Billio: invoice generated on demand by a customer download request for order #%d.', $order->get_id() )
			);
		}

		FileServer::stream(
			Generator::get_absolute_path( $relative_path ),
			'invoice-' . $order->get_meta( '_billio_invoice_number' ) . '.pdf'
		);
	}

	/**
	 * admin-post handler: stream the credit note PDF to its owning customer.
	 *
	 * @return void
	 */
	public function handle_credit_note_download() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET; authorized by ownership, admin capability, or valid order_key check below.
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$refund_id = isset( $_GET['refund_id'] ) ? absint( $_GET['refund_id'] ) : 0;
		$order     = $order_id ? wc_get_order( $order_id ) : false;
		$refund    = $refund_id ? wc_get_order( $refund_id ) : false;

		if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund || (int) $refund->get_parent_id() !== $order->get_id() ) {
			wp_die( esc_html__( 'Credit note not found.', 'billio' ), '', array( 'response' => 404 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$expires = isset( $_GET['expires'] ) ? sanitize_text_field( wp_unslash( $_GET['expires'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- legacy pre-token links only; validated by hash_equals() against the real order_key below.
		$order_key = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';

		if ( ! self::is_authorized_for_order( $order, $refund->get_id(), $token, $expires, $order_key ) ) {
			if ( self::TOKEN_EXPIRED === self::token_status( $order->get_id(), $refund->get_id(), $token, $expires ) ) {
				wp_die( esc_html__( 'This link has expired. Please log in or contact us.', 'billio' ), '', array( 'response' => 403 ) );
			}
			if ( ! is_user_logged_in() ) {
				wp_die( esc_html__( 'Please log in to download your credit note.', 'billio' ), '', array( 'response' => 403 ) );
			}
			wp_die( esc_html__( 'You do not have permission to access this credit note.', 'billio' ), '', array( 'response' => 403 ) );
		}

		if ( ! $refund->get_meta( '_billio_credit_note_number' ) ) {
			wp_die( esc_html__( 'No credit note is available for this refund yet.', 'billio' ), '', array( 'response' => 404 ) );
		}

		try {
			$relative_path = CreditNoteGenerator::generate( $refund, $order );
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf( 'Customer credit note download generation failed for refund #%d: %s', $refund->get_id(), $e->getMessage() )
			);
			wp_die( esc_html__( 'Could not generate the credit note. Please contact us for help.', 'billio' ), '', array( 'response' => 500 ) );
		}

		FileServer::stream(
			Generator::get_absolute_path( $relative_path ),
			'credit-note-' . $refund->get_meta( '_billio_credit_note_number' ) . '.pdf'
		);
	}
}
