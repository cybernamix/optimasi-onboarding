=== Optimasi Content Audit ===
Contributors: optimasi
Tags: woocommerce, audit, seo, content, ecommerce
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-contained content audit plugin: runs a free 4-step audit across your
WooCommerce catalog — scan, brand-voice analysis, your Sales-Ready Inspection
Report, and a video showing how Optimasi works. Nothing is
written to your site and no registration is required.

== Description ==

Optimasi Content Audit is a lightweight, self-contained review tool for new Optimasi
clients. In four steps it shows how your product content measures up:

Step 1 — Scan your products. We measure up to the first 1,000 of your published
products right on your site: description length, image count, review count,
brand presence, and which content blocks (brand story, reviews, trust, FAQ,
specs) each product carries. Nothing leaves your site in this step.

Step 2 — Analyse brand voice. We send a short sample of each description to
Optimasi's servers, which report how consistent your tone and vocabulary are,
whether summaries are AI-ready, and flag duplicate content (a short description
that repeats the main description).

Step 3 — Your Sales-Ready report. Your four-page Sales-Ready Standard Inspection
Report: key findings, the results by category, and a prioritised optimisation
roadmap. It is generated on Optimasi's servers from the review already sent —
read it on the page, print or save it as a PDF, or open it in a new tab. You can
also have it emailed to you as a PDF from the card below it. The report is
displayed inline in a sandboxed frame that cannot run scripts or submit forms,
and it loads no remote images, fonts or stylesheets; the "Open in a new tab"
button shows the same document on its own if you prefer.

Step 4 — See How Optimasi Works. Watch a video explaining how Optimasi can help
your WooCommerce store with Sales-ready pages optimised for AI search. The link
opens optimasi.io in a new tab, so the report stays on screen, and nothing is
sent from your site for this step.

Once the report is on screen you can, if you want to, ask Optimasi for a
one-on-one. Pick how you would rather be contacted — Google Meet, a Microsoft
Teams call, WhatsApp, Viber, or email — and we will arrange it. This is entirely
optional: nothing is sent unless you submit that form yourself.

The whole review is free and read-only: nothing is published to your store. At
the end you get the new four-page Sales-Ready Standard Inspection Report, which
you can print or save as a PDF — and you can optionally enter an email to have
it sent to you (permission only — no spam).

This plugin intentionally does NOT include the full Optimasi Content Manager
feature set (brand profiles, trust content, FAQ, collections, tabs, beacon).
Install the Optimasi Content Manager plugin when you want changes applied
automatically.

== External services ==

This plugin is the free audit front end for the Optimasi content service. It
connects to one external service — the Optimasi platform at https://optimasi.io —
and to nothing else. No third-party trackers, analytics, fonts or CDNs are
loaded; all JavaScript and CSS this plugin uses is bundled with it, and the admin
UI uses WordPress' own bundled jQuery.

= Which host the review calls =

Every request this plugin makes goes to the review service at
**https://staging.optimasi.dev**, which is the Optimasi backend that analyses
brand voice, renders the Sales-Ready report, sends the report email and records
an optional contact request. The linked pages — terms, privacy policy and the
how-it-works video — are on https://optimasi.io.

A site can point the review somewhere else, without any other change, by
defining `OPTIMASI_ONBOARDING_API_URL` in `wp-config.php`. A store that was
connected through the full Optimasi Content Manager keeps using its own regional
service instead — an explicit setting is never overridden by the default.

= What is sent, and when =

Step 1 (scan your products) runs entirely on your site. Nothing is sent.

Step 2 (analyse brand voice) sends a short sample of each product description
(title, short description and main description), the product category names, and
your site URL, to measure how consistent your tone and vocabulary are across the
catalogue. This request is made only after you tick the consent checkbox on that
step. Your consent is remembered, so you are asked once rather than on every run.

