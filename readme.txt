=== Billio — PDF Invoices & Billing for WooCommerce ===
Contributors: getwpkit
Tags: woocommerce, invoice, pdf invoice, billing, packing slip
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically generate and attach branded, sequentially-numbered PDF invoices to WooCommerce orders.

== Description ==

Billio generates a professional PDF invoice for every WooCommerce order and attaches it to the customer email automatically — no manual work.

**Free features**

* Automatic PDF invoice attached to the order email (choose Processing and/or Completed)
* Automatic PDF credit note attached to customer refund emails
* Interactive Slide-over Quick Preview Drawer on the Dashboard with instant inline PDF view, 1-click print, WhatsApp sharing, link copy, and email resending
* B2B Customer Tax ID: Collect customer VAT / BIN / TRN / GSTIN at checkout (compatible with both Classic and Block Checkout) and display on PDF invoices
* Instant PDF download button on the checkout Thank You (Order Received) page
* Customers can download invoices and credit notes from My Account and Thank You screens (with guest order verification)
* Optional PAID watermark stamp on paid order invoices
* Two professional invoice templates (Default, Modern) with customizable accent color
* Interactive Dashboard with revenue KPIs, invoice chart, and live-preview settings
* Company logo, name, address, and VAT/tax number
* Race-condition-safe sequential invoice numbering, with no gaps even if a render fails mid-way
* Packing slip generation from the order edit screen (no prices, ships-to address, never saved to disk)
* Admins can download, resend, or regenerate the invoice from the order list and order edit screen
* Bangla-script support: vowel-sign positioning is corrected so names and addresses render properly (complex consonant conjuncts have known font-rendering limitations — see FAQ)

**Coming Soon in Billio Pro**

* Bulk Actions: Download invoices as ZIP and Bulk Print from the WooCommerce orders list
* Dynamic QR Codes: Instant payment links and e-invoicing verification (ZATCA, UPI, etc.)
* Custom Fiscal Year: Configure financial year start month (e.g. April or July) for numbering resets
* WhatsApp & SMS dispatch: Automated invoice dispatch directly to customer messaging apps
* Drag-and-Drop Visual Template Customizer & Cloud Backup (Google Drive, Dropbox)

== Installation ==

1. Upload the plugin to `/wp-content/plugins/billio`, or install it through the WordPress plugins screen.
2. Activate the plugin.
3. You'll be taken straight to Billio's settings — add your company details and logo, then you're done.

== Frequently Asked Questions ==

= Does this work with WooCommerce's High-Performance Order Storage (HPOS)? =

Yes, Billio is fully HPOS-compatible.

= Will two orders ever get the same invoice number? =

No. Invoice numbers are assigned with an atomic, lock-guarded database operation specifically designed to avoid the duplicate-numbering bugs seen in other invoicing plugins, and the number is saved before the PDF is even rendered so a failure partway through can't leave a gap in the sequence.

= How good is the Bangla (Bengali) rendering? =

Vowel signs (ি ে ৈ ো ৌ) are repositioned so names and addresses display correctly. Complex consonant conjuncts (e.g. ক্ষ, ম্ম) currently render with a visible connecting mark rather than a fully stacked ligature glyph, which is a limitation of the underlying PDF renderer rather than something Billio's text processing can fully correct. This does not affect the accuracy of any amounts or data on the invoice.

== Changelog ==

= 1.3.2 =
* Fix: Customer Tax ID entered on the Block (Store API) checkout now reaches the invoice, credit note and order screens — previously only the classic checkout value was saved.
* Fix: Dashboard "Copy link" and WhatsApp now share the customer download link (order-key protected) instead of an admin-only link customers couldn't open.
* Fix: Dashboard download/preview links no longer fail with "link expired" for a second admin — per-admin links are no longer cached.
* Fix: Bengali text in the Settings live preview no longer appears scrambled (PDF output was already correct).
* Fix: Turning "Reset numbering each year" off and on again in the same year could reissue existing invoice / credit note numbers.
* Fix: The PAID watermark is no longer stamped on Cash on Delivery orders before they're completed.
* Fix: A product name containing an invalid UTF-8 character no longer produces a blank PDF.
* Fix: Backslashes in settings (e.g. the company address) are no longer stripped on save.
* Hardening: Download links given a refund ID instead of an order ID now return "not found" instead of a PHP fatal error.
* Fix: The Settings live preview now reflects unsaved template, paper size, logo and credit note prefix changes, and unchecking a box (e.g. PAID watermark) shows immediately.
* Fix: Packing slips list the quantity still to ship after refunds (fully refunded lines are left out) and show the order date instead of today's date.
* Fix: Credit notes now list refunded shipping and fee lines, not just products.
* Fix: "Orders without invoice" also counts processing orders when invoices are sent at Processing, and updates right after "Generate missing" finishes.
* Fix: Dashboard chart dates no longer shift by a day for sites west of UTC; chart tooltips are now translatable.
* Polish: The order screen's Tax ID row is hidden when the Tax ID feature is off and the order has none.
* Performance: Dashboard stats, chart and search read order fields in bulk instead of loading every order — much faster on large stores.
* Fix: Dashboard search by customer name now works on stores using the legacy (non-HPOS) order storage.
* Fix: Revenue figures only add up orders in the store currency, instead of mixing currencies on multi-currency stores.
* Fix: Dashboard chart days stay correct across daylight-saving changes.
* Performance: The company logo is embedded from a resized copy when available, keeping PDFs and emails small; SVG logos are labelled correctly.
* Fix: Plugin updates now finish their upgrade steps on the first request, not only after an admin page visit.
* Polish: The review request only appears on WooCommerce / Billio screens, and its script is properly enqueued.
* Polish: Invoice line items and totals moved to a shared template partial (`billio/partials/invoice-lines.php`, overridable from a theme).
* Hardening: PDF downloads send `X-Content-Type-Options: nosniff` and a UTF-8 filename; uninstall removes leftover lock rows.
* Dev: Production build installs Composer dependencies with `--no-dev` and writes the zip with forward-slash paths; added `tests/run.php` (unit) and `tests/integration.php` (read-only, WP-CLI).

