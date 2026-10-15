<?php
/**
 * Invoice line items + totals — shared by the Default and Modern invoice
 * templates (included from inside them, so it sees the same variables).
 * Override from a theme at yourtheme/billio/partials/invoice-lines.php.
 *
 * Available variables (from TemplateEngine::render()):
 * @var \WC_Order $order
 * @var string    $currency
 *
 * @package Billio
 */

use Billio\Invoice\TemplateEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are scoped inside TemplateEngine render methods.
?>
	<table class="items">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Item', 'billio' ); ?></th>
				<th class="num"><?php esc_html_e( 'Qty', 'billio' ); ?></th>
				<th class="num"><?php esc_html_e( 'Unit Price', 'billio' ); ?></th>
				<th class="num"><?php esc_html_e( 'Total', 'billio' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $order->get_items() as $item ) : ?>
				<?php
				// Pre-discount (get_subtotal(), not get_total()) so these
				// rows sum to the "Subtotal" line below — Discount is shown
				// as its own line rather than silently baked into each row,
				// which previously made the rows and Subtotal not add up.
				$qty        = $item->get_quantity();
				$line_total = (float) $item->get_subtotal();
				$unit_price = $qty ? $line_total / $qty : $line_total;
				?>
				<tr>
					<td>
						<?php echo esc_html( $item->get_name() ); ?>
						<?php $item_meta = TemplateEngine::item_meta( $item ); ?>
						<?php if ( $item_meta ) : ?>
							<div style="color:#888;font-size:10px;"><?php echo esc_html( $item_meta ); ?></div>
						<?php endif; ?>
					</td>
					<td class="num"><?php echo esc_html( $qty ); ?></td>
					<td class="num"><?php echo esc_html( TemplateEngine::money( $unit_price, $currency ) ); ?></td>
					<td class="num"><?php echo esc_html( TemplateEngine::money( $line_total, $currency ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<table class="totals">
		<tr>
			<td class="label"><?php esc_html_e( 'Subtotal', 'billio' ); ?></td>
			<td class="val"><?php echo esc_html( TemplateEngine::money( (float) $order->get_subtotal(), $currency ) ); ?></td>
		</tr>
		<?php if ( (float) $order->get_shipping_total() > 0 ) : ?>
			<tr>
				<td class="label"><?php esc_html_e( 'Shipping', 'billio' ); ?></td>
				<td class="val"><?php echo esc_html( TemplateEngine::money( (float) $order->get_shipping_total(), $currency ) ); ?></td>
			</tr>
		<?php endif; ?>
		<?php
		$fees_total = 0;
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$fees_total += (float) $fee->get_total();
		}
		?>
		<?php if ( 0 !== $fees_total ) : ?>
			<tr>
				<td class="label"><?php esc_html_e( 'Fees', 'billio' ); ?></td>
				<td class="val"><?php echo esc_html( TemplateEngine::money( $fees_total, $currency ) ); ?></td>
			</tr>
		<?php endif; ?>
		<?php foreach ( $order->get_tax_totals() as $tax_total ) : ?>
			<tr>
				<td class="label"><?php echo esc_html( $tax_total->label ); ?></td>
				<td class="val"><?php echo esc_html( TemplateEngine::money( (float) $tax_total->amount, $currency ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		<?php if ( (float) $order->get_total_discount() > 0 ) : ?>
			<tr>
				<td class="label"><?php esc_html_e( 'Discount', 'billio' ); ?></td>
				<td class="val">-<?php echo esc_html( TemplateEngine::money( (float) $order->get_total_discount(), $currency ) ); ?></td>
			</tr>
		<?php endif; ?>
		<tr class="grand">
			<td class="label"><?php esc_html_e( 'Total', 'billio' ); ?></td>
			<td class="val"><?php echo esc_html( TemplateEngine::money( (float) $order->get_total(), $currency ) ); ?></td>
		</tr>
	</table>

	<?php if ( (float) $order->get_total_refunded() > 0 ) : ?>
		<p style="text-align:right;color:#888;font-size:11px;margin-top:8px;">
			<?php
			printf(
				/* translators: %s: refunded amount, already formatted with currency. */
				esc_html__( 'Refunded: %s (see your refund receipt for details)', 'billio' ),
				esc_html( TemplateEngine::money( (float) $order->get_total_refunded(), $currency ) )
			);
			?>
		</p>
	<?php endif; ?>
