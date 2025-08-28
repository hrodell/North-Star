<?php
/**
 * Plugin Name: NorthStar Delivery Slots - northstar-delivery-slots-plugin.php
 * Description: NorthStar admin calendar, REST API, Woo hooks, and a storefront overlay that forces Tyche/ORDDD to show only NorthStar-approved windows.
 * Author: NorthStar
 * Version: 1.8.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 
 * * Developer Notes
 * ---------------
 * • DATA TRUTH: Availability & bookings live in custom tables:
 *     - wp_northstar_delivery_slots (day+window+type)
 *     - wp_northstar_delivery_slot_bookings (one row per order per slot; includes product_skus)
 *   Woo order meta (_nsds_date, _nsds_window) is audit convenience only.
 * • STOREFRONT: Tyche/ORDDD UI remains, but /orddd/v1/delivery_schedule is fed by NSDS.
 *   Hidden audit inputs written at checkout: _nsds_date (YYYY-MM-DD), _nsds_window (HH:MM-HH:MM).
 * • ADMIN: Calendar + “Booked” modal read NSDS tables. CSV exports from NSDS tables.
 * • SKUs: Bookings store CSV in product_skus (plural). Legacy product_sku is ignored.
 * • SAFETY: All includes have DEFINE-guards. Intercepts fail-closed (no windows if NSDS can’t answer).
 
 * Developer Summary
 * -----------------
 * This bootstrap keeps the plugin lean and stable. It:
 *  - Defines path/URL constants exactly once (DEFINE-guard style).
 *  - Loads each module only once using DEFINE guards (no fatal redeclare).
 *  - Adds the Tyche ORDDD proxy (northstar-orddd-proxy.php) so ORDDD’s
 *    delivery-schedule API is sourced from NorthStar.
 *  - Enqueues the storefront overlay JS (northstar-tyche-slot-sync.js)
 *    on cart/checkout/account pages; it intercepts ORDDD’s slot fetch and
 *    transforms NSDS slots into ORDDD time-slot JSON (blocked/full removed).
 *  - Includes a pre-validation POST shim so ORDDD sees un-indexed keys
 *    (e_deliverydate, h_deliverydate, orddd_time_slot) even if the front-end
 *    submitted only the indexed versions (…_0).
 *  - NEW: Includes a strict, reversible bypass that removes ONLY Tyche’s
 *    “Delivery availability is temporarily unavailable…” error IF NorthStar
 *    confirms the posted date/window are valid for that day.
 *
 * Safety & Idempotence
 * --------------------
 * - Every include is wrapped in a DEFINE guard that this file sets on first load.
 * - Frontend assets use a single action; we check-and-avoid double enqueue.
 * - If a site rolls back other modules, this file won’t crash—guards prevent
 *   duplicate class/function/constant definitions.
 *
 * Changelog
 * ---------
 * 1.8.2  Include northstar-orddd-bypass.php (fail-closed notice filter).
 * 1.8.1  Add northstar-orddd-post-shim.php to mirror indexed ORDDD fields.
 * 1.8.0  Add northstar-orddd-proxy.php; enqueue storefront overlay (JS 1.8.0).
 * 1.7.x  (previous, internal) – legacy bootstrap.
 */

if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

/* ──────────────────────────────────────────────────────────────────────────
 * Core constants (DEFINE‑guard style)
 * -------------------------------------------------------------------------- */
if ( ! defined( 'NSDS_VERSION' ) )            define( 'NSDS_VERSION',            '1.8.2' );
if ( ! defined( 'NSDS_FILE' ) )               define( 'NSDS_FILE',               __FILE__ );
if ( ! defined( 'NSDS_DIR' ) )                define( 'NSDS_DIR',                plugin_dir_path( __FILE__ ) );
if ( ! defined( 'NSDS_URL' ) )                define( 'NSDS_URL',                plugin_dir_url( __FILE__ ) );
if ( ! defined( 'NSDS_ASSETS_URL' ) )         define( 'NSDS_ASSETS_URL',         NSDS_URL );
if ( ! defined( 'NSDS_ASSETS_DIR' ) )         define( 'NSDS_ASSETS_DIR',         NSDS_DIR );

