<?php
/**
 * File: add-to-cart-hook.php
 * Version: 2.6.5
 * Date: 2025-08-27
 *
 * NSDS – Tree Service Cart Orchestration (Add, Link, Sync, Remove)
 *
 * DEVELOPER SUMMARY
 * -----------------------------------------------------------------------------
 * - Handles attaching setup/removal service products as child items to Christmas tree products in WooCommerce cart.
 * - Validates incoming service product IDs against allowed mapping for selected tree height (CRITICAL PATCH).
 * - Validates server-side nonce for service selections (prevents CSRF).
 * - Provides translation wrappers for i18n (domain 'nsds').
 * - Robust debug snapshots if NSDS_CART_DEBUG enabled.
 *
 * SECURITY NOTES
 * -----------------------------------------------------------------------------
 * - CRITICAL: Only allows child service IDs that match allowed mapping for selected tree height.
 * - Nonce must be present and valid for service-enabled add-to-cart forms.
 * - All failed nonce attempts are logged for audit in nsds-security.php.
 * - No direct DB writes from user input.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'NSDS_META_PARENT_KEY' ) )  define( 'NSDS_META_PARENT_KEY',  'parent_cart_key' );
if ( ! defined( 'NSDS_META_SERVICE_TYPE' ) )define( 'NSDS_META_SERVICE_TYPE','service_type' );
if ( ! defined( 'NSDS_CART_DEBUG' ) )       define( 'NSDS_CART_DEBUG', false );

// Load mapping for critical validation
require_once __DIR__ . '/service-product-mapping.php';

/* ---------------------------------------------------------------------------
 * NONCE VALIDATION
 * ------------------------------------------------------------------------- */
if (!function_exists('nsds_validate_services_nonce')) {
    function nsds_validate_services_nonce($passed, $product_id, $quantity, $variation_id = 0, $variation = [], $cart_item_data = []) {
        if (!$passed) return $passed;

        if (empty($_POST['nsds_services_nonce'])) return $passed; // Not a service-enabled form.

        if (!has_term('christmas-trees', 'product_cat', $product_id)) return $passed;

        $nonce = sanitize_text_field( wp_unslash($_POST['nsds_services_nonce']) );
        if (!wp_verify_nonce($nonce, 'nsds_add_services')) {
            if (function_exists('nsds_sec_log')) {
                nsds_sec_log('Failed nonce validation on add-to-cart.', [
                    'product_id' => $product_id,
                    'cart_item_data' => $cart_item_data
                ]);
            }
            if (function_exists('wc_add_notice')) {
                wc_add_notice(
                    __('Security check failed. Please refresh and try again.', 'nsds'),
                    'error'
                );
            }
            return false;
        }
        return $passed;
    }
}
add_filter('woocommerce_add_to_cart_validation', 'nsds_validate_services_nonce', 5, 6);

/* ---------------------------------------------------------------------------
 * HELPERS
 * ------------------------------------------------------------------------- */
function nsds_is_service_line( array $cart_item ): bool {
    return ! empty( $cart_item[ NSDS_META_SERVICE_TYPE ] );
}
function nsds_is_tree_parent_line( array $cart_item ): bool {
    return ! nsds_is_service_line( $cart_item );
}
function nsds_get_posted_service_ids(): array {
    $map = [];
    if ( isset( $_POST['add_setup'] ) ) {
        $id = absint( $_POST['add_setup'] );
        if ( $id > 0 ) $map['setup'] = $id;
    }
    if ( isset( $_POST['add_removal'] ) ) {
        $id = absint( $_POST['add_removal'] );
        if ( $id > 0 ) $map['removal'] = $id;
    }
    return $map;
}

// CRITICAL PATCH: Validate posted service IDs against allowed mapping for selected tree height
function nsds_filter_valid_service_ids(array $service_ids): array {
    $height = isset($_POST['attribute_pa_height']) ? sanitize_text_field($_POST['attribute_pa_height']) : '';
    $map = function_exists('nsds_get_service_product_map') ? nsds_get_service_product_map() : [];
    $valid_setup = isset($map[$height]['setup']['id']) ? (int)$map[$height]['setup']['id'] : null;
    $valid_removal = isset($map[$height]['removal']['id']) ? (int)$map[$height]['removal']['id'] : null;

    $filtered = [];
    if (isset($service_ids['setup']) && $service_ids['setup'] === $valid_setup) {
        $filtered['setup'] = $valid_setup;
    } elseif (isset($service_ids['setup'])) {
        if (function_exists('nsds_sec_log')) {
            nsds_sec_log('Blocked invalid setup service ID on add-to-cart.', [
                'height' => $height,
                'posted_id' => $service_ids['setup'],
                'expected_id' => $valid_setup
            ]);
        }
    }
    if (isset($service_ids['removal']) && $service_ids['removal'] === $valid_removal) {
        $filtered['removal'] = $valid_removal;
    } elseif (isset($service_ids['removal'])) {
        if (function_exists('nsds_sec_log')) {
            nsds_sec_log('Blocked invalid removal service ID on add-to-cart.', [
                'height' => $height,
                'posted_id' => $service_ids['removal'],
                'expected_id' => $valid_removal
            ]);
        }
    }
    return $filtered;
}

