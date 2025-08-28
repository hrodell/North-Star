<?php
/**
 * NorthStar Address Gate — Blocklist Module
 *
 * PURPOSE
 *  - Define specific street addresses that should be blocked even if their ZIP
 *    is normally serviceable.
 *  - Provide helpers & filters for consistent, extensible matching.
 *
 * HOW TO ADD / REMOVE BLOCKED ADDRESSES
 *  1. Go to the BLOCKLIST DATA ENTRY SECTION below.
 *  2. Each ACTIVE blocked address is an array element:
 *       ['line1' => 'HOUSE NUMBER + STREET', 'zip5' => '12345'],
 *  3. To DEACTIVATE an address, comment out the line with // at the start.
 *  4. DO NOT put // in front of an address you want to stay blocked.
 *  5. Use uppercase for readability (code normalizes anyway).
 *
 * MATCHING RULES
 *  - line1 compared EXACTLY after normalization (trim, collapse spaces, uppercase).
 *  - zip5 must match exactly (first 5 digits) unless you set zip5 => '' (any ZIP).
 *  - City / State are NOT considered in blocking logic.
 *
 * EXAMPLES
 *   Block exactly one address in one ZIP:
 *     ['line1' => '777 TEST AVE', 'zip5' => '20190'],
 *   Block same address in ANY ZIP (rare):
 *     ['line1' => '777 TEST AVE', 'zip5' => ''],
 *
 * FILTERS
 *  - nsaddr_blocklist_entries : modify raw entries.
 *  - nsaddr_blocklist_match   : override individual match decisions.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* =============================================================================
 * BLOCKLIST DATA ENTRY SECTION
 * =============================================================================
 * ACTIVE blocked addresses go inside the array below.
 * IMPORTANT: A line starting with // is a COMMENT (inactive).
 * Keep a trailing comma after each active entry (except you may omit on last).
 *
 * CURRENT ACTIVE ENTRIES:
 *  - 777 TEST AVE (20190)  (test entry)
 *
 * PREVIOUS entries (example) you can re-activate by removing //:
 *  // ['line1' => '1965 LOGAN MANOR DR', 'zip5' => '20190'],
 */
$GLOBALS['NSADDR_BLOCKLIST'] = [
    // --- BEGIN ENTRIES ---

    ['line1' => '777 TEST AVE', 'zip5' => '20190'],  // ACTIVE test block

    // Historical / example (inactive):
    // ['line1' => '1965 LOGAN MANOR DR', 'zip5' => '20190'],

    // Add new blocked addresses BELOW this line:
    // ['line1' => '123 EXAMPLE ST', 'zip5' => '75001'],
    // ['line1' => '500 SAMPLE ROAD', 'zip5' => '12345'],
    // ['line1' => '42 ANY ZIP LANE', 'zip5' => ''],  // blocks in ALL ZIPs (use sparingly)

    // --- END ENTRIES ---
];

/* -----------------------------------------------------------------------------
 * NORMALIZATION HELPERS
 * --------------------------------------------------------------------------- */
if (!function_exists('nsaddr_normalize_line1')) {
    function nsaddr_normalize_line1(string $line): string {
        $line = trim($line);
        $line = preg_replace('/\s+/', ' ', $line);
        return strtoupper($line);
    }
}

if (!function_exists('nsaddr_normalize_zip')) {
    function nsaddr_normalize_zip(string $zip): string {
        if (preg_match('/^\s*([0-9]{5})/', $zip, $m)) {
            return $m[1];
        }
        return '';
    }
}

/* -----------------------------------------------------------------------------
 * RAW + NORMALIZED ACCESSORS
 * --------------------------------------------------------------------------- */
function nsaddr_raw_blocklist(): array {
    $raw = $GLOBALS['NSADDR_BLOCKLIST'] ?? [];
    $raw = apply_filters('nsaddr_blocklist_entries', $raw);
    $sanitized = [];
    foreach ($raw as $entry) {
        if (!is_array($entry) || !isset($entry['line1'])) {
            continue;
        }
        $sanitized[] = [
            'line1' => (string) $entry['line1'],
            'zip5'  => isset($entry['zip5']) ? (string) $entry['zip5'] : '',
        ];
    }
    return $sanitized;
}

function nsaddr_blocklist(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $normalized = [];
    foreach (nsaddr_raw_blocklist() as $entry) {
        $normalized[] = [
            'line1' => nsaddr_normalize_line1($entry['line1']),
            'zip5'  => nsaddr_normalize_zip($entry['zip5']),
        ];
    }
    $cache = $normalized;
    return $cache;
}

/* -----------------------------------------------------------------------------
 * MATCHING
 * --------------------------------------------------------------------------- */
function nsaddr_is_blocked_address(string $line1, string $zip5): bool {
    if ($line1 === '') {
        return false;
    }
    $candidate = [
        'line1' => nsaddr_normalize_line1($line1),
        'zip5'  => nsaddr_normalize_zip($zip5),
    ];

    foreach (nsaddr_blocklist() as $entry) {
        $match = false;
        if ($candidate['line1'] === $entry['line1']) {
            if ($entry['zip5'] === '' || $candidate['zip5'] === $entry['zip5']) {
                $match = true;
            }
        }
        $match = apply_filters('nsaddr_blocklist_match', $match, $entry, $candidate);
        if ($match) {
            return true;
        }
    }
    return false;
}

if (!function_exists('nsaddr_is_blocked_line1')) {
    function nsaddr_is_blocked_line1(string $line1): bool {
        return nsaddr_is_blocked_address($line1, '');
    }
}

/* -----------------------------------------------------------------------------
 * DIAGNOSTIC
 * --------------------------------------------------------------------------- */
add_action('nsaddr_debug_blocklist', function () {
    if (!function_exists('nsaddr_log')) {
        return;
    }
    nsaddr_log('Blocklist snapshot', [
        'raw'        => nsaddr_raw_blocklist(),
        'normalized' => nsaddr_blocklist(),
    ]);
});