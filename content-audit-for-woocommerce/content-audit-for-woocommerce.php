<?php
/**
 * Plugin Name:       Content Audit for WooCommerce
 * Plugin URI:        https://optimasi.io
 * Description:       Find out what your WooCommerce product pages are missing. A
 *                     free, read-only content audit: scan every published product,
 *                     measure brand voice and duplicate copy, and get a Sales-Ready
 *                     report you can act on, plus the full executive report by email.
 *                     Nothing is written to your store and no account is needed.
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Optimasi
 * Author URI:        https://optimasi.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       content-audit-for-woocommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct file access.
}

define( 'OPTIMASI_ONBOARDING_VERSION', '1.0.2' );
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
