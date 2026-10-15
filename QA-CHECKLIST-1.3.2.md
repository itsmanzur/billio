# Billio 1.3.2 — সম্পূর্ণ QA চেকলিস্ট (ফেজ ১–৫)

রিলিজের আগে প্রতিটা ফিক্স হাতে যাচাই করার লিস্ট। প্রতিটা ধাপ শেষে `[ ]` কে `[x]` করো।
কোনোটা ফেল করলে পাশে লেখো: **কী দেখার কথা ছিল, কী দেখলে**, আর স্ক্রিনশট রাখো।

| ফেজ | কী | সেকশন |
|---|---|---|
| ১ | গুরুতর বাগ ফিক্স | A – H |
| ২ | Preview আর UX | I – M |
| ৩ | বড় স্টোরে পারফরম্যান্স | O – R |
| ৪ | কোড আর build | S – W |
| ৫ | অটো টেস্ট | X |
| — | রিলিজের আগে শেষ চেক | N, Y |

---

## ০. প্রস্তুতি

- [ ] Billio → Settings-এ কোম্পানির নাম, ঠিকানা আর logo দেওয়া আছে।
- [ ] একটা বাংলা নামের টেস্ট কাস্টমার আছে (যেমন billing name "রহিম কোম্পানি")।
- [ ] অন্তত একটা **COD** আর একটা **অনলাইন পেমেন্ট** (bKash / Bank) টেস্ট অর্ডার করার ব্যবস্থা আছে।
- [ ] দুইজন admin / shop manager ইউজার আছে (C সেকশনের জন্য)।
- [ ] WooCommerce → Status → Logs-এ `billio` লগের বর্তমান শেষ লাইনটা দেখে রাখো (পরে নতুন error খুঁজতে)।

---

# ফেজ ১ — গুরুতর বাগ

## A. Block checkout-এ Tax ID

- [ ] Settings → B2B / Tax ID → "Enable Tax ID at checkout" চালু করে Save।
- [ ] Checkout পেজটা **Block checkout** কিনা দেখো (Pages → Checkout এডিট করলে "Checkout" ব্লক থাকবে)।
- [ ] টেস্ট অর্ডার দাও, Tax ID ঘরে `BIN-TEST-001` লেখো।
- [ ] **প্রত্যাশা:** admin অর্ডার পেজে billing address-এর নিচে Tax ID দেখায়।
- [ ] **প্রত্যাশা:** ইনভয়েস PDF-এ "Billed to"-এর নিচে Tax ID আছে।
- [ ] **প্রত্যাশা:** My Account → View order পেজেও Tax ID দেখায়।
- [ ] "Require Tax ID" চালু করে খালি রেখে অর্ডারের চেষ্টা → **প্রত্যাশা:** অর্ডার হয় না, error দেখায়।
- [ ] Classic checkout (`[woocommerce_checkout]` shortcode) থাকলে সেখানেও একই টেস্ট।

## B. Dashboard-এর Copy link / WhatsApp

- [ ] Dashboard → Recent documents → একটা ইনভয়েসের preview (চোখ আইকন) খোলো।
- [ ] "Copy Link" চেপে লিংকটা **incognito / অন্য ব্রাউজারে** (লগইন ছাড়া) খোলো → **প্রত্যাশা:** PDF আসে।
- [ ] লিংকের `order_key` একটু বদলে খোলো → **প্রত্যাশা:** permission error (403)।
- [ ] "WhatsApp" চাপো → **প্রত্যাশা:** মেসেজের লিংকে `action=billio_download` আর `order_key=` আছে, `billio_admin_download` নেই।
- [ ] refund থাকলে একটা credit note-এর Copy Link-ও টেস্ট করো।
- [ ] "Sample Invoice Preview" খোলো → **প্রত্যাশা:** Copy Link আর WhatsApp বাটন দেখায় না।

## C. দুই admin-এর download লিংক

