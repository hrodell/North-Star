<?php
/**
 * File: northstar-rest-public.php
 * Version: 1.4.1
 * Package: NorthStar Delivery Slots
 * Last Modified: 2025-08-20
 *
 * Developer Summary
 * -----------------
 * PURPOSE
 *   Expose a minimal, read‑only REST endpoint that returns *authoritative*
 *   NorthStar availability for a given calendar day. This lets the storefront
 *   (Tyche UI or anything else) ask NorthStar which windows are available,
 *   blocked, or full.
 *
 *   Route (public, GET):
 *     /wp-json/nsds/v1/slots?date=<various formats>&type=Delivery
 *
 *   Accepted date formats (examples):
 *     - 2025-12-01               (YYYY-MM-DD)
 *     - 01-12-2025 / 1-12-2025   (DD-MM-YYYY / D-M-YYYY)
 *     - 12/01/2025 / 12/1/2025   (MM/DD/YYYY / M/D/YYYY)
 *     - 1 December, 2025         (j F, Y)
 *     - December 1, 2025         (F j, Y)
 *
 *   Response shape:
 *     {
 *       "date": "YYYY-MM-DD",
 *       "type": "Delivery",
 *       "slots": [
 *         {
 *           "label":  "08:00 AM - 12:00 PM",   // as stored in DB
 *           "window": "08:00-12:00",          // canonical 24h (for strict compare)
 *           "blocked": 0,                     // 1 or 0
 *           "capacity": 25,                   // int
 *           "booked": 3,                      // int (bookings count)
 *           "remaining": 22                   // max(0, capacity - booked)
 *         },
 *         ...
 *       ]
 *     }
 *
 * DATABASE (confirmed via DESCRIBE)
 *   - {$wpdb->prefix}northstar_delivery_slots
 *       id (PK), slot_date (DATE), time_window (VARCHAR), type (VARCHAR),
 *       capacity (INT), blocked (TINYINT), created_at (DATETIME), updated_at (DATETIME)
 *   - {$wpdb->prefix}northstar_delivery_slot_bookings
 *       id (PK), slot_id (INT FK -> slots.id), order_id (INT), last_name (VARCHAR),
 *       product_sku (VARCHAR), city (VARCHAR), state (VARCHAR),
 *       setup (TINYINT), removal (TINYINT), created_at (DATETIME),
 *       customer_id (BIGINT), product_skus (VARCHAR), postcode (VARCHAR)
 *
 *   Bookings consumption: each row in *_slot_bookings counts as 1 unit toward
 *   capacity for its slot_id. (If in future you add a status column and want
 *   to filter on it, adjust the $bookedSub query below.)
 *
 * SECURITY
 *   - Read‑only, non‑sensitive availability.
 *   - Publicly readable (permission_callback returns true).
 *   - Input sanitized and normalized server‑side.
 *
 * CHANGELOG
 *   1.4.1  Return both "label" (DB value) and 24h "window"; robust comments.
 *   1.4.0  Relax server‑side date parsing (accept Tyche formats) and normalize
 *          to ISO before querying; initial nsds/v1/slots implementation.
 */

if (!defined('ABSPATH')) exit;

/*───────────────────────────────────────────────────────────────────────────*
 * Helpers: date & time-window normalization
 *───────────────────────────────────────────────────────────────────────────*/

/**
 * Normalize many possible date strings into ISO Y-m-d (UTC).
 * Returns null if unparsable.
 */
function nsds_normalize_date_to_iso($raw) {
	$raw = trim((string)$raw);
	if ($raw === '') return null;

	// Already ISO?
	if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
		return $raw;
	}

	// D-M-YYYY / DD-MM-YYYY (common Tyche hidden format)
	if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $raw, $m)) {
		$dd = str_pad($m[1], 2, '0', STR_PAD_LEFT);
		$mm = str_pad($m[2], 2, '0', STR_PAD_LEFT);
		return "{$m[3]}-{$mm}-{$dd}";
	}

	// M/D/YYYY / MM/DD/YYYY
	if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw, $m)) {
		$mm = str_pad($m[1], 2, '0', STR_PAD_LEFT);
		$dd = str_pad($m[2], 2, '0', STR_PAD_LEFT);
		return "{$m[3]}-{$mm}-{$dd}";
	}

	// Human strings ("1 December, 2025", "December 1, 2025", etc.)
	$ts = strtotime($raw);
	if ($ts !== false) {
		return gmdate('Y-m-d', $ts);
	}

	return null;
}

/**
 * Convert a human AM/PM window label to canonical "HH:MM-HH:MM" 24h.
 * Examples:
 *   "8:00 AM - 12:00 PM"  -> "08:00-12:00"
 *   "7:00 PM - 9:00 PM"   -> "19:00-21:00"
 *   "08:00 - 12:00"       -> "08:00-12:00" (already 24h, keep dash)
 * Returns null if it cannot parse.
 */
