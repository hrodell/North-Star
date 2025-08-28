<?php
/**
 * File: northstar-rest-admin.php
 * Version: 0.9.9.8
 * Admin-only REST for NorthStar Delivery Slots.
 *
 * CHANGELOG
 * 0.9.9.7: Stage 1 Hardening (season lock, strict validation, delete guard, collision checks).
 * 0.9.9.8: SECURITY HARDENING — All mutating endpoints now require WooCommerce manager capability.
 *           All permission_callback for create, update, delete, block/unblock, generate, etc. now use
 *           current_user_can('manage_woocommerce'). Non-mutating endpoints (GET, CSV export) remain
 *           is_user_logged_in().
 *           No other logic altered.
 *
 * Dev Summary:
 * - All critical admin actions now require WooCommerce admin role.
 * - Error handling and input validation remain robust.
 * - No changes to business logic, only tighter security controls.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'NSDS_REST_NS' ) ) {
	// Fallback (normally defined in admin-assets bootstrap)
	define( 'NSDS_REST_NS', 'northstar/v1' );
}

/* ----------------------------------------------------------------------------- */
/* Helpers (season lock, validation, small DB utilities)                         */
/* -------------------------------------------------------------------------- */

function nsds_is_season_locked() : bool {
	return (bool) get_option('nsds_season_lock', false);
}

/**
 * If locked, return WP_Error; else null. $action is for nicer messages.
 * By design we still allow capacity changes and block/unblock under lock.
 */
function nsds_err_locked($action = 'mutate slots') {
	return new WP_Error(
		'nsds_locked',
		sprintf(
			'Season is locked. %s is disabled. You may still change capacity or block/unblock slots while locked.',
			$action
		),
		[ 'status' => 423 ]
	);
}

function nsds_validate_type(?string $type) : bool {
	if ($type === null) return false;
	$t = strtoupper(trim($type));
	return in_array($t, ['DELIVERY','REMOVAL'], true);
}

function nsds_validate_date_iso(?string $date) : bool {
	if ($date === null) return false;
	if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ) return false;
	[$y,$m,$d] = array_map('intval', explode('-', $date));
	return checkdate($m,$d,$y);
}

/** Validate HH:MM in 24h and quarter-hour. */
function nsds_validate_hhmm(string $hhmm) : bool {
	if ( ! preg_match('/^\d{2}:\d{2}$/', $hhmm) ) return false;
	[$h,$m] = array_map('intval', explode(':', $hhmm));
	if ($h < 0 || $h > 23) return false;
	if ($m < 0 || $m > 59) return false;
	return in_array($m, [0,15,30,45], true);
}

/** Validate "HH:MM-HH:MM" with quarter-hour steps and start < end. */
function nsds_validate_time_window(?string $tw) : bool {
	if ($tw === null) return false;
	if ( ! preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $tw) ) return false;
	[$s,$e] = explode('-', $tw, 2);
	if ( ! nsds_validate_hhmm($s) || ! nsds_validate_hhmm($e) ) return false;
	return strcmp($s,$e) < 0;
}

/** Count bookings for a slot. */
function nsds_bookings_count_for_slot(int $slot_id) : int {
	global $wpdb;
	$book = $wpdb->prefix . 'northstar_delivery_slot_bookings';
	return (int) $wpdb->get_var(
		$wpdb->prepare("SELECT COUNT(*) FROM {$book} WHERE slot_id = %d", $slot_id)
	);
}

/** True if a different slot exists with same date/type/window (collision). */
function nsds_slot_exists_collision(string $date, string $type, string $tw, int $exclude_id = 0) : bool {
	global $wpdb;
	$slots = $wpdb->prefix . 'northstar_delivery_slots';
	$sql = "SELECT id FROM {$slots} WHERE slot_date=%s AND type=%s AND time_window=%s";
	$params = [$date,$type,$tw];
	if ($exclude_id > 0) {
		$sql .= " AND id <> %d";
		$params[] = $exclude_id;
	}
	$found = $wpdb->get_var( $wpdb->prepare($sql, ...$params) );
	return ! empty($found);
}