- [ ] Admin A দিয়ে Dashboard খোলো।
- [ ] ১৫ মিনিটের মধ্যে Admin B দিয়ে Dashboard খুলে recent document-এর Download চাপো।
- [ ] **প্রত্যাশা:** PDF ডাউনলোড হয়, "link expired" আসে না।

## D. বাংলা টেক্সট

- [ ] Settings → Company name-এ "রহিম কোম্পানি" লেখো (Save না করে)।
- [ ] **প্রত্যাশা:** Live preview-তে নাম ঠিকঠাক, উল্টাপাল্টা ("রিহম েকাম্পািন") না।
- [ ] Save করে আসল ইনভয়েস PDF ডাউনলোড করো → **প্রত্যাশা:** কারচিহ্ন সঠিক জায়গায়।
- [ ] Dashboard-এর Sample Invoice Preview-তেও বাংলা ঠিক আছে।

## E. Yearly reset অন → অফ → অন

- [ ] Settings → Numbering-এ "Next invoice" নম্বরটা লিখে রাখো (ধরো `…-0010`)।
- [ ] Yearly reset **চালু** করে Save → Next একই থাকে (`…-0010`)।
- [ ] ২টা ইনভয়েস বানাও → Next `…-0012`।
- [ ] Yearly reset **বন্ধ** করে Save → Next `…-0012`।
- [ ] আরও ২টা ইনভয়েস বানাও → Next `…-0014`।
- [ ] Yearly reset আবার **চালু** করে Save → **প্রত্যাশা:** Next `…-0014` বা বেশি, কখনো `…-0012` না।
- [ ] একটা ইনভয়েস বানিয়ে নিশ্চিত হও নম্বরটা আগের কোনোটার সাথে মেলে না।
- [ ] শেষে yearly reset আগের অবস্থায় ফেরাও।

## F. COD অর্ডারে PAID ছাপ

- [ ] Settings → Design → "PAID stamp" চালু।
- [ ] COD অর্ডার (Processing) → ইনভয়েস → **প্রত্যাশা:** PAID ছাপ **নেই**।
- [ ] অর্ডার Completed করে "Regenerate" → **প্রত্যাশা:** PAID ছাপ **আছে**।
- [ ] bKash / Bank-এর Processing অর্ডার → **প্রত্যাশা:** PAID ছাপ আছে।

## G. Refund ID guard

- [ ] যেকোনো অর্ডারে ছোট refund করো, refund ID নাও।
- [ ] লগআউট অবস্থায় খোলো: `/wp-admin/admin-post.php?action=billio_download&order_id=<REFUND_ID>`
- [ ] **প্রত্যাশা:** "Order not found" (404), সাদা পেজ / fatal না।

## H. Backslash ও ভাঙা অক্ষর

- [ ] Company address-এ `Road\5` লিখে Save → reload-এর পরও `Road\5` থাকে।
- [ ] (ঐচ্ছিক) পণ্যের নামে ভাঙা অক্ষর থাকলে তার ইনভয়েস PDF ফাঁকা আসে না।

---

# ফেজ ২ — Preview আর UX

## I. Live preview

Save না করে বদলাও, preview সাথে সাথে বদলায় কিনা দেখো:

- [ ] Template: Default ↔ Modern → Modern-এ রঙিন হেডার দেখায়।
- [ ] Paper size: A4 ↔ Letter → preview-এর আকার বদলায়।
- [ ] Logo: নতুন logo দাও → preview-তে নতুন logo; "Remove" → logo চলে যায়।
- [ ] Preview "Credit note"-এ রেখে Credit note prefix বদলাও (যেমন `RF`) → নম্বরে `RF`।
- [ ] PAID stamp চালু অবস্থায় Save, তারপর বন্ধ করো (Save না করে) → preview থেকে PAID চলে যায়।
- [ ] "Discard" চাপলে সব আগের অবস্থায় ফেরে, preview-ও।

## J. Packing slip