= 1.3.1 =
* Polish: Full internationalization audit — 100% translatable interface strings across Dashboard, Settings, Quick Actions, and setup checklist with complete bundled Bengali (bn_BD) translation.
* Fix: Restored safe JIT bundled translation fallback via `load_textdomain_mofile` filter ensuring complete Plugin Check compliance without deprecated function calls.
* Fix: Corrected placeholder argument handling and added positional printf formatting support for JavaScript localized strings.
* Polish: Cleaned up deprecated UI listeners and streamlined Quick Actions event bindings.
* Polish: Replaced preemptive HTML entity escaping in localized script parameters to prevent double-escaping in client-side text rendering.
* Hardening: Strengthened uninstall routine to clean up per-user checklist dismissal metadata.

= 1.3.0 =
* New: Interactive Slide-over Quick Preview Drawer on the Dashboard with instant inline PDF viewer, 1-click print, WhatsApp sharing, invoice link copy, and customer contact details.
* New: B2B Customer Tax ID (VAT, BIN, TRN, GSTIN) integration — seamlessly collect customer tax numbers at checkout with support for both Classic shortcode and WooCommerce Block Checkout.
* New: Customer Tax ID admin order editing — store managers can view and edit the customer Tax ID on WooCommerce Admin order edit screens.
* New: Automatic display of Customer Tax ID in PDF invoices, credit notes, and customer account/thank you order details.
* New: Live preview support for B2B Tax ID in Billio Settings with real-time toggle and custom field label binding.
* Fix: Removed false unsaved-changes dialog on Settings save.

= 1.2.3 =
* Performance: Enabled Dompdf font subsetting reducing generated PDF file size by up to 90% (~75KB to ~8KB) and dramatically decreasing rendering memory overhead.
* Performance: Enabled Dompdf HTML5 parser option for improved CSS layout conformance and streamlined DOM rendering.
* Performance: Added fast-path ASCII bypass to Bengali shaper when no Bengali unicode bytes are present.
* Performance: Replaced transient base64 logo caching with in-memory memoization, cleaning up transient bloat on upgrade.
* Performance: Added query result caching and proactive cache-busting for legacy orders and refunds lookups on the Dashboard.
* Optimization: Added temporary memory limit increase during PDF generation to prevent out-of-memory errors on constrained hosts.
* New: Added dedicated, interactive "Docs & Features" admin page featuring an organized overview of free & upcoming features, live search, and a non-technical beginner guide.
* Extensibility: Added `billio_invoice_template_data` filter to allow custom template variable filtering and `billio_dompdf_options` filter for fine-grained Dompdf configuration.
* i18n: Complete internationalization audit of documentation strings and included bundled Bengali (bn_BD) translation files.

= 1.2.2 =
* Fix: Removed search cap limitation on Dashboard candidate lookups and resolved OOM memory exhaustion via chunked record queries.
* Hardening: Strengthened upload file path checking and atomic write error handling for PDF invoice generation.
* New: Added guest invoice and credit note download support with secure order key validation.
* New: Added prominent Thank You / Order Received page invoice download button with dedicated frontend styling for paid, processing, and completed orders.
* New: Added optional angled "PAID" watermark stamp setting for paid orders across invoice templates.
* New: Automatically generate and attach PDF credit notes to customer refund emails.
* Fix: Automatically seed missing settings defaults on install and upgrade so unchecking default-true options persists properly.
* Maintenance: Cleaned up Dashboard transients and scheduled hooks on uninstall.

