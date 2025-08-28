<?php
/**
 * Astra Mini-Cart Fragment Support
 * -----------------------------------------------------------------------------
 * Developer Summary
 * Purpose
 * - Ensure WooCommerce cart fragments load and that Astraâ€™s mini-cart and header
 *   count are updated after add-to-cart, including late-mounted drawers.
 *
 * Responsibilities
 * - Enqueue wc-cart-fragments.
 * - Refresh fragments on drawer open and after add-to-cart.
 * - Provide Woo/Astra fragment selectors for robust header updates.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('wp_enqueue_scripts', function () {
    if ( is_admin() ) return;

    if ( class_exists('WooCommerce') && function_exists('wp_script_is') ) {
        if ( wp_script_is('wc-cart-fragments', 'registered') ) {
            wp_enqueue_script('wc-cart-fragments');

            // Inline JS to refresh/populate fragmented mini-carts across contexts.
            $inline = <<<'JS'
            (function($){
                function refreshFragments(){ $(document.body).trigger('wc_fragment_refresh'); }
                function nodeHasItems($node){ if(!$node||!$node.length) return false; return $node.find('.woocommerce-mini-cart, .mini_cart_item').length > 0; }
                function populateAllMiniCartsFromAny(){
                    var $all = $('div.widget_shopping_cart_content'); if(!$all.length) return;
                    var $source = null;
                    $all.each(function(){ var $n=$(this); if (nodeHasItems($n)) { $source=$n; return false; }});
                    if(!$source){
                        setTimeout(function(){
                            var $fallback = $('div.widget_shopping_cart_content').filter(function(){
                                return $(this).text().trim().length>0 || $(this).children().length>0;
                            }).first();
                            if($fallback.length){
                                $('div.widget_shopping_cart_content').each(function(){
                                    if($(this)[0] !== $fallback[0] && $(this).html().trim() === ''){
                                        $(this).html($fallback.html());
                                    }
                                });
                            }
                        }, 80);
                        return;
                    }
                    $all.each(function(){
                        var $t=$(this);
                        if ($t[0] !== $source[0] && $t.html().trim()==='') {
                            $t.html($source.html());
                        }
                    });
                }

                $(document.body).on('wc_fragments_loaded wc_fragments_refreshed added_to_cart', function(){
                    setTimeout(populateAllMiniCartsFromAny, 30);
                });

                $(document).on('click', '.ast-header-cart-link, .ast-site-header-cart, .ast-cart-menu-wrap, .ast-header-cart-container', function(){
                    setTimeout(function(){
                        refreshFragments();
                        setTimeout(populateAllMiniCartsFromAny, 80);
                    }, 20);
                });

                function observeDrawer(){
                    var drawer=document.getElementById('astra-mobile-cart-drawer');
                    if(!drawer||!window.MutationObserver) return;
                    var obs=new MutationObserver(function(muts){
                        for (var i=0;i<muts.length;i++){
                            if (muts[i].attributeName==='class' && drawer.classList.contains('active')){
                                setTimeout(function(){
                                    refreshFragments();
                                    setTimeout(populateAllMiniCartsFromAny, 80);
                                },20);
                            }
                        }
                    });
                    obs.observe(drawer,{ attributes:true, attributeFilter:['class'] });
                }
                $(observeDrawer);
            })(jQuery);
            JS;

            wp_add_inline_script('wc-cart-fragments', $inline);
        }
    }
}, 50);

add_filter('woocommerce_add_to_cart_fragments', function($fragments) {
    // Standard Woo mini cart contents wrapped for selectors below
    ob_start();
    woocommerce_mini_cart();
    $mini_cart_html = ob_get_clean();
    $wrapped = '<div class="widget_shopping_cart_content">'.$mini_cart_html.'</div>';

    // Replace common mini-cart containers
    $fragments['div.widget_shopping_cart_content'] = $wrapped;
    $fragments['div#astra-mobile-cart-drawer div.widget_shopping_cart_content'] = $wrapped;
    $fragments['div.astra-cart-drawer-content div.widget_shopping_cart_content'] = $wrapped;
    $fragments['div.ast-site-header-cart div.widget_shopping_cart_content'] = $wrapped;
    $fragments['div.ast-header-cart-container div.widget_shopping_cart_content'] = $wrapped;

    // Update header count badges
    $count = ( WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
    $count_html = '<span class="ast-site-header-cart-count">' . esc_html( $count ) . '</span>';
    $fragments['span.ast-site-header-cart-count'] = $count_html;
    $fragments['a.ast-header-cart-link .count'] = '<span class="count">' . esc_html( $count ) . '</span>';
    $fragments['a.cart-contents .count']        = '<span class="count">' . esc_html( $count ) . '</span>';
    $fragments['.ast-site-header-cart .count']  = '<span class="count">' . esc_html( $count ) . '</span>';
    $fragments['.ast-cart-menu-wrap .count']    = '<span class="count">' . esc_html( $count ) . '</span>';

    return $fragments;
});