<?php
/**
 * NSDS — AJAX: Load Service Cards for Selected Height
 * A5: Prices now formatted with wc_price().
 * A7: Added translation wrappers for static strings (domain 'nsds').
 */
if ( ! defined('ABSPATH') ) exit;

require_once __DIR__ . '/service-product-mapping.php';

add_action('wp_ajax_nsds_get_tree_services', 'nsds_get_tree_services');
add_action('wp_ajax_nopriv_nsds_get_tree_services', 'nsds_get_tree_services');

function nsds_get_tree_services() {
    if ( ! nsds_verify_nonce_or_maybe_allow('nsds_ajax', 'nonce') ) {
        status_header(403);
        wp_die( esc_html__('Security check failed', 'nsds') );
    }

    $height = isset($_POST['height']) ? sanitize_text_field(wp_unslash($_POST['height'])) : '';
    $map = nsds_get_service_product_map();

    if (!isset($map[$height])) {
        echo '<div>' . esc_html__('Please select a tree height to see service options.', 'nsds') . '</div>';
        wp_die();
    }

    $setup_id     = (int) $map[$height]['setup']['id'];
    $removal_id   = (int) $map[$height]['removal']['id'];
    $setup_img    = $map[$height]['setup']['image'];
    $removal_img  = $map[$height]['removal']['image'];
    $delivery_fee = $map[$height]['delivery_fee'];

    $setup_product   = wc_get_product($setup_id);
    $removal_product = wc_get_product($removal_id);

    $setup_name      = $setup_product ? $setup_product->get_title() : __('Setup Service', 'nsds');
    $removal_name    = $removal_product ? $removal_product->get_title() : __('Removal Service', 'nsds');

    $setup_price_html   = $setup_product ? wc_price($setup_product->get_price()) : '';
    $removal_price_html = $removal_product ? wc_price($removal_product->get_price()) : '';

    ?>
    <style>
    .northstar-services-wrap { display:flex; flex-direction:column; gap:28px; margin-bottom:24px; }
    .northstar-service-card { display:flex; align-items:flex-start; background:linear-gradient(92deg,#f8fafc 0%,#eef4f7 100%); border-radius:16px; box-shadow:0 4px 24px rgba(44,62,80,0.07),0 1.5px 6px rgba(44,62,80,0.06); padding:20px 24px; transition:box-shadow .2s, transform .2s; border:1px solid #e3ecef; cursor:pointer; }
    .northstar-service-card:hover { box-shadow:0 6px 32px rgba(44,62,80,0.10),0 3px 12px rgba(44,62,80,0.09); transform:translateY(-2px) scale(1.015); border-color:#b2d6ee; }
    .northstar-service-img { flex:0 0 200px; width:200px; height:auto; margin-right:32px; border-radius:12px; box-shadow:0 2px 12px rgba(44,62,80,0.08); background:#f1f6f9; object-fit:contain; display:block; transition:box-shadow .2s; }
    .northstar-service-card:hover .northstar-service-img { box-shadow:0 4px 24px rgba(44,62,80,0.14); }
    .northstar-service-content { flex:1; display:flex; flex-direction:column; justify-content:center; }
    .northstar-service-header { display:flex; align-items:center; margin-bottom:10px; }
    .northstar-service-header input[type=checkbox]{ width:35px; height:35px; min-width:35px; min-height:35px; margin-right:16px; accent-color:#449d44; border-radius:10px; outline:4px solid #d3e6d5; transition:outline .15s; box-shadow:0 2px 7px rgba(44,62,80,0.09); }
    .northstar-service-title { font-size:1.22em; font-weight:600; margin-right:16px; color:#24516a; letter-spacing:-.5px; }
    .northstar-service-price { font-size:1.02em; font-weight:700; color:#449d44; background:#e7f6e9; border-radius:6px; padding:3px 10px; margin-left:6px; box-shadow:0 1px 4px rgba(44,62,80,0.03); }
    .northstar-service-desc { font-size:1.05em; color:#3a4a55; line-height:1.6; margin-bottom:2px; letter-spacing:.02em; }
    #delivery-fee-line { margin-top:18px; font-weight:600; font-size:1.08em; color:#24516a; background:#eaf4fa; border-radius:7px; padding:8px 18px; display:inline-block; box-shadow:0 1.5px 3px rgba(44,62,80,0.04); }
    @media(max-width:900px){ .northstar-service-card{flex-direction:column; align-items:flex-start; padding:16px 10px;} .northstar-service-img{margin-right:0; margin-bottom:14px; width:100%; max-width:240px; height:auto;} }
    </style>
    <div class="northstar-services-wrap" id="northstar-services">
        <label class="northstar-service-card" for="northstar-setup-checkbox">
            <img class="northstar-service-img" src="<?php echo esc_url($setup_img); ?>" alt="<?php echo esc_attr__('Setup Service','nsds'); ?>">
            <div class="northstar-service-content">
                <div class="northstar-service-header">
                    <input type="checkbox" id="northstar-setup-checkbox" name="northstar_setup" value="1" data-product-id="<?php echo esc_attr($setup_id); ?>">
                    <span class="northstar-service-title"><?php echo esc_html($setup_name); ?></span>
                    <span class="northstar-service-price"><?php echo wp_kses_post($setup_price_html); ?></span>
                </div>
                <div class="northstar-service-desc">
                    <?php echo esc_html__('Let our friendly, uniformed crew handle the heavy lifting. We\'ll bring your tree inside, give it a fresh cut, place it in your stand, and adjust it until it\'s standing tall and perfectly straight.', 'nsds'); ?>
                </div>
            </div>
        </label>

        <label class="northstar-service-card" for="northstar-removal-checkbox">
            <img class="northstar-service-img" src="<?php echo esc_url($removal_img); ?>" alt="<?php echo esc_attr__('Removal Service','nsds'); ?>">
            <div class="northstar-service-content">
                <div class="northstar-service-header">
                    <input type="checkbox" id="northstar-removal-checkbox" name="northstar_removal" value="1" data-product-id="<?php echo esc_attr($removal_id); ?>">
                    <span class="northstar-service-title"><?php echo esc_html($removal_name); ?></span>
                    <span class="northstar-service-price"><?php echo wp_kses_post($removal_price_html); ?></span>
                </div>
                <div class="northstar-service-desc">
                    <?php echo esc_html__('Skip the hassle and the mess — our courteous team will remove your tree, vacuum needles, and ensure it’s responsibly recycled.', 'nsds'); ?>
                </div>
            </div>
        </label>

        <div id="delivery-fee-line">
            <?php
            /* Delivery fee is a simple numeric value — format with wc_price if you decide to make it dynamic product pricing later. */
            printf(
                esc_html__('Local Delivery Fee: $%d', 'nsds'),
                (int) $delivery_fee
            );
            ?>
        </div>
    </div>
    <?php
    wp_die();
}