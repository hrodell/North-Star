<?php
/*
Plugin Name: NorthStar Tree Dynamic Setup and Removal Services (Plugin)
Description: Adds setup and removal services with dynamic pricing, delivery fee, and professional service card layout to WooCommerce Christmas tree product pages. Designed for Astra theme compatibility.
Version: 2.6.4
Author: North Star
*/

/**
Developer Summary
Purpose
- Display dynamic â€œSetupâ€ and â€œRemovalâ€ service cards on eligible tree product pages,
  using WooCommerce product prices and a height-based mapping.
- Validate service selections server-side and inject selected services into the cart.
- Keep Astraâ€™s header mini-cart and count in sync after add-to-cart via fragments.

Responsibilities
- Bootstrap security, fragments support, front-end JS, server-side AJAX handlers,
  and add-to-cart validation.
- Provide a reminder modal if user attempts to add a tree without services.

Security
- Centralized nonce verification helpers (nsds-security.php).
- Report-only or enforce modes configurable in admin (Settings > NSDS Security).

Structure
- fragments-support.php: Woo fragments enqueue + Astra-aware fragments.
- enqueue-scripts.php: Front-end JS for service cards + reminder popup.
- display-block.php: Injects service container and nonce into product form.
- add-to-cart-hook.php: Validates and adds service products along with the tree.
- service-ajax-handler.php: Renders service cards snippet via AJAX.
- service-reminder-popup-handler.php: Renders reminder modal snippet via AJAX.
- service-product-mapping.php: Height ? {setup/removal IDs, images, delivery_fee}.
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Security bootstrap + optional admin UI for mode selection
require_once __DIR__ . '/nsds-security.php';
if ( is_admin() ) {
    require_once __DIR__ . '/nsds-security-admin.php';
}

// Astra mini-cart/header count support
require_once __DIR__ . '/fragments-support.php';

// Front-end + server modules
require_once __DIR__ . '/enqueue-scripts.php';
require_once __DIR__ . '/service-product-mapping.php';
require_once __DIR__ . '/service-ajax-handler.php';
require_once __DIR__ . '/display-block.php';
require_once __DIR__ . '/add-to-cart-hook.php';
require_once __DIR__ . '/service-reminder-popup-handler.php';