/* ----------------------------------------------------------------------------- */
/* Routes                                                                        */
/* -------------------------------------------------------------------------- */
add_action('rest_api_init', function(){
	$ns = NSDS_REST_NS;

	// Season Lock
	register_rest_route($ns, '/settings/season-lock', [
		'methods'  => 'GET',
		'callback' => 'nsds_admin_get_season_lock',
		'permission_callback' => function(){ return is_user_logged_in(); },
	]);
	register_rest_route($ns, '/settings/season-lock', [
		'methods'  => 'POST',
		'callback' => 'nsds_admin_set_season_lock',
		'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
	]);

	// Slots CRUD
	register_rest_route($ns, '/slots', [
		'methods'  => 'GET',
		'callback' => 'nsds_admin_list_slots',
		'permission_callback' => function(){ return is_user_logged_in(); },
	]);
	register_rest_route($ns, '/slots', [
		'methods'  => 'POST',
		'callback' => 'nsds_admin_create_slot',
		'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
	]);
	register_rest_route($ns, '/slots/(?P<id>\d+)', [
		// Accept both POST and PUT to match various callers
		'methods'  => [ 'POST', 'PUT' ],
		'callback' => 'nsds_admin_update_slot',
		'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
	]);
	register_rest_route($ns, '/slots/(?P<id>\d+)', [
		'methods'  => 'GET',
		'callback' => 'nsds_admin_get_slot',
		'permission_callback' => function(){ return is_user_logged_in(); },
	]);
	register_rest_route($ns, '/slots/(?P<id>\d+)', [
		'methods'  => 'DELETE',
		'callback' => 'nsds_admin_delete_slot',
		'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
	]);
	register_rest_route($ns, '/slots/(?P<id>\d+)/block', [
		'methods'  => 'POST',
		'callback' => 'nsds_admin_block_slot',
		'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
	]);
	register_rest_route($ns, '/slots/(?P<id>\d+)/unblock', [
		'methods'  => 'POST',
		'callback' => 'nsds_admin_unblock_slot',
		'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
	]);

	// Per-slot bookings (Admin UI “Booked” modal)
	register_rest_route($ns, '/slots/(?P<id>\d+)/bookings', [
		'methods'  => 'GET',
		'callback' => 'nsds_admin_slot_bookings',
		'permission_callback' => function(){ return is_user_logged_in(); },
	]);

	// Season generator (ORIGINAL + ALIAS used by Admin UI)
	register_rest_route($ns, '/season/generate', [
		'methods'  => 'POST',
		'callback' => 'nsds_admin_generate_season',
		'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
	]);
	register_rest_route($ns, '/slots/generate', [
		'methods'  => 'POST',
		'callback' => 'nsds_admin_generate_season',
		'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
	]);

	// Order helpers (stubs preserved)
	register_rest_route($ns, '/order/(?P<order_id>\d+)/delivery', [
		'methods'  => 'POST',
		'callback' => 'nsds_admin_set_delivery',
		'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
	]);
	register_rest_route($ns, '/order/(?P<order_id>\d+)/removal', [
		'methods'  => 'POST',
		'callback' => 'nsds_admin_set_removal',
		'permission_callback' => function(){ return current_user_can('manage_woocommerce'); },
	]);

	// Exports
	register_rest_route($ns, '/export/slots.csv', [
		'methods'  => 'GET',
		'callback' => 'nsds_export_slots_csv',
		'permission_callback' => function(){ return is_user_logged_in(); },
	]);
	register_rest_route($ns, '/export/bookings.csv', [
		'methods'  => 'GET',
		'callback' => 'nsds_export_bookings_csv',
		'permission_callback' => function(){ return is_user_logged_in(); },
	]);
});

/* ----------------------------------------------------------------------------- */
/* Season Lock                                                                   */
/* -------------------------------------------------------------------------- */
function nsds_admin_get_season_lock( WP_REST_Request $req ){
	$locked = nsds_is_season_locked();
	return [ 'locked' => $locked ? 1 : 0 ];
}
function nsds_admin_set_season_lock( WP_REST_Request $req ){
	$locked = (bool) $req->get_param('locked');
	update_option('nsds_season_lock', $locked ? 1 : 0);
	return [ 'ok' => true, 'locked' => $locked ? 1 : 0 ];
}

