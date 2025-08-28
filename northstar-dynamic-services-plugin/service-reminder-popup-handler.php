<?php
/**
 * NSDS — AJAX: Reminder Popup
 * A5: wc_price() formatting
 * A7: Translation wrappers
 */
if ( ! defined('ABSPATH') ) exit;

require_once __DIR__ . '/service-product-mapping.php';

add_action('wp_ajax_nsds_get_service_reminder_popup', 'nsds_get_service_reminder_popup');
add_action('wp_ajax_nopriv_nsds_get_service_reminder_popup', 'nsds_get_service_reminder_popup');

function nsds_get_service_reminder_popup() {
    if ( ! nsds_verify_nonce_or_maybe_allow('nsds_ajax', 'nonce') ) {
        status_header(403);
        wp_die( esc_html__('Security check failed','nsds') );
    }

    $height = isset($_POST['height']) ? sanitize_text_field(wp_unslash($_POST['height'])) : '';
    $missing_services = isset($_POST['missing_services']) ? (array) $_POST['missing_services'] : [];
    $missing_services = array_values(array_intersect(['setup','removal'], array_map('sanitize_text_field', $missing_services)));

    $map = nsds_get_service_product_map();
    if (!isset($map[$height])) {
        echo '<div>' . esc_html__('Could not load service options. Please try again.', 'nsds') . '</div>';
        wp_die();
    }

    $response = '
    <style>
    .northstar-service-reminder-popup{display:flex;flex-direction:column;gap:24px;padding-bottom:10px;}
    .northstar-service-popup-card{display:flex;gap:20px;align-items:flex-start;background:linear-gradient(95deg,#f8fafc 0%,#eef4f7 100%);border-radius:18px;box-shadow:0 2px 14px rgba(44,62,80,0.09);padding:22px 26px;margin-bottom:10px;border:1px solid transparent;transition:box-shadow .22s, transform .22s, border-color .22s, background .22s;cursor:pointer;}
    .northstar-service-popup-card:hover{box-shadow:0 6px 32px rgba(44,62,80,0.11),0 3px 12px rgba(44,62,80,0.09);transform:translateY(-2px) scale(1.012);border-color:#b2d6ee;background:linear-gradient(95deg,#f1f6f9 0%,#e9f2fb 100%);}
    .northstar-service-popup-img{width:82px;height:82px;border-radius:12px;object-fit:cover;box-shadow:0 1.5px 8px rgba(44,62,80,0.08);background:#e9f8f0;flex-shrink:0;transition:box-shadow .22s;}
    .northstar-service-popup-content{display:flex;flex-direction:column;flex:1;}
    .northstar-service-popup-row{display:flex;align-items:center;gap:16px;margin-bottom:4px;}
    .northstar-reminder-checkbox{width:28px;height:28px;accent-color:#218c5d;border-radius:6px;margin-right:8px;background:#fff;border:2px solid #b2d6ee;box-shadow:0 1px 3px rgba(44,62,80,0.07);}
    .northstar-service-popup-title{font-size:1.14em;font-weight:800;color:#24516a;margin-right:6px;letter-spacing:-.5px;}
    .northstar-service-popup-price{font-size:1.05em;font-weight:700;color:#fff;background:#218c5d;border-radius:12px;padding:5px 16px;margin-left:6px;letter-spacing:.5px;box-shadow:0 1.5px 8px rgba(44,62,80,0.08);display:inline-block;}
    .northstar-service-popup-desc{font-size:1.01em;color:#3a4a55;margin-top:2px;line-height:1.55;}
    .northstar-add-services-btn{margin-top:22px;width:100%;background:#218c5d;border:none;border-radius:12px;padding:15px 0;font-weight:800;font-size:1.18em;color:#fff;cursor:pointer;box-shadow:0 2px 8px rgba(44,62,80,0.10);letter-spacing:1px;margin-bottom:10px;}
    .northstar-skip-service-btn{width:100%;background:#f4f4f4;border:none;border-radius:12px;padding:15px 0;font-weight:800;font-size:1.18em;color:#218c5d;cursor:pointer;box-shadow:0 1px 4px rgba(44,62,80,0.08);letter-spacing:1px;}
    </style>';

    if (in_array('setup', $missing_services, true)) {
        $setup_id = $map[$height]['setup']['id'];
        $setup_img = $map[$height]['setup']['image'];
        $setup_product = wc_get_product($setup_id);
        $setup_price_html = $setup_product ? wc_price($setup_product->get_price()) : '';
        $setup_name  = $setup_product ? $setup_product->get_title() : __('Setup Service', 'nsds');
        $response .= "
        <div class='northstar-service-popup-card'>
            <img src='".esc_url($setup_img)."' alt='".esc_attr__('Setup Service','nsds')."' class='northstar-service-popup-img'>
            <div class='northstar-service-popup-content'>
                <div class='northstar-service-popup-row'>
                    <input type='checkbox' class='northstar-reminder-checkbox' name='choose_setup' value='1' data-product-id='".esc_attr($setup_id)."'>
                    <span class='northstar-service-popup-title'>".esc_html($setup_name)."</span>
                    <span class='northstar-service-popup-price'>".wp_kses_post($setup_price_html)."</span>
                </div>
                <div class='northstar-service-popup-desc'>".esc_html__("Are you sure you want to tackle the setup yourself? Our crew can do the heavy lifting!",'nsds')."</div>
            </div>
        </div>";
    }

    if (in_array('removal', $missing_services, true)) {
        $removal_id = $map[$height]['removal']['id'];
        $removal_img = $map[$height]['removal']['image'];
        $removal_product = wc_get_product($removal_id);
        $removal_price_html = $removal_product ? wc_price($removal_product->get_price()) : '';
        $removal_name  = $removal_product ? $removal_product->get_title() : __('Removal Service', 'nsds');
        $response .= "
        <div class='northstar-service-popup-card'>
            <img src='".esc_url($removal_img)."' alt='".esc_attr__('Removal Service','nsds')."' class='northstar-service-popup-img'>
            <div class='northstar-service-popup-content'>
                <div class='northstar-service-popup-row'>
                    <input type='checkbox' class='northstar-reminder-checkbox' name='choose_removal' value='1' data-product-id='".esc_attr($removal_id)."'>
                    <span class='northstar-service-popup-title'>".esc_html($removal_name)."</span>
                    <span class='northstar-service-popup-price'>".wp_kses_post($removal_price_html)."</span>
                </div>
                <div class='northstar-service-popup-desc'>".esc_html__("The hardest part of Christmas is the cleanup. You've done enough! Take a break, we got you!",'nsds')."</div>
            </div>
        </div>";
    }

    $response .= "<button class='northstar-add-services-btn'>".esc_html__('ADD SELECTED SERVICE(S)','nsds')."</button>";
    $response .= "<button class='northstar-skip-service-btn'>".esc_html__('NO THANKS','nsds')."</button>";

    echo nsds_kses_popup_html($response);
    wp_die();
}