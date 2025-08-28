<?php
/*
Plugin Name: NorthStar Address Gate (Plugin)
Description: Requires delivery address confirmation before add-to-cart. Provides a soft ZIP check banner and a hard address modal. Enforces a server-side fallback if JS fails. Includes a blocklist and pre-fills checkout fields.
Version: 1.3.0
Author: North Star
Text Domain: nsaddr
*/

/**
 * Developer Summary
 * ---------------------------------------------------------------------------
 * This root file wires the modular Address Gate system:
 *  - address-gate-blocklist.php: Blocklist definitions + helpers
 *  - address-gate-core.php: Core shipping zone + validation + AJAX handlers
 *  - address-gate-checkout-prefill.php: Checkout field prefill from stored address
 *  - address-gate-enqueue.php: Script enqueue + localization (messages & nonce)
 *
 * NEW IN 1.3.0 (A fixes):
 *  - Centralized messages (blocked / validator_down / out_of_area) as constants
 *    with filters for override (A2, A10).
 *  - Removed duplicate blocklist substring logic in core; rely on
 *    nsaddr_is_blocked_address() (A9).
 *  - Added role="alert" usage in JS markup (enqueued script uses localized copy) (A6).
 *  - Typo fix: "deliverying" -> "delivering" (A1).
 *  - Cleaned encoding artifacts in comments (A4).
 *  - Added translation wrappers for localized strings (A7).
 *  - Clarified security / fallback behavior in comments (A8).
 *
 * SECURITY NOTE
 *  The token cookie (ns_addr_token) is *not* a security credential—only a
 *  gating flag. It is intentionally not HttpOnly so client JS can read it.
 */

if (!defined('ABSPATH')) { exit; }

/* ---------------------------------------------------------------------------
 * CORE CONSTANTS
 * ------------------------------------------------------------------------- */
if (!defined('NSADDR_VERSION'))         define('NSADDR_VERSION', '1.3.0');
if (!defined('NSADDR_PLUGIN_FILE'))     define('NSADDR_PLUGIN_FILE', __FILE__);
if (!defined('NSADDR_MIN_PHP'))         define('NSADDR_MIN_PHP', '7.4');
if (!defined('NSADDR_TEXTDOMAIN'))      define('NSADDR_TEXTDOMAIN', 'nsaddr');
if (!defined('NSADDR_ZONE_NAME'))       define('NSADDR_ZONE_NAME', 'North Star Delivery Area');
if (!defined('NSADDR_SOFT_ENABLED'))    define('NSADDR_SOFT_ENABLED', true);
if (!defined('NSADDR_SERVER_FALLBACK')) define('NSADDR_SERVER_FALLBACK', true);
if (!defined('NSADDR_FAIL_OPEN'))       define('NSADDR_FAIL_OPEN', false); // false => if zone missing, treat as NOT serviceable (fail closed)

/* Centralized user-visible messages (filterable) */
if (!defined('NSADDR_MSG_VALIDATOR_DOWN')) define(
    'NSADDR_MSG_VALIDATOR_DOWN',
    __("We're sorry, there are some issues with delivering to this address. Please contact our office at 301-933-4833.", 'nsaddr')
);
if (!defined('NSADDR_MSG_BLOCKED')) define(
    'NSADDR_MSG_BLOCKED',
    __("We're sorry, we cannot deliver to that address. Please contact our office at 301-933-4833.", 'nsaddr')
);
if (!defined('NSADDR_MSG_OUT_ZONE')) define(
    'NSADDR_MSG_OUT_ZONE',
    __("I'm sorry, but we don't deliver to your area.", 'nsaddr')
);

/* Legacy aliases (retain for backward compatibility) */
if (!defined('NS_ADDR_ZONE_NAME'))        define('NS_ADDR_ZONE_NAME', NSADDR_ZONE_NAME);
if (!defined('NS_ADDR_SOFT_ENABLED'))     define('NS_ADDR_SOFT_ENABLED', NSADDR_SOFT_ENABLED);
if (!defined('NS_ADDR_SERVER_FALLBACK'))  define('NS_ADDR_SERVER_FALLBACK', NSADDR_SERVER_FALLBACK);
if (!defined('NS_ADDR_FAIL_OPEN'))        define('NS_ADDR_FAIL_OPEN', NSADDR_FAIL_OPEN);

/* ---------------------------------------------------------------------------
 * SAFE DEBUG LOGGER
 * ------------------------------------------------------------------------- */
if (!function_exists('nsaddr_log')) {
    function nsaddr_log($msg, $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $prefix = '[NSADDR] ';
            error_log($prefix . $msg . ($data !== null ? ' ' . wp_json_encode($data, JSON_UNESCAPED_SLASHES) : ''));
        }
    }
}

/* ---------------------------------------------------------------------------
 * REQUIRED MODULE REGISTRY
 * ------------------------------------------------------------------------- */
function nsaddr_required_files(): array {
    $base = plugin_dir_path(NSADDR_PLUGIN_FILE);
    return [
        $base . 'address-gate-enqueue.php',
        $base . 'address-gate-core.php',
        $base . 'address-gate-checkout-prefill.php',
        $base . 'address-gate-blocklist.php',
    ];
}

