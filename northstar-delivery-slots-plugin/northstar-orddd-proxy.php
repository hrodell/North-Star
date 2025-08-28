<?php
/**
 * File: northstar-orddd-proxy.php
 * Package: NorthStar Delivery Slots
 * Version: 1.0.0
 *
 * Developer Summary
 * -----------------
 * PURPOSE
 *   Tyche/ORDDD drives the checkout UI, but NorthStar is the single source
 *   of truth. This module proxies Tyche’s delivery_schedule REST route to
 *   NorthStar’s /nsds/v1/slots and returns ONLY allowed windows.
 *
 * WHAT IT DOES
 *   - Intercepts GET /wp-json/orddd/v1/delivery_schedule (and variants like /delivery_schedule/0)
 *   - Normalizes the "date" into ISO (YYYY-MM-DD)
 *   - Internally calls /wp-json/nsds/v1/slots via rest_do_request (no external HTTP)
 *   - Excludes windows where blocked == 1 OR remaining <= 0
 *   - Returns data in the exact ORDDD shape:
 *        [{ time_slot: "08:00 AM - 12:00 PM", time_slot_i18n: "...", charges: "" }, ...]
 *
 * WHY SERVER-SIDE
 *   This avoids all front-end script ordering / apiFetch / jQuery differences.
 *   If Tyche keeps calling the same route, we keep control of truth.
 *
 * FAIL-CLOSED
 *   If NorthStar fails, return an empty array (prevents overselling).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// … (all the same functions and filter from the prior version) …