/* Helpful: detect cart/checkout/account quickly */
if ( ! function_exists( 'nsds_is_storefront_context' ) ) {
  function nsds_is_storefront_context() {
    if ( function_exists( 'is_cart' )    && is_cart() ) return true;
    if ( function_exists( 'is_checkout') && is_checkout() ) return true;
    if ( function_exists( 'is_account_page') && is_account_page() ) return true;
    // Fallback: check request path for typical checkout fragments
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    return (bool) preg_match( '#/(cart|checkout|my-account)(/|$)#i', $uri );
  }
}

/* ──────────────────────────────────────────────────────────────────────────
 * Module loader (DEFINE‑guard per file)
 * -------------------------------------------------------------------------- */

# Database helpers
if ( ! defined( 'NSDS_DB_INCLUDED' ) ) {
  $f = NSDS_DIR . 'northstar-db.php';
  if ( file_exists( $f ) ) { require_once $f; if ( ! defined( 'NSDS_DB_INCLUDED' ) ) define( 'NSDS_DB_INCLUDED', 1 ); }
}

# Admin assets (UI scaffolding and FullCalendar glue)
if ( ! defined( 'NSDS_ADMIN_ASSETS_INCLUDED' ) ) {
  $f = NSDS_DIR . 'northstar-admin-assets.php';
  if ( file_exists( $f ) ) { require_once $f; if ( ! defined( 'NSDS_ADMIN_ASSETS_INCLUDED' ) ) define( 'NSDS_ADMIN_ASSETS_INCLUDED', 1 ); }
}

# Admin UI view logic (markup + controls)
if ( ! defined( 'NSDS_ADMIN_UI_INCLUDED' ) ) {
  $f = NSDS_DIR . 'northstar-admin-ui.php';
  if ( file_exists( $f ) ) { require_once $f; if ( ! defined( 'NSDS_ADMIN_UI_INCLUDED' ) ) define( 'NSDS_ADMIN_UI_INCLUDED', 1 ); }
}

# Public REST API (slots, bookings, etc.)
if ( ! defined( 'NSDS_REST_PUBLIC_INCLUDED' ) ) {
  $f = NSDS_DIR . 'northstar-rest-public.php';
  if ( file_exists( $f ) ) { require_once $f; if ( ! defined( 'NSDS_REST_PUBLIC_INCLUDED' ) ) define( 'NSDS_REST_PUBLIC_INCLUDED', 1 ); }
}

# Admin REST API (exports, season lock, CRUD)
if ( ! defined( 'NSDS_REST_ADMIN_INCLUDED' ) ) {
  $f = NSDS_DIR . 'northstar-rest-admin.php';
  if ( file_exists( $f ) ) { require_once $f; if ( ! defined( 'NSDS_REST_ADMIN_INCLUDED' ) ) define( 'NSDS_REST_ADMIN_INCLUDED', 1 ); }
}

# WooCommerce hooks (capture booking, etc.)
if ( ! defined( 'NSDS_WC_HOOKS_INCLUDED' ) ) {
  $f = NSDS_DIR . 'northstar-wc-hooks.php';
  if ( file_exists( $f ) ) { require_once $f; if ( ! defined( 'NSDS_WC_HOOKS_INCLUDED' ) ) define( 'NSDS_WC_HOOKS_INCLUDED', 1 ); }
}

# Email hooks (optional)
if ( ! defined( 'NSDS_EMAIL_HOOKS_INCLUDED' ) ) {
  $f = NSDS_DIR . 'northstar-email-hooks.php';
  if ( file_exists( $f ) ) { require_once $f; if ( ! defined( 'NSDS_EMAIL_HOOKS_INCLUDED' ) ) define( 'NSDS_EMAIL_HOOKS_INCLUDED', 1 ); }
}

