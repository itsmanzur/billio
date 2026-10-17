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
	'billio_watermark_paid',
	'billio_show_thankyou_download',
	'billio_enable_tax_id',
	'billio_tax_id_label',
	'billio_tax_id_required',
	'billio_customer_link_validity_days',
	'billio_customer_ondemand_generate',
	'billio_version',
	'billio_review_notice_dismissed',
);

foreach ( $billio_options as $billio_option ) {
	delete_option( $billio_option );
}

// Keep transient names in sync with Dashboard.php cache keys, BUST_LOCK_TRANSIENT, and Installer.
$billio_transients = array(
	'billio_dash_month_stats',
	'billio_dash_last_month_stats',
	'billio_dash_chart_7',
	'billio_dash_chart_30',
	'billio_dash_chart_90',
	'billio_dash_recent_documents',
	'billio_dash_missing_order_ids',
	'billio_dash_bust_lock',
	'billio_activation_redirect',
);

foreach ( $billio_transients as $billio_transient ) {
	delete_transient( $billio_transient );
}

// Clear any remaining deferred cron hook (Dashboard::DEFERRED_BUST_HOOK).
wp_clear_scheduled_hook( 'billio_dashboard_deferred_bust' );

// Leftover per-order lock rows (Billio\Invoice\OrderLock::PREFIX) — short-
// lived mutexes, not records; only left behind if a request died mid-lock.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup of rows only Billio writes.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'billio_lock_order_' ) . '%' ) );

// Delete per-user dismiss flags.
delete_metadata( 'user', 0, 'billio_dashboard_setup_dismissed', '', true );
