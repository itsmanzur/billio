<?php
/**
 * AJAX endpoints backing the redesigned Dashboard: quick settings toggles,
 * the "Generate missing" batch flow, on-demand chart ranges, and the
 * setup-banner dismiss flag. Every handler: nonce + manage_woocommerce +
 * sanitize + wp_send_json_*, same shape as OrderActions'/MetaBox's own
 * AJAX/admin-post handlers.
 *
 * @package Billio
 */

namespace Billio\Admin;

use Billio\Invoice\Generator;
use Billio\Logger;
use Billio\Settings\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DashboardAjax {

	/**
	 * Max orders generated per "Generate missing" batch request — keeps
	 * each AJAX round-trip fast and lets the JS show real incremental
	 * progress instead of one long opaque request.
	 */
	const BATCH_SIZE = 5;

	/**
	 * @var Dashboard Shared chart-data/missing-ids queries, reused rather
	 *                than duplicated here.
	 */
	private $dashboard;

	/**
	 * @param Dashboard $dashboard The Dashboard instance whose query helpers
	 *                             (chart data, missing-order ids) this reuses.
	 */
	public function __construct( Dashboard $dashboard ) {
		$this->dashboard = $dashboard;

		add_action( 'wp_ajax_billio_dashboard_quick_setting', array( $this, 'ajax_quick_setting' ) );
		add_action( 'wp_ajax_billio_dashboard_missing_order_ids', array( $this, 'ajax_missing_order_ids' ) );
		add_action( 'wp_ajax_billio_dashboard_generate_batch', array( $this, 'ajax_generate_batch' ) );
		add_action( 'wp_ajax_billio_dashboard_chart_data', array( $this, 'ajax_chart_data' ) );
		add_action( 'wp_ajax_billio_dashboard_dismiss_setup', array( $this, 'ajax_dismiss_setup' ) );
	}

	/**
	 * Validate nonce + capability for a dashboard AJAX request. Halts the
	 * request (wp_send_json_error + die) on failure.
	 *
	 * @return void
	 */
	private function authorize() {
		check_ajax_referer( Dashboard::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'billio' ) ), 403 );
		}
	}

	/**
	 * AJAX: update one setting from the dashboard's "at a glance" card.
	 *
	 * Writes to the exact same option keys/validation the Settings page
	 * uses — the submitted option name is looked up in Fields::get(),
	 * which doubles as the allowlist (anything not defined there is
	 * rejected), then run through Fields::sanitize() exactly as
	 * SettingsPage::maybe_save() does for a normal form save.
	 *
	 * @return void
	 */
	public function ajax_quick_setting() {
		$this->authorize();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- already verified in authorize() above; the sniff can't see across the method call.
		$option = isset( $_POST['option'] ) ? sanitize_key( wp_unslash( $_POST['option'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$raw = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';

		$field = null;
		foreach ( Fields::get() as $candidate ) {
			if ( $candidate['option'] === $option ) {
				$field = $candidate;
				break;
			}
		}

		// Only a small, deliberate subset of fields are meant to be quick-
		// toggled from the dashboard; everything else (company name,
		// address, VAT, etc.) is still Settings-page-only, so reject
		// anything not on that short list even though it exists in Fields.
		$allowed_quick_options = array( 'billio_email_triggers', 'billio_template', 'billio_paper_size' );

		if ( ! $field || ! in_array( $option, $allowed_quick_options, true ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'That setting cannot be changed from here.', 'billio' ) ) );
		}

		$value = Fields::sanitize( $field, $raw );

		if ( 'billio_email_triggers' === $option && empty( $value ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'At least one "Send invoice when order is" option must stay checked.', 'billio' ) )
			);
		}

		update_option( $option, $value );

		wp_send_json_success( array( 'value' => $value ) );
	}

	/**
	 * AJAX: the full id list for "Generate missing" — fetched only when the
	 * modal opens, not localized on every page load.
	 *
	 * @return void
	 */
	public function ajax_missing_order_ids() {
		$this->authorize();

		wp_send_json_success( array( 'orderIds' => array_map( 'intval', $this->dashboard->get_missing_order_ids() ) ) );
	}

	/**
	 * AJAX: generate invoices for up to BATCH_SIZE orders in one request.
	 *
	 * @return void
	 */
	public function ajax_generate_batch() {
		$this->authorize();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- already verified in authorize() above.
		$order_ids = isset( $_POST['order_ids'] ) ? (array) wp_unslash( $_POST['order_ids'] ) : array();
		$order_ids = array_slice( array_map( 'absint', $order_ids ), 0, self::BATCH_SIZE );

		$results = array();

		foreach ( $order_ids as $order_id ) {
			$order = $order_id ? wc_get_order( $order_id ) : false;
			if ( ! $order ) {
				$results[] = array(
					'orderId' => $order_id,
					'success' => false,
				);
				continue;
			}

			try {
				Generator::generate( $order );
				$results[] = array(
					'orderId'       => $order_id,
					'success'       => true,
					'invoiceNumber' => $order->get_meta( '_billio_invoice_number' ),
				);
			} catch ( \Throwable $e ) {
				Logger::error(
					sprintf( 'Dashboard batch invoice generation failed for order #%d: %s', $order_id, $e->getMessage() )
				);
				$results[] = array(
					'orderId' => $order_id,
					'success' => false,
				);
			}
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * AJAX: chart data for a non-default range (7 or 90 days — 30 is
	 * rendered server-side on first load already).
	 *
	 * @return void
	 */
	public function ajax_chart_data() {
		$this->authorize();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- already verified in authorize() above.
		$days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;
		if ( ! in_array( $days, array( 7, 30, 90 ), true ) ) {
			$days = 30;
		}

		wp_send_json_success( array( 'days' => $this->dashboard->get_chart_data( $days ) ) );
	}

	/**
	 * AJAX: remember (per-user) that the setup banner was dismissed.
	 *
	 * @return void
	 */
	public function ajax_dismiss_setup() {
		$this->authorize();

		update_user_meta( get_current_user_id(), Dashboard::OPTION_SETUP_DISMISSED, 1 );

		wp_send_json_success();
	}
}
