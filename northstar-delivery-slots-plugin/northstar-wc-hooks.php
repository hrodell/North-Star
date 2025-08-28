<?php
/**
 * NorthStar Delivery Slots – WooCommerce Hooks
 * File: northstar-wc-hooks.php
 * Version: 1.8.5
 *
 * CRITICAL PATCH v1.8.5 (2025-08-27)
 * -----------------------------------
 * Implements graceful duplicate booking error handling for atomic bookings.
 * - When inserting into wp_northstar_delivery_slot_bookings, if a duplicate (slot_id, order_id) exists,
 *   the function detects the DB constraint violation and handles it without fatal error or silent fail.
 * - On duplicate, booking insert is skipped, audit meta is still written, and no duplicate booking row is made.
 * - All other logic remains unchanged.
 */

if ( ! defined('ABSPATH') ) exit;

//
// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

if ( ! function_exists('nsds_wc_normalize_window') ) {
	/**
	 * Convert a human label like `8:00 AM - 12:00 PM` or `08:00 - 12:00`
	 * into the canonical `HH:MM-HH:MM` (24h) used by wp_northstar_delivery_slots.time_window.
	 */
	function nsds_wc_normalize_window( $label ) {
		if ( ! $label ) return '';

		$label = trim( (string) $label );
		// Already canonical?
		if ( preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $label) ) {
			return $label;
		}

		// Try to parse "h:mm AM - h:mm PM" (allow spaces variations)
		if ( preg_match('/(\d{1,2}):(\d{2})\s*([ap]m)\s*[-–]\s*(\d{1,2}):(\d{2})\s*([ap]m)/i', $label, $m) ) {
			[$all,$h1,$m1,$ap1,$h2,$m2,$ap2] = $m;
			$h1 = (int)$h1; $h2 = (int)$h2;
			$m1 = (int)$m1; $m2 = (int)$m2;
			$ap1 = strtolower($ap1); $ap2 = strtolower($ap2);

			// Convert to 24h
			if ($ap1 === 'pm' && $h1 !== 12) $h1 += 12;
			if ($ap1 === 'am' && $h1 === 12) $h1 = 0;
			if ($ap2 === 'pm' && $h2 !== 12) $h2 += 12;
			if ($ap2 === 'am' && $h2 === 12) $h2 = 0;

			return sprintf('%02d:%02d-%02d:%02d', $h1, $m1, $h2, $m2);
		}

		// Try to parse "HH:MM - HH:MM"  (24h with spaces)
		if ( preg_match('/^\s*(\d{1,2}):(\d{2})\s*[-–]\s*(\d{1,2}):(\d{2})\s*$/', $label, $m) ) {
			[$all,$h1,$m1,$h2,$m2] = $m;
			return sprintf('%02d:%02d-%02d:%02d', $h1, $m1, $h2, $m2);
		}

		// Nothing recognized – return as-is (won't match DB, but won't fatal).
		return $label;
	}
}

if ( ! function_exists('nsds_wc_parse_h_delivery_date') ) {
	/**
	 * Parse Tyche hidden delivery date (e.g. "1-12-2025" or "01-12-2025") → "YYYY-MM-DD".
	 */
	function nsds_wc_parse_h_delivery_date( $dmy ) {
		if ( ! $dmy ) return '';
		$dmy = trim((string)$dmy);
		// Accept dd-mm-yyyy, d-m-yyyy, with / or -.
		if ( preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $dmy, $m) ) {
			[$all,$d,$mth,$y] = $m;
			return sprintf('%04d-%02d-%02d', (int)$y, (int)$mth, (int)$d);
		}
		return '';
	}
}

if ( ! function_exists('nsds_wc_get_slot_id') ) {
	/**
	 * Resolve a slot id by date + canonical time window.
	 */
	function nsds_wc_get_slot_id( $date_yyyy_mm_dd, $window_canonical ) {
		global $wpdb;
		if ( ! $date_yyyy_mm_dd || ! $window_canonical ) return 0;
		$table = $wpdb->prefix . 'northstar_delivery_slots';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE slot_date = %s AND time_window = %s LIMIT 1",
				$date_yyyy_mm_dd,
				$window_canonical
			)
		);
	}
}

if ( ! function_exists('nsds_wc_table_exists') ) {
	function nsds_wc_table_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
	}
}

if ( ! function_exists('nsds_wc_column_exists') ) {
	/**
	 * Return true if $column exists on $table.
	 */
	function nsds_wc_column_exists( $table, $column ) {
		global $wpdb;
		$col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
		return ! empty( $col );
	}
}

