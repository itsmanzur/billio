# Billio 1.3.2 — ম্যানুয়াল QA চেকলিস্ট

ফেজ ১ (commit `581a353`) আর ফেজ ২-এর প্রতিটা ফিক্স ব্রাউজারে হাতে যাচাই করার লিস্ট।
প্রতিটা ধাপ শেষে `[ ]` কে `[x]` করে দাও। কোনোটা ফেল করলে পাশে কী দেখলে লিখে রাখো।

**শুরুর আগে:** Billio → Settings-এ কোম্পানির নাম/ঠিকানা দেওয়া থাকুক, আর একটা বাংলা নামের টেস্ট কাস্টমার রাখো (যেমন "রহিম কোম্পানি")।

---

## A. Block checkout-এ Tax ID (ফেজ ১)

- [ ] Settings → B2B / Tax ID → "Enable Tax ID at checkout" চালু করে Save।
- [ ] Checkout পেজটা **Block checkout** কিনা দেখো (Pages → Checkout এডিট করলে "Checkout" ব্লক থাকবে)।
- [ ] একটা টেস্ট অর্ডার দাও, Tax ID ঘরে `BIN-TEST-001` লেখো।
- [ ] **প্রত্যাশা:** admin অর্ডার পেজে billing address-এর নিচে Tax ID দেখাবে।
- [ ] **প্রত্যাশা:** ওই অর্ডারের ইনভয়েস PDF-এ "Billed to"-এর নিচে Tax ID থাকবে।
- [ ] **প্রত্যাশা:** My Account → View order পেজেও Tax ID দেখাবে।
- [ ] "Require Tax ID" চালু করে খালি রেখে অর্ডার দেওয়ার চেষ্টা করো → **প্রত্যাশা:** অর্ডার হবে না, error দেখাবে।
- [ ] Classic checkout (`[woocommerce_checkout]` shortcode) থাকলে সেখানেও একবার একই টেস্ট।

## B. Dashboard-এর Copy link / WhatsApp (ফেজ ১)

- [ ] Billio → Dashboard → Recent documents-এ একটা ইনভয়েসের preview (চোখ আইকন) খোলো।
- [ ] "Copy Link" চাপো, তারপর **incognito / অন্য ব্রাউজারে** (লগইন ছাড়া) লিংকটা খোলো।
- [ ] **প্রত্যাশা:** PDF ডাউনলোড হবে — "link expired" বা "Permission denied" আসবে না।
- [ ] লিংকের `order_key` একটু বদলে খোলো → **প্রত্যাশা:** "permission" error (403)।
- [ ] "WhatsApp" বাটন চাপো → **প্রত্যাশা:** মেসেজে কাস্টমারের লিংক (`action=billio_download&...&order_key=`) থাকবে, `billio_admin_download` না।
- [ ] একটা credit note-এর জন্যও Copy Link টেস্ট করো (refund থাকলে)।
- [ ] Dashboard-এর "Sample Invoice Preview" খোলো → **প্রত্যাশা:** Copy Link আর WhatsApp বাটন দেখাবে না।

## C. দুই admin-এর download লিংক (ফেজ ১)

- [ ] দ্বিতীয় একটা Shop Manager / Administrator ইউজার বানাও (না থাকলে)।
- [ ] Admin A দিয়ে Dashboard খোলো (cache তৈরি হবে)।
- [ ] ১৫ মিনিটের মধ্যে Admin B দিয়ে Dashboard খুলে recent document-এর Download চাপো।
- [ ] **প্রত্যাশা:** PDF ডাউনলোড হবে, "link expired" আসবে না।

## D. বাংলা টেক্সট (ফেজ ১)

- [ ] Settings → Company name-এ "রহিম কোম্পানি" লেখো (Save না করে)।
- [ ] **প্রত্যাশা:** ডানদিকের Live preview-তে নাম ঠিকঠাক "রহিম কোম্পানি" দেখাবে (উল্টাপাল্টা "রিহম েকাম্পািন" না)।
- [ ] Save করে একটা আসল ইনভয়েস PDF ডাউনলোড করো → **প্রত্যাশা:** PDF-এও নাম পড়া যায় (কারচিহ্ন সঠিক জায়গায়)।
- [ ] Dashboard-এর Sample Invoice Preview-তেও বাংলা ঠিক আছে কিনা দেখো।

