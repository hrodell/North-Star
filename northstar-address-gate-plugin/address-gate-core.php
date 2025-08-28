<?php
/**
 * File: address-gate-core.php
 * Version: 1.3.1
 * Date: 2025-08-27
 *
 * NorthStar Address Gate — Core Controller (AJAX, Zone Logic, Cookies)
 *
 * DEVELOPER SUMMARY
 * ---------------------------------------------------------------------------
 * - Central logic for address gating in WooCommerce (NorthStar plugin).
 * - Handles shipping zone checks, blocklist enforcement, PO Box rejection.
 * - Issues and verifies gating tokens via cookies and Woo session.
 * - AJAX endpoints for soft ZIP banner and hard address modal.
 * - Stores standardized address for checkout prefill (used by other modules).
 * - CRITICAL PATCH v1.3.1: Adds audit logging for ALL failed address validation
 *   attempts (invalid input, PO Box, out-of-area ZIP, blocklist matches).
 * - Logging via nsaddr_log(), appears in error_log if WP_DEBUG enabled.
 *
 * SECURITY NOTE
 * ---------------------------------------------------------------------------
 * - Gating token is NOT a security credential, only a logic flag for UI gating.
 * - All AJAX endpoints require nonce for CSRF protection.
 * - No direct DB writes/queries from user input.
 * - Cookie expiry: Token (30 days), Address (7 days, configurable).
 */

if (!defined('ABSPATH')) exit;

/* ---------------------------------------------------------------------------
 * COOKIE HELPERS
 * ------------------------------------------------------------------------- */
function ns_addr_set_token_cookie(): void {
    // Sets UI gating token cookie (not a security credential).
    $params = [
        'expires'  => time() + 30 * DAY_IN_SECONDS,
        'path'     => '/',
        'secure'   => is_ssl(),
        'httponly' => false,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        setcookie('ns_addr_token', '1', $params);
    } else {
        setcookie('ns_addr_token', '1', $params['expires'], $params['path'], '', $params['secure'], $params['httponly']);
    }
    $_COOKIE['ns_addr_token'] = '1';
}
function ns_addr_has_token(): bool {
    // Returns true if gating token is set in cookies.
    return !empty($_COOKIE['ns_addr_token']);
}

function ns_addr_set_std_store(array $std): void {
    // Stores standardized address in Woo session and cookie for checkout prefill.
    if (function_exists('WC') && WC()->session) {
        WC()->session->set('ns_addr_std', $std);
    }
    $val = rawurlencode(wp_json_encode($std));
    $params = [
        'expires'  => time() + 7 * DAY_IN_SECONDS, // Address prefill cookie, 7 days.
        'path'     => '/',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        setcookie('ns_addr_std', $val, $params);
    } else {
        setcookie('ns_addr_std', $val, $params['expires'], $params['path'], '', $params['secure'], $params['httponly']);
    }
    $_COOKIE['ns_addr_std'] = $val;
}

/* ---------------------------------------------------------------------------
 * NORMALIZERS
 * ------------------------------------------------------------------------- */
function ns_addr_normalize_zip(string $raw): string {
    // Returns first 5 digits from ZIP input.
    $digits = preg_replace('/\D+/', '', $raw);
    return substr($digits, 0, 5);
}
function ns_addr_norm_line1(string $line1): string {
    // Collapses spaces, trims and uppercases the address line1.
    return strtoupper(trim(preg_replace('/\s+/', ' ', $line1)));
}
function ns_addr_is_pobox(string $line1): bool {
    // Returns true if address line1 contains PO Box (regex).
    return (bool) preg_match('/\bP\.?\s*O\.?\s*BOX\b/i', $line1);
}

/* ---------------------------------------------------------------------------
 * SHIPPING ZONE CHECKS
 * ------------------------------------------------------------------------- */
function ns_addr_find_zone_by_name(string $name): ?array {
    // Finds WooCommerce shipping zone by zone_name (case-insensitive).
    if (!class_exists('WC_Shipping_Zones')) return null;
    $zones = \WC_Shipping_Zones::get_zones();
    foreach ($zones as $z) {
        if (!empty($z['zone_name']) && strcasecmp($z['zone_name'], $name) === 0) {
            return $z;
        }
    }
    return null;
}
function ns_addr_code_matches(string $zip5, $code): bool {
    // Checks ZIP against zone code, with wildcards.
    $code = (string) $code;
    $rx = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote(strtoupper($code), '/')) . '$/i';
    return (bool) preg_match($rx, strtoupper($zip5));
}
function ns_addr_zip_in_zone(string $zip5, array $zone): bool {
    // Checks if ZIP is in zone's locations.
    if (empty($zone['zone_locations'])) return false;
    foreach ($zone['zone_locations'] as $loc) {
        $type = is_object($loc) ? ($loc->type ?? '') : ($loc['type'] ?? '');
        $code = is_object($loc) ? ($loc->code ?? '') : ($loc['code'] ?? '');
        if ($type === 'postcode' && $code !== '' && ns_addr_code_matches($zip5, $code)) return true;
    }
    return false;
}
function ns_addr_is_serviceable_zip(string $zip): bool {
    // Returns true if ZIP is serviceable (in configured shipping zone).
    $zip = ns_addr_normalize_zip($zip);
    if (strlen($zip) !== 5) return false;

    $zone = ns_addr_find_zone_by_name(NS_ADDR_ZONE_NAME);
    if (!$zone) {
        // Honor fail-open/closed constant.
        return (bool) NS_ADDR_FAIL_OPEN;
    }

    $has_enabled = false;
    if (!empty($zone['shipping_methods']) && is_array($zone['shipping_methods'])) {
        foreach ($zone['shipping_methods'] as $m) {
            if (!empty($m->enabled) && $m->enabled === 'yes') { $has_enabled = true; break; }
        }
    }
    if (!$has_enabled) return false;

    return ns_addr_zip_in_zone($zip, $zone);
}

