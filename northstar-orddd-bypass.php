<?php
/**
 * File: northstar-orddd-bypass.php
 * Package: NorthStar Delivery Slots
 * Version: 1.0.0
 * Author: NorthStar
 * License: GPL-2.0+
 *
 * Developer Summary
 * -----------------
 * PURPOSE
 *   Some Tyche/ORDDD builds throw a generic checkout error:
 *     "Delivery availability is temporarily unavailable. Please try again."
 *   even when the selected date/window are valid according to NorthStar.
 *
 * WHAT THIS MODULE DOES
 *   - During checkout, if the customer posted `_nsds_date` and `_nsds_window`,
 *     we verify the window against NorthStar’s `/nsds/v1/slots` for that date.
 *   - If NSDS confirms the window exists AND is not blocked AND has remaining>0,
 *     we remove ONLY Tyche’s specific availability error from WooCommerce
 *     notices so the order can proceed. All other errors remain (fail-closed).
 *
 * SAFETY & SCOPE
 *   - Fail-closed: when NSDS disagrees or request fails, we do nothing.
 *   - No DB writes, no ORDDD patching. Pure notice filtering.
 *   - Easy rollback: comment out the include in the bootstrap.
 *
 * CHANGELOG
 *   1.0.0  Initial release (strict, reversible bypass).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper: check with NorthStar if a given ISO date + window is valid.
 *
 * @param string $iso   YYYY-MM-DD
 * @param string $win   "HH:MM-HH:MM" (24h)
 * @return bool         true if exists, not blocked, remaining > 0
 */
function nsds_is_window_valid_via_ns( $iso, $win ) {
	if ( ! $iso || ! $win ) {
		return false;
	}

	// Call our internal REST endpoint directly (no external HTTP).
	$req = new WP_REST_Request( 'GET', '/nsds/v1/slots' );
	$req->set_param( 'date', $iso );
	$req->set_param( 'type', 'Delivery' );

	$resp = rest_do_request( $req );
	if ( is_wp_error( $resp ) ) {
		return false;
	}

	$data = rest_get_server()->response_to_data( $resp, false );
	if ( ! is_array( $data ) || empty( $data['slots'] ) || ! is_array( $data['slots'] ) ) {
		return false;
	}

	foreach ( $data['slots'] as $slot ) {
		$window    = isset( $slot['window'] ) ? (string) $slot['window'] : '';
		$blocked   = ! empty( $slot['blocked'] );
		$remaining = isset( $slot['remaining'] ) ? (int) $slot['remaining'] : 0;
		if ( $window === $win && ! $blocked && $remaining > 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Helper: remove ONLY Tyche’s availability error from Woo notices.
 * Preserves all other errors/notices/success messages.
 */
function nsds_remove_tyche_availability_error_notice() {
	$all = function_exists( 'wc_get_notices' ) ? wc_get_notices() : array();
	if ( ! is_array( $all ) || empty( $all ) ) {
		return;
	}

	$needle = 'Delivery availability is temporarily unavailable';
	$changed = false;

	if ( isset( $all['error'] ) && is_array( $all['error'] ) ) {
		$filtered_errors = array();
		foreach ( $all['error'] as $entry ) {
			$msg = '';
			if ( is_array( $entry ) && isset( $entry['notice'] ) ) {
				$msg = wp_strip_all_tags( (string) $entry['notice'] );
			} else {
				$msg = wp_strip_all_tags( (string) $entry );
			}
			if ( stripos( $msg, $needle ) !== false ) {
				$changed = true; // drop this specific error
				continue;
			}
			$filtered_errors[] = $entry;
		}
		$all['error'] = $filtered_errors;
	}

	if ( $changed ) {
		// Reset notices by re-adding all types.
		wc_clear_notices();
		foreach ( $all as $type => $entries ) {
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( is_array( $entry ) && isset( $entry['notice'] ) ) {
					wc_add_notice( $entry['notice'], $type );
				} else {
					wc_add_notice( (string) $entry, $type );
				}
			}
		}
	}
}

/**
 * Main hook: run after all checkout validation has added its notices.
 * If NSDS confirms the posted date/window are valid, strip Tyche's specific
 * availability error so checkout can proceed.
 *
 * @param array            $data   Checkout posted data (unused here)
 * @param WC_Error|object  $errors Error object (not directly modified)
 */
function nsds_bypass_tyche_availability_if_nsds_ok( $data, $errors ) {
	// Gather our audit inputs.
	$iso = isset( $_POST['_nsds_date'] ) ? sanitize_text_field( wp_unslash( $_POST['_nsds_date'] ) ) : '';
	$win = isset( $_POST['_nsds_window'] ) ? sanitize_text_field( wp_unslash( $_POST['_nsds_window'] ) ) : '';

	if ( ! $iso || ! $win ) {
		return; // fail-closed
	}

	// Validate via NorthStar.
	if ( ! nsds_is_window_valid_via_ns( $iso, $win ) ) {
		return; // fail-closed
	}

	// At this point, NSDS says it's valid; remove ONLY Tyche's availability error.
	nsds_remove_tyche_availability_error_notice();
}
add_action( 'woocommerce_after_checkout_validation', 'nsds_bypass_tyche_availability_if_nsds_ok', 9999, 2 );