## E. Yearly reset অন → অফ → অন (ফেজ ১)

- [ ] Settings → Numbering-এ "Next invoice" নম্বরটা লিখে রাখো (ধরো `…-0010`)।
- [ ] Yearly reset **চালু** করে Save → Next invoice একই থাকা উচিত (`…-0010`)।
- [ ] ২টা ইনভয়েস বানাও (Next হবে `…-0012`)।
- [ ] Yearly reset **বন্ধ** করে Save → Next `…-0012` থাকা উচিত।
- [ ] আরও ২টা ইনভয়েস বানাও (Next হবে `…-0014`)।
- [ ] Yearly reset আবার **চালু** করে Save।
- [ ] **প্রত্যাশা:** Next invoice `…-0014` (বা তার বেশি) — কোনোভাবেই `…-0012` না।
- [ ] একটা ইনভয়েস বানিয়ে নিশ্চিত হও নম্বরটা আগে দেওয়া কোনোটার সাথে মেলে না।
- [ ] শেষে yearly reset আগের অবস্থায় ফিরিয়ে দাও।

## F. COD অর্ডারে PAID ছাপ (ফেজ ১)

- [ ] Settings → Design → "PAID stamp" চালু করো।
- [ ] Cash on Delivery দিয়ে একটা অর্ডার দাও (status: Processing)।
- [ ] ইনভয়েস Generate/Download করো → **প্রত্যাশা:** PAID ছাপ **নেই**।
- [ ] অর্ডার Completed করে "Regenerate" চাপো → **প্রত্যাশা:** এবার PAID ছাপ **আছে**।
- [ ] bKash/Bank বা অন্য অনলাইন পেমেন্টের Processing অর্ডারে → **প্রত্যাশা:** PAID ছাপ আছে।

## G. Refund ID guard (ফেজ ১)

- [ ] যেকোনো অর্ডারে একটা ছোট refund করো, refund-এর ID নাও (অর্ডার নোট বা DB থেকে)।
- [ ] লগআউট অবস্থায় খোলো: `/wp-admin/admin-post.php?action=billio_download&order_id=<REFUND_ID>`
- [ ] **প্রত্যাশা:** "Order not found" (404) — সাদা পেজ / fatal error না।
- [ ] WooCommerce → Status → Logs-এ নতুন কোনো PHP fatal নেই।

## H. Backslash ও অবৈধ অক্ষর (ফেজ ১)

- [ ] Company address-এ `Road\5` লিখে Save → **প্রত্যাশা:** reload-এর পরও `Road\5` থাকে।
- [ ] (ঐচ্ছিক) কোনো পণ্যের নামে অদ্ভুত/ভাঙা অক্ষর থাকলে তার ইনভয়েস PDF ফাঁকা আসে না।

---

## I. Live preview (ফেজ ২)

Save না করে প্রতিটা বদলাও, আর preview সাথে সাথে বদলায় কিনা দেখো:

- [ ] Template: Default ↔ Modern → **প্রত্যাশা:** preview-এর ডিজাইন বদলায় (Modern-এ রঙিন হেডার)।
- [ ] Paper size: A4 ↔ Letter → preview-এর আকার বদলায়।
- [ ] Logo: নতুন logo দাও → preview-তে নতুন logo; "Remove" চাপো → logo চলে যায়।
- [ ] Preview-এর ধরন "Credit note" করে Credit note prefix বদলাও (যেমন `RF`) → নম্বরে `RF` দেখায়।
- [ ] PAID stamp চালু অবস্থায় Save করো, তারপর বন্ধ করো (Save না করে) → **প্রত্যাশা:** preview থেকে PAID ছাপ চলে যায়।
- [ ] "Discard" চাপলে সব আগের অবস্থায় ফেরে, preview-ও।

