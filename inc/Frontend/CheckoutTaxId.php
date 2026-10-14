<?php
/**
 * B2B Customer Tax ID (VAT, BIN, TRN, GSTIN) checkout integration.
 *
 * Supports Classic Shortcode Checkout, modern WooCommerce Block Checkout,
 * Admin Order screen editing, customer user profile billing meta,
 * and order details display.
 *
 * @package Billio
 */

namespace Billio\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CheckoutTaxId {

	public function __construct() {
		// Checkout fields registration (Classic & Block).
		add_filter( 'woocommerce_checkout_fields', array( $this, 'register_classic_checkout_field' ) );
		add_action( 'woocommerce_init', array( $this, 'register_block_checkout_field' ) );

		// Save field to order meta on checkout submission.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_classic_checkout_field' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'sync_order_tax_id' ) );

		// WooCommerce Admin Order edit screen.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'render_admin_order_tax_id' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_admin_order_tax_id' ) );

		// Customer My Account / Thank You order details.
		add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'render_customer_order_tax_id' ) );

		// User profile billing fields (Admin > Users > Edit User).
		add_filter( 'woocommerce_customer_meta_fields', array( $this, 'add_customer_profile_field' ) );
	}

	/**
	 * Retrieve the configured field label.
	 *
	 * @return string
	 */
	public static function get_label() {
		$label = (string) get_option( 'billio_tax_id_label', __( 'VAT / Tax / BIN Number', 'billio' ) );
		return ! empty( $label ) ? $label : __( 'VAT / Tax ID', 'billio' );
	}

	/**
	 * Check whether the Customer Tax ID feature is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( 'billio_enable_tax_id', false );
	}

	/**
	 * Check whether entering a Tax ID is mandatory at checkout.
	 *
	 * @return bool
	 */
	public static function is_required() {
		return (bool) get_option( 'billio_tax_id_required', false );
	}

	/**
	 * Register the tax ID field for Classic WooCommerce Checkout.
	 *
	 * @param array $fields Existing checkout fields.
	 * @return array
	 */
	public function register_classic_checkout_field( $fields ) {
		if ( ! self::is_enabled() ) {
			return $fields;
		}

		$label = self::get_label();

		$fields['billing']['billing_billio_tax_id'] = array(
			'type'        => 'text',
			'label'       => $label,
			'placeholder' => $label,
			'required'    => self::is_required(),
			'class'       => array( 'form-row-wide' ),
			'clear'       => true,
			'priority'    => 35, // Positioned near Company Name (priority 30).
		);

		return $fields;
	}

	/**
	 * Register the additional checkout field for WooCommerce Block Checkout (WooCommerce 8.6+).
	 *
	 * @return void
	 */
	public function register_block_checkout_field() {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'billio/tax-id',
				'label'    => self::get_label(),
				'location' => 'address',
				'required' => self::is_required(),
				'type'     => 'text',
			)
		);
	}

	/**
	 * Save classic checkout field to order meta during order creation.
	 *
	 * @param \WC_Order $order New order instance.
	 * @param array     $data  Posted checkout data.
	 * @return void
	 */
	public function save_classic_checkout_field( $order, $data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! self::is_enabled() ) {
			return;
		}

		// Nonce verification is performed upstream by WooCommerce checkout process.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['billing_billio_tax_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$tax_id = sanitize_text_field( wp_unslash( $_POST['billing_billio_tax_id'] ) );
			$order->update_meta_data( '_billio_tax_id', $tax_id );
			$order->update_meta_data( '_billing_billio_tax_id', $tax_id );
		}
	}

	/**
	 * Sync tax ID meta for Block Checkout and other sources after order is created.
	 *
	 * @param \WC_Order $order Created order instance.
	 * @return void
	 */
	public function sync_order_tax_id( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$tax_id = $order->get_meta( '_billio_tax_id' );
		if ( ! empty( $tax_id ) ) {
			return;
		}

		// Retrieve from Block Checkout additional field if available.
		if ( function_exists( 'woocommerce_get_order_additional_field_value' ) ) {
			$block_val = (string) woocommerce_get_order_additional_field_value( $order, 'billio/tax-id' );
			if ( ! empty( $block_val ) ) {
				$clean = sanitize_text_field( $block_val );
				$order->update_meta_data( '_billio_tax_id', $clean );
				$order->update_meta_data( '_billing_billio_tax_id', $clean );
				$order->save();
				return;
			}
		}

		// Fallback check on billing_billio_tax_id.
		$billing_tax_id = $order->get_meta( '_billing_billio_tax_id' );
		if ( ! empty( $billing_tax_id ) ) {
			$order->update_meta_data( '_billio_tax_id', sanitize_text_field( $billing_tax_id ) );
			$order->save();
		}
	}

	/**
	 * Render the Customer Tax ID in WooCommerce Admin order details (after billing address).
	 *
	 * @param \WC_Order $order Current order instance.
	 * @return void
	 */
	public function render_admin_order_tax_id( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$tax_id = $order->get_meta( '_billio_tax_id' );
		if ( '' === $tax_id ) {
			$tax_id = $order->get_meta( '_billing_billio_tax_id' );
		}

		$label = self::get_label();
		?>
		<div class="address">
			<p>
				<strong><?php echo esc_html( $label ); ?>:</strong>
				<?php echo ! empty( $tax_id ) ? esc_html( $tax_id ) : '&mdash;'; ?>
			</p>
		</div>
		<div class="edit_address" style="display:none;">
			<?php
			if ( function_exists( 'woocommerce_wp_text_input' ) ) {
				woocommerce_wp_text_input(
					array(
						'id'            => '_billio_tax_id',
						'label'         => $label,
						'value'         => $tax_id,
						'wrapper_class' => '_billing_billio_tax_id_field',
					)
				);
			}
			?>
		</div>
		<?php
	}

	/**
	 * Save modified Customer Tax ID when an order is updated in WooCommerce Admin.
	 *
	 * @param int $order_id Order ID being updated.
	 * @return void
	 */
	public function save_admin_order_tax_id( $order_id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Nonce verification handled by WooCommerce order meta box.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['_billio_tax_id'] ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$tax_id = sanitize_text_field( wp_unslash( $_POST['_billio_tax_id'] ) );
		$order->update_meta_data( '_billio_tax_id', $tax_id );
		$order->update_meta_data( '_billing_billio_tax_id', $tax_id );
		$order->save();
	}

	/**
	 * Display customer tax ID on My Account > View Order and Order Received (Thank You) screen.
	 *
	 * @param \WC_Order $order Current order instance.
	 * @return void
	 */
	public function render_customer_order_tax_id( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$tax_id = $order->get_meta( '_billio_tax_id' );
		if ( '' === $tax_id ) {
			$tax_id = $order->get_meta( '_billing_billio_tax_id' );
		}

		if ( empty( $tax_id ) ) {
			return;
		}

		$label = self::get_label();
		?>
		<p class="billio-customer-tax-id" style="margin-top: 10px;">
			<strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( $tax_id ); ?>
		</p>
		<?php
	}

	/**
	 * Add Customer Tax ID to WordPress Admin > Users > Edit User > Customer Billing Address.
	 *
	 * @param array $fields Array of user billing/shipping fields.
	 * @return array
	 */
	public function add_customer_profile_field( $fields ) {
		if ( ! self::is_enabled() ) {
			return $fields;
		}

		if ( isset( $fields['billing']['fields'] ) ) {
			$fields['billing']['fields']['billing_billio_tax_id'] = array(
				'label'       => self::get_label(),
				'description' => __( 'Customer VAT / Tax / BIN Number', 'billio' ),
			);
		}

		return $fields;
	}
}
