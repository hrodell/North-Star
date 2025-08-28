<?php
if (!defined('ABSPATH')) exit;

/**
 * File: northstar-db.php
 * Version: 0.9.9.5
 * DB installer + shared helpers.
 *
 * CHANGELOG
 * 0.9.9.4: Installer provisions `product_skus` (VARCHAR(255) NULL) on
 *          wp_northstar_delivery_slot_bookings. On existing installs, ALTER TABLE to add if missing.
 *          Kept legacy `product_sku` column for back-compat.
 * 0.9.9.5: ADDED UNIQUE CONSTRAINT (slot_id, order_id) for bookings to enforce atomicity and prevent
 *          race condition double-booking. This is added both in initial CREATE TABLE and via ALTER TABLE
 *          for idempotent upgrades.
 *
 * Helpers:
 * - nsds_wp_tz(): site timezone.
 * - nsds_hhmm_to_label(): "HH:MM-HH:MM" -> "h:mm AM – h:mm PM".
 */

if (!function_exists('nsds_wp_tz')) {
	function nsds_wp_tz(): DateTimeZone { return wp_timezone(); }
}
if (!function_exists('nsds_hhmm_to_label')) {
	function nsds_hhmm_to_label(string $window): string {
		$parts = explode('-', $window, 2);
		if (count($parts) !== 2) return $window;
		[$s, $e] = $parts;
		$tz  = nsds_wp_tz();
		$ds  = DateTime::createFromFormat('H:i', $s, $tz);
		$de  = DateTime::createFromFormat('H:i', $e, $tz);
		if (!$ds || !$de) return $window;
		return $ds->format('g:i A') . ' – ' . $de->format('g:i A');
	}
}

if (!function_exists('nsds_install')) {
	function nsds_install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$slots    = $wpdb->prefix . 'northstar_delivery_slots';
		$bookings = $wpdb->prefix . 'northstar_delivery_slot_bookings';
		$audit    = $wpdb->prefix . 'northstar_delivery_slot_audit';

		// Slots
		$sql_slots = "CREATE TABLE {$slots} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slot_date DATE NOT NULL,
			type VARCHAR(16) NOT NULL,
			time_window VARCHAR(11) NOT NULL,
			capacity INT NOT NULL DEFAULT 0,
			blocked TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_slot (slot_date, time_window, type),
			KEY idx_date (slot_date),
			KEY idx_type (type)
		) {$charset};";

		// Bookings — product_sku (legacy) and product_skus (plural), plus slot_id+order_id unique constraint
		$sql_book = "CREATE TABLE {$bookings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slot_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL,
			last_name VARCHAR(100) NULL,
			product_sku VARCHAR(100) NULL,
			product_skus VARCHAR(255) NULL,
			city VARCHAR(100) NULL,
			state VARCHAR(50) NULL,
			postcode VARCHAR(20) NULL,
			setup TINYINT(1) NOT NULL DEFAULT 0,
			removal TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slot_order (slot_id, order_id),
			KEY idx_slot (slot_id),
			KEY idx_order (order_id)
		) {$charset};";

		// Audit
		$sql_audit = "CREATE TABLE {$audit} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slot_id BIGINT UNSIGNED NOT NULL,
			change_type VARCHAR(40) NOT NULL,
			admin_user VARCHAR(60) NULL,
			details LONGTEXT NULL,
			changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_slot (slot_id),
			KEY idx_type (change_type)
		) {$charset};";

		dbDelta($sql_slots);
		dbDelta($sql_book);
		dbDelta($sql_audit);

		// Idempotent: ensure `product_skus` exists on existing installs
		$col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$bookings} LIKE %s", 'product_skus' ) );
		if ( empty( $col ) ) {
			$wpdb->query( "ALTER TABLE {$bookings} ADD COLUMN product_skus VARCHAR(255) NULL AFTER product_sku" );
		}

		// Ensure slot_id+order_id unique constraint exists for bookings (atomicity/race protection)
		$unique_exists = $wpdb->get_results(
			$wpdb->prepare("SHOW INDEX FROM {$bookings} WHERE Key_name = %s", 'slot_order')
		);
		if (empty($unique_exists)) {
			// Only add if not present
			$wpdb->query("ALTER TABLE {$bookings} ADD UNIQUE KEY slot_order (slot_id, order_id)");
		}

		// Initialize season lock option if not set
		if (get_option('nsds_season_lock', null) === null) {
			add_option('nsds_season_lock', false);
		}
	}
}

/**
 * Optional convenience wrapper used by some bootstraps.
 * If the bootstrap calls nsds_db_maybe_install(), it will exist here.
 */
if (!function_exists('nsds_db_maybe_install')) {
	function nsds_db_maybe_install() {
		if ( function_exists('nsds_install') ) {
			nsds_install();
		}
	}
}