## J. Packing slip (ফেজ ২)

- [ ] একটা অর্ডার নাও যেখানে একটা পণ্য qty 3।
- [ ] ওই পণ্যের ১টা refund করো (Refund → item qty 1)।
- [ ] অর্ডার পেজ → Billio Invoice বক্স → "Packing Slip" → **প্রত্যাশা:** ওই পণ্যের Qty **2**।
- [ ] অন্য একটা পণ্য পুরো refund করলে → **প্রত্যাশা:** সেটা packing slip-এ একেবারেই নেই।
- [ ] **প্রত্যাশা:** Date হলো অর্ডারের তারিখ, আজকের তারিখ না (পুরনো অর্ডারে টেস্ট করো)।

## K. Credit note (ফেজ ২)

- [ ] শুধু shipping refund করো (Refund → shipping লাইনে amount)।
- [ ] Credit Note PDF খোলো → **প্রত্যাশা:** shipping লাইন নাম আর টাকা সহ দেখায়, Qty ঘরে "—"।
- [ ] পণ্য + shipping একসাথে refund → দুটো লাইনই থাকে, আর যোগফল "Total Refunded"-এর সাথে মেলে।
- [ ] Fee থাকলে fee refund করেও একবার দেখো।

## L. Dashboard (ফেজ ২)

- [ ] Settings-এ "Processing" email trigger চালু করো।
- [ ] ইনভয়েস ছাড়া একটা Processing অর্ডার থাকলে → **প্রত্যাশা:** "Orders without invoice" সংখ্যায় সেটা গোনা হয়।
- [ ] "Generate missing" চালিয়ে শেষ হওয়ার **সাথে সাথে** পেজ reload করো → **প্রত্যাশা:** সংখ্যা ০ / "All caught up" (আগের সংখ্যা থাকবে না)।
- [ ] Chart-এ কোনো বারের উপর mouse রাখো → tooltip-এ "N টি চালান" (বাংলায় সাইট থাকলে) বা "N invoices" — "1 invoices" এর মতো ভুল না।
- [ ] (ঐচ্ছিক) Settings → General-এ timezone `America/New_York` করে chart-এর তারিখ ঠিক দিনে আছে কিনা দেখো, তারপর আগের timezone ফিরিয়ে দাও।

## M. Tax ID row (ফেজ ২)

- [ ] Tax ID ফিচার **বন্ধ** করো।
- [ ] Tax ID ছাড়া কোনো পুরনো অর্ডার খোলো → **প্রত্যাশা:** billing-এর নিচে "Tax ID: —" লাইন নেই।
- [ ] Tax ID আছে এমন অর্ডার খোলো → **প্রত্যাশা:** লাইনটা এখনো দেখায়।

---

## N. সাধারণ রিগ্রেশন চেক

- [ ] নতুন অর্ডার Completed করলে কাস্টমারের ইমেইলে ইনভয়েস PDF attach হয়।
- [ ] Refund করলে refund ইমেইলে credit note PDF attach হয়।
- [ ] Thank You পেজে "Download invoice (PDF)" বাটন কাজ করে (লগইন ছাড়া guest অর্ডারেও)।
- [ ] Orders লিস্টে Invoice কলামের Generate / Download / Resend / Regenerate কাজ করে।
- [ ] Dashboard-এ search (ইনভয়েস নম্বর, কাস্টমার নাম) কাজ করে।
- [ ] সাইটের ভাষা বাংলা (bn_BD) করে Dashboard আর Settings একবার দেখো — নতুন টেক্সটও বাংলায়।
- [ ] WooCommerce → Status → Logs → `billio` লগে নতুন কোনো error নেই।

---

### ফেল করলে যা জানাবে

কোন সেকশনের কোন ধাপ, কী দেখার কথা ছিল আর কী দেখলে, আর স্ক্রিনশট (থাকলে)। তাহলে সরাসরি সেই জায়গাটা ঠিক করা যাবে।