/* ---------------------------------------------------------------------------
 * AJAX: SOFT ZIP BANNER
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_ns_addr_soft_dismiss', 'ns_addr_soft_dismiss');
add_action('wp_ajax_nopriv_ns_addr_soft_dismiss', 'ns_addr_soft_dismiss');
function ns_addr_soft_dismiss() {
    // AJAX: dismiss soft ZIP banner.
    check_ajax_referer('ns_addr_ajax', 'nonce');
    wp_send_json_success(['ok' => true]);
}

add_action('wp_ajax_ns_addr_check_zip', 'ns_addr_check_zip');
add_action('wp_ajax_nopriv_ns_addr_check_zip', 'ns_addr_check_zip');
function ns_addr_check_zip() {
    // AJAX: check ZIP for serviceability (soft banner).
    check_ajax_referer('ns_addr_ajax', 'nonce');
    $zip = isset($_POST['zip']) ? ns_addr_normalize_zip((string) wp_unslash($_POST['zip'])) : '';
    if (strlen($zip) !== 5) {
        wp_send_json_error(['message' => __('Invalid ZIP', 'nsaddr')], 400);
    }
    $ok = ns_addr_is_serviceable_zip($zip);
    wp_send_json_success(['in_zone' => (bool) $ok, 'zip' => $zip]);
}

/* ---------------------------------------------------------------------------
 * AJAX: HARD ADDRESS VALIDATION (CRITICAL PATCH: AUDIT LOGGING ADDED)
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_ns_addr_validate_full', 'ns_addr_validate_full');
add_action('wp_ajax_nopriv_ns_addr_validate_full', 'ns_addr_validate_full');
function ns_addr_validate_full() {
    /**
     * AJAX: Validates full address from modal.
     * - Logs all failed attempts for audit/abuse tracking.
     */
    check_ajax_referer('ns_addr_ajax', 'nonce');

    $line1 = isset($_POST['line1']) ? trim((string) wp_unslash($_POST['line1'])) : '';
    $line2 = isset($_POST['line2']) ? trim((string) wp_unslash($_POST['line2'])) : '';
    $city  = isset($_POST['city'])  ? trim((string) wp_unslash($_POST['city']))  : '';
    $state = isset($_POST['state']) ? strtoupper(preg_replace('/[^A-Za-z]/', '', (string) wp_unslash($_POST['state']))) : '';
    $zip   = isset($_POST['zip'])   ? ns_addr_normalize_zip((string) wp_unslash($_POST['zip'])) : '';

    // --- Audit log: invalid/incomplete input ---
    if ($line1 === '' || $city === '' || strlen($state) !== 2 || strlen($zip) !== 5) {
        if (function_exists('nsaddr_log')) {
            nsaddr_log('Address validation failed: incomplete or invalid input', [
                'line1' => $line1, 'line2' => $line2, 'city' => $city, 'state' => $state, 'zip' => $zip
            ]);
        }
        wp_send_json_error(['message' => __('Please enter a valid US delivery address.', 'nsaddr')], 400);
    }

    // --- Audit log: PO Box detected ---
    if (ns_addr_is_pobox($line1)) {
        if (function_exists('nsaddr_log')) {
            nsaddr_log('Address validation failed: PO Box detected', [
                'line1' => $line1, 'zip' => $zip
            ]);
        }
        wp_send_json_error(['message' => __("We can't deliver to PO Boxes - please use a street address.", 'nsaddr')], 400);
    }

    // --- Audit log: ZIP not serviceable ---
    if (!ns_addr_is_serviceable_zip($zip)) {
        if (function_exists('nsaddr_log')) {
            nsaddr_log('Address validation failed: ZIP not in serviceable area', [
                'line1' => $line1, 'zip' => $zip
            ]);
        }
        $out = apply_filters('nsaddr_message_out_zone', NSADDR_MSG_OUT_ZONE);
        wp_send_json_error(['message' => $out], 400);
    }

    // --- Audit log: blocklisted address ---
    if (function_exists('nsaddr_is_blocked_address') && nsaddr_is_blocked_address($line1, $zip)) {
        if (function_exists('nsaddr_log')) {
            nsaddr_log('Address validation failed: Blocklisted address', [
                'line1' => $line1, 'zip' => $zip
            ]);
        }
        $blocked = apply_filters('nsaddr_message_blocked', NSADDR_MSG_BLOCKED, $line1, $zip);
        wp_send_json_error(['message' => $blocked], 400);
    }

    // --- Success: issue token and store standardized address ---
    ns_addr_set_token_cookie();

    $std = [
        'line1' => trim(preg_replace('/\s+/', ' ', $line1)),
        'line2' => trim(preg_replace('/\s+/', ' ', $line2)),
        'city'  => trim(preg_replace('/\s+/', ' ', $city)),
        'state' => $state,
        'zip5'  => $zip,
    ];
    ns_addr_set_std_store($std);

    wp_send_json_success(['ok' => true, 'std' => $std]);
}