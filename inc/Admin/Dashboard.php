<?php
/**
 * Billio's admin dashboard — interactive header, setup-progress banner, KPI
 * cards, an inline review bar, an invoice-activity chart with quick settings
 * alongside it, and a searchable recent-documents table.
 *
 * @package Billio
 */

namespace Billio\Admin;

use Billio\Invoice\NumberManager;
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
	 * Order statuses that don't represent money actually owed/kept, so
	 * they're excluded from revenue sums (they still count toward invoice
	 * *counts*, which just track how many invoices were generated).
	 */
	const REVENUE_EXCLUDED_STATUSES = array( 'cancelled', 'refunded' );

	/**
	 * Per-user meta flag: this admin dismissed the setup banner once all
	 * steps were already complete (or dismissed it manually).
	 */
	const OPTION_SETUP_DISMISSED = 'billio_dashboard_setup_dismissed';

	/**
	 * Max candidates fetched per search sub-query (find_invoice_candidates(),
	 * find_credit_note_candidates()) — bounds the worst case for a very
	 * broad search term, since results are still paginated/sorted in PHP
	 * after the two sub-queries are merged.
	 */
	const SEARCH_CANDIDATE_CAP = 200;

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
	 * @var string Hook suffix for this page, as returned by add_menu_page().
	 */
	private $hook_suffix = '';

	/**
	 * @var \WC_Order[]|null Per-request memoization — see get_legacy_invoiced_orders().
	 */
	private $invoiced_orders_cache = null;

	/**
	 * @var \WC_Order_Refund[]|null Per-request memoization — see get_legacy_credit_noted_refunds().
	 */
	private $credit_noted_refunds_cache = null;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
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
		delete_transient( 'billio_dash_recent_documents' );
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
				'settings'        => admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ),
				'preview'         => admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) . '#billio-preview-frame',
				'ordersList'      => admin_url( 'admin.php?page=wc-orders' ),
				'editFormatField' => admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG . '#billio_number_format' ),
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
				'emailTriggers' => (array) get_option( 'billio_email_triggers', array( 'customer_completed_order' ) ),
				'template'      => get_option( 'billio_template', 'default' ),
				'paperSize'     => get_option( 'billio_paper_size', 'A4' ),
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
			'error'                  => __( 'Something went wrong. Please try again.', 'billio' ),
			'allInvoiced'            => __( 'All orders invoiced', 'billio' ),
			/* translators: %d: number of orders missing an invoice. */
			'generateMissing'        => __( 'Generate missing (%d)', 'billio' ),
			'allCaughtUp'            => __( 'All caught up', 'billio' ),
			'generateThemNow'        => __( 'Generate them now →', 'billio' ),
			'downloadingPdf'         => __( 'Downloading…', 'billio' ),
			'invoiceEmailed'         => __( 'Invoice emailed to customer', 'billio' ),
			'confirmRegenerateTitle' => __( 'Regenerate this invoice?', 'billio' ),
			'confirmRegenerateBody'  => __( 'The PDF will be rebuilt with your current settings and template. The invoice number stays the same.', 'billio' ),
			'cancel'                 => __( 'Cancel', 'billio' ),
			'regenerate'             => __( 'Regenerate', 'billio' ),
			/* translators: %s: document number. */
			'regenerated'            => __( '%s regenerated', 'billio' ),
			'generateModalTitle'     => __( 'Generate missing invoices', 'billio' ),
			'generateModalNote'      => __( 'Orders will be processed oldest-first so numbering stays chronological.', 'billio' ),
			'start'                  => __( 'Start', 'billio' ),
			'ready'                  => __( 'Ready', 'billio' ),
			/* translators: 1: number done so far, 2: total number, 3: latest invoice number assigned. */
			'progressOf'             => __( '%1$d of %2$d · %3$s', 'billio' ),
			/* translators: %d: number of invoices generated. */
			'generatedCount'         => __( '%d invoices generated', 'billio' ),
			'noDocumentsYet'         => __( 'No invoices generated yet — they appear here as soon as an order is invoiced.', 'billio' ),
			/* translators: %s: the search term that matched nothing. */
			'noSearchMatch'          => __( 'No documents match "%s"', 'billio' ),
			/* translators: 1: number of documents shown, 2: total number of documents. */
			'showingOf'              => __( 'Showing %1$d of %2$d', 'billio' ),
			'viewAllInOrders'        => __( 'View all in Orders →', 'billio' ),
			'paperSizeSet'           => __( 'Paper size updated', 'billio' ),
			'templateSet'            => __( 'Invoice template updated', 'billio' ),
			'emailTriggerOn'         => __( 'Invoice will be attached to this email', 'billio' ),
			'emailTriggerOff'        => __( 'Invoice will no longer be attached to this email', 'billio' ),
			'settingsSaveFailed'     => __( 'Could not save — please try again.', 'billio' ),
			'setupComplete'          => __( '🎉 You\'re all set — every invoice is fully branded', 'billio' ),
			'today'                  => __( 'Today', 'billio' ),
			'noRefundsThisMonth'     => __( 'No refunds this month', 'billio' ),
			'noInvoicesYet'          => __( 'No invoices yet', 'billio' ),
			'noCreditNotesYet'       => __( 'No credit notes yet', 'billio' ),
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
				'key'    => 'logo',
				'label'  => esc_html__( 'Company logo', 'billio' ),
				'done'   => (bool) get_option( 'billio_company_logo_id' ),
				'anchor' => '#billio-field-billio_company_logo_id',
			),
			array(
				'key'    => 'address',
				'label'  => esc_html__( 'Company address', 'billio' ),
				'done'   => (bool) get_option( 'billio_company_address' ),
				'anchor' => '#billio-field-billio_company_address',
			),
			array(
				'key'    => 'email',
				'label'  => esc_html__( 'Email attachment enabled', 'billio' ),
				'done'   => ! empty( get_option( 'billio_email_triggers', array( 'customer_completed_order' ) ) ),
				'anchor' => '#billio-field-billio_email_triggers',
			),
			array(
				'key'    => 'first_invoice',
				'label'  => esc_html__( 'First invoice generated', 'billio' ),
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
			if ( ! in_array( $order->get_status(), self::REVENUE_EXCLUDED_STATUSES, true ) ) {
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
			if ( ! in_array( $order->get_status(), self::REVENUE_EXCLUDED_STATUSES, true ) ) {
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
	 * Every invoiced order whose *resolved issue date* falls within a
	 * timestamp window — split into two bounded queries instead of loading
	 * every invoiced order ever and filtering in PHP:
	 *
	 * 1. The common path: a direct meta_query BETWEEN on _billio_invoice_date
	 *    (set at number-assignment time for every order invoiced since the
	 *    meta existed) — this is virtually every real invoice, fetched as a
	 *    single bounded, indexed-by-meta query.
	 * 2. A legacy-fallback path, only for orders that somehow never got that
	 *    meta at all (pre-existing installs from before it existed) — a
	 *    small, memoized, EXISTS/NOT-EXISTS-scoped query, with
	 *    invoice_issue_timestamp()'s paid/completed/created fallback chain
	 *    still applied, but only to this normally-empty-or-tiny subset
	 *    rather than on every request.
	 *
	 * @param int $from Window start (unix timestamp).
	 * @param int $to   Window end (unix timestamp).
	 * @return \WC_Order[]
	 */
	private function orders_issued_between( $from, $to ) {
		$common = wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'objects',
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

		$legacy_in_window = array_filter(
			$this->get_legacy_invoiced_orders(),
			function ( $order ) use ( $from, $to ) {
				$ts = $this->invoice_issue_timestamp( $order );
				return $ts >= $from && $ts <= $to;
			}
		);

		return array_merge( $common, array_values( $legacy_in_window ) );
	}

	/**
	 * Every credit-noted refund whose *resolved issue date* falls within a
	 * timestamp window — same common-path/legacy-fallback split as
	 * orders_issued_between().
	 *
	 * @param int $from Window start (unix timestamp).
	 * @param int $to   Window end (unix timestamp).
	 * @return \WC_Order_Refund[]
	 */
	private function credit_notes_issued_between( $from, $to ) {
		$common = wc_get_orders(
			array(
				'type'       => 'shop_order_refund',
				'limit'      => -1,
				'return'     => 'objects',
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

		$legacy_in_window = array_filter(
			$this->get_legacy_credit_noted_refunds(),
			function ( $refund ) use ( $from, $to ) {
				$ts = $this->credit_note_issue_timestamp( $refund );
				return $ts >= $from && $ts <= $to;
			}
		);

		return array_merge( $common, array_values( $legacy_in_window ) );
	}

	/**
	 * Invoiced orders that never got _billio_invoice_date at all (e.g.
	 * invoiced by a pre-1.1.0 version of this plugin, before the meta
	 * existed) — normally empty on a real install. Memoized per request:
	 * called from up to three stats methods in the same page load.
	 *
	 * @return \WC_Order[]
	 */
	private function get_legacy_invoiced_orders() {
		if ( null === $this->invoiced_orders_cache ) {
			$this->invoiced_orders_cache = wc_get_orders(
				array(
					'limit'      => -1,
					'return'     => 'objects',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array(
							'key'     => '_billio_invoice_number',
							'compare' => 'EXISTS',
						),
						array(
							'key'     => '_billio_invoice_date',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);
		}

		return $this->invoiced_orders_cache;
	}

	/**
	 * Credit-noted refunds that never got _billio_credit_note_date at all —
	 * same reasoning and memoization as get_legacy_invoiced_orders().
	 *
	 * @return \WC_Order_Refund[]
	 */
	private function get_legacy_credit_noted_refunds() {
		if ( null === $this->credit_noted_refunds_cache ) {
			$this->credit_noted_refunds_cache = wc_get_orders(
				array(
					'type'       => 'shop_order_refund',
					'limit'      => -1,
					'return'     => 'objects',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array(
							'key'     => '_billio_credit_note_number',
							'compare' => 'EXISTS',
						),
						array(
							'key'     => '_billio_credit_note_date',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);
		}

		return $this->credit_noted_refunds_cache;
	}

	/**
	 * The date an invoice was actually issued — never "now", never a file's
	 * mtime. _billio_invoice_date is set once, at the moment a number is
	 * first assigned (see Generator::assign_number_locked()), and is the
	 * correct source of truth; the fallbacks below only matter for an order
	 * that somehow never got that meta (e.g. invoiced by a pre-1.1.0
	 * version of this plugin, before the meta existed).
	 *
	 * @param \WC_Order $order Invoiced order.
	 * @return int Unix timestamp.
	 */
	private function invoice_issue_timestamp( \WC_Order $order ) {
		$meta = (int) $order->get_meta( '_billio_invoice_date' );
		if ( $meta ) {
			return $meta;
		}

		$date_paid = $order->get_date_paid();
		if ( $date_paid ) {
			return $date_paid->getTimestamp();
		}

		$date_completed = $order->get_date_completed();
		if ( $date_completed ) {
			return $date_completed->getTimestamp();
		}

		$date_created = $order->get_date_created();
		return $date_created ? $date_created->getTimestamp() : 0;
	}

	/**
	 * The date a credit note was actually issued — same reasoning as
	 * invoice_issue_timestamp(), falling back to the refund's own creation
	 * date (a refund has no "paid"/"completed" concept of its own).
	 *
	 * @param \WC_Order_Refund $refund Refund with a credit note.
	 * @return int Unix timestamp.
	 */
	private function credit_note_issue_timestamp( \WC_Order_Refund $refund ) {
		$meta = (int) $refund->get_meta( '_billio_credit_note_date' );
		if ( $meta ) {
			return $meta;
		}

		$date_created = $refund->get_date_created();
		return $date_created ? $date_created->getTimestamp() : 0;
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

		$buckets = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$timestamp     = $start + ( $i * DAY_IN_SECONDS );
			$buckets[ $i ] = array(
				'date'        => wp_date( 'Y-m-d', $timestamp ),
				'invoices'    => 0,
				'revenue'     => 0.0,
				'creditNotes' => 0,
			);
		}

		foreach ( $orders as $order ) {
			$timestamp = $this->invoice_issue_timestamp( $order );
			$index     = (int) floor( ( $timestamp - $start ) / DAY_IN_SECONDS );
			if ( ! isset( $buckets[ $index ] ) ) {
				continue;
			}
			++$buckets[ $index ]['invoices'];
			if ( ! in_array( $order->get_status(), self::REVENUE_EXCLUDED_STATUSES, true ) ) {
				$buckets[ $index ]['revenue'] += (float) $order->get_total();
			}
		}

		foreach ( $refunds as $refund ) {
			$timestamp = $this->credit_note_issue_timestamp( $refund );
			$index     = (int) floor( ( $timestamp - $start ) / DAY_IN_SECONDS );
			if ( isset( $buckets[ $index ] ) ) {
				++$buckets[ $index ]['creditNotes'];
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
	 * @return int[]
	 */
	public function get_missing_order_ids() {
		$cutoff = $this->site_now()->modify( '-' . self::ACTION_NEEDED_CUTOFF_DAYS . ' days' )->getTimestamp();

		return wc_get_orders(
			array(
				'status'       => 'completed',
				'limit'        => -1,
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
	}

	/**
	 * Merge the most recent invoices and credit notes into one list, sorted
	 * by issue date (newest first). Cached — see STATS_CACHE_TTL.
	 *
	 * Fetches only `$limit` of each type directly ordered by their own date
	 * meta in SQL (not "every invoiced order ever, sorted in PHP") — orders
	 * invoiced so long ago they predate the _billio_invoice_date meta won't
	 * surface here (an accepted tradeoff for this specific "recent activity"
	 * list; the stats/chart methods still find them via their own legacy
	 * fallback). Parent-order lookups only happen for the credit notes that
	 * actually make the final cut, not every refund in history.
	 *
	 * @return array[]
	 */
	private function get_recent_documents() {
		$cache_key = 'billio_dash_recent_documents';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$limit = (int) apply_filters( 'billio_dashboard_recent_documents_limit', 10 );

		$invoice_candidates = wc_get_orders(
			array(
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
			$documents[] = $this->format_invoice_document( $order );
		}

		foreach ( $refund_candidates as $refund ) {
			$parent_order = wc_get_order( $refund->get_parent_id() );
			if ( ! $parent_order ) {
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
	 * @param string $q        Search text (invoice/CN number, order number, or customer name).
	 * @param string $type     'all', 'inv', or 'cn'.
	 * @param int    $page     1-indexed page number.
	 * @param int    $per_page Results per page (already capped by the caller).
	 * @return array{items: array[], total: int, page: int, perPage: int}
	 */
	public function search_documents( $q, $type, $page, $per_page ) {
		$page     = max( 1, (int) $page );
		$per_page = min( 20, max( 1, (int) $per_page ) );

		$documents = array();

		if ( 'cn' !== $type ) {
			foreach ( $this->find_invoice_candidates( $q ) as $order ) {
				$documents[] = $this->format_invoice_document( $order );
			}
		}

		if ( 'inv' !== $type ) {
			foreach ( $this->find_credit_note_candidates( $q ) as $refund ) {
				$parent_order = wc_get_order( $refund->get_parent_id() );
				if ( ! $parent_order ) {
					continue;
				}
				$documents[] = $this->format_credit_note_document( $refund, $parent_order );
			}
		}

		usort(
			$documents,
			static function ( $a, $b ) {
				return $b['dateTimestamp'] - $a['dateTimestamp'];
			}
		);

		$total = count( $documents );
		$items = array_slice( $documents, ( $page - 1 ) * $per_page, $per_page );

		return array(
			'items'   => $items,
			'total'   => $total,
			'page'    => $page,
			'perPage' => $per_page,
		);
	}

	/**
	 * Candidate invoiced orders for a search term — two bounded queries
	 * (number match, general WooCommerce order search for customer
	 * name/email/numeric order id) merged and de-duplicated, rather than one
	 * "OR across a meta LIKE and the general search" query (WooCommerce's
	 * query API doesn't expose that combination in a single call). Both
	 * queries are always scoped to _billio_invoice_number EXISTS — never a
	 * full orders-table scan — and each internally capped so a broad search
	 * term can't balloon into an unbounded fetch.
	 *
	 * @param string $q Search text; empty string browses the most recent instead.
	 * @return \WC_Order[]
	 */
	private function find_invoice_candidates( $q ) {
		$base_args = array(
			'limit'    => self::SEARCH_CANDIDATE_CAP,
			'return'   => 'objects',
			'orderby'  => 'meta_value_num',
			'meta_key' => '_billio_invoice_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'    => 'DESC',
		);

		if ( '' === $q ) {
			return wc_get_orders(
				$base_args + array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_billio_invoice_number',
							'compare' => 'EXISTS',
						),
					),
				)
			);
		}

		$by_number = wc_get_orders(
			$base_args + array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_billio_invoice_number',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_billio_invoice_number',
						'value'   => $q,
						'compare' => 'LIKE',
					),
				),
			)
		);

		$by_customer = wc_get_orders(
			array(
				'limit'      => self::SEARCH_CANDIDATE_CAP,
				'return'     => 'objects',
				's'          => $q,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_billio_invoice_number',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return $this->dedupe_orders( array_merge( $by_number, $by_customer ) );
	}

	/**
	 * Candidate credit-noted refunds for a search term — same two-query
	 * merge-and-dedupe reasoning as find_invoice_candidates().
	 *
	 * @param string $q Search text; empty string browses the most recent instead.
	 * @return \WC_Order_Refund[]
	 */
	private function find_credit_note_candidates( $q ) {
		$base_args = array(
			'type'     => 'shop_order_refund',
			'limit'    => self::SEARCH_CANDIDATE_CAP,
			'return'   => 'objects',
			'orderby'  => 'meta_value_num',
			'meta_key' => '_billio_credit_note_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'    => 'DESC',
		);

		if ( '' === $q ) {
			return wc_get_orders(
				$base_args + array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_billio_credit_note_number',
							'compare' => 'EXISTS',
						),
					),
				)
			);
		}

		$by_number = wc_get_orders(
			$base_args + array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_billio_credit_note_number',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_billio_credit_note_number',
						'value'   => $q,
						'compare' => 'LIKE',
					),
				),
			)
		);

		$by_customer = wc_get_orders(
			array(
				'type'       => 'shop_order_refund',
				'limit'      => self::SEARCH_CANDIDATE_CAP,
				'return'     => 'objects',
				's'          => $q,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_billio_credit_note_number',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return $this->dedupe_orders( array_merge( $by_number, $by_customer ) );
	}

	/**
	 * De-duplicate a list of orders/refunds by ID, keeping the first
	 * occurrence (used after merging two overlapping queries).
	 *
	 * @param \WC_Abstract_Order[] $orders Orders/refunds, possibly with duplicates.
	 * @return array
	 */
	private function dedupe_orders( array $orders ) {
		$seen   = array();
		$result = array();

		foreach ( $orders as $order ) {
			$id = $order->get_id();
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$result[]    = $order;
		}

		return $result;
	}

	/**
	 * Format one invoiced order into the JS table's row shape.
	 *
	 * @param \WC_Order $order Invoiced order.
	 * @return array
	 */
	private function format_invoice_document( \WC_Order $order ) {
		$timestamp = $this->invoice_issue_timestamp( $order );

		return array(
			'type'             => 'inv',
			'number'           => $order->get_meta( '_billio_invoice_number' ),
			'orderId'          => $order->get_id(),
			'orderNumber'      => $order->get_order_number(),
			'orderEditUrl'     => $this->order_edit_url( $order ),
			'customerName'     => trim( $order->get_formatted_billing_full_name() ),
			'orderStatus'      => $order->get_status(),
			'orderStatusLabel' => wc_get_order_status_name( $order->get_status() ),
			'dateDisplay'      => wp_date( get_option( 'date_format' ), $timestamp ),
			'dateTimestamp'    => $timestamp,
			'total'            => (float) $order->get_total(),
			'downloadUrl'      => $this->raw_download_url( OrderActions::build_download_url( $order ) ),
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
		$timestamp = $this->credit_note_issue_timestamp( $refund );

		return array(
			'type'             => 'cn',
			'number'           => $refund->get_meta( '_billio_credit_note_number' ),
			'orderId'          => $parent_order->get_id(),
			'orderNumber'      => $parent_order->get_order_number(),
			'orderEditUrl'     => $this->order_edit_url( $parent_order ),
			'customerName'     => trim( $parent_order->get_formatted_billing_full_name() ),
			'orderStatus'      => 'refunded',
			'orderStatusLabel' => esc_html__( 'Refunded', 'billio' ),
			'dateDisplay'      => wp_date( get_option( 'date_format' ), $timestamp ),
			'dateTimestamp'    => $timestamp,
			'total'            => -abs( (float) $refund->get_amount() ),
			'downloadUrl'      => $this->raw_download_url( MetaBox::credit_note_url( $parent_order, $refund ) ),
		);
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
