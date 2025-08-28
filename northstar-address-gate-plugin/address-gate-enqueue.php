<?php
/**
 * Address Gate — Enqueue & Localization
 * Centralizes localized strings (soft banner + modal) using constants & filters.
 */
if (!defined('ABSPATH')) exit;

add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;

    $handle  = 'ns-address-gate';
    $src     = plugins_url('address-gate.js', NSADDR_PLUGIN_FILE);
    $version = defined('NSADDR_VERSION') ? NSADDR_VERSION : '1.3.0';

    wp_register_script($handle, $src, ['jquery'], $version, false);

    // Filterable message set (A2 / A10)
    $validator_down = apply_filters('nsaddr_message_validator_down', NSADDR_MSG_VALIDATOR_DOWN);
    $blocked_msg    = apply_filters('nsaddr_message_blocked',       NSADDR_MSG_BLOCKED);
    $out_zone       = apply_filters('nsaddr_message_out_zone',      NSADDR_MSG_OUT_ZONE);

    wp_localize_script($handle, 'nsAddrAjax', [
        'ajax_url'          => admin_url('admin-ajax.php'),
        'nonce'             => wp_create_nonce('ns_addr_ajax'),
        'enable_soft'       => (bool) NS_ADDR_SOFT_ENABLED,
        'soft_position'     => 'top',
        'soft_exclude_home' => true,
        'soft_exclude_cart' => true,

        'soft_copy' => [
            'title'       => __('Delivery availability', 'nsaddr'),
            'placeholder' => __('Enter ZIP code', 'nsaddr'),
            'check_btn'   => __('Check', 'nsaddr'),
            'dismiss'     => __('Not now', 'nsaddr'),
            'in_zone'     => __('Good news — we deliver to {ZIP}.', 'nsaddr'),
            'out_zone'    => $out_zone,
        ],
        'hard_copy' => [
            'modal_title'     => __('Confirm delivery address', 'nsaddr'),
            'submit_btn'      => __('Confirm address', 'nsaddr'),
            'cancel_btn'      => __('Cancel', 'nsaddr'),
            'invalid_generic' => __('Please enter a valid US delivery address.', 'nsaddr'),
            'po_box'          => __("We can't deliver to PO Boxes - please use a street address.", 'nsaddr'),
            'validator_down'  => $validator_down,
            'out_zone'        => $out_zone,
            'blocked'         => $blocked_msg,
        ],
    ]);

    wp_enqueue_script($handle);
}, 1);