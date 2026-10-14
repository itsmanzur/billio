<?php
/**
 * Packing slip template — items + shipping address, no prices (standard
 * packing-slip convention: fulfillment staff don't need line values).
 *
 * Available variables (set by TemplateEngine::render_packing_slip()):
 * @var \WC_Order $order
 * @var array     $company  { name, logo }
 *
 * @package Billio
 */

use Billio\Invoice\TemplateEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are scoped inside TemplateEngine render methods.
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
	body { font-family: 'Noto Sans Bengali', sans-serif; font-size: 12px; color: #1a1a1a; margin: 0; }
	.wrap { padding: 36px 40px; }
	.header { width: 100%; }
	.header td { vertical-align: top; }
	.logo img { max-height: 60px; max-width: 220px; }
	.company-name { font-size: 16px; font-weight: bold; margin-bottom: 4px; }
	.slip-title { font-size: 22px; font-weight: bold; text-align: right; color: #1a1a1a; }
	.slip-meta { text-align: right; color: #555; line-height: 1.6; margin-top: 6px; }
	.slip-meta strong { color: #1a1a1a; }
	hr { border: none; border-top: 1px solid #ddd; margin: 24px 0; }
	.ship-to-label { color: #888; font-size: 10px; letter-spacing: .05em; text-transform: uppercase; margin-bottom: 4px; }
	.ship-to { line-height: 1.6; }
	table.items { width: 100%; border-collapse: collapse; margin-top: 24px; }
	table.items th { background: #f5f5f5; text-align: left; padding: 8px 10px; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; color: #666; border-bottom: 1px solid #ddd; }
	table.items th.num, table.items td.num { text-align: right; }
	table.items td { padding: 10px; border-bottom: 1px solid #eee; }
	.item-meta { color: #888; font-size: 10px; margin-top: 2px; }
	.notes { margin-top: 30px; border-top: 1px solid #ddd; padding-top: 10px; color: #888; font-size: 11px; }
	.footer { margin-top: 40px; color: #999; font-size: 10px; text-align: center; }
</style>
</head>
<body>
<div class="wrap">

	<table class="header">
		<tr>
			<td class="logo" style="width: 55%;">
				<?php if ( $company['logo'] ) : ?>
					<img src="<?php echo esc_attr( $company['logo'] ); ?>" alt="">
				<?php endif; ?>
				<div class="company-name"><?php echo esc_html( $company['name'] ); ?></div>
			</td>
			<td style="width: 45%;">
				<div class="slip-title"><?php esc_html_e( 'PACKING SLIP', 'billio' ); ?></div>
				<div class="slip-meta">
					<strong><?php esc_html_e( 'Order:', 'billio' ); ?></strong> #<?php echo esc_html( $order->get_order_number() ); ?><br>
					<strong><?php esc_html_e( 'Date:', 'billio' ); ?></strong> <?php echo esc_html( wp_date( get_option( 'date_format' ) ) ); ?>
				</div>
			</td>
		</tr>
	</table>

	<hr>

	<div class="ship-to-label"><?php esc_html_e( 'Ship to', 'billio' ); ?></div>
	<div class="ship-to">
		<?php
		$ship_name = trim( $order->get_formatted_shipping_full_name() );
		if ( ! $ship_name ) {
			$ship_name = trim( $order->get_formatted_billing_full_name() );
		}
		$ship_address = $order->get_formatted_shipping_address();
		if ( ! $ship_address ) {
			$ship_address = $order->get_formatted_billing_address();
		}
		?>
		<strong><?php echo esc_html( $ship_name ); ?></strong><br>
		<?php echo wp_kses_post( $ship_address ); ?>
		<?php
		if ( $order->get_billing_phone() ) :
			?>
			<br><?php echo esc_html( $order->get_billing_phone() ); ?><?php endif; ?>
	</div>

	<table class="items">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Item', 'billio' ); ?></th>
				<th><?php esc_html_e( 'SKU', 'billio' ); ?></th>
				<th class="num"><?php esc_html_e( 'Qty', 'billio' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $order->get_items() as $item ) : ?>
				<?php
				$product   = $item->get_product();
				$item_meta = TemplateEngine::item_meta( $item );
				?>
				<tr>
					<td>
						<?php echo esc_html( $item->get_name() ); ?>
						<?php if ( $item_meta ) : ?>
							<div class="item-meta"><?php echo esc_html( $item_meta ); ?></div>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $product ? $product->get_sku() : '' ); ?></td>
					<td class="num"><?php echo esc_html( $item->get_quantity() ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $order->get_customer_note() ) : ?>
		<div class="notes">
			<strong><?php esc_html_e( 'Order notes:', 'billio' ); ?></strong>
			<?php echo esc_html( $order->get_customer_note() ); ?>
		</div>
	<?php endif; ?>

	<div class="footer">
		<?php esc_html_e( 'Thank you for your business.', 'billio' ); ?>
	</div>

</div>
</body>
</html>