/* ----------------------------------------------------------------------------- */
/* Slots CRUD                                                                    */
/* -------------------------------------------------------------------------- */
function nsds_admin_list_slots( WP_REST_Request $req ){
	global $wpdb;
	$slots = $wpdb->prefix . 'northstar_delivery_slots';
	$book  = $wpdb->prefix . 'northstar_delivery_slot_bookings';

	$sql = "
		SELECT s.id, s.slot_date, s.type, s.time_window, s.capacity, s.blocked,
		       s.created_at, s.updated_at,
		       COALESCE(b.booked, 0) AS booked
		FROM {$slots} s
		LEFT JOIN (
			SELECT slot_id, COUNT(*) AS booked
			FROM {$book}
			GROUP BY slot_id
		) b ON b.slot_id = s.id
		ORDER BY s.slot_date ASC, s.time_window ASC
	";

	$rows = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
	return $rows;
}

function nsds_admin_create_slot( WP_REST_Request $req ){
	if ( nsds_is_season_locked() ) {
		return nsds_err_locked('Creating slots');
	}

	global $wpdb;
	$slots = $wpdb->prefix . 'northstar_delivery_slots';

	$date  = sanitize_text_field($req->get_param('slot_date'));
	$type  = sanitize_text_field($req->get_param('type'));
	$win   = sanitize_text_field($req->get_param('time_window'));
	$cap   = max(0, (int)$req->get_param('capacity'));
	$blocked = (int)!!$req->get_param('blocked');

	if ( ! nsds_validate_date_iso($date) )   return new WP_Error('bad_date','slot_date must be YYYY-MM-DD and valid',[ 'status'=>400 ]);
	if ( ! nsds_validate_type($type) )        return new WP_Error('bad_type','type must be Delivery or Removal',[ 'status'=>400 ]);
	if ( ! nsds_validate_time_window($win) )  return new WP_Error('bad_window','time_window must be HH:MM-HH:MM in 24h quarter-hour steps with start < end',[ 'status'=>400 ]);

	// Collision check (unique constraint exists, but we give a friendly 409)
	if ( nsds_slot_exists_collision($date, $type, $win) ) {
		return new WP_Error('conflict','A slot with this date/type/window already exists',[ 'status'=>409 ]);
	}

	$wpdb->insert($slots, [
		'slot_date'   => $date,
		'type'        => $type,
		'time_window' => $win,
		'capacity'    => $cap,
		'blocked'     => $blocked,
		'created_at'  => current_time('mysql'),
		'updated_at'  => current_time('mysql'),
	], ['%s','%s','%s','%d','%d','%s','%s']);

	return [ 'id' => (int)$wpdb->insert_id ];
}

