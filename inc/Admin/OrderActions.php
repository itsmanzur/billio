<?php
/**
 * Order list: invoice column, download/generate/resend buttons, and the
 * AJAX + admin-post endpoints backing them.
 *
 * @package Billio
 */

namespace Billio\Admin;

use Billio\Invoice\FileServer;
use Billio\Invoice\Generator;
use Billio\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderActions {

	const NONCE_ACTION = 'billio_order_action';

	/**
	 * Map an order's current status to the WC customer email id that would
	 * carry the invoice — the free scope only ever attaches to these two
	 * (see EmailAttacher / Settings → "Send invoice when order is").
	 */
	const STATUS_TO_EMAIL_ID = array(
		'processing' => 'customer_processing_order',
		'completed'  => 'customer_completed_order',
	);

	/**
	 * WC email id → the email class that handles it, for triggering resends.
	 */
	const EMAIL_ID_TO_CLASS = array(
		'customer_processing_order' => 'WC_Email_Customer_Processing_Order',
		'customer_completed_order'  => 'WC_Email_Customer_Completed_Order',
	);

	public function __construct() {
		// Registered for both the legacy posts-table screen and the HPOS
		// "wc-orders" admin page — only the one actually in use will fire.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		add_action( 'wp_ajax_billio_generate_invoice', array( $this, 'ajax_generate' ) );
		add_action( 'wp_ajax_billio_resend_invoice', array( $this, 'ajax_resend' ) );
		add_action( 'wp_ajax_billio_regenerate_invoice', array( $this, 'ajax_regenerate' ) );
		add_action( 'admin_post_billio_admin_download', array( $this, 'handle_download' ) );
	}

	/**
	 * Insert the Invoice column right before the Actions column.
	 *
	 * @param array $columns Existing column id => label map.
	 * @return array
	 */
	public function add_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			if ( 'wc_actions' === $key ) {
				$new_columns['billio_invoice'] = esc_html__( 'Invoice', 'billio' );
			}
			$new_columns[ $key ] = $label;
		}

		if ( ! isset( $new_columns['billio_invoice'] ) ) {
			$new_columns['billio_invoice'] = esc_html__( 'Invoice', 'billio' );
		}

		return $new_columns;
	}

	/**
	 * Render the Invoice column cell for one order row.
	 *
	 * @param string     $column         Column id being rendered.
	 * @param int|object $post_or_order  Post ID (legacy) or WC_Order (HPOS screen).
	 * @return void
	 */
	public function render_column( $column, $post_or_order ) {
		if ( 'billio_invoice' !== $column ) {
			return;
		}

		$order = wc_get_order( $post_or_order );
		if ( ! $order ) {
			return;
		}

		$this->render_actions( $order );
	}

	/**
	 * Shared markup for the order-list cell and the meta box.
	 *
	 * @param \WC_Order $order Order to render actions for.
	 * @return void
	 */
	public function render_actions( \WC_Order $order ) {
		$order_id       = $order->get_id();
		$invoice_number = $order->get_meta( '_billio_invoice_number' );
		$nonce          = wp_create_nonce( self::NONCE_ACTION );

		echo '<div class="billio-actions" data-order-id="' . esc_attr( $order_id ) . '" data-nonce="' . esc_attr( $nonce ) . '">';

		if ( $invoice_number ) {
			echo '<div class="billio-number">' . esc_html( $invoice_number ) . '</div>';

			$download_url = self::build_download_url( $order );

			printf(
				'<a href="%s" class="button button-small" target="_blank" rel="noopener">%s</a> ',
				esc_url( $download_url ),
				esc_html__( 'Download', 'billio' )
			);
			printf(
				'<button type="button" class="button button-small billio-resend">%s</button> ',
				esc_html__( 'Resend', 'billio' )
			);
			printf(
				'<button type="button" class="button button-small billio-regenerate">%s</button>',
				esc_html__( 'Regenerate', 'billio' )
			);
		} else {
			printf(
				'<button type="button" class="button button-small billio-generate">%s</button>',
				esc_html__( 'Generate', 'billio' )
			);
		}

		echo '</div>';
	}

	/**
	 * Build the nonce-protected admin download URL for an order's invoice.
	 *
	 * Public/static so other admin screens (the Dashboard's recent-invoices
	 * table) can reuse it without duplicating the nonce-action convention.
	 *
	 * @param \WC_Order $order Order to link to.
	 * @return string
	 */
	public static function build_download_url( \WC_Order $order ) {
		return self::build_download_url_for( $order->get_id() );
	}

	/**
	 * Same as build_download_url(), from a plain order ID (for callers
	 * that only hold cached row data, not an order object).
	 *
	 * @param int $order_id Order ID.
	 * @return string HTML-escaped URL, as wp_nonce_url() returns it.
	 */
	public static function build_download_url_for( $order_id ) {
		$order_id = (int) $order_id;

		return wp_nonce_url(
			admin_url( 'admin-post.php?action=billio_admin_download&order_id=' . $order_id ),
			self::NONCE_ACTION . '_' . $order_id
		);
	}

	/**
	 * Enqueue the small vanilla-JS admin script on order screens only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( $hook ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $hook kept for the admin_enqueue_scripts callback signature; screen id is read via get_current_screen() instead, since $hook alone ("edit.php"/"post.php") can't distinguish orders from any other post type.
		$screen = get_current_screen();

		// "edit.php"/"post.php" are WP's shared list/edit hooks for every
		// post type, not just orders — matching on $hook alone would load
		// this JS/CSS on every post type's screens. The screen id is the
		// precise, CPT-agnostic way to scope to just shop_order (legacy
		// screen) or the HPOS "wc-orders" admin page.
		$order_screen_ids = array( 'edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders' );
		if ( ! $screen || ! in_array( $screen->id, $order_screen_ids, true ) ) {
			return;
		}

		wp_enqueue_script( 'billio-admin', BILLIO_URL . 'assets/js/admin.js', array(), BILLIO_VERSION, true );
		wp_enqueue_style( 'billio-admin', BILLIO_URL . 'assets/css/admin.css', array(), BILLIO_VERSION );
		wp_localize_script(
			'billio-admin',
			'billioAdmin',
			array(
				'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
				'i18nError'             => __( 'Something went wrong. Please try again.', 'billio' ),
				'i18nSent'              => __( 'Invoice email sent.', 'billio' ),
				'i18nConfirmRegenerate' => __( 'Regenerate this invoice\'s PDF? This replaces the existing file (same number) with freshly rendered content.', 'billio' ),
			)
		);
	}

	/**
	 * AJAX: generate an invoice for an order that doesn't have one yet.
	 *
	 * @return void
	 */
	public function ajax_generate() {
		$order = $this->authorize_ajax_request();

		try {
			Generator::generate( $order );
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf( 'Manual invoice generation failed for order #%d: %s', $order->get_id(), $e->getMessage() )
			);
			wp_send_json_error( array( 'message' => esc_html__( 'Could not generate the invoice.', 'billio' ) ) );
		}

		wp_send_json_success( array( 'invoice_number' => $order->get_meta( '_billio_invoice_number' ) ) );
	}

	/**
	 * AJAX: re-send the email matching the order's *current* status (with
	 * the invoice re-attached via the EmailAttacher filter), not always the
	 * Completed email regardless of where the order actually is.
	 *
	 * @return void
	 */
	public function ajax_resend() {
		$order = $this->authorize_ajax_request();

		$email_id = isset( self::STATUS_TO_EMAIL_ID[ $order->get_status() ] ) ? self::STATUS_TO_EMAIL_ID[ $order->get_status() ] : null;

		if ( ! $email_id ) {
			wp_send_json_error(
				array( 'message' => esc_html__( "There's no invoice email for this order's current status.", 'billio' ) )
			);
		}

		$eligible_email_ids = get_option( 'billio_email_triggers', array( 'customer_completed_order' ) );
		if ( ! in_array( $email_id, $eligible_email_ids, true ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( "This order's status isn't enabled for invoice emails in Billio → Settings.", 'billio' ) )
			);
		}

		$class  = self::EMAIL_ID_TO_CLASS[ $email_id ];
		$emails = WC()->mailer()->get_emails();

		if ( ! isset( $emails[ $class ] ) || ! $emails[ $class ]->is_enabled() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Could not find the email to resend.', 'billio' ) ) );
		}

		$emails[ $class ]->trigger( $order->get_id() );

		wp_send_json_success( array( 'message' => esc_html__( 'Invoice email sent.', 'billio' ) ) );
	}

	/**
	 * AJAX: force a fresh render of an order's invoice PDF (same number),
	 * e.g. after correcting company info — the normal generate/download
	 * path reuses the existing cached file, so this is the explicit
	 * "I want new content under the same number" action.
	 *
	 * @return void
	 */
	public function ajax_regenerate() {
		$order = $this->authorize_ajax_request();

		try {
			Generator::regenerate( $order );
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf( 'Invoice regeneration failed for order #%d: %s', $order->get_id(), $e->getMessage() )
			);
			wp_send_json_error( array( 'message' => esc_html__( 'Could not regenerate the invoice.', 'billio' ) ) );
		}

		// Regenerating replaces the issued PDF's content in place — an order
		// note is the lightweight "who + when" audit trail for that, reusing
		// WooCommerce's own existing, already-timestamped note storage.
		$order->add_order_note(
			sprintf(
				/* translators: %s: display name of the admin who regenerated the invoice. */
				esc_html__( 'Invoice PDF regenerated by %s.', 'billio' ),
				wp_get_current_user()->display_name
			)
		);

		wp_send_json_success();
	}

	/**
	 * Validate nonce + capability for an AJAX request and return the order.
	 *
	 * Halts the request (wp_send_json_error + die) on failure.
	 *
	 * @return \WC_Order
	 */
	private function authorize_ajax_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'billio' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Order not found.', 'billio' ) ), 404 );
		}

		return $order;
	}

	/**
	 * admin-post handler: download an order's invoice PDF as an admin.
	 *
	 * @return void
	 */
	public function handle_download() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		check_admin_referer( self::NONCE_ACTION . '_' . $order_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'billio' ), '', array( 'response' => 403 ) );
		}

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'billio' ), '', array( 'response' => 404 ) );
		}

		try {
			// Generator::generate() reuses the cached file when present, and
			// self-heals (rebuilds under the same number) if the meta points
			// to a file that's gone missing (restored backup, migration) —
			// no separate "does the file still exist" check needed here.
			$relative_path = Generator::generate( $order );
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf( 'Invoice download generation failed for order #%d: %s', $order->get_id(), $e->getMessage() )
			);
			wp_die( esc_html__( 'Could not generate the invoice.', 'billio' ), '', array( 'response' => 500 ) );
		}

		FileServer::stream(
			Generator::get_absolute_path( $relative_path ),
			'invoice-' . $order->get_meta( '_billio_invoice_number' ) . '.pdf'
		);
	}
}
