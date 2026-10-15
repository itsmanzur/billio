<?php
/**
 * Default invoice template.
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
	.invoice-title { font-size: 22px; font-weight: bold; text-align: right; color: #1a1a1a; }
	.invoice-meta { text-align: right; color: #555; line-height: 1.6; margin-top: 6px; }
	.invoice-meta strong { color: #1a1a1a; }
	hr { border: none; border-top: 1px solid #ddd; margin: 24px 0; }
	.bill-to-label { color: #888; font-size: 10px; letter-spacing: .05em; text-transform: uppercase; margin-bottom: 4px; }
	.bill-to { line-height: 1.6; }
	table.items { width: 100%; border-collapse: collapse; margin-top: 24px; }
	table.items th { background: #f5f5f5; text-align: left; padding: 8px 10px; font-size: 10px; letter-spacing: .04em; text-transform: uppercase; color: #666; border-bottom: 1px solid #ddd; }
	table.items th.num, table.items td.num { text-align: right; }
	table.items td { padding: 10px; border-bottom: 1px solid #eee; }
	table.totals { width: 280px; margin-left: auto; margin-top: 16px; }
	table.totals td { padding: 5px 0; }
	table.totals td.label { color: #555; }
	table.totals td.val { text-align: right; }
	table.totals tr.grand td { border-top: 2px solid #1a1a1a; font-size: 14px; font-weight: bold; padding-top: 10px; }
	.footer { margin-top: 40px; color: #999; font-size: 10px; text-align: center; }
	.watermark-paid { position: absolute; top: 360px; left: 160px; transform: rotate(-24deg); font-size: 72px; font-weight: bold; color: #16a34a; border: 5px solid #16a34a; padding: 6px 32px; opacity: 0.16; text-transform: uppercase; letter-spacing: 6px; border-radius: 8px; z-index: 999; }
</style>
</head>
<body>
<div class="wrap">

	<?php if ( ! empty( $show_paid_watermark ) ) : ?>
		<div class="watermark-paid"><?php esc_html_e( 'PAID', 'billio' ); ?></div>
	<?php endif; ?>

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
				<div class="invoice-title"><?php esc_html_e( 'INVOICE', 'billio' ); ?></div>
				<div class="invoice-meta">
					<strong><?php esc_html_e( 'Invoice #:', 'billio' ); ?></strong> <?php echo esc_html( $invoice_number ); ?><br>
					<strong><?php esc_html_e( 'Date:', 'billio' ); ?></strong> <?php echo esc_html( $invoice_date ); ?><br>
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

	<?php require TemplateEngine::partial_path( 'invoice-lines' ); ?>

	<div class="footer">
		<?php esc_html_e( 'Thank you for your business.', 'billio' ); ?>
	</div>

</div>
</body>
</html>
