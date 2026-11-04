<?php
/**
 * Optimasi Content Audit — 4-step content review (scan / brand voice / report /
 * video).
 *
 * A self-contained, review-only content-audit tool for new Optimasi clients:
 *   Step 1 (local)  – scans up to the first 1000 published products (facts only;
 *                     nothing leaves the site).
 *   Step 2 (server) – sends short description samples to the Optimasi review
 *                     endpoint, which returns brand-voice consistency, a
 *                     weighted completeness score and AI-readiness checks.
 *   Step 3 (server) – renders the 4-page Sales-Ready Inspection Report from the
 *                     snapshot Step 2 already sent — the deliverable the store
 *                     keeps (print / save as PDF / open in a new tab).
 *   Step 4 (server) – shows illustrative Optimasi example pages (before/after
 *                     on fictional stores) — never the client's own data.
 *
 * Nothing is ever written to the client's site and no registration / site key
 * is required — this is a free read-only review. The heavier "connect and apply
 * changes" path lives in the full Optimasi Content Manager plugin.
 *
 * IP firewall: this plugin never ships scoring weights, thresholds or NLP
 * heuristics — everything clever lives on the Optimasi server. The plugin only
 * collects facts and renders what the server returns.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Optimasi_Onboarding {

    // Distinct menu identity so this plugin never collides with the full
    // Optimasi Content Manager plugin (which owns the 'optimasi' slug).
    const MENU_SLUG = 'optimasi-onboard';

    // Shared with the full Optimasi Content Manager plugin (handoff).
    const OPTION_URL           = 'optimasi_ingest_url';
    // Dedicated endpoint for the FREE REVIEW funnel, stored per site (config
    // data, never a code URL). Distinct from OPTION_URL (which the full plugin
    // points at the store's regional API) so a beta store can test the review
    // against a specific service without touching its main connection.
    const OPTION_API_URL       = 'optimasi_onboarding_api_url';
    const OPTION_SITE_KEY      = 'optimasi_site_key';
    const OPTION_CONNECTION    = 'optimasi_connection_status'; // not_connected|connected
    const OPTION_ACCOUNT       = 'optimasi_account_info';      // JSON array from the server
    const OPTION_CONSENT_MEASUREMENTS = 'optimasi_audit_consent_measurements';
    const OPTION_CONSENT_SAMPLES      = 'optimasi_audit_consent_samples';
    const OPTION_CONSENT_PREVIEW      = 'optimasi_audit_consent_preview';
    // Personal report token for the last completed review on this site. Lets the
    // report page re-render the new 4-page report after a page reload (the token
    // stays server-side; it is never printed into the page).
    const OPTION_REPORT_TOKEN          = 'optimasi_onboarding_report_token';

    // --- Optimasi service ---
    // Named in the readme because WP.org guideline 6 requires the service behind
    // the plugin to be documented, preferably with a link to its terms.
    const SERVICE_URL  = 'https://optimasi.io';
    const TERMS_URL    = 'https://optimasi.io/terms';
    const PRIVACY_URL  = 'https://optimasi.io/privacy';

    // The review service used when the site has configured nothing.
    //
    // This plugin used to ship NO default, and the consequence was two faults at
    // once: every server-side step (brand voice, report, request-key) failed with
    // "Could not reach the Optimasi service" — a wrong diagnosis of an empty
    // setting — and the Advanced endpoint panel had to be public because it was the
    // only way a merchant could fix it. A default removes both.
    //
    // Resolution order in api_base_url(): the OPTIMASI_ONBOARDING_API_URL constant,
    // then the option saved by that panel, then OPTIMASI_INGEST_URL, then the option
    // the full Content Manager writes, then THIS — so any explicit configuration
    // still wins and a connected store keeps talking to its own regional service.
    //
    // Deliberately the staging host: the long-term backend/admin domain is not
    // decided yet (2026-11-03) and marketing already runs on this instance.
    const DEFAULT_API_URL = 'https://staging.optimasi.dev';

    // Step 4 links out rather than fetching a gallery. The video page is where
    // Optimasi's own explanation lives, so this is one canonical page instead of
    // a copy of it that would drift.
    const HOW_IT_WORKS_URL = 'https://optimasi.io/how-it-works/';

    // Preferred-channel contact options. These values are the backend's contract
    // (see _CONTACT_CHANNELS in the API) — changing one here without changing it
    // there makes the request fail validation.
    const CONTACT_CHANNELS = [ 'google_meet', 'ms_teams', 'whatsapp', 'viber', 'email' ];
    const CONTACT_CHANNEL_LABELS = [
        'google_meet' => 'Google Meet',
        'ms_teams'    => 'Microsoft Teams call',
        'whatsapp'    => 'WhatsApp',
        'viber'       => 'Viber',
        'email'       => 'Email is fine — just send me a link',
    ];
    // Channels where we need a phone number to actually reach them.
    const CONTACT_NUMBER_CHANNELS = [ 'whatsapp', 'viber' ];
    const CONTACT_ROLES = [ 'agency', 'store_owner', 'ecommerce_professional', 'store_manager' ];
    const CONTACT_ROLE_LABELS = [
        'agency'                  => 'An agency',
        'store_owner'             => 'A store owner',
        'ecommerce_professional'  => 'An ecommerce professional',
        'store_manager'           => 'A store manager',
    ];

    // Explicit consent for the one step that ships product description text off
    // the site. Nothing is sent unless this is true — see analyse_voice().
    const OPTION_CONSENT_SEND = 'optimasi_onboarding_consent_send_samples';

    // Scan/analysis budget: we scan up to SCAN_CAP products (representative
    // slice), but brand voice is judged from a VOICE_SAMPLE even spread — a few
    // dozen descriptions give a reliable tone read without shipping every page.
    const SCAN_CAP     = 1000;
    const VOICE_SAMPLE = 50;

    public static function init(): void {
        add_action( 'admin_menu',           [ self::class, 'add_menu_pages' ] );
        add_action( 'admin_init',           [ self::class, 'register_options' ] );
        add_action( 'admin_init',           [ self::class, 'add_privacy_policy_content' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_audit_assets' ] );
        add_action( 'wp_ajax_optimasi_run_content_audit', [ self::class, 'handle_content_audit' ] );
        // The report as a standalone sandboxed document. "Open in a new tab"
        // navigates here rather than re-wrapping the HTML in a blob in the browser —
        // see serve_report_document().
        add_action( 'admin_post_optimasi_report_document', [ self::class, 'serve_report_document' ] );
    }

    /**
     * Suggest privacy-policy text to the site owner.
     *
     * The review sends product description samples and, when the visitor asks
     * for one, contact details to Optimasi. WordPress' privacy tool is the right
     * place to say so, so the same account of it that the readme gives is offered
     * here as text the owner can insert into their policy (WP.org guideline 7).
     */
    public static function add_privacy_policy_content(): void {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }
        $content = '<p class="privacy-policy-tutorial">'
            . esc_html__( 'Suggested text: the Optimasi Content Audit plugin runs a free review of your product content. The scanning step runs entirely on your site. Later steps send data to Optimasi, as described below.', 'optimasi-onboarding' )
            . '</p><p>'
            . esc_html__( 'When you run the brand-voice step, a short sample of each published product description — its title, short description and main description — together with the product category names and this site\'s URL, is sent to the Optimasi review service. This happens only after you tick the consent checkbox on that step. Analysing that sample is how the tone and vocabulary across your catalogue are measured.', 'optimasi-onboarding' )
            . '</p><p>'
            . esc_html__( 'When the review finishes it is saved to Optimasi so that the report can be rendered and reopened later: the per-product metrics for up to the first 1,000 products — lengths, counts and which content blocks each product carries — together with each product\'s title, its category name and this site\'s URL. No product description text is sent in this step. After that the report is fetched by reference, so no product data travels again.', 'optimasi-onboarding' )
            . '</p><p>'
            . esc_html__( 'Rendering your report and emailing it to you send the report reference that the previous step returned, plus the email address you enter if you ask for the report to be emailed.', 'optimasi-onboarding' )
            . '</p><p>'
            . esc_html__( 'If you ask for a one-on-one, the contact channel you select and the phone number, email address and role you enter are sent to Optimasi so that the call can be arranged.', 'optimasi-onboarding' )
            . '</p><p>'
            . esc_html__( 'Customer records, orders, addresses, payment details and customer email addresses are never read or sent. No data is sent by the product-scanning step or the how-it-works step.', 'optimasi-onboarding' )
            . '</p><p>'
            . sprintf(
                /* translators: 1: Terms of Use URL, 2: privacy policy URL. */
                esc_html__( 'Optimasi\'s Terms of Use are at %1$s and its privacy policy at %2$s.', 'optimasi-onboarding' ),
                esc_url( self::TERMS_URL ),
                esc_url( self::PRIVACY_URL )
            )
            . '</p>';

        wp_add_privacy_policy_content( 'Optimasi Content Audit', $content );
    }

    /**
     * Enqueue audit CSS/JS on the audit admin page.
     */
    public static function enqueue_audit_assets(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== ( 'toplevel_page_' . self::MENU_SLUG ) ) {
            return;
        }
        wp_enqueue_style(
            'optimasi-onboarding-css',
            plugin_dir_url( __FILE__ ) . '../assets/css/audit.css',
            [],
            OPTIMASI_ONBOARDING_VERSION
        );
        wp_enqueue_script(
            'optimasi-onboarding-js',
            plugin_dir_url( __FILE__ ) . '../assets/js/audit.js',
            [ 'jquery' ],
            OPTIMASI_ONBOARDING_VERSION,
            true
        );
        wp_localize_script(
            'optimasi-onboarding-js',
            'optimasiAudit',
            [
                'ajax_url'          => admin_url( 'admin-ajax.php' ),
                'security'          => wp_create_nonce( 'optimasi_content_audit_nonce' ),
                'connection_status' => self::get_connection_status(),
                'account'           => self::get_account_info_for_js(),
                'consent'           => [
                    'measurements' => (bool) get_option( self::OPTION_CONSENT_MEASUREMENTS, true ),
                    'samples'      => (bool) get_option( self::OPTION_CONSENT_SAMPLES, false ),
                    'preview'      => (bool) get_option( self::OPTION_CONSENT_PREVIEW, false ),
                ],
                // A report token from a previous run lets the page re-render the
                // new 4-page report on load without re-running the review.
                'has_report'        => ! empty( get_option( self::OPTION_REPORT_TOKEN, '' ) ),
                // Consent is remembered, so the checkbox can start ticked.
                'consent_recorded'  => self::consent_recorded(),
                // Step 4's destination. Localised rather than hard-coded in the
                // script so the URL has one home.
                'how_it_works_url'  => self::HOW_IT_WORKS_URL,
                // "Open in a new tab" navigates to this admin-post route, which serves
                // the report with `Content-Security-Policy: sandbox`. The report token
                // is never sent to the browser.
                'report_url'        => wp_nonce_url(
                    admin_url( 'admin-post.php?action=optimasi_report_document' ),
                    'optimasi_report_document'
                ),
            ]
        );
    }

    // ------------------------------------------------------------------
    // Admin menu
    // ------------------------------------------------------------------

    public static function add_menu_pages(): void {
        // Single menu item: 'Content Audit' opens the audit page directly.
        //
        // $page_title and $menu_title differ on purpose. $page_title is what the
        // browser tab and the admin page header read, so it carries the full product
        // name; $menu_title is the sidebar, where 22 characters wrap onto a second
        // line and push the rest of the menu down.
        add_menu_page(
            'Optimasi Content Audit',
            'Content Audit',
            'manage_options',
            self::MENU_SLUG,
            [ self::class, 'render_audit_page' ],
            'dashicons-megaphone',
            30
        );
    }

    // ------------------------------------------------------------------
    // Options (same keys as the full plugin for handoff)
    // ------------------------------------------------------------------

    public static function register_options(): void {
        register_setting( 'optimasi_beacon_settings', self::OPTION_SITE_KEY, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ] );
        register_setting( 'optimasi_beacon_settings', self::OPTION_CONNECTION, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default'           => 'not_connected',
        ] );
        register_setting( 'optimasi_beacon_settings', self::OPTION_ACCOUNT, [
            'type'    => 'array',
            'default' => [],
        ] );
        register_setting( 'optimasi_beacon_settings', self::OPTION_CONSENT_MEASUREMENTS, [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => true,
        ] );
        register_setting( 'optimasi_beacon_settings', self::OPTION_CONSENT_SAMPLES, [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ] );
        register_setting( 'optimasi_beacon_settings', self::OPTION_CONSENT_PREVIEW, [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ] );
        register_setting( 'optimasi_beacon_settings', self::OPTION_API_URL, [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ] );
    }

    // ------------------------------------------------------------------
    // Audit page rendering (revealed after the free audit completes)
    // ------------------------------------------------------------------

    public static function render_audit_page(): void {
        // One capability, and the same one the menu uses. The page and the AJAX
        // dispatcher used to accept `manage_woocommerce` as well, which meant a role
        // with no menu item could still reach the surface by URL — including the
        // endpoint field, which is a privileged configuration operation wearing a
        // settings-page costume.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap optimasi-audit">
            <h1><?php esc_html_e( 'Optimasi Ecommerce Content Completeness Audit', 'optimasi-onboarding' ); ?></h1>
            <p class="optimasi-lead"><?php esc_html_e( 'A free 4-step review of your product content — nothing is written to your site and no registration is required.', 'optimasi-onboarding' ); ?></p>

            <ol class="optimasi-steps">
                <li id="optimasi-step-1" class="optimasi-step is-active">
                    <div class="optimasi-step-head">
                        <span class="optimasi-step-num">1</span>
                        <div class="optimasi-step-title">
                            <h2><?php esc_html_e( 'Scan your products', 'optimasi-onboarding' ); ?></h2>
                            <p><?php esc_html_e( 'We measure up to the first 1,000 of your published products right on your site — description length, image count, reviews, and which content blocks each product has. Nothing is sent to Optimasi during this step.', 'optimasi-onboarding' ); ?></p>
                        </div>
                        <span class="optimasi-step-state"></span>
                    </div>
                    <div class="optimasi-step-actions">
                        <button id="optimasi-run-audit" class="button button-primary">
                            <?php esc_html_e( 'Scan my products', 'optimasi-onboarding' ); ?>
                        </button>
                    </div>
                    <div id="optimasi-result-1" class="optimasi-step-result"></div>
                </li>

                <li id="optimasi-step-2" class="optimasi-step is-locked">
                    <div class="optimasi-step-head">
                        <span class="optimasi-step-num">2</span>
                        <div class="optimasi-step-title">
                            <h2><?php esc_html_e( 'Analyse your brand voice', 'optimasi-onboarding' ); ?></h2>
                            <p><?php esc_html_e( "We send a short sample of each scanned product description to Optimasi's servers and check whether your tone and vocabulary are consistent across the catalog.", 'optimasi-onboarding' ); ?></p>
                        </div>
                        <span class="optimasi-step-state"></span>
                    </div>
                    <div class="optimasi-step-actions">
                        <button id="optimasi-analyse-voice" class="button button-primary" disabled>
                            <?php esc_html_e( 'Analyse brand voice', 'optimasi-onboarding' ); ?>
                        </button>
                    </div>
                    <!-- The only step that ships product description text off the
                         site, so the only step that needs consent (WP.org guideline
                         7). Remembered once given, so it is not re-asked on every
                         run — and the send is refused server-side without it. -->
                    <p class="optimasi-consent">
                        <label for="optimasi-consent-send">
                            <input type="checkbox" id="optimasi-consent-send"<?php checked( self::consent_recorded() ); ?> />
                            <?php esc_html_e( 'I agree to send a short sample of my product descriptions to Optimasi for this analysis.', 'optimasi-onboarding' ); ?>
                        </label>
                    </p>
                    <div id="optimasi-result-2" class="optimasi-step-result"></div>
                </li>

                <!-- Step 3 is the deliverable itself: the new 4-page Sales-Ready
                     report, rendered server-side from the stored snapshot and
                     shown here in place of the old sectioned on-site report. It
                     sits before the example pages because the report is what the
                     store keeps — the examples are illustrative only. -->
                <li id="optimasi-step-3" class="optimasi-step is-report is-locked">
                    <div class="optimasi-step-head">
                        <span class="optimasi-step-num">3</span>
                        <div class="optimasi-step-title">
                            <h2><?php esc_html_e( 'Your Sales-Ready Standard Inspection Report', 'optimasi-onboarding' ); ?></h2>
                            <p><?php esc_html_e( 'Your full four-page report — key findings, results by category and a prioritised optimisation roadmap. Print or save it as a PDF, or open it in a new tab.', 'optimasi-onboarding' ); ?></p>
                        </div>
                        <span class="optimasi-step-state"></span>
                    </div>
                    <div class="optimasi-step-actions">
                        <button id="optimasi-report-print" class="button button-primary" disabled>
                            <?php esc_html_e( 'Print / Save as PDF', 'optimasi-onboarding' ); ?>
                        </button>
                        <button id="optimasi-report-open" class="button button-secondary" disabled>
                            <?php esc_html_e( 'Open in a new tab', 'optimasi-onboarding' ); ?>
                        </button>
                    </div>
                    <div id="optimasi-report-status" class="optimasi-report-status" aria-live="polite"></div>
                    <iframe id="optimasi-report-frame" class="optimasi-report-frame" title="<?php esc_attr_e( 'Sales-Ready Standard Inspection Report', 'optimasi-onboarding' ); ?>" sandbox="allow-same-origin" style="display:none;"></iframe>
                </li>

            </ol>

            <!-- The email capture belongs with the report it sends, so it sits
                 between step 3 and the example pages rather than at the foot of
                 the page. The steps list is split in two so it can interrupt
                 the sequence legally — an <ol> may only contain <li> — and
                 start="4" keeps step 4's position in the ordered list for
                 assistive tech (the visible numbers are the .optimasi-step-num
                 spans). -->
            <div id="optimasi-report-extra" class="optimasi-report-extra is-hidden">
                <div class="optimasi-email-card">
                    <h2><?php esc_html_e( 'Email me this report', 'optimasi-onboarding' ); ?></h2>
                    <p><?php esc_html_e( 'Enter your email and we’ll send you this four-page report as a PDF, along with next-step suggestions. No spam — unsubscribe anytime.', 'optimasi-onboarding' ); ?></p>
                    <div class="optimasi-email-form">
                        <input type="email" id="optimasi-report-email" class="regular-text" autocomplete="email" placeholder="you@yourstore.com" />
                        <button id="optimasi-email-submit" class="button button-primary">
                            <?php esc_html_e( 'Email me this report', 'optimasi-onboarding' ); ?>
                        </button>
                        <span id="optimasi-email-spinner" class="spinner"></span>
                    </div>
                    <p id="optimasi-email-message" class="optimasi-inline-message"></p>
                </div>
            </div>

            <!-- The offer. Sits above the example pages, revealed once there is a
                 report to talk about, and kept to the plugin's own page — WP.org
                 guideline 11 permits a contextual upsell here, but not site-wide
                 notices. The channel list mirrors the emailed contact page
                 exactly (the same values the backend validates), so a choice made
                 here lands in the same sales-contact record as one made from an
                 email. -->
            <div id="optimasi-help" class="optimasi-help is-hidden">
                <h2><?php esc_html_e( 'How Optimasi can help', 'optimasi-onboarding' ); ?></h2>
                <p class="optimasi-help-copy"><?php esc_html_e( 'Your report shows what is missing. Optimasi closes the gap: we write the descriptions, brand storytelling, FAQs, review summaries and trust content your catalogue needs, in your brand voice, and apply it to your product pages. Most stores see that as minutes per product rather than the hours it takes to write from scratch — and because every block is grounded in your own product facts and spec sheets, nothing is invented.', 'optimasi-onboarding' ); ?></p>
                <p class="optimasi-help-copy"><?php esc_html_e( 'We can work through the whole roadmap above, or start with the one category costing you the most. Either way you keep control: nothing is published without your review.', 'optimasi-onboarding' ); ?></p>

                <form id="optimasi-contact-form" class="optimasi-contact-form">
                    <fieldset class="optimasi-contact-channels">
                        <legend><?php esc_html_e( 'I’d prefer to talk it through on', 'optimasi-onboarding' ); ?></legend>
                        <?php foreach ( self::CONTACT_CHANNELS as $index => $channel ) : ?>
                            <label class="optimasi-contact-opt">
                                <input type="radio" name="optimasi_channel" value="<?php echo esc_attr( $channel ); ?>"<?php checked( 0 === $index ); ?> />
                                <?php echo esc_html( self::CONTACT_CHANNEL_LABELS[ $channel ] ); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <div id="optimasi-contact-number-wrap" class="optimasi-contact-field is-hidden">
                        <label for="optimasi-contact-number"><?php esc_html_e( 'Your number (international format)', 'optimasi-onboarding' ); ?></label>
                        <input type="tel" id="optimasi-contact-number" class="regular-text" placeholder="+61 4xx xxx xxx" autocomplete="tel" />
                    </div>

                    <div class="optimasi-contact-field">
                        <label for="optimasi-contact-email"><?php esc_html_e( 'Your email (so we can reach you)', 'optimasi-onboarding' ); ?></label>
                        <input type="email" id="optimasi-contact-email" class="regular-text" autocomplete="email" placeholder="you@yourstore.com" />
                    </div>

                    <div class="optimasi-contact-field">
                        <label for="optimasi-contact-role"><?php esc_html_e( 'I am (optional)', 'optimasi-onboarding' ); ?></label>
                        <select id="optimasi-contact-role">
                            <option value=""><?php esc_html_e( 'Select…', 'optimasi-onboarding' ); ?></option>
                            <?php foreach ( self::CONTACT_ROLES as $role ) : ?>
                                <option value="<?php echo esc_attr( $role ); ?>"><?php echo esc_html( self::CONTACT_ROLE_LABELS[ $role ] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <p class="optimasi-contact-submit">
                        <button id="optimasi-contact-submit" class="button button-primary">
                            <?php esc_html_e( 'Request a one-on-one', 'optimasi-onboarding' ); ?>
                        </button>
                        <span id="optimasi-contact-spinner" class="spinner"></span>
                    </p>
                    <p id="optimasi-contact-message" class="optimasi-inline-message" aria-live="polite"></p>
                    <p class="optimasi-contact-privacy"><?php
                        printf(
                            /* translators: %s: link to the Optimasi privacy policy. */
                            esc_html__( 'We use what you enter here only to contact you about your store, as described in the %s.', 'optimasi-onboarding' ),
                            '<a href="' . esc_url( self::PRIVACY_URL ) . '" target="_blank" rel="noopener noreferrer">'
                                . esc_html__( 'Optimasi privacy policy', 'optimasi-onboarding' ) . '</a>'
                        );
                    ?></p>
                </form>
            </div>

            <ol class="optimasi-steps" start="4">
                <li id="optimasi-step-4" class="optimasi-step is-locked">
                    <div class="optimasi-step-head">
                        <span class="optimasi-step-num">4</span>
                        <div class="optimasi-step-title">
                            <h2><?php esc_html_e( 'See How Optimasi Works', 'optimasi-onboarding' ); ?></h2>
                            <p><?php esc_html_e( 'Watch a video explaining how Optimasi can help your WooCommerce store with Sales-ready pages optimised for AI search.', 'optimasi-onboarding' ); ?></p>
                        </div>
                        <span class="optimasi-step-state"></span>
                    </div>
                    <div class="optimasi-step-actions">
                        <!-- The id is `optimasi-how-it-works`: this button opens the
                             how-it-works video in a new tab and makes no request. It
                             was called `optimasi-preview-rewrites` from when the step
                             fetched a rewrite-preview gallery, which it no longer does. -->
                        <button id="optimasi-how-it-works" class="button button-primary" disabled>
                            <?php esc_html_e( 'See how it works', 'optimasi-onboarding' ); ?>
                        </button>
                    </div>
                    <div id="optimasi-result-4" class="optimasi-step-result"></div>
                </li>
            </ol>

            <?php if ( self::show_advanced_panel() ) : ?>
            <div class="optimasi-advanced">
                <details>
                    <summary><?php esc_html_e( 'Advanced — review service endpoint', 'optimasi-onboarding' ); ?></summary>
                    <p class="description"><?php
                        printf(
                            /* translators: %s: the default review service URL. */
                            esc_html__( 'Where this free review runs. Leave blank to use the default service (%s). Set this only to point a test or agency site at a different instance — a wrong value here breaks steps 2, 3 and connect, and nothing in the page will say why.', 'optimasi-onboarding' ),
                            esc_html( self::DEFAULT_API_URL )
                        );
                    ?></p>
                    <div class="optimasi-email-form">
                        <input type="url" id="optimasi-api-url" class="regular-text code" placeholder="<?php echo esc_attr( self::DEFAULT_API_URL ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_API_URL, '' ) ); ?>" />
                        <button id="optimasi-api-url-save" class="button button-secondary"><?php esc_html_e( 'Save endpoint', 'optimasi-onboarding' ); ?></button>
                        <span id="optimasi-api-url-spinner" class="spinner"></span>
                    </div>
                    <p id="optimasi-api-url-message" class="optimasi-inline-message"></p>
                </details>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // AJAX handler (subaction dispatcher)
    // ------------------------------------------------------------------

    public static function handle_content_audit(): void {
        check_ajax_referer( 'optimasi_content_audit_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'optimasi-onboarding' ) );
            return;
        }

        $subaction = sanitize_key( (string) ( $_POST['subaction'] ?? 'run_local' ) );
        // Subactions surfaced in this UI: run_local, analyse_voice, get_findings, get_explainers,
        // email_report, report_snapshot, report_page, contact_request, save_api_url. The
        // connect / request_key / disconnect / deep_* cases are reserved for the paid
        // "apply changes" handoff in the full Optimasi Content Manager plugin, so they are
        // kept but not rendered on this page.
        switch ( $subaction ) {
            case 'connect':
                self::connect_store();
                return;
            case 'request_key':
                self::request_key();
                return;
            case 'analyse_voice':
                self::analyse_voice();
                return;
            case 'get_findings':
                self::get_findings();
                return;
            case 'get_explainers':
                self::get_explainers();
                return;
            case 'email_report':
                self::email_report();
                return;
            case 'report_snapshot':
                self::report_snapshot();
                return;
            case 'report_page':
                self::report_page();
                return;
            case 'contact_request':
                self::contact_request();
                return;
            case 'flush_previews':
                self::flush_previews();
                return;
            case 'save_api_url':
                self::save_api_url();
                return;
            case 'disconnect':
                self::disconnect_store();
                return;
            case 'deep_init':
                self::deep_init();
                return;
            case 'deep_poll':
                self::deep_poll();
                return;
            case 'run_local':
            default:
                self::run_local_audit();
        }
    }

    // ------------------------------------------------------------------
    // Stage 1: local audit — factual measurements only (no scoring)
    // ------------------------------------------------------------------

    private static function run_local_audit(): void {
        if ( ! function_exists( 'wc_get_products' ) || ! function_exists( 'wc_get_product' ) ) {
            wp_send_json_error( __( 'WooCommerce is not active.', 'optimasi-onboarding' ) );
            return;
        }
        $data                      = self::collect_measurements();
        $data['connection_status'] = self::get_connection_status();
        wp_send_json_success( $data );
    }

    /**
     * Analyse brand voice across the scanned products (review-only, no key).
     *
     * Sends each product's measured facts plus a short description sample to the
     * Optimasi review endpoint (/api/v1/preview/analyse), which returns the
     * weighted completeness score, brand-voice consistency and AI-readiness
     * rubric. All scoring happens server-side; nothing is written to this site
     * and no registration / site key is required.
     */
    private static function analyse_voice(): void {
        if ( ! function_exists( 'wc_get_products' ) || ! function_exists( 'wc_get_product' ) ) {
            self::send_error( __( 'WooCommerce is not active.', 'optimasi-onboarding' ), 422, 'woocommerce_inactive' );
            return;
        }

        // This is the one step that sends product description text off the site,
        // so it is the one step that needs consent (WP.org guideline 7). The
        // checkbox on the step records it and it is remembered, so the visitor
        // answers once rather than on every run. Nothing is sent without it.
        if ( self::record_or_require_consent() === false ) {
            return;
        }

        $measurements = self::collect_measurements( true, self::VOICE_SAMPLE );
        // Send only the sampled products (those carrying description text).
        // Brand voice is a statistical read — a representative spread is enough.
        $products_sent = [];
        foreach ( $measurements['products'] as $product ) {
            if ( ! empty( $product['original_long'] ) || ! empty( $product['original_short'] ) ) {
                $products_sent[] = $product;
            }
        }
        if ( empty( $products_sent ) ) { // tiny / edge catalog — send what we have.
            $products_sent = $measurements['products'];
        }
        $response = self::api_request( 'POST', '/api/v1/preview/analyse', [
            'site_url' => home_url( '/' ),
            'consent'  => [ 'send_description_samples' => true ],
            'products' => $products_sent,
        ], 30.0 );

        if ( is_wp_error( $response ) ) {
            self::send_error( __( 'Could not reach the Optimasi service.', 'optimasi-onboarding' ), 503, 'unreachable' );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $data ) || empty( $data['result'] ) ) {
            $msg = ( is_array( $data ) && ! empty( $data['message'] ) )
                ? sanitize_text_field( (string) $data['message'] )
                : __( 'Could not analyse your brand voice.', 'optimasi-onboarding' );
            $err = ( is_array( $data ) && ! empty( $data['error'] ) )
                ? sanitize_key( (string) $data['error'] )
                : 'analyse_failed';
            if ( $code !== 200 ) {
                $msg .= ' (Optimasi API HTTP ' . (int) $code . ' @ ' . self::api_base_url() . '/api/v1/preview/analyse)';
            }
            self::send_error( $msg, ( $code >= 400 && $code < 500 ) ? $code : 502, $err );
            return;
        }

        wp_send_json_success( $data );
    }

    /**
     * Plain-language audit findings (LLM, bounded + cached — end of the scan).
     *
     * Relays a compact aggregate summary (per-block coverage, health-chip
     * totals, weakest categories, worst-5 products, brand voice + duplicates
     * from the Step-2 analysis) to /api/v1/preview/findings, which writes a
     * warm plain-language narrative and caches it per catalog fingerprint.
     * No product description text is sent in this pass — only the numbers the
     * scan already measured.
     */
    private static function get_findings(): void {
        $facts = self::json_field( 'facts' );

        $response = self::api_request( 'POST', '/api/v1/preview/findings', [
            'site_url'      => home_url( '/' ),
            'scanned_total' => ( isset( $facts['scanned_total'] ) ) ? (int) $facts['scanned_total'] : 0,
            'completeness'  => ( isset( $facts['completeness'] ) ) ? (int) $facts['completeness'] : 0,
            'features'      => ( is_array( $facts['features'] ?? null ) ) ? $facts['features'] : [],
            'health'        => ( is_array( $facts['health'] ?? null ) ) ? $facts['health'] : [],
            'categories'    => ( is_array( $facts['categories'] ?? null ) ) ? $facts['categories'] : [],
            'worst'         => ( is_array( $facts['worst'] ?? null ) ) ? $facts['worst'] : [],
            'brand_voice'   => ( is_array( $facts['brand_voice'] ?? null ) ) ? $facts['brand_voice'] : [],
            'duplicates'    => ( is_array( $facts['duplicates'] ?? null ) ) ? $facts['duplicates'] : [],
            'ai_readiness'  => ( is_array( $facts['ai_readiness'] ?? null ) ) ? $facts['ai_readiness'] : [],
        ], 45.0 );

        if ( is_wp_error( $response ) ) {
            self::send_error( __( 'Could not reach the Optimasi service.', 'optimasi-onboarding' ), 503, 'unreachable' );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $data ) || empty( $data['ok'] ) || empty( $data['text'] ) ) {
            $msg = self::api_message( $data, __( 'Could not write the audit findings.', 'optimasi-onboarding' ) );
            self::send_error( $msg, ( $code >= 400 && $code < 500 ) ? $code : 502, 'findings_failed' );
            return;
        }

        wp_send_json_success( [
            'text'         => (string) $data['text'],
            'generated_by' => ( isset( $data['generated_by'] ) ) ? sanitize_key( (string) $data['generated_by'] ) : 'fallback',
            'cached'       => (bool) ( $data['cached'] ?? false ),
        ] );
    }

    /**
     * Bound the authority block before relaying it to the Optimasi service.
     *
     * Facts only: the published-post counts the scan measured plus the catalogue
     * facts (product total + top category names) the service needs to
     * personalise the authority copy. The plugin performs no classification of
     * its own — it never ships keyword lists or thresholds.
     *
     * @param mixed $raw Authority block from the client payload.
     * @return array
     */
    private static function sanitize_authority_facts( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $catalog_in = ( is_array( $raw['catalog'] ?? null ) ) ? $raw['catalog'] : [];
        $categories = [];
        foreach ( ( is_array( $catalog_in['categories'] ?? null ) ? $catalog_in['categories'] : [] ) as $row ) {
            if ( count( $categories ) >= 12 ) {
                break;
            }
            if ( ! is_array( $row ) ) {
                continue;
            }
            $name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
            if ( '' === $name ) {
                continue;
            }
            $categories[] = [
                'name'     => $name,
                'products' => isset( $row['products'] ) ? (int) $row['products'] : 0,
            ];
        }

        return [
            'platform'         => sanitize_key( (string) ( $raw['platform'] ?? '' ) ),
            'blog_posts'       => isset( $raw['blog_posts'] ) ? (int) $raw['blog_posts'] : 0,
            'recent_posts_90d' => isset( $raw['recent_posts_90d'] ) ? (int) $raw['recent_posts_90d'] : 0,
            'in_depth_posts'   => isset( $raw['in_depth_posts'] ) ? (int) $raw['in_depth_posts'] : 0,
            'catalog'          => [
                'total_products' => isset( $catalog_in['total_products'] ) ? (int) $catalog_in['total_products'] : 0,
                'categories'     => $categories,
            ],
        ];
    }

    /**
     * Per-block "What this means" explainer cards (Step-1 enrichment).
     *
     * Relays the aggregate per-block coverage (pass/total counts the scan
     * already measured — no description text) to /api/v1/preview/explainers,
     * which returns the shared deterministic explainer cards with cited
     * industry stats. The on-site UI and the Summary PDF render these cards
     * verbatim so they never drift apart. Best-effort: if the service is
     * unreachable the Step-1 panel keeps its local summary.
     */
    private static function get_explainers(): void {
        $coverage = self::json_field( 'coverage' );
        $features_raw = ( is_array( $coverage['features'] ?? null ) ) ? $coverage['features'] : [];
        $features     = self::sanitize_explainer_features( $features_raw );

        $response = self::api_request( 'POST', '/api/v1/preview/explainers', [
            'site_url'      => home_url( '/' ),
            'features'      => $features,
            'scanned_total' => ( isset( $coverage['scanned_total'] ) ) ? (int) $coverage['scanned_total'] : 0,
            'completeness'  => ( isset( $coverage['completeness'] ) ) ? (int) $coverage['completeness'] : 0,
            'authority'     => self::sanitize_authority_facts( $coverage['authority'] ?? null ),
        ], 20.0 );

        if ( is_wp_error( $response ) ) {
            self::send_error( __( 'Could not reach the Optimasi service.', 'optimasi-onboarding' ), 503, 'unreachable' );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $data ) || empty( $data['ok'] ) || empty( $data['explainers'] ) ) {
            $msg = self::api_message( $data, __( 'Could not write the content explainers.', 'optimasi-onboarding' ) );
            self::send_error( $msg, ( $code >= 400 && $code < 500 ) ? $code : 502, 'explainers_failed' );
            return;
        }

        wp_send_json_success( [ 'explainers' => $data['explainers'] ] );
    }

    /**
     * Bound explainer input to known content blocks with sane pass/total ints.
     *
     * Keeps the aggregate feature map we forward tiny and predictable (the same
     * blocks the scan measures). Returns a plain array of {pass,total} keyed by
     * block.
     *
     * @param array $features Raw feature map from the client.
     * @return array
     */
    private static function sanitize_explainer_features( array $features ): array {
        $known = [ 'narrative', 'brand_story', 'reviews', 'trust', 'faq', 'specs', 'images' ];
        $out   = [];
        foreach ( $known as $key ) {
            if ( ! isset( $features[ $key ] ) || ! is_array( $features[ $key ] ) ) {
                continue;
            }
            $total = max( 0, (int) ( $features[ $key ]['total'] ?? 0 ) );
            if ( $total <= 0 ) {
                continue;
            }
            $pass = max( 0, min( (int) ( $features[ $key ]['pass'] ?? 0 ), $total ) );
            $out[ $key ] = [ 'pass' => $pass, 'total' => $total ];
        }
        return $out;
    }

    /**
     * Permission an email address for the review report (no registration).
     *
     * Relays the assembled review snapshot + email to /api/v1/preview/email-report,
     * which persists a report request so Optimasi can email the PDF report and
     * next-step suggestions to the address.
     */
    private static function email_report(): void {
        $email = sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) );
        if ( ! is_email( $email ) ) {
            self::send_error( __( 'Please enter a valid email address.', 'optimasi-onboarding' ), 422, 'invalid_email' );
            return;
        }
        $report = self::json_field( 'report' );

        $body = [
            'site_url' => home_url( '/' ),
            'email'    => $email,
            'platform' => 'woocommerce',
            'report'   => $report,
        ];
        // If this review was already captured email-less (report_snapshot fired
        // when Step 2 completed), attach the email to that same lead instead of
        // creating a duplicate.
        $request_id = sanitize_text_field( wp_unslash( (string) ( $_POST['request_id'] ?? '' ) ) );
        if ( $request_id !== '' ) {
            $body['request_id'] = $request_id;
        }

        $response = self::api_request( 'POST', '/api/v1/preview/email-report', $body, 20.0 );

        if ( is_wp_error( $response ) ) {
            self::send_error( __( 'Could not reach the Optimasi service.', 'optimasi-onboarding' ), 503, 'unreachable' );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $data ) || empty( $data['ok'] ) ) {
            $msg = ( is_array( $data ) && ! empty( $data['message'] ) )
                ? sanitize_text_field( (string) $data['message'] )
                : __( 'Could not save your report request.', 'optimasi-onboarding' );
            self::send_error( $msg, ( $code >= 400 && $code < 500 ) ? $code : 502, 'email_report_failed' );
            return;
        }

        wp_send_json_success( $data );
    }

    /**
     * Email-less capture fired the moment the on-site review completes (Step 2).
     * Relays the assembled snapshot to /api/v1/preview/report/snapshot so the
     * PDF is generated + stored for marketing immediately — no email needed.
     * The returned request_id is later attached to an email via email_report.
     */
    private static function report_snapshot(): void {
        $report = self::json_field( 'report' );

        $response = self::api_request( 'POST', '/api/v1/preview/report/snapshot', [
            'site_url' => home_url( '/' ),
            'platform' => 'woocommerce',
            'report'   => $report,
        ], 20.0 );

        if ( is_wp_error( $response ) ) {
            self::send_error( __( 'Could not reach the Optimasi service.', 'optimasi-onboarding' ), 503, 'unreachable' );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $data ) || empty( $data['ok'] ) ) {
            // Best-effort — snapshot capture must never block the review UI.
            return;
        }
        // Remember the personal report token so the (new 4-page) report can be
        // re-fetched on a later page load without re-running the review. The token
        // stays in a WP option — it is never exposed to the browser.
        if ( ! empty( $data['report_token'] ) ) {
            update_option( self::OPTION_REPORT_TOKEN, sanitize_text_field( (string) $data['report_token'] ) );
        }

        // Only the request id goes back. The token is a capability: a value in the
        // page is a value any script on that page can read and replay, and every
        // later step falls back to the stored one, so nothing here needs it.
        wp_send_json_success( [
            'ok'         => true,
            'request_id' => isset( $data['request_id'] ) ? sanitize_text_field( (string) $data['request_id'] ) : '',
        ] );
    }

    /**
     * Serve the report as a standalone document, for "Open in a new tab".
     *
     * Deliberately not the browser wrapping the HTML in a blob URL: a blob inherits
     * the admin page's origin, so any script in the report would run with the
     * store's session. The `sandbox` on the embedded frame exists to prevent exactly
     * that, and the blob path ignores it.
     *
     * `Content-Security-Policy: sandbox` puts the document on an opaque origin
     * instead: it renders, it prints, and it cannot run script or reach this site.
     */
    public static function serve_report_document(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die(
                esc_html__( 'You are not allowed to view this report.', 'optimasi-onboarding' ),
                '',
                [ 'response' => 403 ]
            );
        }

        check_admin_referer( 'optimasi_report_document' );

        $token = (string) get_option( self::OPTION_REPORT_TOKEN, '' );
        if ( $token === '' ) {
            wp_die(
                esc_html__( 'No report is available yet — run the review first.', 'optimasi-onboarding' ),
                '',
                [ 'response' => 404 ]
            );
        }

        $response = self::api_request(
            'GET',
            '/api/v1/preview/report/view?token=' . rawurlencode( $token ) . '&template=marketing',
            [],
            25.0
        );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            wp_die(
                esc_html__( 'Your report could not be loaded right now.', 'optimasi-onboarding' ),
                '',
                [ 'response' => 502 ]
            );
        }

        $html = (string) wp_remote_retrieve_body( $response );

        if ( $html === '' ) {
            wp_die(
                esc_html__( 'Your report could not be loaded right now.', 'optimasi-onboarding' ),
                '',
                [ 'response' => 502 ]
            );
        }

        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        // No allow-same-origin, no allow-scripts: an opaque origin that renders.
        header( "Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:" );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Referrer-Policy: no-referrer' );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this IS the
        // report document; the CSP above is what makes it inert.
        echo $html;
        exit;
    }

    /**
     * Fetch the new 4-page Sales-Ready report for this site's last review.
     *
     * Relays to GET /api/v1/preview/report/view (personal token link, no
     * platform role) and returns the standalone HTML document. The token is kept
     * server-side — the browser only ever sees the rendered report. Rendering
     * happens on Optimasi's servers from the stored snapshot, so the on-site
     * report and the emailed/downloaded PDF are the same document.
     */
    private static function report_page(): void {
        // The stored token is the only authority. A token accepted from the request
        // would make this action a proxy for any report the caller can name, and no
        // part of the UI has one to send.
        $token = (string) get_option( self::OPTION_REPORT_TOKEN, '' );
        if ( $token === '' ) {
            self::send_error( __( 'No report is available yet — run the review first.', 'optimasi-onboarding' ), 404, 'no_report' );
            return;
        }
        $template = sanitize_key( (string) ( $_POST['template'] ?? 'marketing' ) );
        if ( $template === '' ) {
            $template = 'marketing';
        }
        $path     = '/api/v1/preview/report/view?token=' . rawurlencode( $token )
            . '&template=' . rawurlencode( $template );
        $response = self::api_request( 'GET', $path, [], 25.0 );

        if ( is_wp_error( $response ) ) {
            self::send_error( __( 'Could not reach the Optimasi service.', 'optimasi-onboarding' ), 503, 'unreachable' );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $html = (string) wp_remote_retrieve_body( $response );
        if ( $code !== 200 || $html === '' ) {
            self::send_error(
                __( 'Your report could not be loaded right now.', 'optimasi-onboarding' ),
                ( $code >= 400 && $code < 500 ) ? 404 : 502,
                'report_unavailable'
            );
            return;
        }
        wp_send_json_success( [ 'html' => $html, 'template' => $template ] );
    }

    /**
     * Record the visitor's consent to send description samples, or refuse.
     *
     * Returns true when consent stands (either just given, or given on an
     * earlier run — the visitor answers once). When it does not stand the caller
     * has already been sent an error, so it must stop.
     *
     * Guideline 7 requires explicit, authorised consent before contacting an
     * external server, and a checkbox the visitor ticks is that consent. Before
     * this, the plugin advertised consent (it localised a `consent` object and
     * registered consent options) while the send paths hard-coded
     * `send_description_samples => true` — a claim the code did not honour.
     */
    private static function record_or_require_consent(): bool {
        $granted = isset( $_POST['consent'] ) && '1' === (string) wp_unslash( $_POST['consent'] );
        if ( $granted ) {
            update_option( self::OPTION_CONSENT_SEND, true );
            return true;
        }
        if ( get_option( self::OPTION_CONSENT_SEND, false ) ) {
            return true;
        }
        self::send_error(
            __( 'Please tick the box to allow a short sample of your product descriptions to be sent to Optimasi for analysis.', 'optimasi-onboarding' ),
            422,
            'consent_required'
        );
        return false;
    }

    /** Has the visitor already agreed to send description samples? */
    private static function consent_recorded(): bool {
        return (bool) get_option( self::OPTION_CONSENT_SEND, false );
    }

    /**
     * Record a preferred contact channel on this site's lead (the offer in the
     * "How Optimasi can help" panel).
     *
     * Mirrors the backend's /api/v1/preview/report/contact contract exactly, and
     * validates locally first so the visitor gets a useful message rather than a
     * round-trip. The same record is used by the emailed contact page, so a
     * choice made in the plugin shows up in the same sales-contact list.
     */
    private static function contact_request(): void {
        // The stored token is the only authority — see report_page().
        $token = (string) get_option( self::OPTION_REPORT_TOKEN, '' );
        if ( $token === '' ) {
            self::send_error( __( 'Run the review first — there is no report to talk about yet.', 'optimasi-onboarding' ), 404, 'no_report' );
            return;
        }

        $channel = sanitize_key( (string) ( $_POST['channel'] ?? '' ) );
        if ( ! in_array( $channel, self::CONTACT_CHANNELS, true ) ) {
            self::send_error( __( 'Choose how you’d like us to reach you.', 'optimasi-onboarding' ), 422, 'invalid_channel' );
            return;
        }

        $number = self::truncate_text( sanitize_text_field( wp_unslash( (string) ( $_POST['number'] ?? '' ) ) ), 40 );
        if ( in_array( $channel, self::CONTACT_NUMBER_CHANNELS, true )
            && strlen( (string) preg_replace( '/\D+/', '', $number ) ) < 6 ) {
            self::send_error( __( 'Please add your number in international format, e.g. +61 4xx xxx xxx.', 'optimasi-onboarding' ), 422, 'number_required' );
            return;
        }

        $role = sanitize_key( (string) ( $_POST['role'] ?? '' ) );
        if ( $role !== '' && ! in_array( $role, self::CONTACT_ROLES, true ) ) {
            $role = '';
        }

        $email = sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) );
        if ( $email !== '' && ! is_email( $email ) ) {
            self::send_error( __( 'Please check the email address.', 'optimasi-onboarding' ), 422, 'invalid_email' );
            return;
        }

        $response = self::api_request( 'POST', '/api/v1/preview/report/contact', [
            'token'   => $token,
            'channel' => $channel,
            'role'    => $role,
            'number'  => $number,
            'email'   => $email,
        ], 20.0 );

        if ( is_wp_error( $response ) ) {
            self::send_error( __( 'Could not reach the Optimasi service.', 'optimasi-onboarding' ), 503, 'unreachable' );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $data ) || empty( $data['ok'] ) ) {
            $msg = self::api_message( $data, __( 'We could not record your request — please try again.', 'optimasi-onboarding' ) );
            self::send_error( $msg, ( $code >= 400 && $code < 500 ) ? $code : 502, 'contact_failed' );
            return;
        }

        wp_send_json_success( [
            'channel'       => $channel,
            'channel_label' => self::CONTACT_CHANNEL_LABELS[ $channel ],
        ] );
    }

    /**
     * Delete this site's stored previews immediately (no 30-day wait).
     */
    private static function flush_previews(): void {
        $response = self::api_request( 'POST', '/api/v1/preview/flush', [
            'site_url' => home_url( '/' ),
        ], 15.0 );

        if ( is_wp_error( $response ) ) {
            self::send_error( __( 'Could not reach the Optimasi service.', 'optimasi-onboarding' ), 503, 'unreachable' );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $data ) || empty( $data['ok'] ) ) {
            $msg = ( is_array( $data ) && ! empty( $data['message'] ) )
                ? sanitize_text_field( (string) $data['message'] )
                : __( 'Could not flush preview data.', 'optimasi-onboarding' );
            self::send_error( $msg, ( $code >= 400 && $code < 500 ) ? $code : 502, 'flush_failed' );
            return;
        }
        wp_send_json_success( $data );
    }

    /**
     * Collect per-product factual measurements for the first N published
     * products (capped — enough to judge the state of a store without an
     * unbounded payload to the Optimasi servers in Step 2/3).
     *
     * IP firewall: this returns only the store's own raw data (character/word
     * counts, image/review counts, boolean block presence). No tuned weights,
     * thresholds or heuristics are evaluated here — those live on the server.
     *
     * @return array
     */
    private static function collect_measurements( bool $with_text = false, int $text_sample = 0 ): array {
        $products = wc_get_products( [
            'status'  => 'publish',
            'limit'   => self::SCAN_CAP,
            'orderby' => 'id',
            'order'   => 'ASC',
            'return'  => 'ids',
        ] );

        // When a description sample is requested, attach the description text
        // only to an even spread of products (representative of the catalog) —
        // enough to judge brand voice without uploading every product page.
        $text_indices = [];
        $product_total = count( $products );
        if ( $with_text && $text_sample > 0 && $product_total > 0 ) {
            $sample_count = min( $text_sample, $product_total );
            for ( $k = 0; $k < $sample_count; $k++ ) {
                $text_indices[ (int) floor( $k * $product_total / $sample_count ) ] = true;
            }
        }

        $feature_keys = [ 'narrative', 'brand_story', 'reviews', 'trust', 'faq', 'specs', 'images' ];
        $feature_pass = array_fill_keys( $feature_keys, 0 );
        $products_out = [];
        $count        = 0;

        foreach ( $products as $i => $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }
            $short_html = (string) $product->get_short_description();
            $long_html  = (string) $product->get_description();
            $short_desc = wp_strip_all_tags( $short_html );
            $long_desc  = wp_strip_all_tags( $long_html );

            $chars_short = self::text_length( $short_desc );
            $chars_long  = self::text_length( $long_desc );
            $words_short = self::word_count( $short_desc );
            $words_long  = self::word_count( $long_desc );

            // Images: featured image + gallery count.
            $image_count = ( $product->get_image_id() ? 1 : 0 ) + count( $product->get_gallery_image_ids() );

            // Brand taxonomy.
            $has_brand   = false;
            $brand_terms = [];
            $terms       = wp_get_post_terms( $product_id, [ 'product_brand', 'pwb-brand' ] );
            if ( ! is_wp_error( $terms ) ) {
                $brand_terms = wp_list_pluck( $terms, 'name' );
                $has_brand   = ! empty( $brand_terms );
            }

            // Product category (primary names) — enables category-level analysis.
            $categories = [];
            $cat_terms  = wp_get_post_terms( $product_id, 'product_cat' );
            if ( ! is_wp_error( $cat_terms ) ) {
                $categories = array_values( array_slice( wp_list_pluck( $cat_terms, 'name' ), 0, 3 ) );
            }

            // Optimasi block markers already written by the plugin.
            $rewritten  = (bool) get_post_meta( $product_id, '_seo_optimizer_rewritten', true );
            $version_id = (string) get_post_meta( $product_id, '_optimasi_published_version_id', true );
            $tab_slots  = self::extract_tab_slots( $product_id );
            $content    = $short_html . ' ' . $long_html;

            // Boolean presence flags (plain counts of the store's own data).
            $narrative    = ( $chars_long >= 600 ) && ( preg_match_all( '/<h[23][^>]*>/i', $long_html ) >= 2 );
            $brand_story  = $has_brand || self::has_shortcode( $content, 'about_brand' );
            $has_review_block = self::has_shortcode( $content, 'product_reviews' )
                || self::has_shortcode( $content, 'brand_reviews' )
                || in_array( 'opt_brnd_rvw', $tab_slots, true );
            $reviews      = $has_review_block && ( $product->get_review_count() > 0 );
            $trust        = self::has_shortcode( $content, 'store_trust' ) || in_array( 'opt_trust', $tab_slots, true );
            $faq          = self::has_shortcode( $content, 'optimasi_faqs' ) || in_array( 'opt_faq', $tab_slots, true );
            $attributes   = $product->get_attributes();
            $specs        = ( count( $product->get_category_ids() ) > 0 )
                && ( is_array( $attributes ) && ! empty( $attributes ) );
            $images       = $image_count >= 3;

            $features = [
                'narrative'   => $narrative,
                'brand_story' => $brand_story,
                'reviews'     => $reviews,
                'trust'       => $trust,
                'faq'         => $faq,
                'specs'       => $specs,
                'images'      => $images,
            ];
            foreach ( $feature_keys as $key ) {
                if ( $features[ $key ] ) {
                    $feature_pass[ $key ]++;
                }
            }

            $products_out[] = [
                'product_id'          => $product_id,
                'title'               => $product->get_name(),
                'sku'                 => (string) $product->get_sku(),
                'edit_url'            => (string) get_edit_post_link( $product_id, 'raw' ),
                'permalink'           => (string) $product->get_permalink(),
                'chars_short_desc'    => $chars_short,
                'chars_long_desc'     => $chars_long,
                'words_short_desc'    => $words_short,
                'words_long_desc'     => $words_long,
                'image_count'         => $image_count,
                'review_count'        => (int) $product->get_review_count(),
                'average_rating'      => (float) $product->get_average_rating(),
                'has_brand'           => $has_brand,
                'brand_terms'         => array_values( array_map( 'strval', $brand_terms ) ),
                'categories'          => array_values( array_map( 'strval', $categories ) ),
                'rewritten'           => $rewritten,
                'optimasi_version_id' => $version_id,
                'features'            => $features,
            ];
            if ( $with_text && ( $text_sample <= 0 || isset( $text_indices[ $i ] ) ) ) {
                $products_out[ count( $products_out ) - 1 ]['original_short'] = self::truncate_text( $short_desc, 2000 );
                $products_out[ count( $products_out ) - 1 ]['original_long']  = self::truncate_text( $long_desc, 4000 );
            }
            $count++;
        }

        // Transparent completeness % = present elements / total elements (pure arithmetic).
        $completeness = 0;
        if ( $count > 0 ) {
            $total_elements = $count * count( $feature_keys );
            $total_passes   = array_sum( $feature_pass );
            $completeness   = (int) round( ( $total_passes / $total_elements ) * 100 );
        }

        // Website authority + the catalogue facts the server needs to
        // personalise it (product total + top category names — facts only; the
        // classification and the content playbooks live on the Optimasi server).
        $authority            = self::collect_authority_measurements();
        $authority['catalog'] = self::catalog_facts( $products_out );

        return [
            'total'        => $count,
            'audited_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'completeness' => $completeness,
            'features'     => array_map( static function ( $pass ) use ( $count ) {
                return [ 'pass' => $pass, 'total' => $count ];
            }, $feature_pass ),
            'products'     => $products_out,
            'authority'    => $authority,
        ];
    }

    /**
     * Catalogue facts for server-side personalisation (facts only).
     *
     * Sends the product total plus the catalogue's top category names with how
     * many products carry each — enough for the Optimasi service to describe the
     * kind of store this looks like and tailor the authority-content advice. No
     * product text, no thresholds and no keyword rules travel from the site.
     *
     * @param array $products Per-product scan rows (each with 'categories').
     * @return array
     */
    private static function catalog_facts( array $products ): array {
        $tally = [];
        foreach ( $products as $product ) {
            $cats = ( isset( $product['categories'] ) && is_array( $product['categories'] ) )
                ? $product['categories']
                : [];
            foreach ( $cats as $name ) {
                $name = trim( (string) $name );
                if ( '' === $name ) {
                    continue;
                }
                $tally[ $name ] = ( $tally[ $name ] ?? 0 ) + 1;
            }
        }
        arsort( $tally );

        $top = [];
        foreach ( array_slice( $tally, 0, 12, true ) as $name => $count ) {
            $top[] = [ 'name' => (string) $name, 'products' => (int) $count ];
        }

        return [
            'total_products' => count( $products ),
            'categories'     => $top,
        ];
    }

    /**
     * Knowledge-content / authority measurement (Step 1 — local, read-only).
     *
     * Detects whether the store is using its platform's blogging capability for
     * knowledge-type content (buyer guides, how-tos, comparisons). Most stores
     * publish none — an obvious, high-impact authority gap to highlight. Facts
     * only (no scoring): the Optimasi service turns them into the report
     * explainer. Platforms without a native blog report zero posts, which is
     * exactly the "not using it" signal.
     *
     * @return array
     */
    private static function collect_authority_measurements(): array {
        $authority = [
            'platform'         => 'woocommerce',
            'blog_posts'       => 0,
            'recent_posts_90d' => 0,
            'in_depth_posts'   => 0,
            'latest_post_days' => null,
        ];
        if ( ! function_exists( 'wp_count_posts' ) ) {
            return $authority;
        }
        $counts    = wp_count_posts( 'post' );
        $published = ( is_object( $counts ) && isset( $counts->publish ) ) ? (int) $counts->publish : 0;
        $authority['blog_posts'] = $published;
        if ( $published <= 0 ) {
            return $authority;
        }

        // Recency: accurate count of posts published in the last 90 days.
        $recent_q = new WP_Query( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'date_query'     => [ [ 'after' => '90 days ago' ] ],
        ] );
        $authority['recent_posts_90d'] = (int) $recent_q->found_posts;
        wp_reset_postdata();

        // Depth + freshness from a bounded sample of the most recent posts.
        $recent_ids = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 25,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );
        if ( ! is_array( $recent_ids ) || ! $recent_ids ) {
            return $authority;
        }
        $latest_ts = (int) get_post_time( 'U', true, $recent_ids[0] );
        if ( $latest_ts > 0 ) {
            $authority['latest_post_days'] = (int) floor( ( time() - $latest_ts ) / DAY_IN_SECONDS );
        }
        $in_depth = 0;
        foreach ( $recent_ids as $post_id ) {
            $content = wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) );
            if ( self::word_count( $content ) >= 600 ) {
                $in_depth++;
            }
        }
        $authority['in_depth_posts'] = $in_depth;
        return $authority;
    }

    private static function text_length( string $text ): int {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
    }

    /**
     * Rough word count of a stripped text (whitespace-separated tokens).
     */
    private static function word_count( string $text ): int {
        $text = (string) preg_replace( '/\s+/u', ' ', trim( (string) $text ) );
        if ( $text === '' ) {
            return 0;
        }
        $tokens = preg_split( '/[\p{Z}\p{Cc}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $tokens ) ? count( $tokens ) : 0;
    }

    private static function truncate_text( string $text, int $max ): string {
        $text = (string) preg_replace( '/\s+/u', ' ', trim( $text ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
    }

    private static function has_shortcode( string $content, string $shortcode ): bool {
        return stripos( $content, '[' . $shortcode ) !== false;
    }

    private static function extract_tab_slots( int $product_id ): array {
        $raw = get_post_meta( $product_id, '_seo_optimizer_tabs', true );
        if ( ! is_string( $raw ) || $raw === '' ) {
            return [];
        }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            return [];
        }
        $slots = [];
        foreach ( $data as $tab ) {
            if ( is_array( $tab ) && isset( $tab['slot'] ) ) {
                $slots[] = sanitize_key( (string) $tab['slot'] );
            }
        }
        return $slots;
    }

    // ------------------------------------------------------------------
    // Connection state + Optimasi API client (outbound)
    // ------------------------------------------------------------------

    private static function get_connection_status(): string {
        $status = (string) get_option( self::OPTION_CONNECTION, 'not_connected' );
        return ( $status === 'connected' ) ? 'connected' : 'not_connected';
    }

    private static function get_account_info_for_js(): array {
        $account = get_option( self::OPTION_ACCOUNT, [] );
        if ( ! is_array( $account ) ) {
            $account = [];
        }
        $site_key = (string) get_option( self::OPTION_SITE_KEY, '' );
        $account['site_key_masked'] = ( $site_key !== '' )
            ? substr( $site_key, 0, 4 ) . '••••••••' . substr( $site_key, -4 )
            : '';
        if ( ! isset( $account['trial_products_remaining'] ) ) {
            $account['trial_products_remaining'] = 0;
        }
        $account['trial_products_remaining'] = (int) $account['trial_products_remaining'];
        return $account;
    }

    private static function api_base_url(): string {
        // Dedicated free-review endpoint (per-site config, never a code URL) —
        // allows a beta/regional store to point the review at a specific service
        // without disturbing the shared full-plugin ingest URL. Falls back to the
        // shared URL when the dedicated option is empty.
        $base_url = defined( 'OPTIMASI_ONBOARDING_API_URL' ) && is_string( OPTIMASI_ONBOARDING_API_URL )
            ? OPTIMASI_ONBOARDING_API_URL
            : (string) get_option( self::OPTION_API_URL, '' );
        if ( $base_url === '' ) {
            $base_url = defined( 'OPTIMASI_INGEST_URL' ) && is_string( OPTIMASI_INGEST_URL )
                ? OPTIMASI_INGEST_URL
                : (string) get_option( self::OPTION_URL, '' );
        }
        $base_url = rtrim( (string) $base_url, '/' );
        $base_url = (string) preg_replace( '#/api/v1/ingest/events$#', '', $base_url );
        // The shipped default comes LAST, so any explicit configuration above wins.
        if ( $base_url === '' ) {
            $base_url = self::DEFAULT_API_URL;
        }
        return $base_url;
    }

    /**
     * Whether to render the Advanced endpoint panel.
     *
     * Hidden by default. The plugin now ships a working default endpoint, so this is
     * a support/agency tool rather than something a merchant needs — and a wrong
     * value in it silently breaks steps 2, 3 and connect, with no error that names
     * the cause. Opt in per site with:
     *
     *     define( 'OPTIMASI_ONBOARDING_SHOW_ADVANCED', true );   // wp-config.php
     *
     * The option, the AJAX action and save_api_url() all stay, so nothing needs a
     * migration and support can still reach the field on a site that needs it.
     */
    private static function show_advanced_panel(): bool {
        return defined( 'OPTIMASI_ONBOARDING_SHOW_ADVANCED' ) && OPTIMASI_ONBOARDING_SHOW_ADVANCED;
    }

    /**
     * Save the dedicated free-review endpoint for this site (advanced field).
     * An empty value clears it so the shared/default endpoint is used again.
     */
    private static function save_api_url(): void {
        $url = trim( (string) ( $_POST['url'] ?? '' ) );
        if ( $url === '' ) {
            delete_option( self::OPTION_API_URL );
            wp_send_json_success( [ 'message' => __( 'Using the default Optimasi endpoint.', 'optimasi-onboarding' ), 'url' => '' ] );
            return;
        }
        // https only, and nothing that can end an HTML attribute. Every request to
        // this endpoint carries product descriptions, so a typed `http://` is refused
        // rather than quietly upgraded: an implicit repair would hide a decision the
        // merchant is making. The value is echoed back into an input, so quotes and
        // angle brackets are refused as well — escaping there is a second barrier,
        // not the one to rely on.
        if ( ! preg_match( '#^https://#i', $url ) || preg_match( '/[\s"\'<>\\\\`]/', $url ) ) {
            self::send_error( __( 'Enter a full URL starting with https://', 'optimasi-onboarding' ), 400, 'invalid_url' );
            return;
        }
        $url = esc_url_raw( rtrim( $url, '/' ) );
        update_option( self::OPTION_API_URL, $url );
        wp_send_json_success( [ 'message' => __( 'Review service endpoint saved.', 'optimasi-onboarding' ), 'url' => $url ] );
    }

    /**
     * Outbound request to the Optimasi API. Returns WP_Error on transport
     * failure, otherwise the full wp_remote_request response array.
     *
     * @return array|\WP_Error
     */
    private static function api_request( string $method, string $path, array $body = [], float $timeout = 10.0 ) {
        $base = self::api_base_url();
        if ( $base === '' ) {
            // Unreachable while DEFAULT_API_URL is set; kept so an emptied constant
            // reports its own cause instead of the transport failure it resembles.
            return new WP_Error( 'optimasi_no_base', 'No review endpoint is configured (see Optimasi_Onboarding::DEFAULT_API_URL).' );
        }
        $args = [
            'method'      => strtoupper( $method ),
            'timeout'     => $timeout,
            'blocking'    => true,
            'sslverify'   => true,
            // No redirects, ever. This endpoint is a fixed host we control, and a 3xx
            // would re-send the POST body — which for step 2 is the merchant's product
            // text — to whatever the redirect names. The OpenCart extension sets the
            // same limit on its cURL call for the same reason.
            'redirection' => 0,
            'headers'     => [ 'Content-Type' => 'application/json' ],
            'data_format' => 'body',
        ];
        $site_key = (string) get_option( self::OPTION_SITE_KEY, '' );
        if ( $site_key !== '' ) {
            $args['headers']['X-Optimasi-Site-Key'] = $site_key;
        }
        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }
        return wp_remote_request( $base . $path, $args );
    }

    private static function send_error( string $message, int $status = 400, string $error_code = 'error' ): void {
        wp_send_json_error( [ 'message' => $message, 'error' => $error_code ], $status );
    }

    /**
     * One JSON field from the request body, bounded.
     *
     * Decoded with a depth limit and a size limit, and anything that is not an array
     * becomes an empty one — a malformed body should produce an empty report rather
     * than a half-applied one. The size cap is not a trust boundary (this is the
     * admin's own browser) but it is the difference between a clean failure and a
     * memory error half-way through a request.
     */
    private static function json_field( string $key ): array {
        $raw = (string) wp_unslash( (string) ( $_POST[ $key ] ?? '' ) );
        if ( $raw === '' || strlen( $raw ) > 8388608 ) {
            return [];
        }
        $decoded = json_decode( $raw, true, 64 );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * The service's own message for a failed call, or a fallback.
     *
     * FastAPI returns a 422 `detail` as a LIST of `{loc, msg, type}` objects, so
     * casting it to string produced the word "Array" on screen — a message that told
     * the merchant nothing and looked like a bug in this plugin. The first few `msg`
     * values are the useful part.
     */
    private static function api_message( $data, string $fallback ): string {
        if ( ! is_array( $data ) ) {
            return $fallback;
        }
        foreach ( [ 'message', 'detail', 'error' ] as $field ) {
            if ( ! isset( $data[ $field ] ) ) {
                continue;
            }
            $value = $data[ $field ];
            if ( is_string( $value ) && $value !== '' ) {
                return sanitize_text_field( $value );
            }
            if ( is_array( $value ) ) {
                $parts = [];
                foreach ( $value as $row ) {
                    if ( is_array( $row ) && isset( $row['msg'] ) && is_string( $row['msg'] ) ) {
                        $parts[] = $row['msg'];
                    } elseif ( is_string( $row ) && $row !== '' ) {
                        $parts[] = $row;
                    }
                }
                if ( $parts ) {
                    return sanitize_text_field( implode( '; ', array_slice( $parts, 0, 3 ) ) );
                }
            }
        }
        return $fallback;
    }

    /**
     * Request an Optimasi site key for this site.
     *
     * The backend registers the site (minimal registration: domain + audit
     * record) if it is not known yet, then issues a key. The key is returned to
     * the JS which pre-fills the input — the user still reviews consent and
     * clicks Approve & Connect to finish.
     */
    private static function request_key(): void {
        $response = self::api_request( 'POST', '/api/v1/audit/request-key', [
            'site_url'       => home_url( '/' ),
            'plugin_version' => OPTIMASI_ONBOARDING_VERSION,
        ], 20.0 );

        if ( is_wp_error( $response ) ) {
            self::send_error( __( 'Could not reach the Optimasi service.', 'optimasi-onboarding' ), 503, 'unreachable' );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $data ) || empty( $data['site_key'] ) ) {
            $msg = ( is_array( $data ) && ! empty( $data['message'] ) )
                ? sanitize_text_field( (string) $data['message'] )
                : __( 'Could not request an Optimasi key.', 'optimasi-onboarding' );
            $err = ( is_array( $data ) && ! empty( $data['error'] ) )
                ? sanitize_key( (string) $data['error'] )
                : 'request_key_failed';
            self::send_error( $msg, ( $code >= 400 && $code < 500 ) ? $code : 502, $err );
            return;
        }

        wp_send_json_success( [
            'site_key'   => sanitize_text_field( (string) $data['site_key'] ),
            'registered' => (bool) ( $data['registered'] ?? false ),
            'account'    => ( is_array( $data['account'] ?? null ) ) ? $data['account'] : [],
        ] );
    }

    private static function connect_store(): void {
        $site_key = sanitize_text_field( wp_unslash( (string) ( $_POST['site_key'] ?? '' ) ) );
        if ( $site_key === '' ) {
            self::send_error( __( 'Please enter your Optimasi site key.', 'optimasi-onboarding' ), 422, 'missing_key' );
            return;
        }
        $consent = [
            'measurements' => ! empty( $_POST['consent_measurements'] ),
            'samples'      => ! empty( $_POST['consent_samples'] ),
            'preview'      => ! empty( $_POST['consent_preview'] ),
        ];

        $response = self::api_request( 'POST', '/api/v1/audit/connect', [
            'site_key'         => $site_key,
            'site_url'         => home_url( '/' ),
            'store_id'         => (string) get_option( 'optimasi_store_id', '' ),
            'plugin_version'   => OPTIMASI_ONBOARDING_VERSION,
            'requested_scopes' => [ 'audit:deep', 'content:draft', 'events:ingest' ],
        ], 15.0 );

        if ( is_wp_error( $response ) ) {
            self::send_error( __( 'Could not reach the Optimasi service.', 'optimasi-onboarding' ), 503, 'unreachable' );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $data ) || empty( $data['ok'] ) ) {
            $msg = ( is_array( $data ) && ! empty( $data['message'] ) )
                ? sanitize_text_field( (string) $data['message'] )
                : __( 'Connection was not approved.', 'optimasi-onboarding' );
            $err = ( is_array( $data ) && ! empty( $data['error'] ) )
                ? sanitize_key( (string) $data['error'] )
                : 'connect_failed';
            self::send_error( $msg, ( $code >= 400 && $code < 500 ) ? $code : 400, $err );
            return;
        }

        // Persist connection + consent.
        update_option( self::OPTION_SITE_KEY, $site_key );
        update_option( self::OPTION_CONNECTION, 'connected' );
        update_option( self::OPTION_CONSENT_MEASUREMENTS, $consent['measurements'] );
        update_option( self::OPTION_CONSENT_SAMPLES, $consent['samples'] );
        update_option( self::OPTION_CONSENT_PREVIEW, $consent['preview'] );
        $account = ( is_array( $data['account'] ?? null ) ) ? $data['account'] : [];
        update_option( self::OPTION_ACCOUNT, $account );

        wp_send_json_success( [
            'connection_status' => 'connected',
            'account'           => self::get_account_info_for_js(),
        ] );
    }

    private static function disconnect_store(): void {
        $site_key = (string) get_option( self::OPTION_SITE_KEY, '' );
        if ( $site_key !== '' ) {
            self::api_request( 'POST', '/api/v1/audit/disconnect', [
                'site_key' => $site_key,
                'store_id' => (string) get_option( 'optimasi_store_id', '' ),
            ], 5.0 );
        }
        delete_option( self::OPTION_SITE_KEY );
        delete_option( self::OPTION_ACCOUNT );
        // The description-send consent belongs to the connection it was given for, so
        // it goes with it. Leaving it behind meant a later "connect" inherited an
        // answer nobody gave in that context.
        delete_option( self::OPTION_CONSENT_SEND );
        update_option( self::OPTION_CONNECTION, 'not_connected' );
        update_option( self::OPTION_CONSENT_MEASUREMENTS, false );
        update_option( self::OPTION_CONSENT_SAMPLES, false );
        update_option( self::OPTION_CONSENT_PREVIEW, false );
        wp_send_json_success( [ 'connection_status' => 'not_connected' ] );
    }

    private static function deep_init(): void {
        if ( self::get_connection_status() !== 'connected' ) {
            self::send_error( __( 'Connect your store before running a deep audit.', 'optimasi-onboarding' ), 403, 'not_connected' );
            return;
        }
        if ( ! function_exists( 'wc_get_products' ) || ! function_exists( 'wc_get_product' ) ) {
            self::send_error( __( 'WooCommerce is not active.', 'optimasi-onboarding' ), 422, 'woocommerce_inactive' );
            return;
        }

        $measurements = self::collect_measurements();

        // The deep audit ships whole descriptions, so it passes the same consent gate
        // as step 2. It used to read OPTION_CONSENT_SAMPLES — a switch set by the
        // *connect* form, which this page never renders — so "consent to send samples"
        // had two answers and a merchant could easily have given neither.
        if ( self::record_or_require_consent() === false ) {
            return;
        }

        $send_samples = true;

        $body = [
            'store_id'       => (string) get_option( 'optimasi_store_id', '' ),
            'plugin_version' => OPTIMASI_ONBOARDING_VERSION,
            'site_url'       => home_url( '/' ),
            'sample_size'    => $measurements['total'],
            'audited_at'     => $measurements['audited_at'],
            'consent'        => [
                'send_measurements'        => (bool) get_option( self::OPTION_CONSENT_MEASUREMENTS, true ),
                'send_description_samples' => $send_samples,
            ],
            'products' => [],
        ];

        foreach ( $measurements['products'] as $p ) {
            $row = $p;
            if ( $send_samples ) {
                $row['description_sample'] = self::sample_description( (int) $p['product_id'] );
            }
            $body['products'][] = $row;
        }

        $response = self::api_request( 'POST', '/api/v1/audit/deep', $body, 30.0 );
        if ( is_wp_error( $response ) ) {
            self::send_error( __( 'Could not reach the Optimasi service.', 'optimasi-onboarding' ), 503, 'unreachable' );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code !== 202 || ! is_array( $data ) || empty( $data['job_id'] ) ) {
            $msg = ( is_array( $data ) && ! empty( $data['message'] ) )
                ? sanitize_text_field( (string) $data['message'] )
                : __( 'Deep audit could not be started.', 'optimasi-onboarding' );
            $err = ( is_array( $data ) && ! empty( $data['error'] ) )
                ? sanitize_key( (string) $data['error'] )
                : 'deep_init_failed';
            self::send_error( $msg, ( $code >= 400 && $code < 500 ) ? $code : 502, $err );
            return;
        }

        wp_send_json_success( [
            'job_id'   => sanitize_text_field( (string) $data['job_id'] ),
            'poll_url' => esc_url_raw( (string) ( $data['poll_url'] ?? '' ) ),
        ] );
    }

    private static function deep_poll(): void {
        if ( self::get_connection_status() !== 'connected' ) {
            self::send_error( __( 'Connect your store first.', 'optimasi-onboarding' ), 403, 'not_connected' );
            return;
        }
        $job_id = sanitize_text_field( wp_unslash( (string) ( $_POST['job_id'] ?? '' ) ) );
        if ( $job_id === '' ) {
            self::send_error( __( 'Missing job id.', 'optimasi-onboarding' ), 422, 'missing_job' );
            return;
        }
        $response = self::api_request( 'GET', '/api/v1/audit/jobs/' . rawurlencode( $job_id ), [], 10.0 );
        if ( is_wp_error( $response ) ) {
            self::send_error( __( 'Could not reach the Optimasi service.', 'optimasi-onboarding' ), 503, 'unreachable' );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $data ) ) {
            self::send_error( __( 'Deep audit result unavailable.', 'optimasi-onboarding' ), 502, 'poll_failed' );
            return;
        }
        wp_send_json_success( $data );
    }

    private static function sample_description( int $product_id ): string {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return '';
        }
        $text = $product->get_description() . ' ' . $product->get_short_description();
        $text = wp_strip_all_tags( $text );
        $text = (string) preg_replace( '/\s+/u', ' ', $text );
        $text = trim( $text );
        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 2000 ) : substr( $text, 0, 2000 );
    }
}