- [ ] একটা অর্ডারে একটা পণ্য qty 3, তার ১টা refund করো।
- [ ] অর্ডার পেজ → Billio Invoice → "Packing Slip" → **প্রত্যাশা:** Qty **2**।
- [ ] অন্য একটা পণ্য পুরো refund → **প্রত্যাশা:** packing slip-এ সেটা নেই।
- [ ] পুরনো অর্ডারে → **প্রত্যাশা:** Date অর্ডারের তারিখ, আজকের না।

## K. Credit note

- [ ] শুধু shipping refund → Credit Note-এ shipping লাইন নাম আর টাকা সহ, Qty ঘরে "—"।
- [ ] পণ্য + shipping একসাথে refund → দুটো লাইন, যোগফল "Total Refunded"-এর সমান।
- [ ] Fee থাকলে fee refund করেও দেখো।

## L. Dashboard count আর chart

- [ ] "Processing" email trigger চালু করো।
- [ ] ইনভয়েস ছাড়া Processing অর্ডার থাকলে → "Orders without invoice"-এ গোনা হয়।
- [ ] "Generate missing" শেষ হওয়ার **সাথে সাথে** reload → সংখ্যা ০ / "All caught up"।
- [ ] Chart-এর বারে mouse → tooltip-এ "1 invoice" / "2 invoices" (বাংলায় "N টি চালান"), "1 invoices" না।

## M. Tax ID row

- [ ] Tax ID ফিচার **বন্ধ** → Tax ID ছাড়া অর্ডারে "Tax ID: —" লাইন নেই।
- [ ] Tax ID আছে এমন অর্ডারে লাইনটা এখনো দেখায়।

---

# ফেজ ৩ — বড় স্টোরে পারফরম্যান্স

## O. Dashboard-এর সংখ্যা সঠিক আছে কিনা

এখন stats আর chart আগের মতো প্রতিটা অর্ডার লোড না করে সরাসরি ডেটাবেস থেকে পড়ে — তাই সংখ্যাগুলো আগের মতোই কিনা মিলিয়ে দেখতে হবে।

- [ ] Dashboard-এর "Invoices this month" সংখ্যাটা লিখে রাখো।
- [ ] WooCommerce → Orders-এ এই মাসে ইনভয়েস হওয়া অর্ডার গুনে দেখো (Invoice কলামে নম্বর আছে এমন) → **প্রত্যাশা:** একই।
- [ ] "Revenue invoiced this month" = এই মাসে ইনভয়েস হওয়া অর্ডারগুলোর total-এর যোগফল (Cancelled আর Refunded বাদে)।
- [ ] "Credit notes this month" আর "refunded" টাকার অঙ্ক মিলিয়ে দেখো।
- [ ] Chart: 7 / 30 / 90 দিন বদলাও → প্রতিটায় ডেটা আসে, শেষ বারটা আজকের দিন।
- [ ] একটা নতুন ইনভয়েস বানাও → ৩০ সেকেন্ডের মধ্যে reload করলে আজকের বারে ১ বাড়ে।

## P. Multi-currency (শুধু যদি কোনো currency switcher প্লাগিন থাকে)

- [ ] স্টোরের মূল currency ছাড়া অন্য currency-তে একটা অর্ডার ইনভয়েস করো।
- [ ] **প্রত্যাশা:** "Invoices this month" সংখ্যায় গোনা হয়, কিন্তু Revenue-তে যোগ **হয় না**।

## Q. Search

