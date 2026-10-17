=== Billio — PDF Invoices & Billing for WooCommerce ===
Contributors: getwpkit
Tags: woocommerce, invoice, pdf invoice, billing, packing slip
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
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

== Third-Party Libraries and Services ==

**Bundled PHP libraries** (installed via Composer, shipped inside the plugin's own `vendor/` folder — never downloaded or loaded from a remote server at runtime):

* [dompdf/dompdf](https://github.com/dompdf/dompdf) v3.1.6 — LGPL-2.1
* dompdf/php-font-lib 1.0.2 — LGPL-2.1-or-later
* dompdf/php-svg-lib 1.0.2 — LGPL-3.0-or-later
* masterminds/html5 2.11.0 — MIT
* sabberworm/php-css-parser v9.5.0 — MIT
* sRGB2014.icc colour profile, bundled by dompdf (license file included alongside it)

**Bundled font**: Noto Sans Bengali (Regular, Bold weights), licensed under the SIL Open Font License 1.1. Source: https://fonts.google.com/noto/specimen/Noto+Sans+Bengali

**External services**: Billio does not send any data to external servers automatically and does not use any tracking or analytics. The Dashboard's optional WhatsApp share button opens a wa.me link (https://wa.me/) in a new tab only when an administrator clicks it, passing the customer phone number and invoice link entered by that administrator to WhatsApp (service by Meta Platforms; Terms: https://www.whatsapp.com/legal/terms-of-service, Privacy: https://www.whatsapp.com/legal/privacy-policy).

== Frequently Asked Questions ==

= Does this work with WooCommerce's High-Performance Order Storage (HPOS)? =

Yes, Billio is fully HPOS-compatible.

= Will two orders ever get the same invoice number? =

No, two orders will never get the same number — that's enforced by an atomic, lock-guarded database operation, not just application logic. The number is also saved before the PDF is even rendered, so a PDF render failure afterward can never cause a gap. There's one extremely narrow window this doesn't cover: if the server crashes or the database write itself fails in the instant right after a number is assigned (not the render — the save that follows it), that number is consumed from the sequence but may end up unlinked from any order. Billio detects this and logs it (WooCommerce → Status → Logs) for an admin to reconcile by hand; it cannot silently cause a duplicate.

= How good is the Bangla (Bengali) rendering? =

Vowel signs (ি ে ৈ ো ৌ) are repositioned so names and addresses display correctly. Complex consonant conjuncts (e.g. ক্ষ, ম্ম) currently render with a visible connecting mark rather than a fully stacked ligature glyph, which is a limitation of the underlying PDF renderer rather than something Billio's text processing can fully correct. This does not affect the accuracy of any amounts or data on the invoice.

= What happens to my invoices and settings if I delete the plugin? =

Deleting Billio removes its own settings (company info, template choice, etc.). It deliberately keeps everything that's part of your store's financial records: the generated invoice/credit-note PDF files, the invoice numbers and dates stored on each order, and the invoice-number counter itself. This is intentional, not an oversight — those are often legally required to be retained, and if the counter were wiped, reinstalling Billio (or installing it on a restored backup) could reissue a number that's already printed on a real invoice. There's currently no setting to force a full wipe on delete; if you need one (e.g. for a test/staging site), remove the `uploads/billio` folder and the `_billio_invoice_*` / `_billio_credit_note_*` order meta manually.

== Changelog ==

= 1.0.0 =
* Initial release.
* Automatic PDF invoice and credit note generation, attached to order/refund emails.
* Interactive Dashboard with Quick Preview Drawer, print, WhatsApp share, link copy, and resend.
* B2B Customer Tax ID at checkout (Classic and Block Checkout), shown on invoices.
* Customer self-service downloads from My Account and the Thank You page.
* Two invoice templates with a customizable accent color and an optional PAID watermark.
* Race-condition-safe, gapless sequential invoice numbering.
* Bangla-script rendering support for names and addresses.
