<?php
/**
 * Billio's admin dashboard — interactive header, setup-progress banner, KPI
 * cards, an inline review bar, an invoice-activity chart with quick settings
 * alongside it, and a searchable recent-documents table.
 *
 * @package Billio
 */

namespace Billio\Admin;

use Billio\Frontend\MyAccount;
use Billio\Invoice\NumberManager;
use Billio\Settings\Fields;
use Billio\Settings\SettingsPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dashboard {

	const MENU_SLUG    = 'billio';
	const SCREEN_ID    = 'toplevel_page_billio';
	const NONCE_ACTION = 'billio_dashboard';

	/**
	 * How far back the "orders without invoice" KPI/modal looks. Without a
	 * cutoff, a store migrating onto Billio with years of history would be
	 * offered a one-click "backfill thousands of historical orders" button.
	 */
	const ACTION_NEEDED_CUTOFF_DAYS = 90;

	/**
	 * Stat queries below use `limit => -1` (every matching order, no cap) so
	 * counts/sums are exact — fine on most stores, but expensive on ones with
	 * large monthly volume if it ran on every page view. A short cache keeps
	 * the common case cheap; a few minutes of staleness is an easy trade,
	 * and the two billio_*_generated hooks bust it immediately on real
	 * activity anyway.
	 */
	const STATS_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Per-user meta flag: this admin dismissed the setup banner once all
	 * steps were already complete (or dismissed it manually).
	 */
	const OPTION_SETUP_DISMISSED = 'billio_dashboard_setup_dismissed';

	/**
	 * Hard ceiling on how many candidate IDs a single search sub-query can
	 * return, per document type — protects a very broad search term (or
	 * "browse all", $q === '') from resolving an unbounded number of rows
	 * on a very large store. Pagination always runs over the full
	 * (possibly capped-at-this-limit) ID list so the reported total/last
	 * page are correct for anything under the cap; past it, the response
	 * is flagged `truncated` and the UI asks for a narrower search.
	 */
	const SEARCH_HARD_LIMIT = 5000;

	/**
	 * Short-lived lock debouncing bust_stats_cache() — see that method.
	 */
	const BUST_LOCK_TRANSIENT   = 'billio_dash_bust_lock';
	const BUST_DEBOUNCE_SECONDS = 30;

	/**
	 * Cron hook for the deferred bust bust_stats_cache() schedules when it's
	 * debounced — see that method.
	 */
	const DEFERRED_BUST_HOOK = 'billio_dashboard_deferred_bust';

	/**
	 * Recent-documents cache — rows carry no per-admin URLs (see
	 * add_document_urls()), only plain ids/metadata; URLs are built fresh
	 * on every request from whichever admin is viewing.
	 */
	const RECENT_DOCS_TRANSIENT = 'billio_dash_recent_documents';

	/**
	 * @var string Hook suffix for this page, as returned by add_menu_page().
	 */
	private $hook_suffix = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		// Which statuses count as "missing an invoice" follows the email
		// triggers (see get_missing_order_ids()) — recount when they change.
		add_action(
			'update_option_billio_email_triggers',
			static function () {
				delete_transient( 'billio_dash_missing_order_ids' );
			}
		);
	}

	/**
	 * Request (not necessarily perform) a cache bust, debounced: if another
	 * bust already happened within the last BUST_DEBOUNCE_SECONDS, this call
	 * is skipped, but a single deferred bust is scheduled for exactly when
	 * that debounce window ends — so a burst of invoices generated in quick
	 * succession (e.g. a batch "Generate missing" run) doesn't re-run the
	 * stats queries on every single one, while the cache is still
	 * guaranteed to be no more than BUST_DEBOUNCE_SECONDS stale.
	 *
	 * Static, and hooked unconditionally in Plugin.php (not gated behind
	 * is_admin()) — invoices are very often generated from a non-admin
	 * request (a payment gateway webhook completing an order, firing the
	 * customer email that attaches the invoice), where this class is never
	 * instantiated. A hook registered only inside this constructor would
	 * silently never fire for that — the most common real-world path.
	 *
	 * Note: the deferred bust runs via WP-Cron, which only fires on an
	 * incoming request after its scheduled time (not a true timer) — on a
	 * very low-traffic site it may land at the next visit after the 30s
	 * mark rather than exactly at it. That's a standard WP-Cron
	 * characteristic, not something this debounce can avoid.
	 *
	 * @return void
	 */
	public static function bust_stats_cache() {
		if ( false !== get_transient( self::BUST_LOCK_TRANSIENT ) ) {
			if ( ! wp_next_scheduled( self::DEFERRED_BUST_HOOK ) ) {
				wp_schedule_single_event( time() + self::BUST_DEBOUNCE_SECONDS, self::DEFERRED_BUST_HOOK );
			}
			return;
		}

		self::do_bust_stats_cache();
		set_transient( self::BUST_LOCK_TRANSIENT, 1, self::BUST_DEBOUNCE_SECONDS );
	}

	/**
	 * The deferred half of bust_stats_cache()'s debounce — runs the actual
	 * bust once the debounce window from a skipped request ends. Hooked to
	 * DEFERRED_BUST_HOOK unconditionally in Plugin.php, same reasoning as
	 * bust_stats_cache() itself.
	 *
	 * @return void
	 */
	public static function deferred_bust_stats_cache() {
		self::do_bust_stats_cache();
		set_transient( self::BUST_LOCK_TRANSIENT, 1, self::BUST_DEBOUNCE_SECONDS );
	}

	/**
	 * Delete every cached stat/chart/recent-documents transient this class
	 * maintains. The actual work behind bust_stats_cache()'s debounce.
	 *
	 * @return void
	 */
	private static function do_bust_stats_cache() {
		delete_transient( 'billio_dash_month_stats' );
		delete_transient( 'billio_dash_last_month_stats' );
		delete_transient( self::RECENT_DOCS_TRANSIENT );
		delete_transient( 'billio_dash_missing_order_ids' );
		foreach ( array( 7, 30, 90 ) as $days ) {
			delete_transient( 'billio_dash_chart_' . $days );
		}
	}

	/**
	 * Register the top-level "Billio" menu (this page) and rename WordPress's
	 * auto-added first submenu entry from "Billio" to "Dashboard".
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = add_menu_page(
			esc_html__( 'Billio', 'billio' ),
			esc_html__( 'Billio', 'billio' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-media-document',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Dashboard', 'billio' ),
			esc_html__( 'Dashboard', 'billio' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue dashboard-only assets and localize everything needed for
	 * first paint (no empty-chart/empty-table flash, and no extra AJAX
	 * round-trip just to render what PHP already knows).
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'billio-admin-base', BILLIO_URL . 'assets/css/admin-base.css', array(), BILLIO_VERSION );
		wp_enqueue_style( 'billio-admin-dashboard', BILLIO_URL . 'assets/css/admin-dashboard.css', array( 'billio-admin-base' ), BILLIO_VERSION );
		wp_enqueue_script( 'billio-admin-dashboard', BILLIO_URL . 'assets/js/admin-dashboard.js', array(), BILLIO_VERSION, true );

		wp_localize_script( 'billio-admin-dashboard', 'billioDashboard', $this->localized_data() );
	}

	/**
	 * Build the full localized data object consumed by admin-dashboard.js.
	 *
	 * @return array
	 */
	private function localized_data() {
		$month_stats      = $this->get_month_stats();
		$last_month_stats = $this->get_last_month_stats();
		$missing_ids      = $this->get_missing_order_ids();
		$recent           = $this->get_recent_documents();
		$setup            = $this->get_setup_state();

		return array(
			'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
			'nonce'             => wp_create_nonce( self::NONCE_ACTION ),
			// Resend/Regenerate in the recent-documents table call
			// OrderActions' existing AJAX endpoints, so they need its nonce
			// too (same action name for every order — not order-specific).
			'orderActionNonce'  => wp_create_nonce( OrderActions::NONCE_ACTION ),
			'urls'              => array(
				'settings'             => admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ),
				'docs'                 => admin_url( 'admin.php?page=' . DocsPage::MENU_SLUG ),
				'preview'              => admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) . '#billio-preview-frame',
				'sampleInvoicePreview' => wp_nonce_url( admin_url( 'admin-post.php?action=billio_preview&preview=1&doc=inv' ), 'billio_preview' ),
				'ordersList'           => admin_url( 'admin.php?page=wc-orders' ),
				'editFormatField'      => admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG . '#billio_number_format' ),
			),
			'currency'          => array(
				'symbol'      => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'position'    => get_option( 'woocommerce_currency_pos', 'left' ),
				'thousandSep' => wc_get_price_thousand_separator(),
				'decimalSep'  => wc_get_price_decimal_separator(),
				'decimals'    => wc_get_price_decimals(),
			),
			'kpis'              => array(
				'invoicesThisMonth'        => $month_stats['count'],
				'invoicesLastMonth'        => $last_month_stats['count'],
				'revenueThisMonth'         => $month_stats['revenue'],
				'revenueLastMonth'         => $last_month_stats['revenue'],
				'creditNotesThisMonth'     => $month_stats['credit_notes'],
				'creditNotesRefundedTotal' => $month_stats['refunded'],
				'ordersWithoutInvoice'     => count( $missing_ids ),
			),
			'chart'             => array(
				'range' => 30,
				'days'  => $this->get_chart_data( 30 ),
			),
			'nextInvoiceNumber' => NumberManager::peek_next_number( 'invoice' ),
			'quickSettings'     => array(
				'emailTriggers' => Fields::email_triggers(),
				'template'      => get_option( 'billio_template', 'default' ),
				'paperSize'     => get_option( 'billio_paper_size', 'A4' ),
				'enableTaxId'   => (bool) get_option( 'billio_enable_tax_id', false ),
				'watermarkPaid' => (bool) get_option( 'billio_watermark_paid', false ),
			),
			'recentDocuments'   => $recent,
			'setup'             => $setup,
			'reviewNotice'      => $this->get_review_notice_state(),
			'allTimeCount'      => (int) get_option( NumberManager::OPTION_TOTAL_COUNT, 0 ),
			'i18n'              => $this->i18n_strings(),
		);
	}

	/**
	 * All translatable strings the JS needs — kept in one place so every
	 * dynamic/toast/modal string in admin-dashboard.js is translatable.
	 *
	 * @return array
	 */
	private function i18n_strings() {
		return array(
			'error'                    => __( 'Something went wrong. Please try again.', 'billio' ),
			'allInvoiced'              => __( 'All orders invoiced', 'billio' ),
			/* translators: %d: number of orders missing an invoice. */
			'generateMissing'          => __( 'Generate missing (%d)', 'billio' ),
			'allCaughtUp'              => __( 'All caught up', 'billio' ),
			'generateThemNow'          => __( 'Generate them now →', 'billio' ),
			'downloadingPdf'           => __( 'Downloading…', 'billio' ),
			'invoiceEmailed'           => __( 'Invoice emailed to customer', 'billio' ),
			'confirmRegenerateTitle'   => __( 'Regenerate this invoice?', 'billio' ),
			'confirmRegenerateBody'    => __( 'The PDF will be rebuilt with your current settings and template. The invoice number stays the same.', 'billio' ),
			'cancel'                   => __( 'Cancel', 'billio' ),
			'regenerate'               => __( 'Regenerate', 'billio' ),
			/* translators: %s: document number. */
			'regenerated'              => __( '%s regenerated', 'billio' ),
			'generateModalTitle'       => __( 'Generate missing invoices', 'billio' ),
			'generateModalNote'        => __( 'Orders will be processed oldest-first so numbering stays chronological.', 'billio' ),
			'start'                    => __( 'Start', 'billio' ),
			'ready'                    => __( 'Ready', 'billio' ),
			/* translators: 1: number done so far, 2: total number, 3: latest invoice number assigned. */
			'progressOf'               => __( '%1$d of %2$d · %3$s', 'billio' ),
			/* translators: %d: number of invoices generated. */
			'generatedCount'           => __( '%d invoices generated', 'billio' ),
			'noDocumentsYet'           => __( 'No invoices generated yet — they appear here as soon as an order is invoiced.', 'billio' ),
			/* translators: %s: the search term that matched nothing. */
			'noSearchMatch'            => __( 'No documents match "%s"', 'billio' ),
			/* translators: 1: number of documents shown, 2: total number of documents. */
			'showingOf'                => __( 'Showing %1$d of %2$d', 'billio' ),
			'viewAllInOrders'          => __( 'View all in Orders →', 'billio' ),
			'paperSizeSet'             => __( 'Paper size updated', 'billio' ),
			'templateSet'              => __( 'Invoice template updated', 'billio' ),
			'emailTriggerOn'           => __( 'Invoice will be attached to this email', 'billio' ),
			'emailTriggerOff'          => __( 'Invoice will no longer be attached to this email', 'billio' ),
			'settingsSaveFailed'       => __( 'Could not save — please try again.', 'billio' ),
			'setupComplete'            => __( '🎉 You\'re all set — every invoice is fully branded', 'billio' ),
			'today'                    => __( 'Today', 'billio' ),
			'noRefundsThisMonth'       => __( 'No refunds this month', 'billio' ),
			'noInvoicesYet'            => __( 'No invoices yet', 'billio' ),
			'noCreditNotesYet'         => __( 'No credit notes yet', 'billio' ),
			'searchResultsLimited'     => __( 'Results limited — search for something more specific.', 'billio' ),
			'previewDocument'          => __( 'Preview Document', 'billio' ),
			'closePreview'             => __( 'Close preview (Esc)', 'billio' ),
			'printInvoice'             => __( 'Print', 'billio' ),
			'copyLink'                 => __( 'Copy Link', 'billio' ),
			'linkCopied'               => __( 'Link copied to clipboard!', 'billio' ),
			'sendWhatsApp'             => __( 'WhatsApp', 'billio' ),
			'orderDetails'             => __( 'Order Details', 'billio' ),
			'paymentMethod'            => __( 'Payment Method', 'billio' ),
			'billingAddress'           => __( 'Billing Address', 'billio' ),
			'items'                    => __( 'Items', 'billio' ),
			'email'                    => __( 'Email', 'billio' ),
			'phone'                    => __( 'Phone', 'billio' ),
			'openOrder'                => __( 'Open in WooCommerce →', 'billio' ),
			'loadingPreview'           => __( 'Rendering live PDF preview…', 'billio' ),
			'smartChecklist'           => __( 'Smart Setup Checklist', 'billio' ),
			'quickActions'             => __( 'Quick Actions', 'billio' ),
			'instantInvoicePreview'    => __( 'Live Invoice Preview', 'billio' ),
			'b2bTaxIdToggle'           => __( 'Customer Tax ID at checkout', 'billio' ),
			'b2bTaxIdEnabled'          => __( 'Customer Tax ID enabled at checkout', 'billio' ),
			'b2bTaxIdDisabled'         => __( 'Customer Tax ID disabled at checkout', 'billio' ),
			'watermarkPaidToggle'      => __( 'PAID stamp watermark', 'billio' ),
			'watermarkPaidOn'          => __( 'PAID stamp enabled on paid invoices', 'billio' ),
			'watermarkPaidOff'         => __( 'PAID stamp disabled', 'billio' ),
			'allStepsCompleted'        => __( '🎉 All setup steps completed! Your store is fully branded.', 'billio' ),
			/* translators: 1: number of steps completed, 2: total number of steps. */
			'setupProgress'            => __( '%1$d of %2$d complete', 'billio' ),
			'showChecklist'            => __( 'Show checklist', 'billio' ),
			'hideChecklist'            => __( 'Hide checklist', 'billio' ),
			'sampleInvoice'            => __( 'Sample Invoice Preview', 'billio' ),
			'recentDocuments'          => __( 'Recent documents', 'billio' ),
			'filterAll'                => __( 'All', 'billio' ),
			'filterInvoices'           => __( 'Invoices', 'billio' ),
			'filterCreditNotes'        => __( 'Credit notes', 'billio' ),
			'searchPlaceholder'        => __( 'Search by number, order or customer…', 'billio' ),
			'colDocument'              => __( 'Document', 'billio' ),
			'colOrder'                 => __( 'Order', 'billio' ),
			'colCustomer'              => __( 'Customer', 'billio' ),
			'colOrderStatus'           => __( 'Order status', 'billio' ),
			'colDate'                  => __( 'Date', 'billio' ),
			'colTotal'                 => __( 'Total', 'billio' ),
			'colActions'               => __( 'Actions', 'billio' ),
			'invoicesThisMonth'        => __( 'Invoices this month', 'billio' ),
			'revenueInvoicedThisMonth' => __( 'Revenue invoiced this month', 'billio' ),
			'creditNotesThisMonth'     => __( 'Credit notes this month', 'billio' ),
			'ordersWithoutInvoice'     => __( 'Orders without invoice', 'billio' ),
			'setupBannerDesc'          => __( 'A few quick steps make every invoice fully branded, sequential, and tax compliant.', 'billio' ),
			/* translators: %d: number of invoices created. */
			'reviewEnjoying'           => __( 'Billio has created %d invoices for you. Enjoying it?', 'billio' ),
			'leaveReview'              => __( 'Leave a review', 'billio' ),
			'notNow'                   => __( 'Not now', 'billio' ),
			'metricInvoices'           => __( 'Invoices', 'billio' ),
			'metricRevenue'            => __( 'Revenue', 'billio' ),
			'chartTotalInRange'        => __( 'Total in range', 'billio' ),
			'chartDailyAverage'        => __( 'Daily average', 'billio' ),
			'chartBestDay'             => __( 'Best day', 'billio' ),
			'chartActivityByDay'       => __( 'Invoice activity, by day', 'billio' ),
			'invoiceSetupAtGlance'     => __( 'Invoice setup at a glance', 'billio' ),
			'nextInvoiceNumber'        => __( 'Next invoice number', 'billio' ),
			'editFormat'               => __( 'Edit format', 'billio' ),
			'attachToEmail'            => __( 'Attach to email', 'billio' ),
			'processingOrder'          => __( 'Processing order', 'billio' ),
			'completedOrder'           => __( 'Completed order', 'billio' ),
			'b2bTax'                   => __( 'B2B / Tax', 'billio' ),
			'watermark'                => __( 'Watermark', 'billio' ),
			'paperSize'                => __( 'Paper size', 'billio' ),
			'invoiceTemplate'          => __( 'Invoice template', 'billio' ),
			'templateDefault'          => __( 'Default', 'billio' ),
			'templateModern'           => __( 'Modern', 'billio' ),
			'downloadPdf'              => __( 'Download PDF', 'billio' ),
			'download'                 => __( 'Download', 'billio' ),
			'resendEmail'              => __( 'Resend email', 'billio' ),
			'resend'                   => __( 'Resend', 'billio' ),
			'customer'                 => __( 'Customer', 'billio' ),
			'total'                    => __( 'Total', 'billio' ),
			'previousPage'             => __( 'Previous page', 'billio' ),
			'nextPage'                 => __( 'Next page', 'billio' ),
			'generateMissingBtn'       => __( 'Generate Missing', 'billio' ),
			'settings'                 => __( 'Settings', 'billio' ),
			'docsAndGuide'             => __( 'Docs & Guide', 'billio' ),
			'tagline'                  => __( 'Invoices, credit notes & packing slips for your WooCommerce store', 'billio' ),
			'invoiceActivity'          => __( 'Invoice activity', 'billio' ),
			'hide'                     => __( 'Hide', 'billio' ),
			/* translators: %s: formatted refund amount. */
			'refundedThisMonth'        => __( '%s refunded this month', 'billio' ),
			/* translators: %d: percentage complete. */
			'percentComplete'          => __( '(%d%% complete)', 'billio' ),
			/* translators: %d: number of invoices (always 1). */
			'invoiceCountOne'          => __( '%d invoice', 'billio' ),
			/* translators: %d: number of invoices. */
			'invoiceCountMany'         => __( '%d invoices', 'billio' ),
			/* translators: %d: number of credit notes (always 1). */
			'creditNoteCountOne'       => __( '%d credit note', 'billio' ),
			/* translators: %d: number of credit notes. */
			'creditNoteCountMany'      => __( '%d credit notes', 'billio' ),
		);
	}

	/**
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public function render() {
		?>
		<div class="wrap billio-ui billio-dash">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Billio Dashboard', 'billio' ); ?></h1>
			<div id="billio-dash-root" data-version="<?php echo esc_attr( BILLIO_VERSION ); ?>"></div>
		</div>
		<?php
	}

	/**
	 * Build the setup-progress state: which steps are done, whether the
	 * admin has dismissed the banner, and which is the first incomplete
	 * step (the one the white CTA pill should point at).
	 *
	 * @return array
	 */
	private function get_setup_state() {
		$steps = array(
			array(
				'key'    => 'company',
				'label'  => __( 'Company details & logo', 'billio' ),
				'done'   => (bool) get_option( 'billio_company_logo_id' ) && (bool) get_option( 'billio_company_address' ),
				'anchor' => '#billio-sec-company',
			),
			array(
				'key'    => 'numbering',
				'label'  => __( 'Invoice numbering format', 'billio' ),
				'done'   => false !== strpos( (string) get_option( 'billio_number_format', '{PREFIX}-{YEAR}-{NUMBER}' ), '{NUMBER}' ),
				'anchor' => '#billio-sec-numbering',
			),
			array(
				'key'    => 'email',
				'label'  => __( 'Email attachment triggers', 'billio' ),
				'done'   => ! empty( Fields::email_triggers() ),
				'anchor' => '#billio-sec-delivery',
			),
			array(
				'key'    => 'tax_id',
				'label'  => __( 'B2B Customer Tax ID', 'billio' ),
				'done'   => (bool) get_option( 'billio_enable_tax_id', false ),
				'anchor' => '#billio-sec-tax-id',
			),
			array(
				'key'    => 'first_invoice',
				'label'  => __( 'First invoice generated', 'billio' ),
				'done'   => (int) get_option( NumberManager::OPTION_TOTAL_COUNT, 0 ) > 0,
				'anchor' => '',
			),
		);

		$done_count = 0;
		foreach ( $steps as $step ) {
			if ( $step['done'] ) {
				++$done_count;
			}
		}

		return array(
			'steps'     => $steps,
			'done'      => $done_count,
			'total'     => count( $steps ),
			'allDone'   => count( $steps ) === $done_count,
			'dismissed' => (bool) get_user_meta( get_current_user_id(), self::OPTION_SETUP_DISMISSED, true ),
		);
	}

	/**
	 * Mirror ReviewNotice's own threshold/dismiss logic so the dashboard's
	 * inline bar and the global admin notice never disagree — reuses its
	 * public constants rather than redefining the threshold here.
	 *
	 * @return array
	 */
	private function get_review_notice_state() {
		$total = (int) get_option( NumberManager::OPTION_TOTAL_COUNT, 0 );

		return array(
			'show'  => ! get_option( ReviewNotice::OPTION_DISMISS ) && $total >= ReviewNotice::THRESHOLD,
			'total' => $total,
			'url'   => 'https://wordpress.org/support/plugin/billio/reviews/#new-post',
			'nonce' => wp_create_nonce( ReviewNotice::NONCE_ACTION ),
		);
	}

	/**
	 * "Now", in the site's configured timezone (Settings → General) rather
	 * than the server's PHP default timezone.
	 *
	 * @return \DateTimeImmutable
	 */
	private function site_now() {
		return new \DateTimeImmutable( 'now', wp_timezone() );
	}

	/**
	 * Count + revenue + credit-note stats for the current calendar month so
	 * far, in the site timezone. Cached — see STATS_CACHE_TTL.
	 *
	 * @return array{count:int,revenue:float,credit_notes:int,refunded:float}
	 */
	private function get_month_stats() {
		$cache_key = 'billio_dash_month_stats';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$month_start = $this->site_now()->modify( 'first day of this month midnight' )->getTimestamp();
		$now         = time();

		$orders  = $this->orders_issued_between( $month_start, $now );
		$refunds = $this->credit_notes_issued_between( $month_start, $now );

		$revenue = 0.0;
		foreach ( $orders as $order ) {
			if ( $this->counts_toward_revenue( $order ) ) {
				$revenue += (float) $order->get_total();
			}
		}

		$refunded = 0.0;
		foreach ( $refunds as $refund ) {
			$refunded += abs( (float) $refund->get_amount() );
		}

		$result = array(
			'count'        => count( $orders ),
			'revenue'      => $revenue,
			'credit_notes' => count( $refunds ),
			'refunded'     => $refunded,
		);

		set_transient( $cache_key, $result, self::STATS_CACHE_TTL );

		return $result;
	}

	/**
	 * Same shape as get_month_stats(), but for the *same elapsed portion* of
	 * last month (e.g. the 1st–7th, if today is the 7th) — comparing against
	 * the full previous month would unfairly show a ▼ trend for most of
	 * every month. Cached — see STATS_CACHE_TTL.
	 *
	 * @return array{count:int,revenue:float}
	 */
	private function get_last_month_stats() {
		$cache_key = 'billio_dash_last_month_stats';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$now             = $this->site_now();
		$last_month_from = $now->modify( 'first day of last month midnight' );
		$day_of_month    = (int) $now->format( 'j' );
		$last_month_days = (int) $last_month_from->format( 't' );
		$end_day         = min( $day_of_month, $last_month_days );
		$last_month_to   = $last_month_from->modify( '+' . ( $end_day - 1 ) . ' days' )->setTime( 23, 59, 59 );

		$orders = $this->orders_issued_between( $last_month_from->getTimestamp(), $last_month_to->getTimestamp() );

		$revenue = 0.0;
		foreach ( $orders as $order ) {
			if ( $this->counts_toward_revenue( $order ) ) {
				$revenue += (float) $order->get_total();
			}
		}

		$result = array(
			'count'   => count( $orders ),
			'revenue' => $revenue,
		);

		set_transient( $cache_key, $result, self::STATS_CACHE_TTL );

		return $result;
	}

	/**
	 * Whether an invoiced order's total counts toward the revenue figures.
	 *
	 * The admin's manual "Generate" button has no status restriction (an
	 * admin can invoice a pending/failed/on-hold order for record-keeping),
	 * so having an invoice at all doesn't mean the order represents money
	 * actually collected — only WooCommerce's own "paid" statuses do.
	 * Refunded is deliberately excluded even though wc_get_is_paid_statuses()
	 * includes it by default: money that was given back isn't money kept.
	 * Orders in a different currency than the store's (multi-currency
	 * plugins) are left out too: adding e.g. USD and BDT totals into one
	 * number would be meaningless, and there's no exchange rate to convert
	 * with. Excluded orders still count toward invoice *counts*, which just
	 * track how many invoices were generated.
	 *
	 * @param DashboardOrderRecord|\WC_Order $order Invoiced order.
	 * @return bool
	 */
	private function counts_toward_revenue( $order ) {
		$paid_statuses = array_diff( wc_get_is_paid_statuses(), array( 'refunded' ) );

		if ( ! in_array( $order->get_status(), $paid_statuses, true ) ) {
			return false;
		}

		$currency = (string) $order->get_currency();

		return '' === $currency || get_woocommerce_currency() === $currency;
	}

	/**
	 * Lightweight stat records for invoiced orders that carry
	 * _billio_invoice_date — read in bulk (OrderStatsQuery), never by
	 * hydrating a full \WC_Order per row.
	 *
	 * @param int[] $order_ids Order IDs (all with _billio_invoice_date).
	 * @return DashboardOrderRecord[]
	 */
	private function ids_to_stat_orders( array $order_ids ) {
		$fields  = OrderStatsQuery::order_fields( $order_ids );
		$dates   = OrderStatsQuery::meta_map( array_keys( $fields ), '_billio_invoice_date' );
		$results = array();

		foreach ( $fields as $id => $row ) {
			$results[] = new DashboardOrderRecord(
				$row['status'],
				$row['total'],
				isset( $dates[ $id ] ) ? (int) $dates[ $id ] : 0,
				$row['currency']
			);
		}

		return $results;
	}

	/**
	 * Lightweight stat records for credit-noted refunds that carry
	 * _billio_credit_note_date — bulk reads, same as ids_to_stat_orders().
	 *
	 * @param int[] $refund_ids Refund IDs (all with _billio_credit_note_date).
	 * @return DashboardRefundRecord[]
	 */
	private function ids_to_stat_refunds( array $refund_ids ) {
		$amounts = OrderStatsQuery::meta_map( $refund_ids, '_refund_amount' );
		$dates   = OrderStatsQuery::meta_map( $refund_ids, '_billio_credit_note_date' );
		$results = array();

		foreach ( $dates as $id => $date ) {
			$results[] = new DashboardRefundRecord(
				isset( $amounts[ $id ] ) ? (float) $amounts[ $id ] : 0.0,
				(int) $date
			);
		}

		return $results;
	}

	/**
	 * Every invoiced order whose *resolved issue date* falls within a
	 * timestamp window — a single bulk query; _billio_invoice_date is
	 * always set at number-assignment time (see
	 * Generator::assign_number_locked()), so every invoiced order has one.
	 *
	 * Public so this can be exercised directly (see tests/integration.php)
	 * to confirm its query never mixes in a WC_Order_Refund.
	 *
	 * @param int $from Window start (unix timestamp).
	 * @param int $to   Window end (unix timestamp).
	 * @return DashboardOrderRecord[]
	 */
	public function orders_issued_between( $from, $to ) {
		$order_ids = wc_get_orders(
			array(
				'type'       => 'shop_order', // Without this, legacy (posts) storage's default type list also includes shop_order_refund.
				'limit'      => -1,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_billio_invoice_date',
						'value'   => array( $from, $to ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return $this->ids_to_stat_orders( $order_ids );
	}

	/**
	 * Every credit-noted refund whose *resolved issue date* falls within a
	 * timestamp window — same shape as orders_issued_between().
	 *
	 * @param int $from Window start (unix timestamp).
	 * @param int $to   Window end (unix timestamp).
	 * @return DashboardRefundRecord[]
	 */
	private function credit_notes_issued_between( $from, $to ) {
		$refund_ids = wc_get_orders(
			array(
				'type'       => 'shop_order_refund',
				'limit'      => -1,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_billio_credit_note_date',
						'value'   => array( $from, $to ),
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return $this->ids_to_stat_refunds( $refund_ids );
	}

	/**
	 * The date an invoice was actually issued — a plain meta read.
	 * _billio_invoice_date is set once, at the moment a number is first
	 * assigned (see Generator::assign_number_locked()), so this is the
	 * correct source of truth; 0 if it's ever missing or not a valid
	 * positive integer, never a notice or fatal. Callers that bucket by
	 * date (e.g. get_chart_data()) simply skip a 0 timestamp.
	 *
	 * Public so this can be unit-tested directly (see tests/run.php).
	 *
	 * @param \WC_Order|DashboardOrderRecord $order Invoiced order.
	 * @return int Unix timestamp.
	 */
	public static function invoice_issue_timestamp( $order ) {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return 0;
		}

		return max( 0, (int) $order->get_meta( '_billio_invoice_date' ) );
	}

	/**
	 * The date a credit note was actually issued — same reasoning as
	 * invoice_issue_timestamp().
	 *
	 * Public so this can be unit-tested directly (see tests/run.php).
	 *
	 * @param \WC_Order_Refund|DashboardRefundRecord $refund Refund with a credit note.
	 * @return int Unix timestamp.
	 */
	public static function credit_note_issue_timestamp( $refund ) {
		if ( ! method_exists( $refund, 'get_meta' ) ) {
			return 0;
		}

		return max( 0, (int) $refund->get_meta( '_billio_credit_note_date' ) );
	}

	/**
	 * Daily {date, invoices, revenue, credit_notes} buckets for the trailing
	 * N days (inclusive of today), in the site timezone. Cached per range —
	 * see STATS_CACHE_TTL — and busted by billio_invoice_generated /
	 * billio_credit_note_generated.
	 *
	 * @param int $days Window size in days (7, 30, or 90).
	 * @return array[]
	 */
	public function get_chart_data( $days ) {
		$cache_key = 'billio_dash_chart_' . $days;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$start = $this->day_window_start( $days );
		$now   = time();

		$orders  = $this->orders_issued_between( $start, $now );
		$refunds = $this->credit_notes_issued_between( $start, $now );

		// Buckets are keyed by the site-local calendar date, stepping one
		// calendar day at a time — not "$start + $i * DAY_IN_SECONDS", which
		// drifts by an hour across a DST change and files invoices issued
		// near midnight under the wrong day.
		$buckets = array();
		$day     = ( new \DateTimeImmutable( '@' . $start ) )->setTimezone( wp_timezone() );
		for ( $i = 0; $i < $days; $i++ ) {
			$key             = $day->format( 'Y-m-d' );
			$buckets[ $key ] = array(
				'date'        => $key,
				'invoices'    => 0,
				'revenue'     => 0.0,
				'creditNotes' => 0,
			);
			$day             = $day->modify( '+1 day' );
		}

		foreach ( $orders as $order ) {
			// A 0 timestamp (meta missing/invalid — see invoice_issue_timestamp())
			// resolves to a date outside this window, so it never matches a
			// bucket key below; no separate check needed to exclude it.
			$key = wp_date( 'Y-m-d', self::invoice_issue_timestamp( $order ) );
			if ( ! isset( $buckets[ $key ] ) ) {
				continue;
			}
			++$buckets[ $key ]['invoices'];
			if ( $this->counts_toward_revenue( $order ) ) {
				$buckets[ $key ]['revenue'] += (float) $order->get_total();
			}
		}

		foreach ( $refunds as $refund ) {
			$key = wp_date( 'Y-m-d', self::credit_note_issue_timestamp( $refund ) );
			if ( isset( $buckets[ $key ] ) ) {
				++$buckets[ $key ]['creditNotes'];
			}
		}

		$result = array_values( $buckets );

		set_transient( $cache_key, $result, self::STATS_CACHE_TTL );

		return $result;
	}

	/**
	 * Site-timezone-correct start timestamp for a trailing N-day window.
	 *
	 * @param int $days Window size in days.
	 * @return int Unix timestamp.
	 */
	private function day_window_start( $days ) {
		return $this->site_now()->modify( 'today -' . ( (int) $days - 1 ) . ' days' )->getTimestamp();
	}

	/**
	 * IDs of completed orders with no invoice yet, oldest first, within the
	 * cutoff window — feeds both the KPI/header count and the "Generate
	 * missing" modal's batch list.
	 *
	 * Cached to protect performance on high-volume stores, and invalidated
	 * automatically when new invoices are generated.
	 *
	 * @return int[]
	 */
	public function get_missing_order_ids() {
		$cache_key = 'billio_dash_missing_order_ids';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$cutoff = $this->site_now()->modify( '-' . self::ACTION_NEEDED_CUTOFF_DAYS . ' days' )->getTimestamp();
		$limit  = (int) apply_filters( 'billio_dashboard_missing_orders_limit', 500 );

		// Completed orders always count; processing ones too when invoices
		// are configured to go out at processing (Settings → "Send invoice
		// when order is") — those orders are expected to have one by now.
		$statuses = array( 'completed' );
		if ( in_array( 'customer_processing_order', Fields::email_triggers(), true ) ) {
			$statuses[] = 'processing';
		}

		$ids = wc_get_orders(
			array(
				'type'         => 'shop_order', // Without this, legacy (posts) storage's default type list also includes shop_order_refund.
				'status'       => $statuses,
				'limit'        => $limit,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'return'       => 'ids',
				'date_created' => '>' . $cutoff,
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_billio_invoice_number',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		set_transient( $cache_key, $ids, self::STATS_CACHE_TTL );

		return $ids;
	}

	/**
	 * Merge the most recent invoices and credit notes into one list, sorted
	 * by issue date (newest first). Cached — see STATS_CACHE_TTL.
	 *
	 * Fetches only `$limit` of each type directly ordered by their own date
	 * meta in SQL (not "every invoiced order ever, sorted in PHP"). Parent-
	 * order lookups only happen for the credit notes that actually make the
	 * final cut, not every refund in history.
	 *
	 * @return array[]
	 */
	private function get_recent_documents() {
		return array_map( array( $this, 'add_document_urls' ), $this->get_cached_recent_documents() );
	}

	/**
	 * The cache-safe half of get_recent_documents(): rows without any
	 * per-admin URL, shared by every admin through one transient.
	 *
	 * Public so this can be exercised directly (see tests/integration.php)
	 * to confirm no row's orderId resolves to a WC_Order_Refund.
	 *
	 * @return array[]
	 */
	public function get_cached_recent_documents() {
		$cache_key = self::RECENT_DOCS_TRANSIENT;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$limit = (int) apply_filters( 'billio_dashboard_recent_documents_limit', 10 );

		$invoice_candidates = wc_get_orders(
			array(
				'type'       => 'shop_order', // Without this, legacy (posts) storage's default type list also includes shop_order_refund.
				'limit'      => $limit,
				'return'     => 'objects',
				'orderby'    => 'meta_value_num',
				'meta_key'   => '_billio_invoice_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order'      => 'DESC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_billio_invoice_date',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$refund_candidates = wc_get_orders(
			array(
				'type'       => 'shop_order_refund',
				'limit'      => $limit,
				'return'     => 'objects',
				'orderby'    => 'meta_value_num',
				'meta_key'   => '_billio_credit_note_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order'      => 'DESC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_billio_credit_note_date',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$documents = array();

		foreach ( $invoice_candidates as $order ) {
			// Defensive on top of the explicit 'type' above: format_invoice_document()
			// is type-hinted \WC_Order, so anything else here would otherwise
			// be a fatal TypeError, not just a bad row.
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$documents[] = $this->format_invoice_document( $order );
		}

		foreach ( $refund_candidates as $refund ) {
			if ( ! $refund instanceof \WC_Order_Refund ) {
				continue;
			}
			$parent_order = wc_get_order( $refund->get_parent_id() );
			if ( ! $parent_order instanceof \WC_Order ) {
				continue;
			}
			$documents[] = $this->format_credit_note_document( $refund, $parent_order );
		}

		usort(
			$documents,
			static function ( $a, $b ) {
				return $b['dateTimestamp'] - $a['dateTimestamp'];
			}
		);

		$result = array_slice( $documents, 0, $limit );

		set_transient( $cache_key, $result, self::STATS_CACHE_TTL );

		return $result;
	}

	/**
	 * Search invoices/credit notes server-side (not just the localized
	 * top-10), paginated. Backs the Dashboard table's search box.
	 *
	 * Works over ID lists plus one bulk date read per document type
	 * (OrderStatsQuery::meta_map()) — the only full \WC_Order objects ever
	 * loaded are the current page's rows, so the cost of a broad search no
	 * longer grows with how many documents match. The reported total and
	 * page count are correct for everything under SEARCH_HARD_LIMIT per
	 * sub-query; past it the response is flagged `truncated`.
	 *
	 * @param string $q        Search text (invoice/CN number, order number, or customer name).
	 * @param string $type     'all', 'inv', or 'cn'.
	 * @param int    $page     1-indexed page number.
	 * @param int    $per_page Results per page (already capped by the caller).
	 * @return array{items: array[], total: int, page: int, perPage: int, truncated: bool}
	 */
	public function search_documents( $q, $type, $page, $per_page ) {
		$page     = max( 1, (int) $page );
		$per_page = min( 20, max( 1, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$none = array(
			'refs'      => array(),
			'truncated' => false,
		);

		$invoice_result     = ( 'cn' === $type ) ? $none : $this->find_candidates( 'inv', $q );
		$credit_note_result = ( 'inv' === $type ) ? $none : $this->find_candidates( 'cn', $q );

		$refs = array_merge( $invoice_result['refs'], $credit_note_result['refs'] );
		usort( $refs, array( __CLASS__, 'compare_candidate_refs' ) );

		$items = array();
		foreach ( array_slice( $refs, $offset, $per_page ) as $ref ) {
			if ( 'inv' === $ref['type'] ) {
				$order = wc_get_order( $ref['id'] );
				if ( $order instanceof \WC_Order ) {
					$items[] = $this->format_invoice_document( $order );
				}
				continue;
			}

			$refund       = wc_get_order( $ref['id'] );
			$parent_order = $refund instanceof \WC_Order_Refund ? wc_get_order( $refund->get_parent_id() ) : false;
			if ( $parent_order instanceof \WC_Order ) {
				$items[] = $this->format_credit_note_document( $refund, $parent_order );
			}
		}

		return array(
			'items'     => array_map( array( $this, 'add_document_urls' ), $items ),
			'total'     => count( $refs ),
			'page'      => $page,
			'perPage'   => $per_page,
			'truncated' => $invoice_result['truncated'] || $credit_note_result['truncated'],
		);
	}

	/**
	 * usort() comparator: newest issue date first, then highest ID (so
	 * same-second documents keep a stable, sensible order).
	 *
	 * @param array{type: string, id: int, date: int} $a First ref.
	 * @param array{type: string, id: int, date: int} $b Second ref.
	 * @return int
	 */
	private static function compare_candidate_refs( array $a, array $b ) {
		if ( $a['date'] !== $b['date'] ) {
			return $b['date'] < $a['date'] ? -1 : 1;
		}

		return $b['id'] - $a['id'];
	}

	/**
	 * Candidate {type, id, date} refs for one document type matching a
	 * search term — every query here is ID-only and capped at
	 * SEARCH_HARD_LIMIT, scoped to documents that actually have a Billio
	 * number:
	 *
	 * - number match: LIKE on the invoice / credit note number meta;
	 * - customer match: orders matching the term (name, email, address,
	 *   order number — see customer_search_order_ids()), then those orders
	 *   themselves (invoices) or their refunds (credit notes, via
	 *   post_parent__in, supported by both HPOS and legacy storage).
	 *
	 * An empty term browses the most recent documents instead.
	 *
	 * @param string $type 'inv' or 'cn'.
	 * @param string $q    Search text.
	 * @return array{refs: array[], truncated: bool}
	 */
	private function find_candidates( $type, $q ) {
		$is_invoice = 'inv' === $type;
		$number_key = $is_invoice ? '_billio_invoice_number' : '_billio_credit_note_number';
		$date_key   = $is_invoice ? '_billio_invoice_date' : '_billio_credit_note_date';

		$base = array(
			// Explicit either way — without this, legacy (posts) storage's
			// default type list also includes shop_order_refund alongside
			// shop_order.
			'type'     => $is_invoice ? 'shop_order' : 'shop_order_refund',
			'limit'    => self::SEARCH_HARD_LIMIT,
			'return'   => 'ids',
			'orderby'  => 'meta_value_num',
			'meta_key' => $date_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'    => 'DESC',
		);

		$has_number = array(
			'key'     => $number_key,
			'compare' => 'EXISTS',
		);

		$id_lists  = array();
		$truncated = false;

		if ( '' === $q ) {
			$id_lists[] = wc_get_orders( $base + array( 'meta_query' => array( $has_number ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		} else {
			$id_lists[] = wc_get_orders(
				$base + array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						$has_number,
						array(
							'key'     => $number_key,
							'value'   => $q,
							'compare' => 'LIKE',
						),
					),
				)
			);

			$customer_order_ids = $this->customer_search_order_ids( $q );
			$truncated          = count( $customer_order_ids ) >= self::SEARCH_HARD_LIMIT;

			if ( ! empty( $customer_order_ids ) ) {
				$id_lists[] = wc_get_orders(
					$base + array(
						( $is_invoice ? 'include' : 'post_parent__in' ) => $customer_order_ids,
						'meta_query' => array( $has_number ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					)
				);
			}
		}

		$ids = array();
		foreach ( $id_lists as $list ) {
			if ( count( $list ) >= self::SEARCH_HARD_LIMIT ) {
				$truncated = true;
			}
			foreach ( $list as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		$dates = OrderStatsQuery::meta_map( array_keys( $ids ), $date_key );
		$refs  = array();
		foreach ( array_keys( $ids ) as $id ) {
			$refs[] = array(
				'type' => $type,
				'id'   => $id,
				'date' => isset( $dates[ $id ] ) ? (int) $dates[ $id ] : 0,
			);
		}

		return array(
			'refs'      => $refs,
			'truncated' => $truncated,
		);
	}

	/**
	 * Order IDs whose customer details / order number match a search term.
	 *
	 * HPOS supports wc_get_orders( 's' ) directly. The legacy posts-based
	 * store does not — there 's' becomes a WP_Query post title/content
	 * search, which never matches billing names — so it goes through
	 * WooCommerce's own order search (wc_order_search(), the same lookup
	 * the Orders screen's search box uses) instead.
	 *
	 * wc_order_search() itself takes no limit/offset — its legacy CPT data
	 * store (WC_Order_Data_Store_CPT::search_orders(), WooCommerce core)
	 * runs its postmeta/order-items/customer-lookup queries with no SQL
	 * LIMIT at all before returning. The array_slice() below is a
	 * best-effort cap on what Billio itself then holds and processes, not
	 * a fix for the unbounded DB read — that bound lives entirely inside
	 * WooCommerce core and isn't something Billio can safely override
	 * without reimplementing WC's own search query (which would drift out
	 * of sync with it on every WC update). Legacy (non-HPOS) stores are
	 * already the less-common path going forward.
	 *
	 * @param string $q Search text.
	 * @return int[] At most SEARCH_HARD_LIMIT IDs.
	 */
	private function customer_search_order_ids( $q ) {
		if ( OrderStatsQuery::uses_hpos() ) {
			return array_map(
				'intval',
				wc_get_orders(
					array(
						'limit'  => self::SEARCH_HARD_LIMIT,
						'return' => 'ids',
						's'      => $q,
					)
				)
			);
		}

		return array_slice( array_map( 'intval', (array) wc_order_search( $q ) ), 0, self::SEARCH_HARD_LIMIT );
	}

	/**
	 * Format one invoiced order into the JS table's row shape.
	 *
	 * @param \WC_Order $order Invoiced order.
	 * @return array
	 */
	private function format_invoice_document( \WC_Order $order ) {
		$timestamp = self::invoice_issue_timestamp( $order );
		$name      = trim( $order->get_formatted_billing_full_name() );
		$phone     = trim( (string) $order->get_billing_phone() );

		return array(
			'type'             => 'inv',
			'number'           => $order->get_meta( '_billio_invoice_number' ),
			'orderId'          => $order->get_id(),
			'refundId'         => 0,
			'orderNumber'      => $order->get_order_number(),
			'orderEditUrl'     => $this->order_edit_url( $order ),
			'customerName'     => $name,
			'customerEmail'    => $order->get_billing_email(),
			'customerPhone'    => $phone,
			'paymentMethod'    => $order->get_payment_method_title(),
			'itemCount'        => (int) $order->get_item_count(),
			'billingAddress'   => wp_strip_all_tags( $order->get_formatted_billing_address() ),
			'orderStatus'      => $order->get_status(),
			'orderStatusLabel' => wc_get_order_status_name( $order->get_status() ),
			'dateDisplay'      => wp_date( get_option( 'date_format' ), $timestamp ),
			'dateTimestamp'    => $timestamp,
			'total'            => (float) $order->get_total(),
		);
	}

	/**
	 * Format one credit-noted refund into the JS table's row shape.
	 *
	 * @param \WC_Order_Refund $refund       Refund with a credit note.
	 * @param \WC_Order        $parent_order The original order.
	 * @return array
	 */
	private function format_credit_note_document( \WC_Order_Refund $refund, \WC_Order $parent_order ) {
		$timestamp = self::credit_note_issue_timestamp( $refund );
		$name      = trim( $parent_order->get_formatted_billing_full_name() );
		$phone     = trim( (string) $parent_order->get_billing_phone() );

		return array(
			'type'             => 'cn',
			'number'           => $refund->get_meta( '_billio_credit_note_number' ),
			'orderId'          => $parent_order->get_id(),
			'refundId'         => $refund->get_id(),
			'orderNumber'      => $parent_order->get_order_number(),
			'orderEditUrl'     => $this->order_edit_url( $parent_order ),
			'customerName'     => $name,
			'customerEmail'    => $parent_order->get_billing_email(),
			'customerPhone'    => $phone,
			'paymentMethod'    => $parent_order->get_payment_method_title(),
			'itemCount'        => (int) $parent_order->get_item_count(),
			'billingAddress'   => wp_strip_all_tags( $parent_order->get_formatted_billing_address() ),
			'orderStatus'      => 'refunded',
			'orderStatusLabel' => esc_html__( 'Refunded', 'billio' ),
			'dateDisplay'      => wp_date( get_option( 'date_format' ), $timestamp ),
			'dateTimestamp'    => $timestamp,
			'total'            => -abs( (float) $refund->get_amount() ),
		);
	}

	/**
	 * Attach the URLs to a formatted document row, for the current admin.
	 *
	 * Kept out of format_*_document() on purpose: those rows are cached in
	 * a transient shared by every admin, while the admin download URL
	 * carries a nonce bound to whoever's viewing — caching it would hand
	 * other admins a nonce that fails for them ("link expired").
	 *
	 * Share links (Copy link, WhatsApp) use the customer-facing,
	 * token-authorized URL — the admin URL is useless to a customer.
	 *
	 * @param array $doc Row from format_invoice_document()/format_credit_note_document().
	 * @return array The row with downloadUrl/previewUrl/shareUrl/whatsappUrl set.
	 */
	private function add_document_urls( array $doc ) {
		if ( 'cn' === $doc['type'] ) {
			$download_url = $this->raw_download_url( MetaBox::credit_note_url_for( $doc['orderId'], $doc['refundId'] ) );
			$share_url    = MyAccount::credit_note_url_for( $doc['orderId'], $doc['refundId'] );
			/* translators: 1: customer name, 2: credit note number, 3: download URL */
			$wa_format = __( 'Hello %1$s, here is your credit note %2$s: %3$s', 'billio' );
		} else {
			$download_url = $this->raw_download_url( OrderActions::build_download_url_for( $doc['orderId'] ) );
			$share_url    = MyAccount::invoice_url_for( $doc['orderId'] );
			/* translators: 1: customer name, 2: invoice number, 3: download URL */
			$wa_format = __( 'Hello %1$s, here is your invoice %2$s: %3$s', 'billio' );
		}

		$wa_text     = sprintf( $wa_format, $doc['customerName'] ? $doc['customerName'] : __( 'Customer', 'billio' ), $doc['number'], $share_url );
		$clean_phone = preg_replace( '/[^0-9]/', '', (string) $doc['customerPhone'] );

		$doc['downloadUrl'] = $download_url;
		$doc['previewUrl']  = $download_url . '&preview=1';
		$doc['shareUrl']    = $share_url;
		$doc['whatsappUrl'] = 'https://wa.me/' . $clean_phone . '?text=' . rawurlencode( $wa_text );

		return $doc;
	}

	/**
	 * HPOS-safe order-edit screen URL, with a legacy-CPT fallback for older
	 * WooCommerce versions that predate get_edit_order_url().
	 *
	 * @param \WC_Order $order Order to link to.
	 * @return string
	 */
	private function order_edit_url( \WC_Order $order ) {
		if ( method_exists( $order, 'get_edit_order_url' ) ) {
			return $order->get_edit_order_url();
		}

		return admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
	}

	/**
	 * Undo wp_nonce_url()'s internal esc_html() so the URL is safe to put
	 * into JSON (via wp_localize_script) and later into an HTML attribute
	 * via JS — not into raw HTML output directly.
	 *
	 * wp_nonce_url() (used by OrderActions::build_download_url() and
	 * MetaBox::credit_note_url()) is designed to be echoed straight into an
	 * href="" in server-rendered HTML, where the browser's HTML parser
	 * decodes the "&amp;" back into "&" while parsing. That never happens
	 * here: the URL instead goes into a JSON blob and then into
	 * admin-dashboard.js's own attribute-building, so the pre-escaped
	 * "&amp;" survives as literal text, gets escaped a *second* time by the
	 * JS escaping helper, and the resulting query string parameter names
	 * come out as "amp;order_id"/"amp;_wpnonce" instead of the real names —
	 * breaking the download with a "link expired" error. Decoding here
	 * once, to the real (unescaped) URL, is the correct fix: the JS side
	 * then escapes it exactly once, for the one HTML context it's actually
	 * used in.
	 *
	 * @param string $url A URL built via wp_nonce_url() (HTML-entity-escaped).
	 * @return string The same URL with entities decoded back to raw characters.
	 */
	private function raw_download_url( $url ) {
		return wp_specialchars_decode( $url, ENT_QUOTES );
	}
}
