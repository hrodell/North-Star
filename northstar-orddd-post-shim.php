<?php
/**
 * File: northstar-orddd-post-shim.php
 * Package: NorthStar Delivery Slots
 * Version: 1.0.0
 * Author: NorthStar
 * License: GPL-2.0+
 *
 * Developer Summary
 * -----------------
 * PURPOSE
 *   Some Tyche/ORDDD builds validate checkout using the un-indexed POST keys:
 *     - e_deliverydate
 *     - h_deliverydate
 *     - orddd_time_slot
 *   …even when the front-end submits the indexed versions (…_0, …_1).
 *
 * WHAT THIS DOES
 *   Before WooCommerce/ORDDD validation runs, this shim copies the first
 *   populated indexed values (e.g., e_deliverydate_0) into the un-indexed keys
 *   if they’re missing. That satisfies ORDDD’s validator without changing
 *   your UI or data model.
 *
 * SCOPE
 *   - Runs during checkout processing (both standard submit and wc-ajax=checkout).
 *   - No DB writes. No side effects beyond normalizing $_POST keys.
 *
 * CHANGELOG
 *   1.0.0  Initial release.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copy first available indexed ORDDD fields to un-indexed POST keys if missing.
 * Detects keys with suffixes _0, _1, etc. and mirrors them as:
 *   e_deliverydate     ← e_deliverydate_0 (or _1…)
 *   h_deliverydate     ← h_deliverydate_0 (or _1…)
 *   orddd_time_slot    ← orddd_time_slot_0 (or _1…)
 *
 * Also mirrors our NorthStar audit values as a convenience:
 *   _nsds_date / _nsds_window (no-op if they’re already present).
 */
function northstar_orddd_mirror_indexed_fields_to_unindexed() {
	// Only act on checkout postbacks (standard or AJAX).
	$is_checkout = ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'checkout' )
		|| ( isset( $_REQUEST['wc-ajax'] ) && $_REQUEST['wc-ajax'] === 'checkout' )
		|| ( isset( $_POST['woocommerce-process-checkout-nonce'] ) );

	if ( ! $is_checkout ) {
		return;
	}

	// Helper: find the first populated value among indexed variants.
	$pick_indexed = static function( $base ) {
		$direct = isset( $_POST[ $base ] ) ? wc_clean( wp_unslash( $_POST[ $base ] ) ) : '';
		if ( $direct !== '' ) {
			return $direct;
		}
		// Scan common suffixes (0..4) to be safe.
		for ( $i = 0; $i <= 4; $i++ ) {
			$key = "{$base}_{$i}";
			if ( isset( $_POST[ $key ] ) ) {
				$val = wc_clean( wp_unslash( $_POST[ $key ] ) );
				if ( $val !== '' ) {
					return $val;
				}
			}
		}
		// Fallback: scan all keys (handles vendor_id or other suffixes).
		foreach ( $_POST as $k => $v ) {
			if ( strpos( $k, "{$base}_" ) === 0 && $v !== '' ) {
				return wc_clean( wp_unslash( $v ) );
			}
		}
		return '';
	};

	// Mirror date display (e.g., "1 December, 2025")
	if ( empty( $_POST['e_deliverydate'] ) ) {
		$val = $pick_indexed( 'e_deliverydate' );
		if ( $val !== '' ) {
			$_POST['e_deliverydate'] = $val;
		}
	}

	// Mirror date machine format (e.g., "1-12-2025")
	if ( empty( $_POST['h_deliverydate'] ) ) {
		$val = $pick_indexed( 'h_deliverydate' );
		if ( $val !== '' ) {
			$_POST['h_deliverydate'] = $val;
		}
	}

	// Mirror time slot label (e.g., "08:00 AM - 12:00 PM")
	if ( empty( $_POST['orddd_time_slot'] ) ) {
		$val = $pick_indexed( 'orddd_time_slot' );
		if ( $val !== '' ) {
			$_POST['orddd_time_slot'] = $val;
		}
	}

	// Leave our audit fields intact; ensure they’re simple scalars.
	if ( isset( $_POST['_nsds_date'] ) && is_array( $_POST['_nsds_date'] ) ) {
		$_POST['_nsds_date'] = reset( $_POST['_nsds_date'] );
	}
	if ( isset( $_POST['_nsds_window'] ) && is_array( $_POST['_nsds_window'] ) ) {
		$_POST['_nsds_window'] = reset( $_POST['_nsds_window'] );
	}
}

// Run as early as possible in checkout processing so ORDDD sees the mirrored keys.
add_action( 'woocommerce_checkout_process', 'northstar_orddd_mirror_indexed_fields_to_unindexed', 1 );

// Also catch the AJAX path in case the action is routed differently by theme/plugins.
add_action( 'wp_loaded', function() {
	if ( isset( $_REQUEST['wc-ajax'] ) && $_REQUEST['wc-ajax'] === 'checkout' ) {
		northstar_orddd_mirror_indexed_fields_to_unindexed();
	}
}, 1 );
