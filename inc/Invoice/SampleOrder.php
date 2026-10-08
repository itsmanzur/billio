<?php
/**
 * Builds an in-memory (never saved) sample order, used only to render the
 * Settings page's live invoice preview against the real template files.
 *
 * @package Billio
 */

namespace Billio\Invoice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SampleOrder {

	/**
	 * Build a representative, unsaved WC_Order for previewing.
	 *
	 * @return \WC_Order
	 */
	public static function make() {
		$order = new \WC_Order();
		$order->set_currency( get_woocommerce_currency() );
		$order->set_billing_first_name( 'Rahim' );
		$order->set_billing_last_name( 'Uddin' );
		$order->set_billing_address_1( '42 Gulshan Avenue' );
		$order->set_billing_city( 'Dhaka' );
		$order->set_billing_country( 'BD' );
		$order->set_billing_email( 'rahim@example.com' );

		$item_one = new \WC_Order_Item_Product();
		$item_one->set_name( esc_html__( 'Sample Product A', 'billio' ) );
		$item_one->set_quantity( 2 );
		$item_one->set_total( 40 );
		$order->add_item( $item_one );

		$item_two = new \WC_Order_Item_Product();
		$item_two->set_name( esc_html__( 'Sample Product B', 'billio' ) );
		$item_two->set_quantity( 1 );
		$item_two->set_total( 25 );
		$order->add_item( $item_two );

		$order->set_shipping_total( 5 );
		$order->set_discount_total( 0 );
		$order->set_total( 70 );

		// Never persisted: no $order->save() call, and id stays 0.
		return $order;
	}

	/**
	 * Build a representative, unsaved WC_Order_Refund against a sample
	 * order, used only for the Settings page's live credit-note preview.
	 *
	 * @param \WC_Order $order Parent order (normally SampleOrder::make()'s result).
	 * @return \WC_Order_Refund
	 */
	public static function make_refund( \WC_Order $order ) {
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_currency( $order->get_currency() );
		$refund->set_reason( esc_html__( 'Customer changed their mind', 'billio' ) );

		$item = new \WC_Order_Item_Product();
		$item->set_name( esc_html__( 'Sample Product A', 'billio' ) );
		// Refund line items use negative quantity/total by WooCommerce
		// convention (matches how a real partial refund stores its items).
		$item->set_quantity( -1 );
		$item->set_total( -20 );
		$refund->add_item( $item );

		$refund->set_amount( 20 );

		// Never persisted: no $refund->save() call, and id stays 0.
		return $refund;
	}
}