# ICS exports (optional)
if ( ! defined( 'NSDS_ICS_INCLUDED' ) ) {
  $f = NSDS_DIR . 'northstar-ics.php';
  if ( file_exists( $f ) ) { require_once $f; if ( ! defined( 'NSDS_ICS_INCLUDED' ) ) define( 'NSDS_ICS_INCLUDED', 1 ); }
}

# Tyche ORDDD Proxy — ensures ORDDD gets NorthStar’s filtered list
if ( ! defined( 'NSDS_ORDDD_PROXY_INCLUDED' ) ) {
  $f = NSDS_DIR . 'northstar-orddd-proxy.php';
  if ( file_exists( $f ) ) { require_once $f; if ( ! defined( 'NSDS_ORDDD_PROXY_INCLUDED' ) ) define( 'NSDS_ORDDD_PROXY_INCLUDED', 1 ); }
}

# Pre-validation POST shim — mirror indexed ORDDD fields to un-indexed
if ( ! defined( 'NSDS_ORDDD_POST_SHIM_INCLUDED' ) ) {
  $f = NSDS_DIR . 'northstar-orddd-post-shim.php';
  if ( file_exists( $f ) ) { require_once $f; if ( ! defined( 'NSDS_ORDDD_POST_SHIM_INCLUDED' ) ) define( 'NSDS_ORDDD_POST_SHIM_INCLUDED', 1 ); }
}

# NEW: Bypass Tyche availability error IF NSDS confirms validity
if ( ! defined( 'NSDS_ORDDD_BYPASS_INCLUDED' ) ) {
  $f = NSDS_DIR . 'northstar-orddd-bypass.php';
  if ( file_exists( $f ) ) { require_once $f; if ( ! defined( 'NSDS_ORDDD_BYPASS_INCLUDED' ) ) define( 'NSDS_ORDDD_BYPASS_INCLUDED', 1 ); }
}

/* ──────────────────────────────────────────────────────────────────────────
 * Storefront overlay: enqueue the JS that intercepts ORDDD requests and
 * filters DOM as a fallback. This is kept *very* defensive to avoid dupes.
 * -------------------------------------------------------------------------- */

if ( ! function_exists( 'nsds_enqueue_storefront_overlay' ) ) :
function nsds_enqueue_storefront_overlay() {
  if ( ! nsds_is_storefront_context() ) return;

  $handle = 'northstar-tyche-slot-sync';
  $src    = NSDS_ASSETS_URL . 'northstar-tyche-slot-sync.js';
  $ver    = NSDS_VERSION;

  if ( ! wp_script_is( $handle, 'registered' ) ) {
    wp_register_script( $handle, $src, array(), $ver, true );
  }

  if ( function_exists( 'wp_create_nonce' ) ) {
    $rest_base = esc_url_raw( rest_url( 'nsds/v1/slots' ) );
    wp_localize_script( $handle, 'NSDS', array(
      'rest'  => $rest_base,
      'nonce' => wp_create_nonce( 'wp_rest' ),
      'ver'   => NSDS_VERSION,
    ) );
  }

  if ( ! wp_script_is( $handle, 'enqueued' ) ) {
    wp_enqueue_script( $handle );
  }
}
endif;
add_action( 'wp_enqueue_scripts', 'nsds_enqueue_storefront_overlay', 99 );

/* ──────────────────────────────────────────────────────────────────────────
 * Activation: ensure DB is present (guarded).
 * -------------------------------------------------------------------------- */
if ( ! function_exists( 'nsds_on_activate' ) ) :
function nsds_on_activate() {
  if ( function_exists( 'nsds_db_maybe_install' ) ) {
    nsds_db_maybe_install();
  }
}
endif;
register_activation_hook( __FILE__, 'nsds_on_activate' );

/* ──────────────────────────────────────────────────────────────────────────
 * Done.
 * -------------------------------------------------------------------------- */