function nsds_admin_get_slot( WP_REST_Request $req ){
	global $wpdb;
	$slots = $wpdb->prefix . 'northstar_delivery_slots';
	$book  = $wpdb->prefix . 'northstar_delivery_slot_bookings';
	$id    = (int)$req['id'];

	$sql = $wpdb->prepare("
		SELECT s.id, s.slot_date, s.type, s.time_window, s.capacity, s.blocked,
		       s.created_at, s.updated_at,
		       COALESCE(b.booked, 0) AS booked
		FROM {$slots} s
		LEFT JOIN (
			SELECT slot_id, COUNT(*) AS booked
			FROM {$book}
			GROUP BY slot_id
		) b ON b.slot_id = s.id
		WHERE s.id = %d
	", $id);

	$row = $wpdb->get_row( $sql, ARRAY_A );
	return $row ?: new WP_Error('not_found', 'Slot not found', ['status'=>404]);
}

function nsds_admin_update_slot( WP_REST_Request $req ){
	global $wpdb;
	$slots = $wpdb->prefix . 'northstar_delivery_slots';
	$id    = (int)$req['id'];

	// Build whitelist of fields we accept
	$incoming = [];
	foreach (['slot_date','type','time_window','capacity','blocked'] as $k) {
		if ($req->has_param($k)) {
			$incoming[$k] = $req->get_param($k);
		}
	}
	if (!$incoming) return ['ok'=>true,'updated'=>0];

	// Fetch current row (for comparisons & collision checks)
	$current = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$slots} WHERE id=%d", $id), ARRAY_A );
	if (!$current) return new WP_Error('not_found','Slot not found',[ 'status'=>404 ]);

	$locked = nsds_is_season_locked();

	// Validation & lock policy
	$updates = [];
	// capacity: always allowed, including when locked
	if ( array_key_exists('capacity',$incoming) ) {
		$cap = (int) $incoming['capacity'];
		if ($cap < 0) return new WP_Error('bad_capacity','capacity must be >= 0',[ 'status'=>400 ]);
		$updates['capacity'] = $cap;
	}
	// time_window: validate; disallow when locked; disallow if bookings exist
	if ( array_key_exists('time_window',$incoming) ) {
		if ($locked) return nsds_err_locked('Editing time window');
		$tw = sanitize_text_field($incoming['time_window']);
		if ( ! nsds_validate_time_window($tw) ) {
			return new WP_Error('bad_window','time_window must be HH:MM-HH:MM in 24h quarter-hour steps with start < end',[ 'status'=>400 ]);
		}
		$booked = nsds_bookings_count_for_slot($id);
		if ($booked > 0) {
			return new WP_Error('has_bookings','Cannot change time window on a slot that already has bookings',[ 'status'=>409 ]);
		}
		// Detect collision with other slot using (date,type,tw)
		$date = isset($incoming['slot_date']) ? sanitize_text_field($incoming['slot_date']) : $current['slot_date'];
		$type = isset($incoming['type'])      ? sanitize_text_field($incoming['type'])      : $current['type'];
		if ( ! nsds_validate_date_iso($date) ) return new WP_Error('bad_date','slot_date must be YYYY-MM-DD and valid',[ 'status'=>400 ]);
		if ( ! nsds_validate_type($type) )     return new WP_Error('bad_type','type must be Delivery or Removal',[ 'status'=>400 ]);

		if ( nsds_slot_exists_collision($date, $type, $tw, $id) ) {
			return new WP_Error('conflict','Another slot already exists with this date/type/window',[ 'status'=>409 ]);
		}
		$updates['time_window'] = $tw;
	}
	// slot_date: validate; disallow when locked; collision check applies if time_window/type combine
	if ( array_key_exists('slot_date',$incoming) ) {
		if ($locked) return nsds_err_locked('Editing slot date');
		$d = sanitize_text_field($incoming['slot_date']);
		if ( ! nsds_validate_date_iso($d) ) return new WP_Error('bad_date','slot_date must be YYYY-MM-DD and valid',[ 'status'=>400 ]);
		$updates['slot_date'] = $d;
	}
	// type: validate; disallow when locked
	if ( array_key_exists('type',$incoming) ) {
		if ($locked) return nsds_err_locked('Editing slot type');
		$t = sanitize_text_field($incoming['type']);
		if ( ! nsds_validate_type($t) ) return new WP_Error('bad_type','type must be Delivery or Removal',[ 'status'=>400 ]);
		$updates['type'] = $t;
	}
	// blocked: we accept here, but UI uses dedicated endpoints; still honor it and allow under lock
	if ( array_key_exists('blocked',$incoming) ) {
		$updates['blocked'] = (int) !! $incoming['blocked'];
	}

	// If date/type/time_window change together, perform final collision check
	$final_date = array_key_exists('slot_date',$updates)   ? $updates['slot_date']   : $current['slot_date'];
	$final_type = array_key_exists('type',$updates)        ? $updates['type']        : $current['type'];
	$final_tw   = array_key_exists('time_window',$updates) ? $updates['time_window'] : $current['time_window'];
	if ( ($final_date !== $current['slot_date']) || ($final_type !== $current['type']) || ($final_tw !== $current['time_window']) ) {
		if ( nsds_slot_exists_collision($final_date, $final_type, $final_tw, $id) ) {
			return new WP_Error('conflict','Another slot already exists with this date/type/window',[ 'status'=>409 ]);
		}
	}

	if (!$updates) return ['ok'=>true,'updated'=>0];

	$updates['updated_at'] = current_time('mysql');
	$wpdb->update($slots, $updates, ['id'=>$id]);

	return ['ok'=>true,'updated'=>1];
}