function nsds_debug_snapshot( string $context ): void {
    if ( ! NSDS_CART_DEBUG || ! function_exists( 'WC' ) || ! WC()->cart ) return;
    static $seen = [];
    $out = [];
    foreach ( WC()->cart->get_cart() as $key => $item ) {
        $out[] = [
            'key'   => $key,
            'pid'   => $item['product_id'] ?? 0,
            'qty'   => $item['quantity'] ?? 0,
            'parent'=> $item[ NSDS_META_PARENT_KEY ] ?? '',
            'type'  => $item[ NSDS_META_SERVICE_TYPE ] ?? '',
        ];
    }
    $hash = md5( wp_json_encode( $out ) . $context );
    if ( isset( $seen[ $hash ] ) ) return;
    $seen[ $hash ] = true;
    error_log( 'NSDS_CART_DEBUG ' . $context . ' ' . wp_json_encode( $out ) );
}
function nsds_find_child_service_key( WC_Cart $cart, string $parent_cart_key, string $service_type ): ?string {
    foreach ( $cart->get_cart() as $ckey => $citem ) {
        if (
            isset( $citem[ NSDS_META_PARENT_KEY ], $citem[ NSDS_META_SERVICE_TYPE ] ) &&
            $citem[ NSDS_META_PARENT_KEY ] === $parent_cart_key &&
            $citem[ NSDS_META_SERVICE_TYPE ] === $service_type
        ) {
            return $ckey;
        }
    }
    return null;
}
function nsds_ensure_child_service(
    WC_Cart $cart,
    string  $parent_cart_key,
    string  $service_type,
    int     $service_product_id,
    int     $parent_qty
): void {
    $existing_key = nsds_find_child_service_key( $cart, $parent_cart_key, $service_type );
    if ( $existing_key ) {
        $child = $cart->get_cart_item( $existing_key );
        if ( $child && (int) $child['quantity'] !== $parent_qty ) {
            $cart->set_quantity( $existing_key, $parent_qty, false );
            nsds_debug_snapshot( 'child_sync:' . $service_type );
        }
        return;
    }
    $cart->add_to_cart(
        $service_product_id,
        $parent_qty,
        0,
        [],
        [
            NSDS_META_PARENT_KEY   => $parent_cart_key,
            NSDS_META_SERVICE_TYPE => $service_type,
        ]
    );
    nsds_debug_snapshot( 'child_added:' . $service_type );
}
function nsds_remove_children( WC_Cart $cart, string $parent_cart_key ): void {
    $changed = false;
    foreach ( $cart->get_cart() as $ckey => $citem ) {
        if ( isset( $citem[ NSDS_META_PARENT_KEY ] ) && $citem[ NSDS_META_PARENT_KEY ] === $parent_cart_key ) {
            $cart->remove_cart_item( $ckey );
            $changed = true;
        }
    }
    if ( $changed ) nsds_debug_snapshot( 'children_removed' );
}

/* PARENT ADD -> ATTACH CHILD SERVICES */
add_action(
    'woocommerce_add_to_cart',
    function( string $parent_cart_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data ) {
        if ( nsds_is_service_line( $cart_item_data ) ) return;
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;
        $service_ids = nsds_get_posted_service_ids();
        $service_ids = nsds_filter_valid_service_ids($service_ids); // CRITICAL: Validate IDs
        if ( ! $service_ids ) return;
        foreach ( $service_ids as $service_type => $service_pid ) {
            if ( $service_pid > 0 ) {
                nsds_ensure_child_service( WC()->cart, $parent_cart_key, $service_type, $service_pid, $quantity );
            }
        }
        nsds_debug_snapshot( 'parent_added' );
    },
    20,
    6
);

/* PARENT QTY SYNC -> CHILDREN */
add_action(
    'woocommerce_after_cart_item_quantity_update',
    function( $cart_item_key, $new_qty, $old_qty, $cart ) {
        if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart_item' ) ) return;
        $item = $cart->get_cart_item( $cart_item_key );
        if ( ! $item ) return;
        if ( ! nsds_is_tree_parent_line( $item ) ) return;

        static $guard = [];
        if ( isset( $guard[ $cart_item_key ] ) ) return;
        $guard[ $cart_item_key ] = true;

        $qty = (int) $new_qty;
        if ( $qty > 0 ) {
            foreach ( $cart->get_cart() as $ckey => $citem ) {
                if (
                    isset( $citem[ NSDS_META_PARENT_KEY ], $citem[ NSDS_META_SERVICE_TYPE ] ) &&
                    $citem[ NSDS_META_PARENT_KEY ] === $cart_item_key &&
                    (int) $citem['quantity'] !== $qty
                ) {
                    $cart->set_quantity( $ckey, $qty, false );
                }
            }
            nsds_debug_snapshot( 'parent_qty_sync' );
        } else {
            nsds_remove_children( $cart, $cart_item_key );
        }

        unset( $guard[ $cart_item_key ] );
    },
    20,
    4
);

/* PARENT REMOVAL -> CHILD REMOVAL */
add_action(
    'woocommerce_remove_cart_item',
    function( $removed_key, $cart ) {
        if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) return;
        nsds_remove_children( $cart, $removed_key );
    },
    20,
    2
);