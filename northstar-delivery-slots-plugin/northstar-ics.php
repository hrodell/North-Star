<?php
/**
 * File: northstar-ics.php
 * Version: 0.9.1
 * Removal ICS feed: /wp-json/northstar/v1/ics/removal?token=...
 *
 * Fix in 0.9.1 (Task 4):
 * - Closed the header comment properly (the previous file’s comment block was
 *   unterminated before executable code).
 *
 * Notes:
 * - Uses NSDS_REST_NS namespace for route.
 * - Requires a shared helper nsds_wp_tz() from DB module.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'NSDS_REST_NS' ) ) {
	define( 'NSDS_REST_NS', 'northstar/v1' );
}

/** Register REST route */
add_action('rest_api_init', function(){
	register_rest_route(NSDS_REST_NS, '/ics/removal', [
		'methods'  => 'GET',
		'callback' => 'nsds_rest_ics_removal',
		'permission_callback' => '__return_true',
		'args' => [
			'token' => ['type'=>'string','required'=>true]
		],
	]);
});

/** REST callback: generate ICS for upcoming Removal slots */
function nsds_rest_ics_removal( WP_REST_Request $req ) {
	global $wpdb;

	$token = $req->get_param('token');
	if ( ! $token || $token !== get_option('nsds_removal_ics_token') ) {
		return new WP_Error('forbidden','Invalid token', ['status'=>403]);
	}

	$slots_tbl = $wpdb->prefix . 'northstar_delivery_slots';
	$book_tbl  = $wpdb->prefix . 'northstar_delivery_slot_bookings';

	$from = gmdate('Y-m-d');
	$to   = gmdate('Y-m-d', strtotime('+120 days'));

	$sql = $wpdb->prepare("
		SELECT s.slot_date, s.time_window, b.order_id, b.last_name, b.city, b.state, b.postcode, b.setup, b.removal
		FROM {$book_tbl} b
		INNER JOIN {$slots_tbl} s ON s.id = b.slot_id
		WHERE s.type='Removal' AND s.slot_date BETWEEN %s AND %s
		ORDER BY s.slot_date ASC, s.time_window ASC
	", $from, $to);

	$rows = $wpdb->get_results($sql, ARRAY_A);
	$tz = wp_timezone_string();

	$out = [
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//NorthStar//Delivery Slots//EN',
		'CALSCALE:GREGORIAN',
		'METHOD:PUBLISH',
	];

	foreach ( (array)$rows as $r ) {
		list($start,$end) = explode('-', $r['time_window']);
		$dtstart = new DateTime( $r['slot_date'].' '.$start, nsds_wp_tz() );
		$dtend   = new DateTime( $r['slot_date'].' '.$end,   nsds_wp_tz() );
		$uid     = 'nsds-removal-' . md5(json_encode($r));

		$title = sprintf('[Removal] #%d %s — %s, %s', $r['order_id'], $r['last_name'], $r['city'], $r['state']);
		$desc  = 'Time Window: ' . nsds_hhmm_to_label($r['time_window']) . "\n" .
		         'ZIP: ' . $r['postcode'] . "\n" .
		         'Setup: ' . $r['setup'] . ' | Removal: ' . $r['removal'];

		$out[] = 'BEGIN:VEVENT';
		$out[] = 'UID:' . $uid;
		$out[] = 'DTSTART;TZID='.$tz.':' . $dtstart->format('Ymd\THis');
		$out[] = 'DTEND;TZID='.$tz.':'   . $dtend->format('Ymd\THis');
		$out[] = 'SUMMARY:' . nsds_ics_escape($title);
		$out[] = 'DESCRIPTION:' . nsds_ics_escape($desc);
		$out[] = 'END:VEVENT';
	}

	$out[] = 'END:VCALENDAR';

	return new WP_REST_Response( implode("\r\n", $out), 200, [
		'Content-Type' => 'text/calendar; charset=utf-8'
	] );
}

/** Escape text for ICS fields */
function nsds_ics_escape($s){
	$s = str_replace("\\","\\\\",$s);
	$s = str_replace("\n","\\n",$s);
	$s = str_replace(",", "\\,", $s);
	$s = str_replace(";", "\\;", $s);
	return $s;
}
