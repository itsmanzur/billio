<?php
/**
 * Uninstall cleanup.
 *
 * Deliberately only removes Billio's own settings options — not the
 * generated invoice PDFs in uploads/billio, not the _billio_invoice_* order
 * meta, and not the invoice sequence counter. Those are the store's
 * financial records (often legally required to be retained, and the
 * counter must never go backwards or a reinstall will reissue a number
 * that's already on a real invoice) and must survive even if the plugin
 * itself is removed.
 *
 * @package Billio
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$billio_options = array(
	'billio_company_name',
	'billio_company_logo_id',
	'billio_company_address',
	'billio_company_vat',
	'billio_number_prefix',
	'billio_credit_note_prefix',
	'billio_number_format',
	'billio_number_padding',
	'billio_number_reset_yearly',
	'billio_paper_size',
	'billio_email_triggers',
	'billio_template',
	'billio_accent_color',
	'billio_version',
	'billio_review_notice_dismissed',
);

foreach ( $billio_options as $billio_option ) {
	delete_option( $billio_option );
}