Step 3 (your report) saves the finished review to Optimasi — the per-product
metrics for up to the first 1,000 products (lengths, counts, and which content
blocks each product carries), together with each product's title, its category
name and your site URL — so that your report can be rendered and reopened later.
No product description text is sent in this step. From then on the report is
fetched by reference: the plugin sends the report token it was given, not the
data again.

The "Email me this report" card sends the same report reference plus the email
address you type, in order to send you the report.

The "Request a one-on-one" form sends only what you entered: the contact channel
you selected and, if you provided them, a phone number, an email address and your
role. It is submitted only when you press that button.

Connecting the store (optional) sends your site URL and this plugin's version to
Optimasi, which registers the store and issues a site key that you then review
and approve. The review itself needs no account, and nothing is registered until
you press that button.

Step 4 (how Optimasi works) sends nothing from your store: it opens a video page
on optimasi.io, in a new tab, and makes no other request.

= What is never sent =

Customer records, orders, addresses, payment details, customer email addresses —
none of these are read or transmitted. No product data beyond the description
sample and category names listed above is ever sent.

= Links =

Terms of Use: https://optimasi.io/terms
Privacy policy: https://optimasi.io/privacy

== Installation ==

1. Upload the `optimasi-onboarding` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Open **Content Audit** in the admin menu and run the 4-step review.

== Frequently Asked Questions ==

= Does the audit send my data anywhere? =

Only what each step spells out. Step 1 (scan) runs entirely on your site. Step 2
sends a short sample of each product description to Optimasi's servers to
analyse brand voice — that is stated on the step, and it waits for the consent
checkbox on it. Step 3 saves the finished review to Optimasi so the four-page
report can be rendered and reopened later: the per-product metrics for up to the
first 1,000 products (lengths, counts, and which content blocks each product
carries), together with each product's title, its category name and your site
URL. No product description text is sent in that step. After that the report is
fetched by reference, so no product data travels again. Step 4 (how Optimasi
works) sends nothing at all: it opens a video page on optimasi.io in a new tab
and makes no other request. No customer data, emails or purchase history is sent.

= Do you write anything to my site? =

No. Everything you see is review-only. Nothing is written to your live store.

= What happens when I've finished the audit? =

Deactivate this plugin and install the Optimasi Content Manager plugin. It reads
the same connection options, so your connection is preserved.

= Do you contact me if I don't ask? =

No. The one-on-one form submits only when you press its button, and we use what
you entered there solely to arrange the call you asked for. The email card is the
same: it sends the report to the address you typed, and nothing else. Every
request the plugin makes is listed under External services.

= Can you delete what you hold? =

Yes. Contact us through the details on https://optimasi.io and we will delete the
description sample and any contact details we hold for your store.

== Changelog ==

= 1.0.0 =
* First public release.

* **A free, four-step content review.** Scan up to the first 1,000 published
  WooCommerce products, analyse brand voice across a description sample, read the
  four-page Sales-Ready Standard Inspection Report, and watch a video explaining
  how Optimasi works. Nothing is written to the store and no registration or
  account is required.

* **Step 1 runs entirely on your site.** Description length and headings, image
  count, review count and rating, brand, categories, attributes, and which
  content blocks each product carries. No request is made in this step.

* **Step 2 is the only step that sends product text**, and it is refused on the
  server unless the consent checkbox on that step is ticked — not merely hidden in
  the browser. The decision is remembered, so it is asked once rather than on
  every run.

* **Step 3 renders the report on Optimasi's servers** from the snapshot step 2
  already sent, and displays it in a sandboxed frame that cannot run scripts or
  submit forms. Print / Save as PDF and Open in a new tab show the same document,
  and it can be emailed to you as a PDF.

* **"How Optimasi can help"** offer panel, revealed with the report, with the same
  five contact channels the emailed contact page uses.

* **Works on a fresh install with no configuration.** The plugin ships pointing at
  Optimasi's review service; the endpoint field is available for pointing a test
  or agency site at a different instance.

* **Read-only, and small.** Six files, no database tables, no custom post types,
  no build step. Uninstalling removes the plugin's own settings and nothing else.
