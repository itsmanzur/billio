=== Billio — WooCommerce PDF Invoice & Billing ===
Contributors: getwpkit
Tags: woocommerce, invoice, pdf invoice, billing, packing slip
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically generate and attach branded, sequentially-numbered PDF invoices to WooCommerce orders.

== Description ==

Billio generates a professional PDF invoice for every WooCommerce order and attaches it to the customer email automatically — no manual work.

**Free features**

* Automatic PDF invoice attached to the order email (choose Processing and/or Completed)
* Two invoice templates (Default, Modern)
* Company logo, name, address, and VAT/tax number
* Race-condition-safe sequential invoice numbering, with no gaps even if a render fails mid-way
* Packing slip generation from the order edit screen (no prices, ships-to address, never saved to disk)
* Customers can download their invoice from My Account
* Admins can download, resend, or regenerate the invoice from the order list and order edit screen
* Bangla-script support: vowel-sign positioning is corrected so names and addresses render properly (complex consonant conjuncts have known font-rendering limitations — see FAQ)

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
