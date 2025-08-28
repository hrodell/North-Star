<?php
/**
 * Version: 0.9.9.4  filename: northstar-admin-assets.php
 * Admin asset enqueuer for the NorthStar Delivery Slots UI.
 *
 * DEV SUMMARY
 * - Robust page detection (supports both the current slug `northstar-slots`
 *   and the legacy slug `northstar-delivery-slots`).
 * - Enqueues FullCalendar and your admin UI.
 * - Localizes BOTH globals used by your JS:
 *      window.NSDS       -> base REST + nonce (general)
 *      window.NSDS_ADMIN -> concrete admin endpoints + seasonLock value
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'NSDS_PAGE_SLUG' ) ) define( 'NSDS_PAGE_SLUG', 'northstar-slots' );
if ( ! defined( 'NSDS_VERSION' ) )    define( 'NSDS_VERSION', '0.9.9.4' );
if ( ! defined( 'NSDS_REST_NS' ) )    define( 'NSDS_REST_NS', 'northstar/v1' );

add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {

	$current = 'toplevel_page_' . NSDS_PAGE_SLUG;           // e.g. toplevel_page_northstar-slots
	$legacy  = 'toplevel_page_northstar-delivery-slots';   // older builds

	$ok = ( $hook_suffix === $current ) || ( $hook_suffix === $legacy );
	if ( ! $ok ) {
		$page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
		if ( $page === NSDS_PAGE_SLUG || $page === 'northstar-delivery-slots' ) {
			$ok = true;
		}
	}
	if ( ! $ok ) return; // not our screen

	// FullCalendar shipped with your plugin (root folder)
	wp_enqueue_style( 'nsds-fc', NSDS_URL . 'index.global.min.css', [], NSDS_VERSION );
	wp_enqueue_script( 'nsds-fc', NSDS_URL . 'index.global.min.js', [], NSDS_VERSION, true );

	// Your Admin UI assets
	wp_enqueue_style( 'nsds-admin', NSDS_URL . 'northstar-admin-ui.css', [ 'nsds-fc' ], NSDS_VERSION );
	wp_enqueue_script( 'nsds-admin', NSDS_URL . 'northstar-admin-ui.js', [ 'nsds-fc', 'jquery' ], NSDS_VERSION, true );

	// Base REST + nonce
	wp_localize_script( 'nsds-admin', 'NSDS', [
		'rest'    => esc_url_raw( rest_url( NSDS_REST_NS . '/' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'version' => NSDS_VERSION,
	] );

	// Concrete endpoints that your admin JS expects (NSDS_ADMIN.*)
	$base = rest_url( NSDS_REST_NS . '/' );
	wp_localize_script( 'nsds-admin', 'NSDS_ADMIN', [
		'nonce'              => wp_create_nonce( 'wp_rest' ),
		// slots & actions
		'restSlots'          => esc_url_raw( $base . 'slots' ),
		'restGen'            => esc_url_raw( $base . 'slots/generate' ),
		// exports
		'restExportSlots'    => esc_url_raw( $base . 'export/slots.csv' ),
		'restExportBookings' => esc_url_raw( $base . 'export/bookings.csv' ),
		// season lock (new & required by the UI)
		'restSeasonLock'     => esc_url_raw( $base . 'settings/season-lock' ),
		'seasonLock'         => get_option( 'nsds_season_lock' ) ? 1 : 0,
	] );
} );
