<?php
/**
 * NSDS â€” Enqueue Front-End JS
 * -----------------------------------------------------------------------------
 * Developer Summary
 * Purpose
 * - Load the service cards loader and reminder popup logic on single product pages.
 * - Provide AJAX URL + nonce via localized object.
 */

if ( ! defined('ABSPATH') ) exit;

add_action('wp_enqueue_scripts', function() {
    if (!function_exists('is_product') || !is_product()) return;

    // Ensure Woo fragments are available (header cart count, mini cart)
    if ( function_exists('wp_script_is') && wp_script_is('wc-cart-fragments', 'registered') ) {
        wp_enqueue_script('wc-cart-fragments');
    }

    // jQuery dependency
    wp_enqueue_script('jquery');

    // Main front-end logic for services
    wp_enqueue_script(
        'nsds-tree-service-dynamic',
        plugin_dir_url(__FILE__) . 'tree-service-dynamic.js',
        ['jquery'],
        null,
        true // footer ok; not a capture-phase requirement
    );

    // Localized AJAX config
    wp_localize_script('nsds-tree-service-dynamic', 'nsdsTreeServiceAjax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('nsds_ajax'),
    ]);
});