//
// ──────────────────────────────────────────────────────────────────────────────
// Persist our hidden fields on checkout
// ──────────────────────────────────────────────────────────────────────────────

if ( ! function_exists('nsds_wc_save_hidden_meta') ) {
	/**
	 * Hook: woocommerce_checkout_update_order_meta ( $order_id, $data )
	 * Save _nsds_date / _nsds_window so they're always on the order.
	 */
	function nsds_wc_save_hidden_meta( $order_id, $data ) {
		// Prefer the explicit hidden fields we inject.
		$date = isset($_POST['_nsds_date'])    ? sanitize_text_field($_POST['_nsds_date'])    : '';
		$win  = isset($_POST['_nsds_window'])  ? sanitize_text_field($_POST['_nsds_window'])  : '';

		// Fallbacks to Tyche fields if our overlay ever missed one:
		if ( ! $date ) {
			$h = isset($_POST['h_deliverydate_0']) ? $_POST['h_deliverydate_0'] :
			     ( isset($_POST['h_deliverydate']) ? $_POST['h_deliverydate'] : '' );
			$date = nsds_wc_parse_h_delivery_date( $h );
		}
		if ( ! $win ) {
			$tyche_label = isset($_POST['orddd_time_slot_0']) ? $_POST['orddd_time_slot_0'] :
			              ( isset($_POST['orddd_time_slot'])   ? $_POST['orddd_time_slot']   : '' );
			$win = nsds_wc_normalize_window( $tyche_label );
		}

		if ( $date ) update_post_meta( $order_id, '_nsds_date', $date );
		if ( $win  ) update_post_meta( $order_id, '_nsds_window', $win  );
	}
	add_action( 'woocommerce_checkout_update_order_meta', 'nsds_wc_save_hidden_meta', 10, 2 );
}

//
// ──────────────────────────────────────────────────────────────────────────────
// Insert booking row once the order is created + mirror snapshot to order meta
// ──────────────────────────────────────────────────────────────────────────────

