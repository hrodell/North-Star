<?php
/**
 * Checkout Prefill
 *
 * Prefills WooCommerce billing + shipping fields from the stored standard
 * address captured by the gate.
 *
 * Key points:
 * - Uses unified nsaddr_get_std_address() if available (future‑proof).
 * - Falls back to legacy WC()->session->get('ns_addr_std') if needed.
 * - Only fills empty fields (won't overwrite user edits).
 * - Clears stored address after order unless NSADDR_CLEAR_AFTER_ORDER is set false.
 * - Light logging (only when WP_DEBUG & WP_DEBUG_LOG enabled).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get the stored address once per request.
 *
 * @return array|null
 */
function nsaddr_checkout_prefill_get_address(): ?array {
    static $cached = false;
    static $addr   = null;

    if ($cached) {
        return $addr;
    }
    $cached = true;

    // Preferred unified getter
    if (function_exists('nsaddr_get_std_address')) {
        $addr = nsaddr_get_std_address();
    }

    // Legacy session fallback
    if (!$addr && function_exists('WC') && WC()->session) {
        $legacy = WC()->session->get('ns_addr_std');
        if (is_array($legacy) && !empty($legacy['line1']) && !empty($legacy['zip5'])) {
            $addr = $legacy;
        }
    }

    if (!is_array($addr) || empty($addr['line1']) || empty($addr['zip5'])) {
        $addr = null;
        return null;
    }

    // Normalize (safe if already normalized)
    if (function_exists('nsaddr_normalize_line1')) {
        $addr['line1'] = nsaddr_normalize_line1($addr['line1']);
    }
    if (function_exists('nsaddr_normalize_zip')) {
        $addr['zip5'] = nsaddr_normalize_zip($addr['zip5']);
    }

    return $addr;
}

/**
 * Prefill via woocommerce_checkout_get_value (most reliable across themes).
 */
add_filter('woocommerce_checkout_get_value', function ($value, $field_key) {
    $addr = nsaddr_checkout_prefill_get_address();
    if (!$addr) {
        return $value;
    }

    $map = [
        'billing_address_1'  => 'line1',
        'billing_address_2'  => 'line2',
        'billing_city'       => 'city',
        'billing_state'      => 'state',
        'billing_postcode'   => 'zip5',
        'shipping_address_1' => 'line1',
        'shipping_address_2' => 'line2',
        'shipping_city'      => 'city',
        'shipping_state'     => 'state',
        'shipping_postcode'  => 'zip5',
    ];

    if (isset($map[$field_key]) && ($value === '' || $value === null)) {
        $k = $map[$field_key];
        if (!empty($addr[$k])) {
            return $addr[$k];
        }
    }
    return $value;
}, 10, 2);

/**
 * Optional early defaults for themes that only read field definitions.
 */
add_filter('woocommerce_checkout_fields', function ($fields) {
    $addr = nsaddr_checkout_prefill_get_address();
    if (!$addr) {
        return $fields;
    }

    $apply = function (&$scopeArr, $wcKey, $addrKey) use ($addr) {
        if (!isset($scopeArr[$wcKey])) {
            return;
        }
        if (!empty($scopeArr[$wcKey]['default'])) {
            return; // Don't override existing default
        }
        if (!empty($addr[$addrKey])) {
            $scopeArr[$wcKey]['default'] = $addr[$addrKey];
        }
    };

    foreach (['billing', 'shipping'] as $scope) {
        if (!isset($fields[$scope])) {
            continue;
        }
        $apply($fields[$scope], $scope . '_address_1', 'line1');
        $apply($fields[$scope], $scope . '_address_2', 'line2');
        $apply($fields[$scope], $scope . '_city', 'city');
        $apply($fields[$scope], $scope . '_state', 'state');
        $apply($fields[$scope], $scope . '_postcode', 'zip5');
    }

    return $fields;
}, 9);

/**
 * Clear stored address after order (toggle with NSADDR_CLEAR_AFTER_ORDER).
 */
if (!defined('NSADDR_CLEAR_AFTER_ORDER')) {
    define('NSADDR_CLEAR_AFTER_ORDER', true);
}

add_action('woocommerce_checkout_order_processed', function () {
    if (!NSADDR_CLEAR_AFTER_ORDER) {
        return;
    }

    if (function_exists('WC') && WC()->session) {
        WC()->session->__unset('ns_addr_std');
    }

    // Expire cookie safely
    setcookie(
        'ns_addr_std',
        '',
        time() - 3600,
        '/',
        defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
        is_ssl(),
        true
    );

    if (function_exists('nsaddr_log')) {
        nsaddr_log('Cleared ns_addr_std after order');
    }
});

/**
 * Health hook for debugging
 */
add_action('nsaddr_health_check', function () {
    if (function_exists('nsaddr_log')) {
        $addr = nsaddr_checkout_prefill_get_address();
        nsaddr_log('Checkout prefill health', [
            'has_addr' => (bool) $addr,
            'line1'    => $addr['line1'] ?? null,
        ]);
    }
});