function nsds_admin_delete_slot( WP_REST_Request $req ){
	if ( nsds_is_season_locked() ) {
		return nsds_err_locked('Deleting slots');
	}

	global $wpdb;
	$slots = $wpdb->prefix . 'northstar_delivery_slots';
	$id = (int)$req['id'];

	$exists = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$slots} WHERE id=%d", $id) );
	if (!$exists) return new WP_Error('not_found','Slot not found',[ 'status'=>404 ]);

	$booked = nsds_bookings_count_for_slot($id);
	if ($booked > 0) {
		return new WP_Error('has_bookings', sprintf('Cannot delete: this slot has %d booking(s)', $booked), [ 'status'=>409 ]);
	}

	$wpdb->delete($slots, ['id'=>$id]);
	return ['ok'=>true,'deleted'=>1];
}

function nsds_admin_block_slot( WP_REST_Request $req ){
	// Allowed under lock
	global $wpdb;
	$slots = $wpdb->prefix . 'northstar_delivery_slots';
	$id = (int)$req['id'];
	$wpdb->update($slots, ['blocked'=>1, 'updated_at'=>current_time('mysql')], ['id'=>$id]);
	return ['ok'=>true];
}
function nsds_admin_unblock_slot( WP_REST_Request $req ){
	// Allowed under lock
	global $wpdb;
	$slots = $wpdb->prefix . 'northstar_delivery_slots';
	$id = (int)$req['id'];
	$wpdb->update($slots, ['blocked'=>0, 'updated_at'=>current_time('mysql')], ['id'=>$id]);
	return ['ok'=>true];
}

