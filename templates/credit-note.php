<?php
/**
 * Credit note template — documents a refund against an order.
 *
 * Available variables (set by TemplateEngine::render_credit_note()):
 * @var \WC_Order        $order               The original (parent) order.
 * @var \WC_Order_Refund $refund              The refund being documented.
 * @var string           $credit_note_number  Already-assigned credit note number.
 * @var string           $invoice_number      The original invoice's number, if any.
 * @var string           $currency            Order currency.
 * @var array            $company             { name, address, vat, logo }
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
	.company-meta { color: #555; line-height: 1.5; }
	.note-title { font-size: 22px; font-weight: bold; text-align: right; color: #e05740; }
	.note-meta { text-align: right; color: #555; line-height: 1.6; margin-top: 6px; }
	.note-meta strong { color: #1a1a1a; }
	hr { border: none; border-top: 1px solid #ddd; margin: 24px 0; }
	.bill-to-label { color: #888; font-size: 10px; letter-spacing: .05em; text-transform: uppercase; margin-bottom: 4px; }
	.bill-to { line-height: 1.6; }
	table.items { width: 100%; border-collapse: collapse; margin-top: 24px; }
	table.items th { background: #f5f5f5; text-align: left; padding: 8px 10px; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; color: #666; border-bottom: 1px solid #ddd; }
	table.items th.num, table.items td.num { text-align: right; }
	table.items td { padding: 10px; border-bottom: 1px solid #eee; }
	.item-meta { color: #888; font-size: 10px; margin-top: 2px; }
	table.totals { width: 280px; margin-left: auto; margin-top: 16px; }
	table.totals td { padding: 5px 0; }
	table.totals td.label { color: #555; }
	table.totals td.val { text-align: right; }
	table.totals tr.grand td { border-top: 2px solid #e05740; font-size: 14px; font-weight: bold; padding-top: 10px; color: #e05740; }
	.reason { margin-top: 30px; border-top: 1px solid #ddd; padding-top: 10px; color: #888; font-size: 11px; }
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
				<div class="company-meta">
					<?php if ( $company['address'] ) : ?>
						<?php echo nl2br( esc_html( $company['address'] ) ); ?><br>
					<?php endif; ?>
					<?php if ( $company['vat'] ) : ?>
						<?php esc_html_e( 'VAT/Tax No:', 'billio' ); ?> <?php echo esc_html( $company['vat'] ); ?>
					<?php endif; ?>
				</div>
			</td>
			<td style="width: 45%;">
				<div class="note-title"><?php esc_html_e( 'CREDIT NOTE', 'billio' ); ?></div>
				<div class="note-meta">
					<strong><?php esc_html_e( 'Credit Note #:', 'billio' ); ?></strong> <?php echo esc_html( $credit_note_number ); ?><br>
					<?php $credit_note_timestamp = (int) $refund->get_meta( '_billio_credit_note_date' ); ?>
					<strong><?php esc_html_e( 'Date:', 'billio' ); ?></strong> <?php echo esc_html( wp_date( get_option( 'date_format' ), $credit_note_timestamp ? $credit_note_timestamp : time() ) ); ?><br>
					<?php if ( $invoice_number ) : ?>
						<strong><?php esc_html_e( 'For Invoice #:', 'billio' ); ?></strong> <?php echo esc_html( $invoice_number ); ?><br>
					<?php endif; ?>
					<strong><?php esc_html_e( 'Order:', 'billio' ); ?></strong> #<?php echo esc_html( $order->get_order_number() ); ?>
				</div>
			</td>
		</tr>
	</table>

	<hr>

	<div class="bill-to-label"><?php esc_html_e( 'Billed to', 'billio' ); ?></div>
	<div class="bill-to">
		<strong><?php echo esc_html( trim( $order->get_formatted_billing_full_name() ) ); ?></strong><br>
		<?php echo wp_kses_post( $order->get_formatted_billing_address() ); ?>
		<?php if ( ! empty( $customer_tax_id ) ) : ?>
			<br><strong><?php echo esc_html( $customer_tax_id_label ); ?>:</strong> <?php echo esc_html( $customer_tax_id ); ?>
		<?php endif; ?>
	</div>

	<table class="items">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Item', 'billio' ); ?></th>
				<th class="num"><?php esc_html_e( 'Qty', 'billio' ); ?></th>
				<th class="num"><?php esc_html_e( 'Refunded', 'billio' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			// Product lines plus refunded shipping and fees — otherwise a
			// shipping-only refund would read as "flat amount" and the rows
			// wouldn't add up to Total Refunded.
			$refund_items = $refund->get_items( array( 'line_item', 'shipping', 'fee' ) );
			?>
			<?php if ( empty( $refund_items ) ) : ?>
				<tr>
					<td colspan="3"><?php esc_html_e( 'Refund issued as a flat amount (no item breakdown).', 'billio' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $refund_items as $item ) : ?>
					<tr>
						<td><?php echo esc_html( $item->get_name() ); ?></td>
						<td class="num"><?php echo $item->is_type( 'line_item' ) ? esc_html( abs( $item->get_quantity() ) ) : '&mdash;'; ?></td>
						<td class="num"><?php echo esc_html( TemplateEngine::money( abs( (float) $item->get_total() ), $currency ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<table class="totals">
		<?php foreach ( $refund->get_items( 'tax' ) as $tax_item ) : ?>
			<?php if ( 0 !== (float) $tax_item->get_tax_total() ) : ?>
				<tr>
					<td class="label"><?php echo esc_html( $tax_item->get_label() ); ?></td>
					<td class="val"><?php echo esc_html( TemplateEngine::money( abs( (float) $tax_item->get_tax_total() ), $currency ) ); ?></td>
				</tr>
			<?php endif; ?>
		<?php endforeach; ?>
		<tr class="grand">
			<td class="label"><?php esc_html_e( 'Total Refunded', 'billio' ); ?></td>
			<td class="val"><?php echo esc_html( TemplateEngine::money( abs( (float) $refund->get_amount() ), $currency ) ); ?></td>
		</tr>
	</table>

	<?php if ( $refund->get_reason() ) : ?>
		<div class="reason">
			<strong><?php esc_html_e( 'Reason:', 'billio' ); ?></strong>
			<?php echo esc_html( $refund->get_reason() ); ?>
		</div>
	<?php endif; ?>

	<div class="footer">
		<?php esc_html_e( 'This credit note documents a refund against the order above.', 'billio' ); ?>
	</div>

</div>
</body>
</html>
