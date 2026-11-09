<?php
/**
 * Uninstall — runs when the plugin is DELETED from the Plugins screen.
 *
 * Not on deactivation, and that difference is deliberate: a deactivated plugin keeps
 * its settings so that reactivating it is not a fresh start. This plugin is an audit a
 * merchant runs over days, so deactivation must not lose the remembered consent or the
 * last report. Deleting the plugin is the visitor saying "remove this".
 *
 * WordPress prefers this file over any `register_uninstall_hook()` callback, and loads
 * it directly — which is why the list of what gets removed lives in the class, beside
 * the constants that name the options, rather than being written out again here. One
 * home for the rule; this file only reaches it.
 *
 * What is removed: every option this plugin owns. What is not: the five connection
 * keys it shares with the full Optimasi Content Manager (site key, ingest URL,
 * connection status, account, store id), because deleting those would silently
 * disconnect a store that is still connected through the other plugin. The reasoning
 * is in Optimasi_Onboarding::uninstall().
 *
 * No files: this plugin writes nothing to disk — no uploads, no cache, no exports — so
 * there is nothing of its own to delete. WordPress removes the plugin's files as part
 * of the same Delete action that fires this.
 *
 * @package Optimasi_Onboarding
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-onboarding.php';

Optimasi_Onboarding::uninstall();
