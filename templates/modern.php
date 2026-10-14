<?php
/**
 * Modern invoice template — accent-color header band, lighter typography.
 *
 * Available variables (extracted by TemplateEngine::render()):
 * @var \WC_Order $order
 * @var string    $invoice_number
 * @var string    $invoice_date
 * @var string    $currency
 * @var array     $company  { name, address, vat, logo }
 *
 * @package Billio
 */

use Billio\Invoice\TemplateEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are scoped inside TemplateEngine render methods.
$accent = get_option( 'billio_accent_color', '#3b6cf4' );
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
	body { font-family: 'Noto Sans Bengali', sans-serif; font-size: 12px; color: #26262c; margin: 0; }
	.band { background: <?php echo esc_html( $accent ); ?>; color: #fff; padding: 28px 40px; }
	.band table { width: 100%; }
	.band .logo img { max-height: 48px; max-width: 200px; }
	.band .title { font-size: 24px; font-weight: bold; text-align: right; }
	.band .sub { text-align: right; font-size: 11px; opacity: .85; margin-top: 4px; }
	.wrap { padding: 28px 40px; }
	.company-name { font-size: 15px; font-weight: bold; margin-bottom: 4px; }
	.meta-row { width: 100%; margin-bottom: 20px; }
	.meta-row td { vertical-align: top; width: 50%; }
	.label { color: #999; font-size: 9px; letter-spacing: .06em; text-transform: uppercase; margin-bottom: 4px; }
	.block { line-height: 1.6; }
	table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
	table.items th { border-bottom: 2px solid <?php echo esc_html( $accent ); ?>; text-align: left; padding: 8px 10px; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; color: #666; }
	table.items th.num, table.items td.num { text-align: right; }
	table.items td { padding: 10px; border-bottom: 1px solid #f0f0f0; }
	table.totals { width: 280px; margin-left: auto; margin-top: 16px; }
	table.totals td { padding: 5px 0; }
	table.totals td.label { color: #555; }
	table.totals td.val { text-align: right; }
	table.totals tr.grand td { border-top: 2px solid <?php echo esc_html( $accent ); ?>; font-size: 15px; font-weight: bold; padding-top: 10px; color: <?php echo esc_html( $accent ); ?>; }
	.footer { margin-top: 36px; color: #aaa; font-size: 10px; text-align: center; }
	.watermark-paid { position: absolute; top: 360px; left: 160px; transform: rotate(-24deg); font-size: 72px; font-weight: bold; color: #16a34a; border: 5px solid #16a34a; padding: 6px 32px; opacity: 0.16; text-transform: uppercase; letter-spacing: 6px; border-radius: 8px; z-index: 999; }
</style>
</head>
<body>

<?php if ( ! empty( $show_paid_watermark ) && ( $order->is_paid() || in_array( $order->get_status(), array( 'completed', 'processing' ), true ) ) ) : ?>
	<div class="watermark-paid"><?php esc_html_e( 'PAID', 'billio' ); ?></div>
<?php endif; ?>

<div class="band">
	<table>
		<tr>
			<td class="logo" style="width: 55%;">
				<?php if ( $company['logo'] ) : ?>
					<img src="<?php echo esc_attr( $company['logo'] ); ?>" alt="">
				<?php else : ?>
					<?php echo esc_html( $company['name'] ); ?>
				<?php endif; ?>
			</td>
			<td style="width: 45%;">
				<div class="title"><?php esc_html_e( 'INVOICE', 'billio' ); ?></div>
				<div class="sub"><?php echo esc_html( $invoice_number ); ?> &middot; <?php echo esc_html( $invoice_date ); ?></div>
			</td>
		</tr>
	</table>
</div>

<div class="wrap">

	<table class="meta-row">
		<tr>
			<td>
				<div class="label"><?php esc_html_e( 'From', 'billio' ); ?></div>
				<div class="block">
					<div class="company-name"><?php echo esc_html( $company['name'] ); ?></div>
					<?php
					if ( $company['address'] ) :
						?>
						<?php echo nl2br( esc_html( $company['address'] ) ); ?><br><?php endif; ?>
					<?php
					if ( $company['vat'] ) :
						?>
						<?php esc_html_e( 'VAT/Tax No:', 'billio' ); ?> <?php echo esc_html( $company['vat'] ); ?><?php endif; ?>
				</div>
			</td>
			<td>
				<div class="label"><?php esc_html_e( 'Billed to', 'billio' ); ?></div>
				<div class="block">
					<strong><?php echo esc_html( trim( $order->get_formatted_billing_full_name() ) ); ?></strong><br>
					<?php echo wp_kses_post( $order->get_formatted_billing_address() ); ?>
					<?php
					if ( $order->get_billing_email() ) :
						?>
						<br><?php echo esc_html( $order->get_billing_email() ); ?><?php endif; ?>
					<?php
					if ( $order->get_billing_phone() ) :
						?>
						<br><?php echo esc_html( $order->get_billing_phone() ); ?><?php endif; ?>
					<?php if ( ! empty( $customer_tax_id ) ) : ?>
						<br><strong><?php echo esc_html( $customer_tax_id_label ); ?>:</strong> <?php echo esc_html( $customer_tax_id ); ?>
					<?php endif; ?>
				</div>
			</td>
		</tr>
	</table>

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

	<div class="footer">
		<?php esc_html_e( 'Thank you for your business.', 'billio' ); ?>
	</div>

</div>
</body>
</html>