function nsds_window_to_24h($label) {
	$label = trim((string)$label);
	if ($label === '') return null;
	$label = preg_replace('/[–—]/u', '-', $label); // normalize dash types

	// Already 24h "HH:MM - HH:MM" or "HH:MM-HH:MM"
	if (preg_match('/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', $label, $m)) {
		return "{$m[1]}-{$m[2]}";
	}

	// "h:mm AM - h:mm PM"
	if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)\s*-\s*(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $label, $m)) {
		$h1 = (int)$m[1]; $m1 = $m[2]; $ap1 = strtoupper($m[3]);
		$h2 = (int)$m[4]; $m2 = $m[5]; $ap2 = strtoupper($m[6]);
		if ($ap1 === 'PM' && $h1 !== 12) $h1 += 12;
		if ($ap1 === 'AM' && $h1 === 12) $h1  = 0;
		if ($ap2 === 'PM' && $h2 !== 12) $h2 += 12;
		if ($ap2 === 'AM' && $h2 === 12) $h2  = 0;
		return sprintf('%02d:%s-%02d:%s', $h1, $m1, $h2, $m2);
	}

	// "h - h AM/PM" (looser variant)
	if (preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})\s*(AM|PM)$/i', $label, $m)) {
		$ap = strtoupper($m[3]);
		$h1 = (int)$m[1]; $h2 = (int)$m[2];
		if ($ap === 'PM') { if ($h1 !== 12) $h1 += 12; if ($h2 !== 12) $h2 += 12; }
		if ($ap === 'AM') { if ($h1 === 12) $h1  = 0;  if ($h2 === 12) $h2  = 0;  }
		return sprintf('%02d:00-%02d:00', $h1, $h2);
	}

	return null;
}

/*───────────────────────────────────────────────────────────────────────────*
 * REST route registration
 *───────────────────────────────────────────────────────────────────────────*/

add_action('rest_api_init', function () {
	register_rest_route('nsds/v1', '/slots', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'nsds_rest_get_slots',
		'permission_callback' => '__return_true', // public read-only
		// We validate/normalize 'date' inside the callback (flexible input).
		'args'                => array(
			'date' => array('required' => true),
			'type' => array('required' => false, 'default' => 'Delivery'),
		),
	), false);
});

/*───────────────────────────────────────────────────────────────────────────*
 * REST callback
 *───────────────────────────────────────────────────────────────────────────*/

/**
 * GET /wp-json/nsds/v1/slots?date=<...>&type=Delivery
 */
function nsds_rest_get_slots(WP_REST_Request $req) {
	global $wpdb;

	$rawDate = sanitize_text_field($req->get_param('date'));
	$type    = sanitize_text_field($req->get_param('type') ?: 'Delivery');

	$iso = nsds_normalize_date_to_iso($rawDate);
	if ($iso === null) {
		return new WP_Error(
			'rest_invalid_param',
			__('Invalid parameter(s): date', 'northstar'),
			array('status' => 400, 'params' => array('date' => 'Invalid parameter.'))
		);
	}

	$rows = nsds_db_fetch_slots_for_date($iso, $type);

	$slots = array();
	foreach ($rows as $r) {
		$capacity  = (int) $r->capacity;
		$booked    = (int) $r->booked;
		$remaining = max(0, $capacity - $booked);

		$label  = (string) $r->time_window;          // e.g. "08:00 AM - 12:00 PM"
		$window = nsds_window_to_24h($label);        // e.g. "08:00-12:00" (may be null if unparsable)

		$slots[] = array(
			'label'     => $label,
			'window'    => $window ?: '',             // keep stable key; empty if unparsable
			'blocked'   => ((int)$r->blocked ? 1 : 0),
			'capacity'  => $capacity,
			'booked'    => $booked,
			'remaining' => $remaining,
		);
	}

	return new WP_REST_Response(array(
		'date'  => $iso,
		'type'  => $type,
		'slots' => $slots,
	), 200);
}

/*───────────────────────────────────────────────────────────────────────────*
 * DB access (schema-specific to your install)
 *───────────────────────────────────────────────────────────────────────────*/

/**
 * Fetch Delivery slots for the day (with current booking counts).
 *
 * - Slots table:   wp_northstar_delivery_slots
 * - Bookings table:wp_northstar_delivery_slot_bookings
 *
 * If you later add a booking-status column and only want to count certain
 * statuses (e.g., paid/processing), add a WHERE clause in $bookedSub.
 */
function nsds_db_fetch_slots_for_date($isoDate, $type) {
	global $wpdb;

	$slots_table    = $wpdb->prefix . 'northstar_delivery_slots';
	$bookings_table = $wpdb->prefix . 'northstar_delivery_slot_bookings';

	// Count bookings per slot_id. (No status column yet, so count all.)
	$bookedSub = "
		SELECT slot_id, COUNT(*) AS booked
		FROM {$bookings_table}
		GROUP BY slot_id
	";

	$sql = "
		SELECT
			s.id,
			s.slot_date,
			s.time_window,
			s.type,
			s.capacity,
			s.blocked,
			COALESCE(b.booked, 0) AS booked
		FROM {$slots_table} s
		LEFT JOIN ({$bookedSub}) b
		       ON b.slot_id = s.id
		WHERE s.slot_date = %s
		  AND s.type = %s
		ORDER BY s.time_window ASC
	";

	$prepared = $wpdb->prepare($sql, $isoDate, $type);
	$rows     = $wpdb->get_results($prepared);

	return is_array($rows) ? $rows : array();
}

/* EOF */