= 1.2.1 =
* Fix: a download/resend/regenerate link on the Dashboard's recent-documents table could show "link expired" — the nonce'd download URL was being HTML-entity-escaped twice (once by WordPress, once by the Dashboard's JS) before being read back, corrupting its parameters.
* Fix: the Dashboard no longer loads every invoiced order and refund your store has ever had on every page load; the stats cards, chart, and recent-documents list now query only the data they actually need, and recent-documents is now cached like the other stats. Cache invalidation is also debounced so a burst of invoice generation doesn't re-run these queries on every single one, while never staying stale more than ~30 seconds.
* New: the Dashboard's recent-documents search now searches your entire invoice/credit note history (by number, order, or customer), not just the 10 most recent rows — with pagination for larger result sets.
* Hardening: the path check guarding PDF downloads now specifically confirms the resolved file stays inside the plugin's own uploads/billio directory, closing a theoretical sibling-directory bypass (e.g. an uploads-backup folder) that a looser prefix check would have allowed.

= 1.2.0 =
* New: redesigned, interactive Dashboard — setup-progress ring, KPI cards with trend/sparklines, an invoice-activity chart (Invoices/Revenue, 7D/30D/90D) with instant quick-settings (email triggers, paper size, template), and a searchable, filterable recent-documents table (invoices + credit notes) with inline Download/Resend/Regenerate actions.
* New: "Generate missing invoices" now runs as a batched background job with a progress bar instead of one request per order.
* New: the Dashboard's stats/chart now update immediately when an invoice or credit note is generated, instead of waiting out the cache.
* New: redesigned Settings page — section cards (Company, Numbering, Email & paper, Design, Advanced) with a sticky live preview that switches between Invoice/Credit note/Packing slip, a number-format builder with clickable placeholder chips and live next-number preview, a logo dropzone with drag-and-drop upload, an unsaved-changes bar with Ctrl+S, and a scrollspy section nav with a profile-completeness indicator.
* Fix: the number format can no longer be saved without a {NUMBER} placeholder (previously only caught client-side); the server now rejects it and keeps the previous format.

= 1.1.0 =
* New: configurable invoice number format (custom prefix, {PREFIX}/{YEAR}/{NUMBER} placeholders, digit padding, optional yearly reset).
* New: credit notes — a downloadable PDF for each refund, with its own number series.
* New: product variation attributes and SKU now shown on invoices and the packing slip.
* New: paper size setting (A4 / Letter), applied to invoices, packing slips, and credit notes.
* New: theme-level template overrides — drop a file at yourtheme/billio/{default,modern,packing-slip,credit-note}.php to override any bundled document.
* New: declares Requires Plugins: woocommerce so WordPress 6.5+ blocks activation natively if WooCommerce isn't active.
* Fix: customers can no longer trigger a brand-new invoice number by requesting the download URL for an order that was never actually invoiced (e.g. pending/failed/cancelled).
* Fix: turning on "Reset numbering each year" mid-year no longer reissues numbers that already exist for the current year.
* Fix: invoice/credit-note numbering and file storage now use the site's configured timezone instead of UTC.
* Fix: "Generate all" now processes orders oldest-first, matching the chronological numbering intent.
* Fix: order fees are now reflected in the invoice totals box, not just the line-item table.
* Fix: credit notes now show the date they were actually issued, not the date of the last (re)download; number assignment is now race-safe; a credit note can no longer be downloaded against a mismatched order.
* Fix: the settings live preview now reflects the number format/padding fields instead of always showing a hardcoded sample.
* Fix: "Resend" no longer reports success when the matching WooCommerce email is disabled in WooCommerce → Emails.
* Fix: "Regenerate" no longer leaves the previous PDF file orphaned on disk.
* Fix: a rare lock-contention case could leave a duplicate, orphaned invoice PDF on disk.
* Fix: the settings page no longer shows both an error and a "Settings saved" message at once.

= 1.0.1 =
* Fix: Bangla vowel signs ো and ৌ no longer render as a broken/missing-glyph mark.
* Fix: invoice numbers are now saved immediately on assignment (before PDF rendering), closing a gap that could occur if rendering failed partway through.
* Fix: concurrent requests for the same order's invoice are now serialized, preventing a rare double-numbering race.
* Fix: invoices are no longer silently re-rendered (as a new file, under the same number) on every download/resend — the existing PDF is reused; an explicit "Regenerate" action is available when you actually want fresh content.
* Fix: packing slips are streamed directly and never written to disk, avoiding permanent accumulation of customer name/address/phone in uploads.
* Fix: "Resend" now sends the email matching the order's actual current status instead of always the Completed email.
* Fix: invoice line totals now reconcile with the Subtotal line when a discount is applied; order fees are now shown; a refund note appears when applicable.
* Fix: uninstalling no longer deletes the invoice counter (this could cause newly-issued numbers to collide with existing invoices on reinstall).
* Fix: Dashboard stats now use the site's configured timezone instead of the server's, and are cached briefly to reduce load on stores with high order volume.
* Fix: several smaller hardening and reliability improvements (output-buffer safety on PDF downloads, Apache 2.2 compatibility for the uploads/ protection file, and others).

= 1.0.0 =
* Initial release.