- [ ] Dashboard search-এ একটা ইনভয়েস নম্বর লেখো (যেমন `INV-2026-0003`) → পাওয়া যায়।
- [ ] নম্বরের অংশ লেখো (যেমন `0003`) → পাওয়া যায়।
- [ ] কাস্টমারের নাম, ইমেইল আর অর্ডার নম্বর দিয়ে খোঁজো → সব ক্ষেত্রেই পাওয়া যায়।
- [ ] "Invoices" / "Credit notes" ফিল্টার বদলালে ঠিক ধরনের ডকুমেন্টই দেখায়।
- [ ] ফলাফল নতুন থেকে পুরনো ক্রমে সাজানো।
- [ ] ১০টার বেশি ফলাফল হলে পরের পেজে যাও → ডুপ্লিকেট নেই, কিছু বাদ পড়েনি।
- [ ] একটা অক্ষর লিখে খোঁজো (যেমন `a`) → দ্রুত উত্তর আসে, পেজ আটকে যায় না।

## R. পুরনো order storage (non-HPOS) — খুব গুরুত্বপূর্ণ

লোকাল অটো টেস্ট শুধু HPOS-এ চলেছে। পুরনো storage-এ একবার আলাদা টেস্ট সাইটে দেখো (আসল সাইটে না):

- [ ] টেস্ট সাইটে WooCommerce → Settings → Advanced → Features → Order data storage = "WordPress posts storage (legacy)"।
- [ ] Dashboard খোলে, KPI আর chart-এ সংখ্যা আসে (O সেকশনের মতো মিলিয়ে দেখো)।
- [ ] **কাস্টমারের নাম দিয়ে search করলে পাওয়া যায়** (আগে এখানে কাজ করত না)।
- [ ] ইনভয়েস Generate / Download / Resend কাজ করে।
- [ ] টেস্ট শেষে storage আগের মতো ফেরাও।

## R2. Logo সাইজ

- [ ] বড় একটা logo (যেমন ৩০০০px+ PNG, কয়েক MB) দিয়ে Save করো।
- [ ] নতুন ইনভয়েস PDF-এর ফাইল সাইজ দেখো → **প্রত্যাশা:** কয়েকশো KB-এর বেশি না (আগে logo-র পুরো সাইজ যোগ হতো)।
- [ ] PDF-এ logo ঝাপসা না, পরিষ্কার দেখায়।
- [ ] (ঐচ্ছিক) SVG logo upload করা গেলে PDF-এ logo দেখায় কিনা দেখো।

---

# ফেজ ৪ — কোড আর build

## S. Plugin update-এর পর upgrade

- [ ] টেস্ট সাইটে পুরনো ভার্সন (1.3.1) বসাও, তারপর নতুন zip দিয়ে update করো।
- [ ] admin-এ না ঢুকে প্রথমে frontend থেকে একটা অর্ডার দিয়ে ইনভয়েস তৈরি করো।
- [ ] **প্রত্যাশা:** কোনো error নেই, আর `wp_options`-এ `billio_version` = `1.3.2` (বা Dashboard-এ ভার্সন ঠিক দেখায়)।

## T. Review notice

- [ ] (১০+ ইনভয়েস লাগবে) WooCommerce → Orders পেজে review notice দেখায়।
- [ ] Posts / Pages / Plugins-এর মতো সাধারণ admin পেজে notice **দেখায় না**।
- [ ] Billio Dashboard-এ উপরের সাধারণ notice দেখায় না (Dashboard-এর নিজের inline bar থাকতে পারে)।
- [ ] Notice-এর ✕ চাপো → reload-এর পর আর দেখায় না।

## U. Template override (theme developer দের জন্য)

- [ ] Settings → Advanced → "Override templates" লিস্টে `partials/invoice-lines.php` দেখায়।
- [ ] (ঐচ্ছিক) theme-এ `billio/partials/invoice-lines.php` কপি করে একটা লেখা বদলাও → Default আর Modern দুটো ইনভয়েসেই বদল দেখায়, আর লিস্টে "Overridden by theme" আসে।
- [ ] Default আর Modern ইনভয়েসে items, Subtotal, Shipping, Tax, Discount, Total আগের মতোই আছে।

## V. Download header

