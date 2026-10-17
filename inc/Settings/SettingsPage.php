<?php
/**
 * Billio settings screen — section-cards layout with a sticky live preview,
 * matching the Dashboard's design system.
 *
 * @package Billio
 */

namespace Billio\Settings;

use Billio\Admin\Dashboard;
use Billio\Admin\DocsPage;
use Billio\Invoice\NumberManager;
use Billio\Invoice\SampleOrder;
use Billio\Invoice\TemplateEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsPage {

	const NONCE_ACTION = 'billio_save_settings';
	const MENU_SLUG    = 'billio-settings';

	/**
	 * @var string Hook suffix for this page, as returned by add_submenu_page().
	 *             Read back in enqueue() instead of guessing the string.
	 */
	private $hook_suffix = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_billio_preview', array( $this, 'render_preview' ) );
		add_filter( 'plugin_action_links_' . BILLIO_BASENAME, array( $this, 'add_action_link' ) );

		// First-activation redirect straight to this page (decision #8: a
		// guided single page beats a multi-step wizard for v1).
		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
	}

	/**
	 * Register "Settings" under the Billio top-level menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = add_submenu_page(
			Dashboard::MENU_SLUG,
			esc_html__( 'Billio Settings', 'billio' ),
			esc_html__( 'Settings', 'billio' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Add a "Settings" link on the Plugins list screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_action_link( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Settings', 'billio' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Redirect to the settings page once, right after activation.
	 *
	 * @return void
	 */
	public function maybe_redirect_after_activation() {
		if ( ! get_transient( 'billio_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'billio_activation_redirect' );

		// Read-only existence check (are we on the bulk-activate screen?),
		// not form processing — nothing here is trusted as input.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( wp_doing_ajax() || isset( $_GET['activate-multi'] ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/**
	 * Handle the settings form submission.
	 *
	 * Reused unchanged from the pre-redesign version except for one
	 * addition (the {NUMBER} guard) — every option key, Fields::sanitize()
	 * call, and the yearly-reset seed/catch-up logic stay exactly as they
	 * were; this is a UI redesign, not a data migration.
	 *
	 * @return void
	 */
	public function maybe_save() {
		if ( ! isset( $_POST['billio_settings_nonce'] ) ) {
			return;
		}

		if ( ! check_admin_referer( self::NONCE_ACTION, 'billio_settings_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$was_reset_yearly = (bool) get_option( 'billio_number_reset_yearly' );
		$has_errors       = false;
		$submitted        = array();

		foreach ( Fields::get() as $field ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via Fields::sanitize() below.
			$raw   = isset( $_POST[ $field['option'] ] ) ? wp_unslash( $_POST[ $field['option'] ] ) : '';
			$value = Fields::sanitize( $field, $raw );

			// An empty trigger list silently means "invoices are never
			// emailed" with no indication anything's wrong — refuse to
			// save that state and keep whatever was configured before.
			if ( 'billio_email_triggers' === $field['option'] && empty( $value ) ) {
				add_settings_error(
					'billio',
					'billio_empty_triggers',
					esc_html__( 'At least one "Send invoice when order is" option must stay checked — your previous choice was kept.', 'billio' ),
					'error'
				);
				$has_errors = true;
				continue;
			}

			// A number format with no {NUMBER} placeholder would give every
			// document in the same year/prefix the exact same visible
			// number — block it server-side (the client already blocks the
			// Save button for this, but that's just UX, not enforcement)
			// and keep whatever format was configured before.
			if ( 'billio_number_format' === $field['option'] && false === strpos( $value, '{NUMBER}' ) ) {
				add_settings_error(
					'billio',
					'billio_format_missing_number',
					esc_html__( 'Number format must include {NUMBER} — your previous format was kept.', 'billio' ),
					'error'
				);
				$has_errors = true;
				continue;
			}

			$submitted[ $field['option'] ] = $value;
			update_option( $field['option'], $value );
		}

		$now_reset_yearly = ! empty( $submitted['billio_number_reset_yearly'] );

		// Both numbering series (invoices and credit notes) switch onto a
		// per-year counter key when this setting is on, so both need the
		// same seed-on-enable / catch-up-on-disable treatment.
		foreach ( array( 'invoice', 'credit_note' ) as $series ) {
			$base_key = NumberManager::sequence_option_key( $series, false );
			$year_key = NumberManager::sequence_option_key( $series, true );

			if ( $now_reset_yearly && ! $was_reset_yearly ) {
				// Turning on mid-year must not restart the sequence at 0 —
				// that would reissue numbers that already exist for this
				// year. Seed the new per-year counter from the current
				// all-time count so numbering continues where it left off.
				//
				// The per-year counter may already exist from an earlier
				// on→off cycle this same year — and the all-time counter has
				// moved past it since — so never just keep it as-is
				// (add_option() alone would): take the higher of the two, or
				// re-enabling would reissue numbers given out while it was off.
				$current_sequence = (int) get_option( $base_key, 0 );
				$year_sequence    = (int) get_option( $year_key, 0 );
				if ( false === get_option( $year_key ) ) {
					add_option( $year_key, $current_sequence, '', false );
				} elseif ( $current_sequence > $year_sequence ) {
					update_option( $year_key, $current_sequence, false );
				}
			} elseif ( $was_reset_yearly && ! $now_reset_yearly ) {
				// Turning off resumes from the all-time counter, which was
				// frozen the whole time yearly-reset was on. If the per-year
				// counter has since moved past it, catch the all-time
				// counter up first or it would reissue numbers already
				// given out this year.
				$year_sequence = (int) get_option( $year_key, 0 );
				$base_sequence = (int) get_option( $base_key, 0 );
				if ( $year_sequence > $base_sequence ) {
					update_option( $base_key, $year_sequence );
				}
			}
		}

		$format_has_year = isset( $submitted['billio_number_format'] )
			? ( false !== strpos( $submitted['billio_number_format'], '{YEAR}' ) )
			: ( false !== strpos( get_option( 'billio_number_format', '{PREFIX}-{YEAR}-{NUMBER}' ), '{YEAR}' ) );

		if ( $now_reset_yearly && ! $format_has_year ) {
			add_settings_error(
				'billio',
				'billio_reset_yearly_no_year',
				esc_html__( 'Your number format has no {YEAR} placeholder, so numbers from different years will look identical with "Reset numbering each year" on. Consider adding {YEAR} to the format.', 'billio' ),
				'warning'
			);
		}

		// Invalidate cached logo data URI so any updated logo reflects immediately.
		TemplateEngine::clear_logo_cache();

		if ( ! $has_errors ) {
			add_settings_error( 'billio', 'billio_saved', esc_html__( 'Settings saved.', 'billio' ), 'success' );
		}

		// Post-Redirect-Get: without this, refreshing the page after a save
		// re-submits the form (browser "confirm resubmission" prompt).
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * Enqueue the media-uploader + settings page scripts/styles, and
	 * localize everything admin-settings.js needs (current values for the
	 * client-side next-number recompute, i18n, nonce).
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style( 'billio-admin-base', BILLIO_URL . 'assets/css/admin-base.css', array(), BILLIO_VERSION );
		wp_enqueue_style( 'billio-admin-settings', BILLIO_URL . 'assets/css/admin-settings.css', array( 'billio-admin-base' ), BILLIO_VERSION );
		wp_enqueue_script( 'billio-admin-settings', BILLIO_URL . 'assets/js/admin-settings.js', array(), BILLIO_VERSION, true );

		wp_localize_script( 'billio-admin-settings', 'billioSettings', $this->localized_data() );
	}

	/**
	 * Build the full localized data object consumed by admin-settings.js.
	 *
	 * @return array
	 */
	private function localized_data() {
		return array(
			'nonce'       => wp_create_nonce( 'billio_preview' ),
			'previewUrl'  => admin_url( 'admin-post.php?action=billio_preview' ),
			'mediaTitle'  => __( 'Choose a logo', 'billio' ),
			'mediaButton' => __( 'Use this logo', 'billio' ),
			'current'     => array(
				'numberPrefix'      => get_option( 'billio_number_prefix', 'INV' ),
				'creditNotePrefix'  => get_option( 'billio_credit_note_prefix', 'CN' ),
				'numberFormat'      => get_option( 'billio_number_format', '{PREFIX}-{YEAR}-{NUMBER}' ),
				'numberPadding'     => (int) get_option( 'billio_number_padding', 4 ),
				'nextInvoiceSeq'    => $this->peek_next_sequence( 'invoice' ),
				'nextCreditNoteSeq' => $this->peek_next_sequence( 'credit_note' ),
			),
			'i18n'        => array(
				/* translators: %d: digit count example, e.g. "4 → 0001". */
				'paddingExample'       => __( '%1$d → %2$s', 'billio' ),
				'formatMissingNumber'  => __( 'The format must include {NUMBER}, otherwise every invoice gets the same number.', 'billio' ),
				'resetYearlyHasYear'   => __( 'Your format includes {YEAR}, so numbers stay unique.', 'billio' ),
				'resetYearlyNoYear'    => __( 'Add {YEAR} to the format, otherwise numbers will repeat each year.', 'billio' ),
				'noEmailStatus'        => __( 'No status selected — customers won\'t receive invoices by email.', 'billio' ),
				'confirmNoEmailStatus' => __( 'No email status selected — customers won\'t receive invoices automatically. Save anyway?', 'billio' ),
				/* translators: %d: number of unsaved changes. */
				'unsavedChange'        => __( '%d unsaved change', 'billio' ),
				/* translators: %d: number of unsaved changes. */
				'unsavedChanges'       => __( '%d unsaved changes', 'billio' ),
				'settingsSaved'        => __( '✓ Settings saved', 'billio' ),
				'fixFormatFirst'       => __( 'Fix the number format first', 'billio' ),
				'addressMissing'       => __( 'Add your company address', 'billio' ),
				'logoUploaded'         => __( 'Logo uploaded', 'billio' ),
				'logoRemoved'          => __( 'Logo removed', 'billio' ),
				'invalidImageFile'     => __( 'Please drop an image file (PNG or JPG).', 'billio' ),
				'profileComplete'      => __( 'Complete', 'billio' ),
				/* translators: %d: percentage complete. */
				'profilePctComplete'   => __( '%d%% complete', 'billio' ),
			),
		);
	}

	/**
	 * The current counter value for a series, before formatting — the raw
	 * number NumberManager::peek_next_number() would format, exposed
	 * separately so JS can recompute the *display string* live as the user
	 * edits prefix/format/padding without another round trip, while the
	 * counter value itself still only ever comes from the real option
	 * (read-only, never incremented).
	 *
	 * @param string $series 'invoice' or 'credit_note'.
	 * @return int
	 */
	private function peek_next_sequence( $series ) {
		$option_key = NumberManager::sequence_option_key( $series, (bool) get_option( 'billio_number_reset_yearly' ) );
		return (int) get_option( $option_key, 0 ) + 1;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render() {
		settings_errors( 'billio' );

		$company_name     = get_option( 'billio_company_name', get_bloginfo( 'name' ) );
		$logo_id          = (int) get_option( 'billio_company_logo_id', 0 );
		$logo_url         = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$logo_filename    = $logo_id ? wp_basename( get_attached_file( $logo_id ) ) : '';
		$address          = get_option( 'billio_company_address', '' );
		$vat              = get_option( 'billio_company_vat', '' );
		$inv_prefix       = get_option( 'billio_number_prefix', 'INV' );
		$cn_prefix        = get_option( 'billio_credit_note_prefix', 'CN' );
		$number_format    = get_option( 'billio_number_format', '{PREFIX}-{YEAR}-{NUMBER}' );
		$padding          = (int) get_option( 'billio_number_padding', 4 );
		$reset_yearly     = (bool) get_option( 'billio_number_reset_yearly', false );
		$paper_size       = get_option( 'billio_paper_size', 'A4' );
		$email_triggers   = Fields::email_triggers();
		$template         = get_option( 'billio_template', 'default' );
		$accent_color     = get_option( 'billio_accent_color', '#3b6cf4' );
		$watermark_paid   = (bool) get_option( 'billio_watermark_paid', false );
		$thankyou_down_on = (bool) get_option( 'billio_show_thankyou_download', true );
		$enable_tax_id    = (bool) get_option( 'billio_enable_tax_id', false );
		$tax_id_label     = get_option( 'billio_tax_id_label', __( 'VAT / Tax / BIN Number', 'billio' ) );
		$tax_id_required  = (bool) get_option( 'billio_tax_id_required', false );
		$link_validity    = (int) get_option( 'billio_customer_link_validity_days', 30 );
		$ondemand_on      = (bool) get_option( 'billio_customer_ondemand_generate', true );
		$processing_on    = in_array( 'customer_processing_order', $email_triggers, true );
		$completed_on     = in_array( 'customer_completed_order', $email_triggers, true );
		$format_has_year  = false !== strpos( $number_format, '{YEAR}' );

		$next_invoice     = NumberManager::peek_next_number( 'invoice' );
		$next_credit_note = NumberManager::peek_next_number( 'credit_note' );
		$preview_url      = wp_nonce_url( admin_url( 'admin-post.php?action=billio_preview' ), 'billio_preview' );

		$swatches = array( '#2b1a05', '#4f46e5', '#0f766e', '#0f172a', '#b91c1c', '#15803d' );
		?>
		<div class="wrap billio-ui billio-settings">
			<div class="billio-hdr">
				<div class="billio-logo"><svg class="billio-i" style="width:22px;height:22px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6M9 13h7M9 17h5"/></svg></div>
				<div>
					<h1><?php esc_html_e( 'Settings', 'billio' ); ?></h1>
					<div class="billio-sub"><?php esc_html_e( 'Changes show in the live preview instantly. Nothing is saved until you click Save.', 'billio' ); ?></div>
				</div>
				<div class="billio-sp"></div>
				<a class="billio-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . DocsPage::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Docs & Features', 'billio' ); ?></a>
				<a class="billio-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Dashboard::MENU_SLUG ) ); ?>">&larr; <?php esc_html_e( 'Dashboard', 'billio' ); ?></a>
				<button type="button" class="billio-btn billio-pri" id="billio-top-save" disabled><?php esc_html_e( 'Save changes', 'billio' ); ?></button>
			</div>

			<form method="post" id="billio-settings-form">
				<?php wp_nonce_field( self::NONCE_ACTION, 'billio_settings_nonce' ); ?>

				<div class="billio-settings-layout">
					<!-- section nav -->
					<aside class="billio-settings-nav" id="billio-settings-nav">
						<a href="#billio-sec-company" class="billio-nav-on">
							<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h1M14 9h1M9 13h1M14 13h1M9 17h1M14 17h1"/></svg>
							<?php esc_html_e( 'Company', 'billio' ); ?>
							<span class="billio-nav-dot" id="billio-nav-dot" <?php echo $address ? 'hidden' : ''; ?>></span>
						</a>
						<a href="#billio-sec-tax-id"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg><?php esc_html_e( 'B2B / Tax ID', 'billio' ); ?></a>
						<a href="#billio-sec-numbering"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 9h16M4 15h16M10 3L8 21M16 3l-2 18"/></svg><?php esc_html_e( 'Numbering', 'billio' ); ?></a>
						<a href="#billio-sec-delivery"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v16H4z"/><path d="M22 6l-10 7L2 6"/></svg><?php esc_html_e( 'Email & paper', 'billio' ); ?></a>
						<a href="#billio-sec-design"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2a10 10 0 0 0 0 20c1 0 2-1 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.3 0-1 1-2 2-2h2.3A5.7 5.7 0 0 0 22 10c0-4.4-4.5-8-10-8z"/></svg><?php esc_html_e( 'Design', 'billio' ); ?></a>
						<a href="#billio-sec-advanced"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/></svg><?php esc_html_e( 'Advanced', 'billio' ); ?></a>

						<div class="billio-profile-card">
							<small><?php esc_html_e( 'Invoice profile', 'billio' ); ?></small>
							<div class="billio-profile-pct" id="billio-profile-pct"></div>
							<div class="billio-profile-bar"><i id="billio-profile-bar-fill"></i></div>
						</div>
					</aside>

					<!-- form sections -->
					<div class="billio-settings-sections">

						<section class="billio-card billio-sec" id="billio-sec-company">
							<header>
								<span class="billio-sec-ic"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l7-4 7 4v14"/></svg></span>
								<div><h2><?php esc_html_e( 'Company details', 'billio' ); ?></h2><p><?php esc_html_e( 'Printed in the "From" block of every invoice, credit note and packing slip.', 'billio' ); ?></p></div>
							</header>
							<div class="billio-sec-body">
								<div class="billio-f">
									<label for="billio_company_name"><?php esc_html_e( 'Company name', 'billio' ); ?></label>
									<div><input class="billio-in" type="text" name="billio_company_name" id="billio_company_name" value="<?php echo esc_attr( $company_name ); ?>" data-bind="name"></div>
								</div>
								<div class="billio-f">
									<div class="billio-lab"><?php esc_html_e( 'Company logo', 'billio' ); ?><small><?php esc_html_e( 'PNG or JPG, at least 300px wide', 'billio' ); ?></small></div>
									<div>
										<div class="billio-drop" id="billio-drop">
											<div class="billio-drop-img">
												<img id="billio-logo-preview" src="<?php echo esc_url( $logo_url ); ?>" alt="" style="<?php echo $logo_url ? '' : 'display:none;'; ?>">
												<svg class="billio-i" id="billio-logo-placeholder" style="width:28px;height:28px;<?php echo $logo_url ? 'display:none;' : ''; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>
											</div>
											<div class="billio-drop-t">
												<b id="billio-logo-filename"><?php echo esc_html( $logo_filename ? $logo_filename : esc_html__( 'No logo uploaded', 'billio' ) ); ?></b>
												<?php esc_html_e( 'Drop a new image here or', 'billio' ); ?>
											</div>
											<input type="hidden" name="billio_company_logo_id" id="billio_company_logo_id" value="<?php echo esc_attr( $logo_id ); ?>">
											<button type="button" class="billio-btn billio-sm" id="billio-logo-replace"><?php esc_html_e( 'Replace', 'billio' ); ?></button>
											<button type="button" class="billio-btn billio-ghost billio-sm" id="billio-logo-remove" style="<?php echo $logo_url ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'billio' ); ?></button>
										</div>
									</div>
								</div>
								<div class="billio-f">
									<label for="billio_company_address">
										<?php esc_html_e( 'Company address', 'billio' ); ?>
										<span class="billio-tag billio-tag-req" id="billio-addr-req" <?php echo $address ? 'hidden' : ''; ?>><?php esc_html_e( 'Required', 'billio' ); ?></span>
										<small><?php esc_html_e( 'One line per row — shown exactly as typed', 'billio' ); ?></small>
									</label>
									<div>
										<textarea class="billio-in" name="billio_company_address" id="billio_company_address" data-bind="addr" rows="3" placeholder="<?php echo esc_attr( "House 12, Road 5\nDhanmondi, Dhaka 1209\nBangladesh" ); ?>"><?php echo esc_textarea( $address ); ?></textarea>
										<div class="billio-hint billio-hint-warn" id="billio-addr-hint" <?php echo $address ? 'hidden' : ''; ?>>⚠ <?php esc_html_e( 'Invoices without an address may not be accepted as valid tax documents.', 'billio' ); ?></div>
									</div>
								</div>
								<div class="billio-f">
									<label for="billio_company_vat"><?php esc_html_e( 'VAT / Tax number', 'billio' ); ?> <span class="billio-tag billio-tag-opt"><?php esc_html_e( 'Optional', 'billio' ); ?></span><small><?php esc_html_e( 'Shown under your company name', 'billio' ); ?></small></label>
									<div><input class="billio-in" type="text" name="billio_company_vat" id="billio_company_vat" value="<?php echo esc_attr( $vat ); ?>" data-bind="vat" placeholder="<?php esc_attr_e( 'e.g. BIN 000123456-0101', 'billio' ); ?>"></div>
								</div>
							</div>
						</section>

						<section class="billio-card billio-sec" id="billio-sec-tax-id">
							<header>
								<span class="billio-sec-ic"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
								<div><h2><?php esc_html_e( 'B2B Customer Tax ID', 'billio' ); ?></h2><p><?php esc_html_e( 'Collect customer VAT, Tax, BIN, TRN, or GSTIN numbers at checkout and print on invoices.', 'billio' ); ?></p></div>
							</header>
							<div class="billio-sec-body">
								<div class="billio-f">
									<div class="billio-lab"><?php esc_html_e( 'Checkout field', 'billio' ); ?><small><?php esc_html_e( 'Adds a Tax ID input field to WooCommerce checkout', 'billio' ); ?></small></div>
									<div>
										<div class="billio-sw <?php echo $enable_tax_id ? 'billio-sw-on' : ''; ?>" id="billio-sw-tax-id">
											<div class="billio-sw-t"><?php esc_html_e( 'Enable Tax ID at checkout', 'billio' ); ?><small><?php esc_html_e( 'Works seamlessly with Classic shortcode checkout and Block checkout', 'billio' ); ?></small></div>
											<input type="checkbox" name="billio_enable_tax_id" id="billio_enable_tax_id" value="1" tabindex="-1" aria-hidden="true" class="billio-sr-only" <?php checked( $enable_tax_id ); ?>>
											<button type="button" class="billio-tg" role="switch" aria-checked="<?php echo $enable_tax_id ? 'true' : 'false'; ?>" aria-label="<?php esc_attr_e( 'Enable Tax ID at checkout', 'billio' ); ?>" data-checkbox="billio_enable_tax_id"></button>
										</div>
									</div>
								</div>
								<div class="billio-f <?php echo $enable_tax_id ? '' : 'billio-dim-row'; ?>" id="billio-tax-id-options">
									<label for="billio_tax_id_label"><?php esc_html_e( 'Field label', 'billio' ); ?><small><?php esc_html_e( 'Shown on checkout, order details, and PDF invoices', 'billio' ); ?></small></label>
									<div>
										<input class="billio-in" type="text" name="billio_tax_id_label" id="billio_tax_id_label" value="<?php echo esc_attr( $tax_id_label ); ?>" placeholder="<?php esc_attr_e( 'e.g. VAT / Tax / BIN Number', 'billio' ); ?>">
									</div>
								</div>
								<div class="billio-f <?php echo $enable_tax_id ? '' : 'billio-dim-row'; ?>" id="billio-tax-id-req-row">
									<div class="billio-lab"><?php esc_html_e( 'Field requirement', 'billio' ); ?><small><?php esc_html_e( 'Control whether buyers are required to fill this field', 'billio' ); ?></small></div>
									<div>
										<div class="billio-sw <?php echo $tax_id_required ? 'billio-sw-on' : ''; ?>" id="billio-sw-tax-req">
											<div class="billio-sw-t"><?php esc_html_e( 'Require Tax ID at checkout', 'billio' ); ?><small><?php esc_html_e( 'Orders cannot be placed without entering a Tax ID', 'billio' ); ?></small></div>
											<input type="checkbox" name="billio_tax_id_required" id="billio_tax_id_required" value="1" tabindex="-1" aria-hidden="true" class="billio-sr-only" <?php checked( $tax_id_required ); ?>>
											<button type="button" class="billio-tg" role="switch" aria-checked="<?php echo $tax_id_required ? 'true' : 'false'; ?>" aria-label="<?php esc_attr_e( 'Require Tax ID at checkout', 'billio' ); ?>" data-checkbox="billio_tax_id_required"></button>
										</div>
									</div>
								</div>
							</div>
						</section>

						<section class="billio-card billio-sec" id="billio-sec-numbering">
							<header>
								<span class="billio-sec-ic">#</span>
								<div><h2><?php esc_html_e( 'Invoice numbering', 'billio' ); ?></h2><p><?php esc_html_e( 'Numbers are assigned atomically — no duplicates, no gaps.', 'billio' ); ?></p></div>
							</header>
							<div class="billio-sec-body">
								<div class="billio-f">
									<div class="billio-lab"><?php esc_html_e( 'Prefixes', 'billio' ); ?></div>
									<div class="billio-two">
										<div>
											<small><?php esc_html_e( 'Invoice', 'billio' ); ?></small>
											<input class="billio-in billio-mono" type="text" name="billio_number_prefix" id="billio_number_prefix" value="<?php echo esc_attr( $inv_prefix ); ?>">
										</div>
										<div>
											<small><?php esc_html_e( 'Credit note', 'billio' ); ?> <span title="<?php esc_attr_e( 'Credit notes have their own sequence', 'billio' ); ?>">ⓘ</span></small>
											<input class="billio-in billio-mono" type="text" name="billio_credit_note_prefix" id="billio_credit_note_prefix" value="<?php echo esc_attr( $cn_prefix ); ?>">
										</div>
									</div>
								</div>
								<div class="billio-f">
									<label for="billio_number_format"><?php esc_html_e( 'Number format', 'billio' ); ?><small><?php esc_html_e( 'Used for invoices and credit notes', 'billio' ); ?></small></label>
									<div>
										<input class="billio-in billio-mono" type="text" name="billio_number_format" id="billio_number_format" value="<?php echo esc_attr( $number_format ); ?>">
										<div class="billio-chips">
											<span class="billio-chips-label"><?php esc_html_e( 'Insert:', 'billio' ); ?></span>
											<button type="button" class="billio-chip" data-ins="{PREFIX}">{PREFIX}</button>
											<button type="button" class="billio-chip" data-ins="{YEAR}">{YEAR}</button>
											<button type="button" class="billio-chip" data-ins="{NUMBER}">{NUMBER}</button>
										</div>
										<div class="billio-hint billio-hint-bad" id="billio-fmt-err" hidden>✕ <?php esc_html_e( 'The format must include {NUMBER}, otherwise every invoice gets the same number.', 'billio' ); ?></div>
										<div class="billio-nprev">
											<div><small><?php esc_html_e( 'Next invoice', 'billio' ); ?></small><b id="billio-next-invoice"><?php echo esc_html( $next_invoice ); ?></b></div>
											<div class="billio-nprev-cn"><small><?php esc_html_e( 'Next credit note', 'billio' ); ?></small><b id="billio-next-creditnote"><?php echo esc_html( $next_credit_note ); ?></b></div>
										</div>
									</div>
								</div>
								<div class="billio-f">
									<div class="billio-lab"><?php esc_html_e( 'Number padding', 'billio' ); ?><small><?php esc_html_e( 'Digits in {NUMBER}', 'billio' ); ?></small></div>
									<div class="billio-step-wrap">
										<div class="billio-step">
											<button type="button" id="billio-pad-dec" aria-label="<?php esc_attr_e( 'Decrease', 'billio' ); ?>">−</button>
											<input type="text" inputmode="numeric" name="billio_number_padding" id="billio_number_padding" value="<?php echo esc_attr( $padding ); ?>" aria-label="<?php esc_attr_e( 'Padding', 'billio' ); ?>">
											<button type="button" id="billio-pad-inc" aria-label="<?php esc_attr_e( 'Increase', 'billio' ); ?>">+</button>
										</div>
										<span class="billio-hint" id="billio-pad-example"></span>
									</div>
								</div>
								<div class="billio-f">
									<div class="billio-lab"><?php esc_html_e( 'Yearly reset', 'billio' ); ?></div>
									<div>
										<div class="billio-sw <?php echo $reset_yearly ? 'billio-sw-on' : ''; ?>" id="billio-reset-row">
											<div class="billio-sw-t">
												<?php esc_html_e( 'Start a fresh sequence every January', 'billio' ); ?>
												<small id="billio-reset-hint"><?php echo $format_has_year ? esc_html__( 'Your format includes {YEAR}, so numbers stay unique.', 'billio' ) : esc_html__( 'Add {YEAR} to the format, otherwise numbers will repeat each year.', 'billio' ); ?></small>
											</div>
											<input type="checkbox" name="billio_number_reset_yearly" id="billio_number_reset_yearly" value="1" tabindex="-1" aria-hidden="true" class="billio-sr-only" <?php checked( $reset_yearly ); ?>>
											<button type="button" class="billio-tg" role="switch" aria-checked="<?php echo $reset_yearly ? 'true' : 'false'; ?>" aria-label="<?php esc_attr_e( 'Start a fresh sequence every January', 'billio' ); ?>" data-checkbox="billio_number_reset_yearly"></button>
										</div>
										<div class="billio-hint billio-hint-warn" id="billio-reset-warn" <?php echo $reset_yearly ? '' : 'hidden'; ?>>⚠ <?php esc_html_e( 'Turning this on mid-year starts a new sequence immediately. Existing numbers are never reissued.', 'billio' ); ?></div>
									</div>
								</div>
							</div>
						</section>

						<section class="billio-card billio-sec" id="billio-sec-delivery">
							<header>
								<span class="billio-sec-ic"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v16H4z"/><path d="M22 6l-10 7L2 6"/></svg></span>
								<div><h2><?php esc_html_e( 'Email & paper', 'billio' ); ?></h2><p><?php esc_html_e( 'When the PDF is attached, and what size it prints on.', 'billio' ); ?></p></div>
							</header>
							<div class="billio-sec-body">
								<div class="billio-f">
									<div class="billio-lab"><?php esc_html_e( 'Attach invoice when order is', 'billio' ); ?><small><?php esc_html_e( 'Pick at least one', 'billio' ); ?></small></div>
									<div>
										<div class="billio-sw <?php echo $processing_on ? 'billio-sw-on' : ''; ?>" id="billio-sw-processing">
											<div class="billio-sw-t"><?php esc_html_e( 'Processing', 'billio' ); ?><small><?php esc_html_e( 'Payment received — customer gets the invoice right away', 'billio' ); ?></small></div>
											<input type="checkbox" name="billio_email_triggers[]" id="billio-trigger-processing" value="customer_processing_order" tabindex="-1" aria-hidden="true" class="billio-sr-only" <?php checked( $processing_on ); ?>>
											<button type="button" class="billio-tg" role="switch" aria-checked="<?php echo $processing_on ? 'true' : 'false'; ?>" aria-label="<?php esc_attr_e( 'Processing', 'billio' ); ?>" data-checkbox="billio-trigger-processing"></button>
										</div>
										<div class="billio-sw <?php echo $completed_on ? 'billio-sw-on' : ''; ?>" id="billio-sw-completed">
											<div class="billio-sw-t"><?php esc_html_e( 'Completed', 'billio' ); ?><small><?php esc_html_e( 'Order fulfilled — invoice arrives with the shipping email', 'billio' ); ?></small></div>
											<input type="checkbox" name="billio_email_triggers[]" id="billio-trigger-completed" value="customer_completed_order" tabindex="-1" aria-hidden="true" class="billio-sr-only" <?php checked( $completed_on ); ?>>
											<button type="button" class="billio-tg" role="switch" aria-checked="<?php echo $completed_on ? 'true' : 'false'; ?>" aria-label="<?php esc_attr_e( 'Completed', 'billio' ); ?>" data-checkbox="billio-trigger-completed"></button>
										</div>
										<div class="billio-hint billio-hint-bad" id="billio-mail-err" <?php echo ( $processing_on || $completed_on ) ? 'hidden' : ''; ?>>✕ <?php esc_html_e( "No status selected — customers won't receive invoices by email.", 'billio' ); ?></div>
									</div>
								</div>
								<div class="billio-f">
									<div class="billio-lab"><?php esc_html_e( 'Paper size', 'billio' ); ?><small><?php esc_html_e( 'Invoices, packing slips, credit notes', 'billio' ); ?></small></div>
									<div class="billio-paper-fieldset">
										<div class="billio-seg" id="billio-paper-seg" role="radiogroup" aria-label="<?php esc_attr_e( 'Paper size', 'billio' ); ?>">
											<label><input type="radio" name="billio_paper_size" value="A4" class="billio-sr-only" <?php checked( $paper_size, 'A4' ); ?>><button type="button" aria-pressed="<?php echo 'A4' === $paper_size ? 'true' : 'false'; ?>" data-v="A4">A4 <span class="billio-dim"><?php esc_html_e( '210×297', 'billio' ); ?></span></button></label>
											<label><input type="radio" name="billio_paper_size" value="Letter" class="billio-sr-only" <?php checked( $paper_size, 'Letter' ); ?>><button type="button" aria-pressed="<?php echo 'Letter' === $paper_size ? 'true' : 'false'; ?>" data-v="Letter"><?php esc_html_e( 'Letter', 'billio' ); ?> <span class="billio-dim">8.5×11″</span></button></label>
										</div>
									</div>
								</div>
								<div class="billio-f">
									<div class="billio-lab"><?php esc_html_e( 'Thank You page', 'billio' ); ?><small><?php esc_html_e( 'Checkout confirmation', 'billio' ); ?></small></div>
									<div>
										<div class="billio-sw <?php echo $thankyou_down_on ? 'billio-sw-on' : ''; ?>" id="billio-sw-thankyou">
											<div class="billio-sw-t"><?php esc_html_e( 'Show download button', 'billio' ); ?><small><?php esc_html_e( 'Allows buyers to immediately download their invoice on the order received page', 'billio' ); ?></small></div>
											<input type="checkbox" name="billio_show_thankyou_download" id="billio_show_thankyou_download" value="1" tabindex="-1" aria-hidden="true" class="billio-sr-only" <?php checked( $thankyou_down_on ); ?>>
											<button type="button" class="billio-tg" role="switch" aria-checked="<?php echo $thankyou_down_on ? 'true' : 'false'; ?>" aria-label="<?php esc_attr_e( 'Show download button', 'billio' ); ?>" data-checkbox="billio_show_thankyou_download"></button>
										</div>
									</div>
								</div>
								<div class="billio-f">
									<label for="billio_customer_link_validity_days"><?php esc_html_e( 'Customer link validity', 'billio' ); ?><small><?php esc_html_e( 'Days before a My Account / Thank You / share link expires. 0 = never.', 'billio' ); ?></small></label>
									<div>
										<input class="billio-in billio-mono" type="text" inputmode="numeric" style="max-width:120px;" name="billio_customer_link_validity_days" id="billio_customer_link_validity_days" value="<?php echo esc_attr( $link_validity ); ?>">
									</div>
								</div>
								<div class="billio-f">
									<div class="billio-lab"><?php esc_html_e( 'On-demand generation', 'billio' ); ?><small><?php esc_html_e( 'Customer-facing downloads', 'billio' ); ?></small></div>
									<div>
										<div class="billio-sw <?php echo $ondemand_on ? 'billio-sw-on' : ''; ?>" id="billio-sw-ondemand">
											<div class="billio-sw-t"><?php esc_html_e( 'Generate on demand', 'billio' ); ?><small><?php esc_html_e( "Let a customer's first download request create the invoice (e.g. right after checkout) instead of only serving one already generated by the email trigger or an admin", 'billio' ); ?></small></div>
											<input type="checkbox" name="billio_customer_ondemand_generate" id="billio_customer_ondemand_generate" value="1" tabindex="-1" aria-hidden="true" class="billio-sr-only" <?php checked( $ondemand_on ); ?>>
											<button type="button" class="billio-tg" role="switch" aria-checked="<?php echo $ondemand_on ? 'true' : 'false'; ?>" aria-label="<?php esc_attr_e( 'Generate on demand', 'billio' ); ?>" data-checkbox="billio_customer_ondemand_generate"></button>
										</div>
									</div>
								</div>
							</div>
						</section>

						<section class="billio-card billio-sec" id="billio-sec-design">
							<header>
								<span class="billio-sec-ic"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg></span>
								<div><h2><?php esc_html_e( 'Design', 'billio' ); ?></h2><p><?php esc_html_e( 'Choose a template and match it to your brand.', 'billio' ); ?></p></div>
							</header>
							<div class="billio-sec-body">
								<div class="billio-f">
									<div class="billio-lab"><?php esc_html_e( 'Template', 'billio' ); ?></div>
									<div class="billio-settings-tpls" id="billio-tpl-group" role="radiogroup" aria-label="<?php esc_attr_e( 'Invoice template', 'billio' ); ?>">
										<label class="billio-settings-tpl <?php echo 'default' === $template ? 'billio-settings-tpl-on' : ''; ?>">
											<input type="radio" name="billio_template" value="default" class="billio-sr-only" data-v="default" <?php checked( $template, 'default' ); ?>>
											<div class="billio-settings-mini"><i style="width:35%;height:9px;background:#334155"></i><i style="height:1.5px;background:#334155"></i><i></i><i style="width:70%"></i><i></i><i style="width:50%;margin-left:auto"></i></div>
											<?php esc_html_e( 'Default', 'billio' ); ?>
											<div class="billio-settings-tpl-desc"><?php esc_html_e( 'Clean, black & white', 'billio' ); ?></div>
										</label>
										<label class="billio-settings-tpl <?php echo 'modern' === $template ? 'billio-settings-tpl-on' : ''; ?>">
											<input type="radio" name="billio_template" value="modern" class="billio-sr-only" data-v="modern" <?php checked( $template, 'modern' ); ?>>
											<div class="billio-settings-mini"><span class="billio-settings-mini-hd"></span><i></i><i style="width:70%"></i><i></i><i style="width:50%;margin-left:auto"></i></div>
											<?php esc_html_e( 'Modern', 'billio' ); ?>
											<div class="billio-settings-tpl-desc"><?php esc_html_e( 'Color header band', 'billio' ); ?></div>
										</label>
									</div>
								</div>
								<div class="billio-f <?php echo 'default' === $template ? 'billio-dim-row' : ''; ?>" id="billio-accent-row">
									<div class="billio-lab"><?php esc_html_e( 'Accent color', 'billio' ); ?><small><?php esc_html_e( 'Modern template only', 'billio' ); ?></small></div>
									<div class="billio-swatches" id="billio-swatches">
										<?php foreach ( $swatches as $swatch ) : ?>
											<button type="button" class="billio-swc <?php echo strtolower( $accent_color ) === strtolower( $swatch ) ? 'billio-swc-on' : ''; ?>" style="background:<?php echo esc_attr( $swatch ); ?>" data-c="<?php echo esc_attr( $swatch ); ?>" aria-label="<?php echo esc_attr( $swatch ); ?>"></button>
										<?php endforeach; ?>
										<label class="billio-cust">
											<input type="color" id="billio-accent-picker" value="<?php echo esc_attr( $accent_color ); ?>">
											<span id="billio-accent-hex"><?php echo esc_html( strtoupper( $accent_color ) ); ?></span>
										</label>
										<input type="text" class="billio-color-field" name="billio_accent_color" id="billio_accent_color" value="<?php echo esc_attr( $accent_color ); ?>" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;">
									</div>
								</div>
								<div class="billio-f">
									<div class="billio-lab"><?php esc_html_e( 'Watermark', 'billio' ); ?><small><?php esc_html_e( 'Paid orders only', 'billio' ); ?></small></div>
									<div>
										<div class="billio-sw <?php echo $watermark_paid ? 'billio-sw-on' : ''; ?>" id="billio-sw-watermark">
											<div class="billio-sw-t"><?php esc_html_e( 'PAID stamp', 'billio' ); ?><small><?php esc_html_e( 'Display an angled, semi-transparent PAID watermark across the invoice', 'billio' ); ?></small></div>
											<input type="checkbox" name="billio_watermark_paid" id="billio_watermark_paid" value="1" tabindex="-1" aria-hidden="true" class="billio-sr-only" <?php checked( $watermark_paid ); ?>>
											<button type="button" class="billio-tg" role="switch" aria-checked="<?php echo $watermark_paid ? 'true' : 'false'; ?>" aria-label="<?php esc_attr_e( 'PAID stamp', 'billio' ); ?>" data-checkbox="billio_watermark_paid"></button>
										</div>
									</div>
								</div>
							</div>
						</section>

						<section class="billio-card billio-sec" id="billio-sec-advanced">
							<header>
								<span class="billio-sec-ic"><svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 18l6-6-6-6M8 6l-6 6 6 6"/></svg></span>
								<div><h2><?php esc_html_e( 'Advanced', 'billio' ); ?></h2><p><?php esc_html_e( 'For developers and theme authors.', 'billio' ); ?></p></div>
							</header>
							<details>
								<summary><?php esc_html_e( 'Override templates from your theme', 'billio' ); ?></summary>
								<p class="billio-advanced-note"><?php esc_html_e( 'Copy any file into your theme and Billio will use it instead of the bundled one:', 'billio' ); ?></p>
								<div class="billio-code">
									<?php foreach ( array( 'default', 'modern', 'packing-slip', 'credit-note', 'partials/invoice-lines' ) as $slug ) : ?>
										<?php $overridden = (bool) locate_template( array( 'billio/' . $slug . '.php' ) ); ?>
										yourtheme/<span>billio/</span><?php echo esc_html( $slug ); ?>.php
										<?php
										if ( $overridden ) :
											?>
											<span class="billio-overridden-badge"><?php esc_html_e( 'Overridden by theme', 'billio' ); ?></span><?php endif; ?><br>
									<?php endforeach; ?>
								</div>
							</details>
						</section>

					</div>

					<!-- live preview -->
					<aside class="billio-settings-pv">
						<div class="billio-pvh">
							<span class="billio-pvh-t"><span class="billio-live-dot"></span><?php esc_html_e( 'Live preview', 'billio' ); ?></span>
							<span class="billio-sp"></span>
							<div class="billio-seg" id="billio-doc-seg" role="radiogroup" aria-label="<?php esc_attr_e( 'Preview document type', 'billio' ); ?>">
								<button type="button" aria-pressed="true" data-v="inv"><?php esc_html_e( 'Invoice', 'billio' ); ?></button>
								<button type="button" aria-pressed="false" data-v="cn"><?php esc_html_e( 'Credit note', 'billio' ); ?></button>
								<button type="button" aria-pressed="false" data-v="ps"><?php esc_html_e( 'Packing slip', 'billio' ); ?></button>
							</div>
						</div>
						<div class="billio-paperwrap">
							<iframe id="billio-preview-frame" title="<?php esc_attr_e( 'Live invoice preview', 'billio' ); ?>" src="<?php echo esc_url( $preview_url ); ?>" class="<?php echo 'Letter' === $paper_size ? 'billio-letter' : ''; ?>"></iframe>
						</div>
					</aside>
				</div>
			</form>
		</div>

		<div class="billio-savebar" id="billio-savebar">
			<div class="billio-savebar-t">
				<b id="billio-savebar-count"></b>
				<small><?php esc_html_e( 'Press', 'billio' ); ?> <span class="billio-kbd">Ctrl</span> + <span class="billio-kbd">S</span> <?php esc_html_e( 'to save', 'billio' ); ?></small>
			</div>
			<button type="button" class="billio-btn billio-ghost" id="billio-discard"><?php esc_html_e( 'Discard', 'billio' ); ?></button>
			<button type="button" class="billio-btn billio-pri" id="billio-savebar-save"><?php esc_html_e( 'Save changes', 'billio' ); ?></button>
		</div>
		<?php
	}

	/**
	 * admin-post handler: render the live preview iframe content as plain
	 * HTML (not a PDF) against the real template files + sample data, so
	 * the preview is instant and always matches what Dompdf will produce.
	 * The PDF renderer (Generator/CreditNoteGenerator + Dompdf) remains the
	 * source of truth for the actual printed output — this is a same-markup
	 * stand-in for instant feedback, not a separate reimplementation.
	 *
	 * @return void
	 */
	public function render_preview() {
		check_admin_referer( 'billio_preview' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'billio' ), '', array( 'response' => 403 ) );
		}

		// Temporarily override options from the (unsaved) query-string values
		// so the preview reflects what's currently in the form, not just what
		// was last saved. Every template field (incl. accent color, which the
		// "modern" template reads straight from get_option()) goes through
		// get_option(), so a short-lived pre_option_* filter covers all of
		// them without threading overrides through TemplateEngine itself.
		$callbacks = array();
		foreach ( Fields::get() as $field ) {
			$option = $field['option'];
			if ( ! isset( $_GET[ $option ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via Fields::sanitize() below.
			$value = Fields::sanitize( $field, wp_unslash( $_GET[ $option ] ) );

			// The unsaved logo: only ever an image attachment (0 = removed) —
			// never let an arbitrary attachment ID get base64-inlined.
			if ( 'media' === $field['type'] && $value && ! wp_attachment_is_image( $value ) ) {
				continue;
			}

			// A pre_option_* filter returning false means "no override" to
			// WordPress, so an unchecked box would silently fall back to the
			// saved (checked) value — return 0 instead, which reads as off.
			if ( false === $value ) {
				$value = 0;
			}

			$callback             = static function () use ( $value ) {
				return $value;
			};
			$callbacks[ $option ] = $callback;
			add_filter( 'pre_option_' . $option, $callback );
		}

		$doc = isset( $_GET['doc'] ) ? sanitize_key( wp_unslash( $_GET['doc'] ) ) : 'inv';
		if ( ! in_array( $doc, array( 'inv', 'cn', 'ps' ), true ) ) {
			$doc = 'inv';
		}

		$sample_order = SampleOrder::make();

		if ( 'cn' === $doc ) {
			$sample_number = NumberManager::preview_number( 1, 'credit_note' );
			// In-memory only (never saved) — gives the "For Invoice #" line
			// in the credit-note template something realistic to show.
			$sample_order->update_meta_data( '_billio_invoice_number', NumberManager::preview_number() );
			$sample_refund = SampleOrder::make_refund( $sample_order );
			echo TemplateEngine::render_credit_note( $sample_order, $sample_refund, $sample_number, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'ps' === $doc ) {
			echo TemplateEngine::render_packing_slip( $sample_order, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			$sample_number = NumberManager::preview_number();
			// false: this is HTML for the browser, not Dompdf — see TemplateEngine::render().
			echo TemplateEngine::render( $sample_order, $sample_number, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		foreach ( $callbacks as $option => $callback ) {
			remove_filter( 'pre_option_' . $option, $callback );
		}

		exit;
	}
}
