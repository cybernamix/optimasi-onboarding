<?php
/**
 * Plugin Name:       Optimasi Content Audit
 * Plugin URI:        https://optimasi.io
 * Description:       Content audit plugin for new Optimasi clients. Runs a free
 *                     four-step audit of up to the first 1,000 of your published
 *                     WooCommerce products: scan, brand-voice analysis, your
 *                     Sales-Ready Inspection Report, and a video showing how
 *                     Optimasi works. You can also ask for a one-on-one from the
 *                     report panel. Nothing is written automatically.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Optimasi
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       optimasi-onboarding
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access.
}

define( 'OPTIMASI_ONBOARDING_VERSION', '1.0.0' );
define( 'OPTIMASI_ONBOARDING_DIR', plugin_dir_path( __FILE__ ) );

require_once OPTIMASI_ONBOARDING_DIR . 'includes/class-onboarding.php';

/**
 * Bootstrap the content audit plugin.
 *
 * Reads/writes the same connection options as the full Optimasi Content Manager
 * plugin (optimasi_ingest_url, optimasi_site_key, optimasi_connection_status,
 * optimasi_account_info) so a site handed off from onboarding to the full plugin
 * keeps its connection without re-connecting.
 */
function optimasi_onboarding_bootstrap(): void {
    Optimasi_Onboarding::init();
}
add_action( 'plugins_loaded', 'optimasi_onboarding_bootstrap' );