if ( ! function_exists('nsds_wc_insert_booking_on_processed') ) {
	/**
	 * Hook: woocommerce_checkout_order_processed ( $order_id, $posted_data, $order )
	 * Handles duplicate booking constraint violations gracefully.
	 */
	function nsds_wc_insert_booking_on_processed( $order_id, $posted, $order ) {
		global $wpdb;

		$book_tbl = $wpdb->prefix . 'northstar_delivery_slot_bookings';
		if ( ! nsds_wc_table_exists( $book_tbl ) ) {
			// Table missing – bail silently.
			return;
		}

		// Read from saved meta (preferred).
		$date = get_post_meta( $order_id, '_nsds_date', true );
		$win  = get_post_meta( $order_id, '_nsds_window', true );

		// If missing, try POST fallbacks (defensive).
		if ( ! $date ) {
			$h = isset($posted['h_deliverydate_0']) ? $posted['h_deliverydate_0'] :
			     ( isset($posted['h_deliverydate']) ? $posted['h_deliverydate'] : '' );
			$date = nsds_wc_parse_h_delivery_date( $h );
		}
		if ( ! $win ) {
			$tyche_label = isset($posted['orddd_time_slot_0']) ? $posted['orddd_time_slot_0'] :
			              ( isset($posted['orddd_time_slot'])   ? $posted['orddd_time_slot']   : '' );
			$win = nsds_wc_normalize_window( $tyche_label );
		}

		// Need both to proceed.
		if ( ! $date || ! $win ) return;

		$slot_id = nsds_wc_get_slot_id( $date, $win );
		if ( ! $slot_id ) return;

		// Avoid duplicate inserts on retries (network hiccups etc.)
		$dupe = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$book_tbl} WHERE slot_id=%d AND order_id=%d LIMIT 1",
				$slot_id, $order_id
			)
		);
		if ( $dupe ) {
			// Even when we skip the insert, ensure downstream meta mirror exists.
			update_post_meta( $order_id, '_nsds_slot_id', (int) $slot_id );
			return;
		}

		// Collect order details (defensive if $order is null).
		$last_name = $order ? $order->get_billing_last_name() : '';
		$city      = $order ? $order->get_billing_city()      : '';
		$state     = $order ? $order->get_billing_state()     : '';
		$postcode  = $order ? $order->get_billing_postcode()  : '';
		$cust_id   = $order ? (string) $order->get_customer_id() : '';

		// Product SKUs + flags (best-effort). If you later want exact IDs, wire a mapping.
		$setup   = 0;
		$removal = 0;
		$skus    = array();

		if ( $order && method_exists( $order, 'get_items' ) ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( ! $product ) continue;

				$sku = $product->get_sku();
				if ( $sku ) $skus[] = $sku;

				$name = $product->get_name();
				if ( !$setup   && stripos( $name, 'setup' )   !== false ) $setup   = 1;
				if ( !$removal && stripos( $name, 'removal' ) !== false ) $removal = 1;
			}
		}
		$sku_list = $skus ? implode( ',', $skus ) : null;

		// Back-compat: include legacy column only if it still exists
		$has_legacy_single = nsds_wc_column_exists( $book_tbl, 'product_sku' );

		try {
			if ( $has_legacy_single ) {
				// With legacy column present
				$result = $wpdb->insert(
					$book_tbl,
					array(
						'slot_id'      => $slot_id,
						'order_id'     => $order_id,
						'last_name'    => $last_name ?: null,
						'product_sku'  => null,          // legacy column (kept for back-compat)
						'city'         => $city ?: null,
						'state'        => $state ?: null,
						'setup'        => $setup,
						'removal'      => $removal,
						'created_at'   => current_time( 'mysql', 1 ), // mirror table default if needed
						'customer_id'  => $cust_id ?: null,
						'product_skus' => $sku_list,
						'postcode'     => $postcode ?: null,
					),
					array('%d','%d','%s','%s','%s','%s','%d','%d','%s','%s','%s','%s')
				);
			} else {
				// Clean, plural-only write (no legacy column)
				$result = $wpdb->insert(
					$book_tbl,
					array(
						'slot_id'      => $slot_id,
						'order_id'     => $order_id,
						'last_name'    => $last_name ?: null,
						'city'         => $city ?: null,
						'state'        => $state ?: null,
						'setup'        => $setup,
						'removal'      => $removal,
						'created_at'   => current_time( 'mysql', 1 ),
						'customer_id'  => $cust_id ?: null,
						'product_skus' => $sku_list,
						'postcode'     => $postcode ?: null,
					),
					array('%d','%d','%s','%s','%s','%d','%d','%s','%s','%s','%s')
				);
			}

			// If insert failed, check for duplicate entry error
			if ($result === false) {
				$last_error = $wpdb->last_error;
				if ($last_error && stripos($last_error, 'Duplicate entry') !== false) {
					// Booking already exists, mirror meta and exit gracefully
					update_post_meta( $order_id, '_nsds_slot_id', (int) $slot_id );
					return;
				} else {
					// Other DB error; optionally log or handle
					return;
				}
			}
		} catch (Exception $ex) {
			// In rare cases (DB layer exception), fail gracefully
			return;
		}

		// ────────────────────────────────────────────────────────────────
		// Mirror a read-only snapshot to order meta
		// ────────────────────────────────────────────────────────────────
		update_post_meta( $order_id, '_nsds_slot_id', (int) $slot_id );
		if ( is_array( $skus ) && ! empty( $skus ) ) {
			update_post_meta( $order_id, '_nsds_product_skus', implode( ',', $skus ) );
			update_post_meta( $order_id, '_nsds_product_skus_arr', wp_json_encode( array_values( $skus ) ) );
		} else {
			// Ensure keys exist for simpler querying, even if empty.
			update_post_meta( $order_id, '_nsds_product_skus', '' );
			update_post_meta( $order_id, '_nsds_product_skus_arr', '[]' );
		}
	}
	add_action( 'woocommerce_checkout_order_processed', 'nsds_wc_insert_booking_on_processed', 20, 3 );
}

//
// ──────────────────────────────────────────────────────────────────────────────
// (Optional) expose tiny helper for admin UI to re-derive the slot quickly
// ──────────────────────────────────────────────────────────────────────────────

if ( ! function_exists('nsds_wc_find_slot_for_order') ) {
	/**
	 * Utility that admin modules can call to map an order → slot row.
	 * Returns slot array or null.
	 */
	function nsds_wc_find_slot_for_order( $order_id ) {
		global $wpdb;
		$date = get_post_meta( $order_id, '_nsds_date', true );
		$win  = get_post_meta( $order_id, '_nsds_window', true );
		if ( ! $date || ! $win ) return null;

		$table = $wpdb->prefix . 'northstar_delivery_slots';
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE slot_date=%s AND time_window=%s LIMIT 1",
				$date, $win
			),
			ARRAY_A
		);
	}
}
