<?php
/**
 * File: display-block.php
 * Version: 2.6.5
 * Date: 2025-08-27
 *
 * NSDS — Inject Service Container + Nonce
 *
 * DEVELOPER SUMMARY
 * -----------------------------------------------------------------------------
 * - Injects an empty container for AJAX-loaded service cards above the add-to-cart button.
 * - Always emits nonce field 'nsds_services_nonce' for server-side validation.
 * - Only runs for Christmas tree products.
 *
 * SECURITY NOTES
 * -----------------------------------------------------------------------------
 * - Nonce is required for all service-enabled add-to-cart submissions.
 * - No user input processing here.
 */

if ( ! defined('ABSPATH') ) exit;

add_action('woocommerce_before_add_to_cart_button', function() {
    global $product;
    if (!$product) return;

    // Limit to Christmas tree products
    if (has_term('christmas-trees', 'product_cat', $product->get_id())) {
        echo '<div id="tree-service-container"></div>';
        wp_nonce_field('nsds_add_services', 'nsds_services_nonce');
    }
});