<?php
/**
 * NSDS Security Admin UI
 * -----------------------------------------------------------------------------
 * Developer Summary
 * Purpose
 * - Provide a simple settings page under Settings > NSDS Security to choose the
 *   current security mode (Off / Report-only / Enforce).
 */

if ( ! defined('ABSPATH') ) exit;

add_action('admin_init', function () {
    register_setting('nsds_security','nsds_secure_mode',[
        'type'              => 'integer',
        'sanitize_callback' => function($v){ $v=(int)$v; if($v<0)$v=0; if($v>2)$v=2; return $v; },
        'default'           => 2,
        'show_in_rest'      => false,
    ]);

    add_settings_section('nsds_security_section', __('NSDS Security Mode','nsds'), function(){
        echo '<p>'.esc_html__('Control how strictly NSDS verifies nonces for AJAX and form submissions.','nsds').'</p>';
    }, 'nsds_security');

    add_settings_field('nsds_secure_mode_field', __('Mode','nsds'), function(){
        $current = (int) get_option('nsds_secure_mode', 2);
        $modes = [
            0 => __('Off (not recommended)', 'nsds'),
            1 => __('Report-only (logs invalid, allows request)', 'nsds'),
            2 => __('Enforce (recommended)', 'nsds'),
        ];
        echo '<fieldset>';
        foreach ($modes as $val=>$label){
            printf('<label style="display:block;margin:6px 0;"><input type="radio" name="nsds_secure_mode" value="%d" %s> %s</label>',
                $val, checked($current,$val,false), esc_html($label));
        }
        echo '</fieldset>';
    }, 'nsds_security', 'nsds_security_section');
});

add_action('admin_menu', function () {
    add_options_page(
        __('NSDS Security','nsds'),
        __('NSDS Security','nsds'),
        'manage_options',
        'nsds-security',
        function () {
            if (!current_user_can('manage_options')) return;
            echo '<div class="wrap"><h1>'.esc_html__('NSDS Security','nsds').'</h1>';
            echo '<form method="post" action="options.php">';
            settings_fields('nsds_security');
            do_settings_sections('nsds_security');
            submit_button(__('Save Changes','nsds'));
            echo '</form></div>';
        }
    );
});