/* ---------------------------------------------------------------------------
 * ACTIVATION CHECKS
 * ------------------------------------------------------------------------- */
register_activation_hook(NSADDR_PLUGIN_FILE, function () {
    if (version_compare(PHP_VERSION, NSADDR_MIN_PHP, '<')) {
        deactivate_plugins(plugin_basename(NSADDR_PLUGIN_FILE));
        wp_die(
            sprintf(
                esc_html__('NorthStar Address Gate requires PHP %1$s or higher. Your server is running %2$s.', 'nsaddr'),
                NSADDR_MIN_PHP,
                PHP_VERSION
            ),
            esc_html__('Plugin Activation Error', 'nsaddr'),
            ['back_link' => true]
        );
    }
    $missing = [];
    foreach (nsaddr_required_files() as $file) {
        if (!file_exists($file)) $missing[] = $file;
    }
    if ($missing) {
        deactivate_plugins(plugin_basename(NSADDR_PLUGIN_FILE));
        wp_die(
            '<p>' . esc_html__('NorthStar Address Gate could not activate because required files are missing:', 'nsaddr') . '</p><pre>'
            . esc_html(implode("\n", $missing)) . '</pre>',
            esc_html__('Plugin Activation Error', 'nsaddr'),
            ['back_link' => true]
        );
    }
    if (!class_exists('WooCommerce')) {
        update_option('nsaddr_admin_notice_missing_woo', 1, false);
    }
});

/* ---------------------------------------------------------------------------
 * TEXT DOMAIN
 * ------------------------------------------------------------------------- */
add_action('plugins_loaded', function () {
    load_plugin_textdomain('nsaddr', false, dirname(plugin_basename(NSADDR_PLUGIN_FILE)) . '/languages/');
});

/* ---------------------------------------------------------------------------
 * ADMIN NOTICE (WooCommerce Missing)
 * ------------------------------------------------------------------------- */
add_action('admin_notices', function () {
    if (!current_user_can('activate_plugins')) return;
    if (get_option('nsaddr_admin_notice_missing_woo')) {
        delete_option('nsaddr_admin_notice_missing_woo');
        echo '<div class="notice notice-warning is-dismissible"><p>'
            . esc_html__('NorthStar Address Gate is active, but WooCommerce is not active. Please activate WooCommerce.', 'nsaddr')
            . '</p></div>';
    }
});

/* ---------------------------------------------------------------------------
 * INCLUDE MODULES (Ordered)
 * ------------------------------------------------------------------------- */
$__nsaddr_modules = nsaddr_required_files();
/* Desired order: blocklist -> core -> checkout -> enqueue */
require_once $__nsaddr_modules[3];
require_once $__nsaddr_modules[1];
require_once $__nsaddr_modules[2];
require_once $__nsaddr_modules[0];

/* ---------------------------------------------------------------------------
 * SERVER-SIDE FALLBACK: ADD-TO-CART VALIDATION
 * ---------------------------------------------------------------------------
 * If JavaScript is disabled OR user bypasses modal:
 *  - Require token (unless filtered off)
 */
if (!function_exists('nsaddr_validate_add_to_cart_token')) {
    function nsaddr_validate_add_to_cart_token($passed, $product_id, $quantity, $variation_id = 0, $variation = [], $cart_item_data = []) {
        if (!$passed || is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) return $passed;
        $require = apply_filters('nsaddr_require_token_for_cart', (bool) NS_ADDR_SERVER_FALLBACK, $product_id);
        if (!$require) return $passed;
        if (function_exists('ns_addr_has_token') && ns_addr_has_token()) return $passed;

        if (function_exists('wc_add_notice')) {
            wc_add_notice(
                esc_html__('Please confirm your delivery address before adding to cart.', 'nsaddr'),
                'error'
            );
        }
        nsaddr_log('Blocked add-to-cart (no token).', ['product_id' => $product_id, 'variation_id' => $variation_id]);
        return false;
    }
}
add_filter('woocommerce_add_to_cart_validation', 'nsaddr_validate_add_to_cart_token', 1, 6);

/* ---------------------------------------------------------------------------
 * PLUGIN ROW META
 * ------------------------------------------------------------------------- */
add_filter('plugin_row_meta', function ($links, $file) {
    if ($file === plugin_basename(NSADDR_PLUGIN_FILE)) {
        $links[] = '<a href="mailto:dev@northstar.example" target="_blank" rel="noopener">'
            . esc_html__('Support', 'nsaddr') . '</a>';
    }
    return $links;
}, 10, 2);

/* ---------------------------------------------------------------------------
 * HEALTH CHECK (Debug Hook)
 * ------------------------------------------------------------------------- */
add_action('nsaddr_health_check', function () {
    nsaddr_log('Health check', [
        'version'         => NSADDR_VERSION,
        'wc_active'       => class_exists('WooCommerce'),
        'fail_open'       => NSADDR_FAIL_OPEN,
        'server_fallback' => NSADDR_SERVER_FALLBACK,
    ]);
});