/* ----------------------------------------------------------------------------- */
/* Per-slot bookings — plural-only SKUs already implemented                      */
/* -------------------------------------------------------------------------- */
function nsds_admin_slot_bookings( WP_REST_Request $req ){
	global $wpdb;
	$id   = (int)$req['id'];
	$book  = $wpdb->prefix . 'northstar_delivery_slot_bookings';

	$sql = $wpdb->prepare("
		SELECT b.id, b.order_id, b.last_name, b.product_skus, b.city, b.state, b.postcode,
		       b.setup, b.removal, b.created_at
		FROM {$book} b
		WHERE b.slot_id = %d
		ORDER BY b.created_at DESC
	", $id);

	$rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
	return [ 'bookings' => $rows ];
}

/* ----------------------------------------------------------------------------- */
/* Season Generator (kept simple in Stage 1; Stage 2 will add duplicate guard)   */
/* -------------------------------------------------------------------------- */
function nsds_admin_generate_season( WP_REST_Request $req ){
	if ( nsds_is_season_locked() ) {
		return nsds_err_locked('Generating a season');
	}

	$start = sanitize_text_field($req->get_param('start')); // optional
	$end   = sanitize_text_field($req->get_param('end'));   // optional
	$year  = intval($req->get_param('year'));               // UI passes {year}
	$type  = sanitize_text_field($req->get_param('type') ?: 'Delivery');
	$cap   = max(0, (int)($req->get_param('capacity') ?: 25));
	$win   = sanitize_text_field($req->get_param('time_window') ?: '08:00-12:00');

	if (!$start || !$end) {
		if ($year > 1970 && $year < 2100) {
			$start = sprintf('%04d-11-15', $year);
			$end   = sprintf('%04d-12-24', $year);
		}
	}

	if (!$start || !$end) {
		return new WP_Error('bad_range','Invalid or missing date range',['status'=>400]);
	}
	if ( ! nsds_validate_date_iso($start) || ! nsds_validate_date_iso($end) ) {
		return new WP_Error('bad_range','start/end must be YYYY-MM-DD',['status'=>400]);
	}
	if ( ! nsds_validate_time_window($win) ) {
		return new WP_Error('bad_window','time_window must be HH:MM-HH:MM in 24h quarter-hour steps with start < end',[ 'status'=>400 ]);
	}
	if ( ! nsds_validate_type($type) ) {
		return new WP_Error('bad_type','type must be Delivery or Removal',[ 'status'=>400 ]);
	}

	global $wpdb;
	$slots = $wpdb->prefix . 'northstar_delivery_slots';

	$d1 = strtotime($start);
	$d2 = strtotime($end);
	if (!$d1 || !$d2 || $d2 < $d1) return new WP_Error('bad_range','Invalid date range',['status'=>400]);

	$tz   = wp_timezone();
	$cur  = new DateTimeImmutable($start, $tz);
	$stop = new DateTimeImmutable($end,   $tz);

	$count = 0;
	for ($d = $cur; $d <= $stop; $d = $d->modify('+1 day')) {
		$wpdb->insert($slots, [
			'slot_date'   => $d->format('Y-m-d'),
			'type'        => $type,
			'time_window' => $win,
			'capacity'    => $cap,
			'blocked'     => 0,
			'created_at'  => current_time('mysql'),
			'updated_at'  => current_time('mysql'),
		], ['%s','%s','%s','%d','%d','%s','%s']);
		$count++;
	}
	return ['ok'=>true,'inserted'=>$count];
}

/* ----------------------------------------------------------------------------- */
/* Order helpers (stubs)                                                         */
/* -------------------------------------------------------------------------- */
function nsds_admin_set_delivery( WP_REST_Request $req ){ return ['ok'=>true]; }
function nsds_admin_set_removal( WP_REST_Request $req ){ return ['ok'=>true]; }

/* ----------------------------------------------------------------------------- */
/* Exports                                                                       */
/* -------------------------------------------------------------------------- */
function nsds_export_slots_csv( WP_REST_Request $req ){
	global $wpdb;
	$slots = $wpdb->prefix . 'northstar_delivery_slots';
	$rows  = $wpdb->get_results("SELECT id, slot_date, type, time_window, capacity, blocked, created_at, updated_at FROM {$slots} ORDER BY slot_date, time_window", ARRAY_A) ?: [];

	$fh = fopen('php://temp', 'w+');
	fputcsv($fh, ['id','slot_date','type','time_window','capacity','blocked','created_at','updated_at']);
	foreach ($rows as $r) fputcsv($fh, $r);
	rewind($fh);
	$csv = stream_get_contents($fh); fclose($fh);

	return new WP_REST_Response( $csv, 200, [
		'Content-Type' => 'text/csv; charset=utf-8',
		'Content-Disposition' => 'attachment; filename="northstar-slots.csv"',
	] );
}
function nsds_export_bookings_csv( WP_REST_Request $req ){
	global $wpdb;
	$book  = $wpdb->prefix . 'northstar_delivery_slot_bookings';
	$slots = $wpdb->prefix . 'northstar_delivery_slots';

	$sql = "
		SELECT b.id, b.order_id, s.type, s.slot_date, s.time_window,
		       b.last_name, b.product_skus, b.city, b.state, b.postcode,
		       b.setup, b.removal, b.created_at
		FROM {$book} b
		INNER JOIN {$slots} s ON s.id = b.slot_id
		ORDER BY s.slot_date ASC, s.time_window ASC, b.created_at DESC
	";
	$rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

	$fh = fopen('php://temp', 'w+');
	fputcsv($fh, ['id','order_id','type','slot_date','time_window','last_name','product_skus','city','state','postcode','setup','removal','created_at']);
	foreach ($rows as $r) fputcsv($fh, $r);
	rewind($fh);
	$csv = stream_get_contents($fh); fclose($fh);

	return new WP_REST_Response( $csv, 200, [
		'Content-Type' => 'text/csv; charset=utf-8',
		'Content-Disposition' => 'attachment; filename="northstar-bookings.csv"',
	] );
}
