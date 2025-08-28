<?php
/**
 * filename: northstar-email-hooks.php Version: 0.9.9
 * Adds Removal date/time + disclaimer to WooCommerce emails (admin & customer).
 *
 * DEV SUMMARY
 * - Reads `_ns_removal_timestamp` (UTC) and `_ns_removal_time_window` ("HH:MM-HH:MM").
 * - If present, renders a simple section under the order meta in emails.
 * - Uses site timezone and the same 12-hour label helper as the rest of the plugin.
 * - Fails gracefully (prints nothing) when meta are absent.
 */

if (!defined('ABSPATH')) exit;

/** Render extra order meta in emails */
add_action('woocommerce_email_order_meta', function ($order, $sent_to_admin, $plain_text) {

	if (!($order instanceof WC_Order)) return;

	$stamp = $order->get_meta('_ns_removal_timestamp');
	$win   = $order->get_meta('_ns_removal_time_window');

	if (!$stamp && !$win) return; // nothing to show

	// Convert timestamp (assumed UTC) into site timezone
	$when  = null;
	if ($stamp) {
		try {
			$dt = new DateTime('@' . (int)$stamp);
			$dt->setTimezone(nsds_wp_tz());
			$when = $dt;
		} catch (Throwable $e) { /* ignore */ }
	}

	$label = $win ? nsds_hhmm_to_label($win) : null;

	// Copy you requested
	$disclaimer = __('Time windows are estimated, and occasionally the delivery crew is running slightly ahead or behind. Thank you for your understanding.', 'northstar');

	if ($plain_text) {
		echo "\n";
		echo "Removal:\n";
		if ($when)  echo '  Date: ' . $when->format('F j, Y') . "\n";
		if ($label) echo '  Time: ' . $label . "\n";
		echo '  ' . $disclaimer . "\n";
		echo "\n";
	} else {
		echo '<h3 style="margin:1.2em 0 0.5em;">' . esc_html__('Removal', 'northstar') . '</h3>';
		echo '<ul class="nsds-email-removal" style="list-style:disc;margin:0 0 1em 1.2em;padding:0;">';
		if ($when)  echo '<li><strong>' . esc_html__('Date:', 'northstar') . '</strong> ' . esc_html($when->format('F j, Y')) . '</li>';
		if ($label) echo '<li><strong>' . esc_html__('Time:', 'northstar') . '</strong> ' . esc_html($label) . '</li>';
		echo '<li>' . esc_html($disclaimer) . '</li>';
		echo '</ul>';
	}
}, 20, 3);
