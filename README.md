# Optimasi Content Audit

A free, four-step **content review** for a WooCommerce catalogue. It measures what
your product pages have, reports what is missing, and shows you the four-page
report a prospective client keeps — without writing anything to your store and
without an account.

| Step | What happens | Where it runs |
|---|---|---|
| 1. Scan your products | measures up to 1,000 published products: description length and headings, images, reviews, brand, categories, attributes, and which content blocks each one carries | **your own site** — nothing leaves it |
| 2. Analyse brand voice | sends a short sample of descriptions to measure tone/vocabulary consistency, AI-readiness and duplicate copy | Optimasi's servers — **only after you tick the consent box** |
| 3. Your Sales-Ready report | the four-page Sales-Ready Standard Inspection Report: key findings, results by category, prioritised roadmap | Optimasi's servers |
| 4. See How Optimasi Works | opens a video page in a new tab | nothing is sent |

**Nothing is written to your store.** No tables, no options on your storefront, no
product data — the plugin reads and reports. Its only stored state is the
remembered consent decision and the last report's reference.

## How Optimasi works

**Watch the walkthrough: <https://optimasi.io/how-it-works/>**

Step 4 of the audit opens this page. The walkthrough explains how Optimasi closes the
gaps the audit measures, so you can see what comes after the report before deciding
whether you want any of it.

## Install

1. Download `optimasi-onboarding.zip` from the [latest release](https://github.com/cybernamix/optimasi-onboarding/releases/latest).
2. **WordPress Admin → Plugins → Add New → Upload Plugin** → choose the zip → **Install Now** → **Activate**.
3. Open **Content Audit** in the admin menu and press **Scan my products**.

Upload through the Plugins screen rather than extracting the zip on the server:
WordPress's own uploader sets correct file permissions, while a raw `unzip` (or
cPanel's File Manager) can leave the files world-writable.

## Requirements

- WordPress 6.0 or later, PHP 8.0 or later.
- WooCommerce, active — the scan reads WooCommerce products.
- Outbound HTTPS to Optimasi's review service for steps 2 and 3. Step 1 needs no
  network at all.

## What it sends, and when

The plugin's own `readme.txt` (inside `optimasi-onboarding/`) is the canonical
account of every request it makes, in the **External services** section: which
step sends what, to which host, and why. It is the same text submitted to the
WordPress.org directory, so it is the place to check rather than a summary here.

In short: step 1 sends nothing, step 2 sends description samples and refuses to
run without consent, step 3 sends the review snapshot once and then fetches the
report by reference, and step 4 sends nothing. Customer records, orders,
addresses, payment details and customer email addresses are never read.

## Licence

GPL-2.0-or-later. The full licence text ships inside the plugin as
`optimasi-onboarding/license.txt`, so the distributed zip is self-contained.

## Support

Optimasi — <https://optimasi.io>. The plugin is a **content audit** of your own
product pages: it reads what you have published and reports what a shopper would
find missing, so you can judge and improve the content yourself. It is free and
needs no account.

## Releases

`main` is the released source; each release is tagged `vX.Y.Z` and its built zip
is attached to the GitHub release. Marketing links should point at
`releases/latest/download/optimasi-onboarding.zip` so they never go stale.