- [ ] ব্রাউজারে ইনভয়েস ডাউনলোড করো → ফাইলের নাম `invoice-<নম্বর>.pdf`।
- [ ] (ঐচ্ছিক) Invoice prefix বাংলায় দিলে (যেমন `বিল`) ডাউনলোড ফাইলের নামও ঠিক থাকে।
- [ ] Dashboard drawer-এর preview-তে PDF ভেতরেই খোলে (download হয় না)।

## W. Build zip আর uninstall

- [ ] `powershell -ExecutionPolicy Bypass -File bin/build-zip.ps1` চালাও → "Successfully built" আর সাইজ ~৪ MB।
- [ ] zip খুলে দেখো: ভেতরে একটা `billio/` ফোল্ডার, তার ভেতরে `inc/`, `templates/partials/`, `vendor/dompdf`, `fonts/`, `languages/`।
- [ ] zip-এ **নেই**: `tests/`, `bin/`, `QA-CHECKLIST-*.md`, `vendor/squizlabs`, `vendor/wp-coding-standards`, `composer.phar`।
- [ ] **Linux সার্ভার / অন্য সাইটে** zip upload করে activate করো → কাজ করে (আগে Windows-এ বানানো zip-এ path ভাঙার ঝুঁকি ছিল)।
- [ ] টেস্ট সাইটে plugin Delete করো → `wp_options`-এ `billio_lock_order_%` বা `billio_dash_%` row থাকে না, কিন্তু `billio_invoice_seq` আর uploads/billio-র PDF থেকে যায় (ইচ্ছাকৃত)।

---

# ফেজ ৫ — অটো টেস্ট

## X. টেস্ট চালানো

- [ ] Unit টেস্ট (WordPress লাগে না):

  ```bash
  php tests/run.php
  ```

  **প্রত্যাশা:** `38 passed, 0 failed`।

- [ ] Integration টেস্ট (শুধু পড়ে, কিছু লেখে না; WP-CLI লাগে):

  ```bash
  wp eval-file wp-content/plugins/billio/tests/integration.php
  ```

  **প্রত্যাশা:** শেষ লাইনে `0 failed`। লোকাল সাইটে (HPOS) `15 passed` এসেছে।

- [ ] পুরনো storage-এর টেস্ট সাইটেও (R সেকশন) integration টেস্ট একবার চালাও।
- [ ] Refund আর credit note আছে এমন সাইটে চালাও → "credit note renders to a PDF" আর "refund amounts match" লাইন আসে।

---

# রিলিজের আগে

## N. সাধারণ রিগ্রেশন চেক

- [ ] নতুন অর্ডার Completed → কাস্টমারের ইমেইলে ইনভয়েস PDF attach হয়।
- [ ] Refund → refund ইমেইলে credit note PDF attach হয়।
- [ ] Thank You পেজে "Download invoice (PDF)" কাজ করে (guest অর্ডারেও)।
- [ ] Orders লিস্টে Generate / Download / Resend / Regenerate কাজ করে।
- [ ] সাইটের ভাষা বাংলা (bn_BD) করে Dashboard আর Settings দেখো — নতুন টেক্সটও বাংলায়।
- [ ] WooCommerce → Status → Logs → `billio` লগে নতুন error নেই।

## Y. শেষ ধাপ

- [ ] PHP 7.4 আর PHP 8.3 — দুটোতে অন্তত একবার Dashboard, Settings আর একটা ইনভয়েস ডাউনলোড।
- [ ] WordPress-এর "Plugin Check" প্লাগিন দিয়ে zip চেক → কোনো error নেই।
- [ ] readme.txt-এর Changelog আর "Stable tag: 1.3.2" ঠিক আছে।
- [ ] সব ঠিক থাকলে git push আর রিলিজ।

---

### ফেল করলে যা জানাবে

সেকশন আর ধাপ (যেমন "Q-৬"), কী দেখার কথা ছিল, কী দেখলে, স্ক্রিনশট। Integration টেস্ট ফেল করলে পুরো আউটপুট কপি করে দিও।
