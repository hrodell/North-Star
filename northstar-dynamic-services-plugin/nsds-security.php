<?php
/**
 * File: nsds-security.php
 * Version: 2.6.5
 * Date: 2025-08-27
 *
 * NSDS Security Bootstrap
 *
 * DEVELOPER SUMMARY
 * -----------------------------------------------------------------------------
 * - Provides nonce verification, audit logging, and sanitization helpers for all NSDS AJAX and form submissions.
 * - Configurable security mode: Off / Report-only / Enforce (default: Enforce).
 * - Centralizes security for all plugin endpoints.
 *
 * SECURITY NOTES
 * -----------------------------------------------------------------------------
 * - CRITICAL: All failed nonce attempts are logged for audit/monitoring.
 * - Only allow switching mode via admin UI (see nsds-security-admin.php).
 * - No direct user input to DB.
 */

if ( ! defined('ABSPATH') ) exit;

// Default security mode if no option is set (2 = enforce)
if (!defined('NSDS_SECURE_MODE')) define('NSDS_SECURE_MODE', 2);

/** Read the current security mode (0/1/2). */
function nsds_secure_mode(): int {
    $opt = get_option('nsds_secure_mode', null);
    if ($opt === null || $opt === '') $opt = NSDS_SECURE_MODE;
    $mode = (int) apply_filters('nsds_secure_mode', $opt);
    return max(0, min(2, $mode));
}

/** Log a security warning (Woo logger when available, else error_log). */
function nsds_sec_log($message, array $context = []) {
    $context = array_merge([
        'source'  => 'nsds-security',
        'referer' => $_SERVER['HTTP_REFERER']  ?? '',
        'ua'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'ip'      => $_SERVER['REMOTE_ADDR']   ?? '',
        'uri'     => $_SERVER['REQUEST_URI']   ?? '',
    ], $context);

    if (function_exists('wc_get_logger')) {
        wc_get_logger()->warning($message, $context);
    } else {
        error_log('[nsds-security] ' . $message . ' ' . wp_json_encode($context));
    }
}

/**
 * Verify a nonce posted as $field for $action.
 * Modes
 * - 0 (off)          : allow
 * - 1 (report-only)  : log invalid, allow
 * - 2 (enforce)      : block if invalid
 */
function nsds_verify_nonce_or_maybe_allow(string $action, string $field): bool {
    if (nsds_secure_mode() === 0) return true;

    $nonce = isset($_POST[$field]) ? sanitize_text_field( wp_unslash($_POST[$field]) ) : '';
    $ok = $nonce && wp_verify_nonce($nonce, $action);

    if (!$ok) {
        nsds_sec_log('Nonce missing/invalid', ['action' => $action, 'field' => $field]);
        return nsds_secure_mode() === 1;
    }
    return true;
}

/** Sanitize a posted scalar string. */
function nsds_post_text(string $key): string {
    return isset($_POST[$key]) ? sanitize_text_field( wp_unslash($_POST[$key]) ) : '';
}

/** Sanitize a posted integer. */
function nsds_post_int(string $key): int {
    return isset($_POST[$key]) ? absint($_POST[$key]) : 0;
}

/**
 * Sanitize popup HTML while allowing needed attributes.
 * Use for AJAX endpoints that render HTML snippets into modals.
 */
function nsds_kses_popup_html(string $html): string {
    $allowed = wp_kses_allowed_html('post');

    // Allow inline <style> for component-scoped CSS in the snippet
    $allowed['style'] = [];

    // Expand attributes used by our markup
    foreach (['div','label','span','button','input','img'] as $tag) {
        if (!isset($allowed[$tag])) $allowed[$tag] = [];
    }
    $allowed['div']['id'] = true;
    $allowed['div']['class'] = true;
    $allowed['label']['for'] = true;
    $allowed['label']['class'] = true;
    $allowed['img']['class'] = true;
    $allowed['img']['src'] = true;
    $allowed['img']['alt'] = true;
    $allowed['img']['width'] = true;
    $allowed['img']['height'] = true;
    $allowed['img']['loading'] = true;
    $allowed['span']['class'] = true;
    $allowed['button']['class'] = true;
    $allowed['button']['type'] = true;
    $allowed['button']['name'] = true;
    $allowed['button']['value'] = true;
    $allowed['input']['type'] = true;
    $allowed['input']['id'] = true;
    $allowed['input']['name'] = true;
    $allowed['input']['value'] = true;
    $allowed['input']['class'] = true;
    $allowed['input']['checked'] = true;
    $allowed['input']['data-product-id'] = true;

    return wp_kses($html, $allowed);
}