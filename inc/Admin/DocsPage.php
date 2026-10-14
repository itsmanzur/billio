<?php
/**
 * Interactive Docs & Features dedicated page.
 *
 * Provides a user-friendly overview of Billio features, SEO identity,
 * and a step-by-step non-technical user guide for store owners.
 *
 * @package Billio
 */

namespace Billio\Admin;

use Billio\Admin\Dashboard;
use Billio\Settings\SettingsPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DocsPage {

	const MENU_SLUG = 'billio-docs';

	/**
	 * @var string Hook suffix returned by add_submenu_page().
	 */
	private $hook_suffix = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . BILLIO_BASENAME, array( $this, 'add_action_link' ), 20 );
	}

	/**
	 * Register "Docs & Features" under the Billio admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = add_submenu_page(
			Dashboard::MENU_SLUG,
			esc_html__( 'Billio Docs & Features', 'billio' ),
			esc_html__( 'Docs & Features', 'billio' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue assets only on the Docs & Features page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'billio-admin-base', BILLIO_URL . 'assets/css/admin-base.css', array(), BILLIO_VERSION );
		wp_enqueue_style( 'billio-admin-docs', BILLIO_URL . 'assets/css/admin-docs.css', array( 'billio-admin-base' ), BILLIO_VERSION );
		wp_enqueue_script( 'billio-admin-docs', BILLIO_URL . 'assets/js/admin-docs.js', array(), BILLIO_VERSION, true );
	}

	/**
	 * Add "Docs" link to the plugin row on Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function add_action_link( $links ) {
		$docs_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Docs & Features', 'billio' )
		);

		$links[] = $docs_link;

		return $links;
	}

	/**
	 * Render the Docs & Features admin page.
	 *
	 * @return void
	 */
	public function render() {
		$settings_url = admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG );
		$orders_url   = admin_url( 'admin.php?page=wc-orders' );
		?>
		<div class="wrap billio-ui billio-docs-wrap">

			<!-- Hero Header -->
			<div class="billio-docs-hero">
				<div class="billio-docs-hero-badge">
					<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
					<?php esc_html_e( 'Official Documentation & Feature Tour', 'billio' ); ?>
				</div>
				<h1><?php esc_html_e( 'Billio — WooCommerce PDF Invoicing & Automated Billing Suite', 'billio' ); ?></h1>
				<p>
					<?php esc_html_e( 'The lightweight, high-performance, and gapless PDF invoicing plugin for WooCommerce. Automatically create, brand, and send PDF invoices, credit notes, and packing slips to your customers with reliable accounting and sequential numbering.', 'billio' ); ?>
				</p>
				<div class="billio-docs-hero-actions">
					<a href="<?php echo esc_url( $settings_url ); ?>" class="billio-docs-hero-btn">
						<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
						<?php esc_html_e( 'Configure Billio Settings', 'billio' ); ?>
					</a>
					<a href="<?php echo esc_url( $orders_url ); ?>" class="billio-docs-hero-btn secondary">
						<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
						<?php esc_html_e( 'View WooCommerce Orders', 'billio' ); ?>
					</a>
				</div>
			</div>

			<!-- Tab Navigation -->
			<div class="billio-docs-nav-tabs" role="tablist">
				<button type="button" class="billio-docs-tab-btn active" data-tab="tab-features" role="tab" aria-selected="true">
					<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
					<?php esc_html_e( 'Features & Overview', 'billio' ); ?>
					<span class="billio-tab-badge"><?php esc_html_e( '15 Features', 'billio' ); ?></span>
				</button>
				<button type="button" class="billio-docs-tab-btn" data-tab="tab-docs" role="tab" aria-selected="false">
					<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
					<?php esc_html_e( 'Non-Tech User Guide', 'billio' ); ?>
					<span class="billio-tab-badge"><?php esc_html_e( 'Easy Steps', 'billio' ); ?></span>
				</button>
			</div>

			<!-- Live Search & Filter Bar -->
			<div class="billio-docs-filter-bar">
				<div class="billio-docs-search">
					<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
					<input type="text" id="billio-docs-search-input" placeholder="<?php esc_attr_e( 'Search features, topics, or FAQs...', 'billio' ); ?>">
				</div>
				<div class="billio-category-pills">
					<button type="button" class="billio-pill-btn active" data-category="all"><?php esc_html_e( 'All', 'billio' ); ?></button>
					<button type="button" class="billio-pill-btn" data-category="automation"><?php esc_html_e( 'Automation', 'billio' ); ?></button>
					<button type="button" class="billio-pill-btn" data-category="design"><?php esc_html_e( 'Templates & Branding', 'billio' ); ?></button>
					<button type="button" class="billio-pill-btn" data-category="customer"><?php esc_html_e( 'Customer Experience', 'billio' ); ?></button>
					<button type="button" class="billio-pill-btn" data-category="compliance"><?php esc_html_e( 'Accounting & Compliance', 'billio' ); ?></button>
					<button type="button" class="billio-pill-btn" data-category="pro"><?php esc_html_e( 'Pro Roadmap', 'billio' ); ?></button>
				</div>
			</div>

			<!-- TAB 1: Features & SEO Overview -->
			<div id="tab-features" class="billio-docs-tab-panel active" role="tabpanel">
				<div class="billio-features-grid">

					<!-- Feature 1 -->
					<div class="billio-feature-card" data-category="automation">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
							</div>
							<span class="billio-feature-badge free"><?php esc_html_e( 'Free Core', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'Automated Email Invoicing', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( 'Hands-free delivery on order completion', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'Automatically generates and attaches branded PDF invoices to WooCommerce Processing and Completed order emails. Zero manual work required.', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'WooCommerce Emails', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Zero Admin Work', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'PDF Attachment', 'billio' ); ?></span>
						</div>
					</div>

					<!-- Feature 2 -->
					<div class="billio-feature-card" data-category="automation">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
							</div>
							<span class="billio-feature-badge free"><?php esc_html_e( 'Free Core', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'Automated Credit Notes', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( 'Order refund compliance', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'Whenever an order is refunded, Billio automatically creates an official sequential Credit Note PDF and attaches it to the refund email.', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'Refund Compliance', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Credit Notes', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Audit-Proof', 'billio' ); ?></span>
						</div>
					</div>

					<!-- Feature 3 -->
					<div class="billio-feature-card" data-category="customer">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
							</div>
							<span class="billio-feature-badge free"><?php esc_html_e( 'Free Core', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'Thank You Page Download', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( 'Direct 1-click download after checkout', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'A prominent "Download Invoice (PDF)" button appears directly on the checkout Order Received screen right after purchase.', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'Instant Download', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Checkout UX', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( '1-Click PDF', 'billio' ); ?></span>
						</div>
					</div>

					<!-- Feature 4 -->
					<div class="billio-feature-card" data-category="customer">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
							</div>
							<span class="billio-feature-badge free"><?php esc_html_e( 'Free Core', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'Guest & My Account Access', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( 'Secure downloads for members and guests', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'Registered users can download invoices from their My Account orders list. Guest buyers can securely download using the order key.', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'Guest Friendly', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'My Account', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Secure Access', 'billio' ); ?></span>
						</div>
					</div>

					<!-- Feature 5 -->
					<div class="billio-feature-card" data-category="design">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 14 14"/></svg>
							</div>
							<span class="billio-feature-badge free"><?php esc_html_e( 'Free Core', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'PAID Watermark Stamp', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( 'Professional paid stamp overlay', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'Optionally display a modern, angled "PAID" stamp across fulfilled invoices. Easily toggleable with live preview in the settings page.', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'Watermark', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Paid Stamp', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Professional', 'billio' ); ?></span>
						</div>
					</div>

					<!-- Feature 6 -->
					<div class="billio-feature-card" data-category="design">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
							</div>
							<span class="billio-feature-badge free"><?php esc_html_e( 'Free Core', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'Modern & Default Templates', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( 'Customizable layouts and branding', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'Choose between clean Default and sleek Modern layouts. Customize brand accent color, upload company logo, and set paper size (A4 / Letter).', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'Brand Color', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'A4 & Letter', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Logo Upload', 'billio' ); ?></span>
						</div>
					</div>

					<!-- Feature 7 -->
					<div class="billio-feature-card" data-category="compliance">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/></svg>
							</div>
							<span class="billio-feature-badge free"><?php esc_html_e( 'Free Core', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'Gapless Sequential Numbering', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( 'Sequential invoice numbering', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'Lock-guarded atomic numbering ensures duplicate-free numbers without gaps. Fully customizable with {PREFIX}, {YEAR}, and {NUMBER} tokens.', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'Atomic Lock', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'No Gaps', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Custom Tokens', 'billio' ); ?></span>
						</div>
					</div>

					<!-- Feature 8 -->
					<div class="billio-feature-card" data-category="compliance">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
							</div>
							<span class="billio-feature-badge free"><?php esc_html_e( 'Free Core', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'Warehouse Packing Slips', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( '1-click shipping slips', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'Streamlined shipping slips for order fulfillment without displaying product prices. Generated on demand without keeping temporary files.', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'Warehouse Ready', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Price Free', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Order Edit Action', 'billio' ); ?></span>
						</div>
					</div>

					<!-- Feature 9 -->
					<div class="billio-feature-card" data-category="design">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
							</div>
							<span class="billio-feature-badge free"><?php esc_html_e( 'Free Core', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'Bengali (Bangla) Script Support', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( 'Noto Sans Bengali with vowel sign positioning', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'Includes bundled Noto Sans Bengali font and vowel-sign reordering (such as split vowels o-kar and ou-kar). Complex consonant conjuncts have known font rendering limitations (see FAQ).', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'Noto Sans Bengali', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Vowel Correction', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Bangla Support', 'billio' ); ?></span>
						</div>
					</div>

					<!-- Feature 10 -->
					<div class="billio-feature-card" data-category="automation">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>
							</div>
							<span class="billio-feature-badge free"><?php esc_html_e( 'Free Core', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'Real-Time Revenue Dashboard', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( 'Revenue KPIs and recent activity', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'Monitor monthly revenue KPIs, document counts, 7/30/90-day activity trends, and search recent documents with optimized queries.', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'Fast Analytics', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Document Search', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Optimized Queries', 'billio' ); ?></span>
						</div>
					</div>

					<!-- Feature 11 -->
					<div class="billio-feature-card" data-category="compliance">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
							</div>
							<span class="billio-feature-badge free"><?php esc_html_e( 'Free Core', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'B2B Customer Tax ID (VAT / BIN / GSTIN)', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( 'Seamless checkout field & invoice display', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'Collect customer VAT, BIN, TRN, or GSTIN numbers during checkout with full Classic and Block Checkout support, editable from the admin order screen.', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'B2B Checkout', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Block & Classic', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Tax ID on PDF', 'billio' ); ?></span>
						</div>
					</div>

					<!-- Feature 12 -->
					<div class="billio-feature-card" data-category="customer">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
							</div>
							<span class="billio-feature-badge free"><?php esc_html_e( 'Free Core', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'Slide-Over Preview Drawer & Instant Actions', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( 'Zero page reload live PDF viewing & sharing', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'Preview live invoice PDFs directly from the dashboard or orders list in an elegant slide-over drawer. Share invoices to customers via WhatsApp in 1 click, print directly, copy download link, or resend invoice emails.', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'Slide-Over Drawer', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'WhatsApp Sharing', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( '1-Click Print', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Copy Link', 'billio' ); ?></span>
						</div>
					</div>

					<!-- Feature 13 -->
					<div class="billio-feature-card" data-category="automation">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
							</div>
							<span class="billio-feature-badge free"><?php esc_html_e( 'Free Core', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'Smart Setup Checklist & Quick Actions Hub', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( 'Animated onboarding & instant dashboard controls', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'Guides store owners through essential setup steps with a live SVG radial progress ring. Quick Actions hub allows instant sample invoice preview, 1-click batch generation of missing invoices, and real-time AJAX toggles for B2B Tax ID and PAID watermark.', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'Radial Progress', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Onboarding Checklist', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Quick Actions', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Instant Toggles', 'billio' ); ?></span>
						</div>
					</div>

					<!-- Feature 14 (Pro) -->
					<div class="billio-feature-card pro-card" data-category="pro">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
							</div>
							<span class="billio-feature-badge pro"><?php esc_html_e( 'Pro Roadmap', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'Bulk ZIP Download & Batch Print', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( 'Download or print multiple orders at once', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'Download dozens of invoices in a single ZIP archive or merge selected invoices into a single multi-page PDF for instant batch warehouse printing.', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'Bulk Actions', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'ZIP Archive', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Batch Print', 'billio' ); ?></span>
						</div>
					</div>

					<!-- Feature 12 (Pro) -->
					<div class="billio-feature-card pro-card" data-category="pro">
						<div class="billio-feature-card-header">
							<div class="billio-feature-icon">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
							</div>
							<span class="billio-feature-badge pro"><?php esc_html_e( 'Pro Roadmap', 'billio' ); ?></span>
						</div>
						<h3><?php esc_html_e( 'Dynamic QR Codes & Electronic Invoicing', 'billio' ); ?></h3>
						<div class="billio-feature-subtitle"><?php esc_html_e( 'Payment and tax authority QR codes', 'billio' ); ?></div>
						<p class="billio-feature-desc">
							<?php esc_html_e( 'Embed payment QR codes (bKash, Nagad, UPI) and explore electronic invoicing formats (including planned ZATCA e-invoicing support).', 'billio' ); ?>
						</p>
						<div class="billio-feature-tags">
							<span class="billio-feature-tag"><?php esc_html_e( 'Planned ZATCA', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'Payment QR', 'billio' ); ?></span>
							<span class="billio-feature-tag"><?php esc_html_e( 'E-Invoicing', 'billio' ); ?></span>
						</div>
					</div>

				</div>
			</div>

			<!-- TAB 2: Non-Tech User Guide -->
			<div id="tab-docs" class="billio-docs-tab-panel" role="tabpanel">
				<div class="billio-docs-guide-container">

					<!-- Sidebar Navigation -->
					<div class="billio-docs-sidebar">
						<h4><?php esc_html_e( 'Quick Guide Topics', 'billio' ); ?></h4>
						<ul class="billio-docs-menu">
							<li><a href="#step-setup" class="active"><?php esc_html_e( '1. Quick 3-Minute Setup', 'billio' ); ?></a></li>
							<li><a href="#step-numbering"><?php esc_html_e( '2. Invoice Numbering Format', 'billio' ); ?></a></li>
							<li><a href="#step-delivery"><?php esc_html_e( '3. How Customers Receive Invoices', 'billio' ); ?></a></li>
							<li><a href="#step-tax-id"><?php esc_html_e( '4. B2B Customer Tax ID (VAT / BIN / GSTIN)', 'billio' ); ?></a></li>
							<li><a href="#step-preview-drawer"><?php esc_html_e( '5. Live Preview Drawer & 1-Click Sharing', 'billio' ); ?></a></li>
							<li><a href="#step-refunds"><?php esc_html_e( '6. Order Refunds & Credit Notes', 'billio' ); ?></a></li>
							<li><a href="#step-packing"><?php esc_html_e( '7. Warehouse Packing Slips', 'billio' ); ?></a></li>
							<li><a href="#step-faq"><?php esc_html_e( '8. Frequently Asked Questions (FAQ)', 'billio' ); ?></a></li>
							<li><a href="#step-system"><?php esc_html_e( '9. System Health & Diagnostics', 'billio' ); ?></a></li>
						</ul>
					</div>

					<!-- Guide Body Content -->
					<div class="billio-docs-body">

						<!-- Step 1 -->
						<div id="step-setup" class="billio-guide-card">
							<div class="billio-guide-card-header">
								<span class="billio-step-num">1</span>
								<h3><?php esc_html_e( 'Getting Started: Quick 3-Minute Setup', 'billio' ); ?></h3>
							</div>
							<p class="billio-guide-text">
								<?php esc_html_e( 'After activating the plugin, you can set up professional invoicing for your store in just 3 minutes without writing any code:', 'billio' ); ?>
							</p>
							<ol class="billio-guide-list">
								<li><strong><?php esc_html_e( 'Go to Settings:', 'billio' ); ?></strong> <?php esc_html_e( 'Click on Billio > Settings in your WordPress admin menu.', 'billio' ); ?></li>
								<li><strong><?php esc_html_e( 'Enter Company Info:', 'billio' ); ?></strong> <?php esc_html_e( 'In the Company Info card, enter your store name, business address, and contact or tax identification number.', 'billio' ); ?></li>
								<li><strong><?php esc_html_e( 'Upload Brand Logo:', 'billio' ); ?></strong> <?php esc_html_e( 'Drag and drop your company logo into the Company Logo box. It will be positioned automatically at the top of your invoices.', 'billio' ); ?></li>
								<li><strong><?php esc_html_e( 'Save Changes:', 'billio' ); ?></strong> <?php esc_html_e( 'Click the "Save settings" button at the bottom of the page. Your invoices are now ready!', 'billio' ); ?></li>
							</ol>
							<div class="billio-tip-box">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
								<div><strong><?php esc_html_e( 'Pro Tip:', 'billio' ); ?></strong> <?php esc_html_e( 'Using a logo with a transparent background (PNG) gives your invoices a clean and modern look.', 'billio' ); ?></div>
							</div>
						</div>

						<!-- Step 2 -->
						<div id="step-numbering" class="billio-guide-card">
							<div class="billio-guide-card-header">
								<span class="billio-step-num">2</span>
								<h3><?php esc_html_e( 'Customizing Invoice Numbers', 'billio' ); ?></h3>
							</div>
							<p class="billio-guide-text">
								<?php esc_html_e( 'Billio keeps track of sequential invoice numbers (e.g., 0001, 0002, 0003) automatically. You can customize the pattern using standard tags:', 'billio' ); ?>
							</p>
							<ul class="billio-guide-list">
								<li><code>{PREFIX}</code>: <?php esc_html_e( 'Your invoice prefix (e.g. INV or BILL).', 'billio' ); ?></li>
								<li><code>{YEAR}</code>: <?php esc_html_e( 'The current calendar year (e.g. 2026).', 'billio' ); ?></li>
								<li><code>{NUMBER}</code>: <?php esc_html_e( 'The sequential order counter padded with zeroes (e.g. 0001).', 'billio' ); ?></li>
							</ul>
							<div class="billio-sample-code">
								<span><?php esc_html_e( 'Example format:', 'billio' ); ?> <code>{PREFIX}-{YEAR}-{NUMBER}</code></span>
								<span style="color:#94a3b8;"><?php esc_html_e( 'Result: INV-2026-0001', 'billio' ); ?></span>
							</div>
							<p class="billio-guide-text">
								<strong><?php esc_html_e( 'Reset yearly:', 'billio' ); ?></strong> <?php esc_html_e( 'When enabled, invoice counting resets back to 1 every January (e.g., INV-2026-0001, INV-2027-0001). When disabled, numbers continue sequentially without resetting.', 'billio' ); ?>
							</p>
						</div>

						<!-- Step 3 -->
						<div id="step-delivery" class="billio-guide-card">
							<div class="billio-guide-card-header">
								<span class="billio-step-num">3</span>
								<h3><?php esc_html_e( 'How Customers Receive Invoices', 'billio' ); ?></h3>
							</div>
							<p class="billio-guide-text">
								<?php esc_html_e( 'Your store customers can access their PDF invoices through 3 convenient delivery methods:', 'billio' ); ?>
							</p>
							<ol class="billio-guide-list">
								<li><strong><?php esc_html_e( 'Automated Email Attachments:', 'billio' ); ?></strong> <?php esc_html_e( 'When an order is marked Processing or Completed, WooCommerce automatically attaches the PDF invoice to the customer notification email.', 'billio' ); ?></li>
								<li><strong><?php esc_html_e( 'Thank You Page Download:', 'billio' ); ?></strong> <?php esc_html_e( 'Immediately after placing an order, customers can click the "Download Invoice (PDF)" button on the Order Received confirmation screen.', 'billio' ); ?></li>
								<li><strong><?php esc_html_e( 'My Account Order History:', 'billio' ); ?></strong> <?php esc_html_e( 'Registered shoppers can log in anytime and download past invoices directly from their order list.', 'billio' ); ?></li>
							</ol>
							<div class="billio-tip-box">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
								<div><strong><?php esc_html_e( 'Guest Shoppers:', 'billio' ); ?></strong> <?php esc_html_e( 'Customers who checkout as guests can also securely download their invoice directly from the Thank You page using their unique order key.', 'billio' ); ?></div>
							</div>
						</div>

						<!-- Step 4 -->
						<div id="step-tax-id" class="billio-guide-card">
							<div class="billio-guide-card-header">
								<span class="billio-step-num">4</span>
								<h3><?php esc_html_e( 'B2B Customer Tax ID (VAT / BIN / GSTIN) at Checkout', 'billio' ); ?></h3>
							</div>
							<p class="billio-guide-text">
								<?php esc_html_e( 'If you sell to corporate, business, or wholesale clients, collecting their business tax identification number (such as VAT, BIN, TRN, or GSTIN) is essential for official tax invoicing:', 'billio' ); ?>
							</p>
							<ol class="billio-guide-list">
								<li><strong><?php esc_html_e( 'Enable at Checkout:', 'billio' ); ?></strong> <?php esc_html_e( 'Go to Billio > Settings > B2B Customer Tax ID card (or use the quick toggle directly in the Dashboard "At a glance" card) and check "Enable Tax ID at checkout".', 'billio' ); ?></li>
								<li><strong><?php esc_html_e( 'Custom Label & Field Requirement:', 'billio' ); ?></strong> <?php esc_html_e( 'Set your custom field label (e.g., "VAT / BIN Number" or "TRN / GSTIN") and choose whether this field is Optional or Required for your customers.', 'billio' ); ?></li>
								<li><strong><?php esc_html_e( 'Full Block & Classic Checkout Compatibility:', 'billio' ); ?></strong> <?php esc_html_e( 'Billio automatically registers the input field on modern WooCommerce Block Checkout as well as Classic shortcode checkout without requiring extra add-ons.', 'billio' ); ?></li>
								<li><strong><?php esc_html_e( 'Automatic Display on Invoices:', 'billio' ); ?></strong> <?php esc_html_e( 'When entered by the buyer, their Tax ID is automatically printed under the customer billing address on PDF Invoices and Packing Slips, and can be viewed or edited in the WooCommerce Order Edit screen.', 'billio' ); ?></li>
							</ol>
							<div class="billio-tip-box">
								<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
								<div><strong><?php esc_html_e( 'B2B & B2C Stores:', 'billio' ); ?></strong> <?php esc_html_e( 'If you cater to both general consumers and businesses, keep the field Optional so regular retail shoppers can checkout without interruption.', 'billio' ); ?></div>
							</div>
						</div>

						<!-- Step 5 -->
						<div id="step-preview-drawer" class="billio-guide-card">
							<div class="billio-guide-card-header">
								<span class="billio-step-num">5</span>
								<h3><?php esc_html_e( 'Slide-Over Preview Drawer & 1-Click Sharing', 'billio' ); ?></h3>
							</div>
							<p class="billio-guide-text">
								<?php esc_html_e( 'Inspect invoices in real-time and share them directly with customers without leaving your admin dashboard:', 'billio' ); ?>
							</p>
							<ul class="billio-guide-list">
								<li><strong><?php esc_html_e( 'Zero-Reload Slide-Over Drawer:', 'billio' ); ?></strong> <?php esc_html_e( 'Click the preview icon on any order in the Recent Documents table, or click "Live Invoice Preview" in the Quick Actions bar. The drawer slides open smoothly without refreshing the page.', 'billio' ); ?></li>
								<li><strong><?php esc_html_e( '1-Click WhatsApp Sharing:', 'billio' ); ?></strong> <?php esc_html_e( 'Click the green "WhatsApp" button in the drawer. Billio creates a polite, pre-formatted message with order details and the secure customer PDF download link ready to send via WhatsApp Web or mobile app.', 'billio' ); ?></li>
								<li><strong><?php esc_html_e( '1-Click Print & Link Copy:', 'billio' ); ?></strong> <?php esc_html_e( 'Send the document straight to your printer using the "Print" button, or copy the direct private download link to your clipboard with "Copy Link".', 'billio' ); ?></li>
								<li><strong><?php esc_html_e( 'Resend Invoice Email:', 'billio' ); ?></strong> <?php esc_html_e( 'If a customer requests their invoice again, simply click "Resend" in the Recent Documents table to dispatch a fresh email with the PDF attached.', 'billio' ); ?></li>
							</ul>
						</div>

						<!-- Step 6 -->
						<div id="step-refunds" class="billio-guide-card">
							<div class="billio-guide-card-header">
								<span class="billio-step-num">6</span>
								<h3><?php esc_html_e( 'Refunds & Credit Notes', 'billio' ); ?></h3>
							</div>
							<p class="billio-guide-text">
								<?php esc_html_e( 'When an order is refunded, maintaining proper accounting records requires issuing an official credit note:', 'billio' ); ?>
							</p>
							<ul class="billio-guide-list">
								<li><?php esc_html_e( 'Open the order in WooCommerce > Orders and process the refund using the standard Refund button.', 'billio' ); ?></li>
								<li><?php esc_html_e( 'Billio automatically generates a separate Credit Note document with sequential numbering (e.g., CN-0001).', 'billio' ); ?></li>
								<li><?php esc_html_e( 'The Credit Note PDF is attached automatically to the customer refund email.', 'billio' ); ?></li>
							</ul>
						</div>

						<!-- Step 7 -->
						<div id="step-packing" class="billio-guide-card">
							<div class="billio-guide-card-header">
								<span class="billio-step-num">7</span>
								<h3><?php esc_html_e( 'Warehouse Packing Slips', 'billio' ); ?></h3>
							</div>
							<p class="billio-guide-text">
								<?php esc_html_e( 'Packing slips simplify order fulfillment by providing a clean list of items for warehouse staff and couriers:', 'billio' ); ?>
							</p>
							<ul class="billio-guide-list">
								<li><?php esc_html_e( 'Go to WooCommerce > Orders and click on any order to open its details.', 'billio' ); ?></li>
								<li><?php esc_html_e( 'In the "Billio Invoice" sidebar box, click the "Packing Slip" button.', 'billio' ); ?></li>
								<li><?php esc_html_e( 'A clean packing slip opens in your browser, ready to print without showing item prices.', 'billio' ); ?></li>
							</ul>
						</div>

						<!-- Step 8: FAQ -->
						<div id="step-faq" class="billio-guide-card">
							<div class="billio-guide-card-header">
								<span class="billio-step-num">8</span>
								<h3><?php esc_html_e( 'Frequently Asked Questions (FAQ)', 'billio' ); ?></h3>
							</div>

							<div class="billio-faq-item active">
								<button type="button" class="billio-faq-trigger" aria-expanded="true">
									<span><?php esc_html_e( 'Why are customers not receiving invoice attachments in their emails?', 'billio' ); ?></span>
									<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
								</button>
								<div class="billio-faq-content">
									<?php esc_html_e( 'Check two settings: 1) Go to Billio > Settings and ensure the "Processing" and/or "Completed" email checkboxes are selected under Email & Paper. 2) Go to WooCommerce > Settings > Emails and verify that customer order notification emails are enabled.', 'billio' ); ?>
								</div>
							</div>

							<div class="billio-faq-item">
								<button type="button" class="billio-faq-trigger" aria-expanded="false">
									<span><?php esc_html_e( 'How do I display a "PAID" watermark stamp on the invoice?', 'billio' ); ?></span>
									<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
								</button>
								<div class="billio-faq-content">
									<?php esc_html_e( 'Go to Billio > Settings > Invoice Styling card. Check the "PAID watermark" checkbox and click Save settings. Fulfilled orders will display a subtle, angled PAID stamp.', 'billio' ); ?>
								</div>
							</div>

							<div class="billio-faq-item">
								<button type="button" class="billio-faq-trigger" aria-expanded="false">
									<span><?php esc_html_e( 'Can I send invoices to customers via WhatsApp?', 'billio' ); ?></span>
									<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
								</button>
								<div class="billio-faq-content">
									<?php esc_html_e( 'Yes! In the Billio Dashboard under Recent Documents, click the preview icon on any order to open the slide-over drawer, then click the green "WhatsApp" button. Billio opens WhatsApp Web or the WhatsApp mobile app with a personalized message and the secure invoice download link ready to send.', 'billio' ); ?>
								</div>
							</div>

							<div class="billio-faq-item">
								<button type="button" class="billio-faq-trigger" aria-expanded="false">
									<span><?php esc_html_e( 'Does Billio support both WooCommerce Block Checkout and Classic Checkout for Tax ID?', 'billio' ); ?></span>
									<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
								</button>
								<div class="billio-faq-content">
									<?php esc_html_e( 'Yes! Billio natively supports both the modern WooCommerce Cart & Checkout Blocks (via the Store API) and classic shortcode checkout ([woocommerce_checkout]). Customer Tax ID inputs are saved seamlessly and displayed on the order screen and PDF invoices.', 'billio' ); ?>
								</div>
							</div>

							<div class="billio-faq-item">
								<button type="button" class="billio-faq-trigger" aria-expanded="false">
									<span><?php esc_html_e( 'What does the Smart Setup Checklist on the Dashboard do?', 'billio' ); ?></span>
									<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
								</button>
								<div class="billio-faq-content">
									<?php esc_html_e( 'The Smart Setup Checklist guides you step-by-step through configuring your company logo, numbering format, email delivery triggers, and B2B Tax ID. The radial progress ring updates automatically. Once 100% complete, you can dismiss or re-open the checklist anytime.', 'billio' ); ?>
								</div>
							</div>

							<div class="billio-faq-item">
								<button type="button" class="billio-faq-trigger" aria-expanded="false">
									<span><?php esc_html_e( 'Are Bengali names and addresses supported?', 'billio' ); ?></span>
									<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
								</button>
								<div class="billio-faq-content">
									<?php esc_html_e( 'Yes. Billio includes the bundled Noto Sans Bengali font and vowel-sign reordering (such as split vowels o-kar and ou-kar). Please note that due to font shaping limitations in Dompdf, some complex consonant conjuncts may not connect properly.', 'billio' ); ?>
								</div>
							</div>

							<div class="billio-faq-item">
								<button type="button" class="billio-faq-trigger" aria-expanded="false">
									<span><?php esc_html_e( 'Can I generate invoices for older, existing orders?', 'billio' ); ?></span>
									<svg class="billio-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
								</button>
								<div class="billio-faq-content">
									<?php esc_html_e( 'Yes. In the Billio Dashboard, look for the "Action Needed" banner. You can generate invoices for past orders in safe background batches with a single click.', 'billio' ); ?>
								</div>
							</div>

						</div>

						<!-- Step 9: System Status & Health -->
						<div id="step-system" class="billio-guide-card">
							<div class="billio-guide-card-header">
								<span class="billio-step-num">9</span>
								<h3><?php esc_html_e( 'System Health & Diagnostics', 'billio' ); ?></h3>
							</div>
							<p class="billio-guide-text">
								<?php esc_html_e( 'Verify at a glance whether your hosting environment meets the optimal conditions for fast PDF generation:', 'billio' ); ?>
							</p>

							<?php
							$upload_dir   = wp_get_upload_dir();
							$billio_dir   = trailingslashit( $upload_dir['basedir'] ) . 'billio';
							$is_writable  = wp_is_writable( $billio_dir );
							$hpos_active  = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
							$memory_limit = ini_get( 'memory_limit' );
							$has_mbstring = extension_loaded( 'mbstring' );
							$has_image    = extension_loaded( 'gd' ) || extension_loaded( 'imagick' );
							?>

							<table class="billio-system-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Environment / Component', 'billio' ); ?></th>
										<th><?php esc_html_e( 'Current Value', 'billio' ); ?></th>
										<th><?php esc_html_e( 'Status', 'billio' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td><strong>PHP Version</strong></td>
										<td><?php echo esc_html( PHP_VERSION ); ?></td>
										<td>
											<?php if ( version_compare( PHP_VERSION, '7.4', '>=' ) ) : ?>
												<span class="billio-status-badge ok">✓ <?php esc_html_e( 'Compatible', 'billio' ); ?></span>
											<?php else : ?>
												<span class="billio-status-badge warn">⚠ <?php esc_html_e( 'Upgrade to 7.4+', 'billio' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<td><strong>PHP Memory Limit</strong></td>
										<td><?php echo esc_html( $memory_limit ); ?></td>
										<td>
											<span class="billio-status-badge ok">✓ <?php esc_html_e( 'Protected with Auto-Raise', 'billio' ); ?></span>
										</td>
									</tr>
									<tr>
										<td><strong>mbstring Extension</strong> (Unicode & Bangla)</td>
										<td><?php echo $has_mbstring ? esc_html__( 'Installed', 'billio' ) : esc_html__( 'Missing', 'billio' ); ?></td>
										<td>
											<?php if ( $has_mbstring ) : ?>
												<span class="billio-status-badge ok">✓ <?php esc_html_e( 'Active', 'billio' ); ?></span>
											<?php else : ?>
												<span class="billio-status-badge warn">⚠ <?php esc_html_e( 'Recommended for Unicode', 'billio' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<td><strong>GD / Imagick</strong> (Logo & Image Processing)</td>
										<td><?php echo $has_image ? esc_html__( 'Installed', 'billio' ) : esc_html__( 'Missing', 'billio' ); ?></td>
										<td>
											<?php if ( $has_image ) : ?>
												<span class="billio-status-badge ok">✓ <?php esc_html_e( 'Active', 'billio' ); ?></span>
											<?php else : ?>
												<span class="billio-status-badge warn">⚠ <?php esc_html_e( 'Recommended for Images', 'billio' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<td><strong>Storage Directory</strong> (uploads/billio)</td>
										<td><code><?php echo esc_html( wp_basename( $upload_dir['basedir'] ) . '/billio' ); ?></code></td>
										<td>
											<?php if ( $is_writable ) : ?>
												<span class="billio-status-badge ok">✓ <?php esc_html_e( 'Writable (.htaccess secured)', 'billio' ); ?></span>
											<?php else : ?>
												<span class="billio-status-badge warn">⚠ <?php esc_html_e( 'Check Permissions (755)', 'billio' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<td><strong>WooCommerce HPOS</strong> (High-Performance Orders)</td>
										<td><?php echo $hpos_active ? esc_html__( 'HPOS Active', 'billio' ) : esc_html__( 'CPT Compatibility Mode', 'billio' ); ?></td>
										<td>
											<span class="billio-status-badge ok">✓ <?php esc_html_e( '100% Compatible', 'billio' ); ?></span>
										</td>
									</tr>
									<tr>
										<td><strong>Dompdf Engine & Subsetting</strong></td>
										<td>Dompdf v3.1</td>
										<td>
											<span class="billio-status-badge ok">✓ <?php esc_html_e( 'Optimized (Subsetting On)', 'billio' ); ?></span>
										</td>
									</tr>
								</tbody>
							</table>
						</div>

						<!-- Support & Footer Card -->
						<div class="billio-support-card">
							<div>
								<h4><?php esc_html_e( 'Need Additional Help or Have Questions?', 'billio' ); ?></h4>
								<p><?php esc_html_e( 'Our support team is ready to assist with any questions or configuration needs. Visit the settings page or view the official documentation.', 'billio' ); ?></p>
							</div>
							<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary" style="padding:8px 18px;height:auto;font-size:14px;">
								<?php esc_html_e( 'Open Settings Page', 'billio' ); ?>
							</a>
						</div>

					</div>
				</div>
			</div>

		</div>
		<?php
	}
}
