<?php
/**
 * File: service-product-mapping.php
 * Version: 2.6.5
 * Date: 2025-08-27
 *
 * NSDS — Height → Service Product IDs, Images, and Delivery Fee
 *
 * DEVELOPER SUMMARY
 * -----------------------------------------------------------------------------
 * - Central mapping for valid setup/removal service product IDs for each tree height.
 * - Used for validation and UI rendering, prevents tampering.
 * - Shared image URLs are used for all heights, update as needed.
 *
 * SECURITY NOTE
 * -----------------------------------------------------------------------------
 * - Only IDs in this mapping are permitted for child service add-to-cart.
 */

function nsds_get_service_product_map() {
    // Shared images for all heights — REPLACE with your own if desired
    $setup_image   = 'https://northstartrees.wpenginepowered.com/wp-content/uploads/2025/07/setupserviceimagemed.png';
    $removal_image = 'https://northstartrees.wpenginepowered.com/wp-content/uploads/2025/07/removalserviceimagemed.png';

    return [
        '3-4-ft'  => ['setup' => ['id' => 1753, 'image' => $setup_image], 'removal' => ['id' => 1763, 'image' => $removal_image], 'delivery_fee' => 30],
        '4-5-ft'  => ['setup' => ['id' => 1754, 'image' => $setup_image], 'removal' => ['id' => 1764, 'image' => $removal_image], 'delivery_fee' => 40],
        '5-6-ft'  => ['setup' => ['id' => 1755, 'image' => $setup_image], 'removal' => ['id' => 1765, 'image' => $removal_image], 'delivery_fee' => 40],
        '6-7-ft'  => ['setup' => ['id' => 1756, 'image' => $setup_image], 'removal' => ['id' => 1766, 'image' => $removal_image], 'delivery_fee' => 60],
        '7-8-ft'  => ['setup' => ['id' => 1757, 'image' => $setup_image], 'removal' => ['id' => 1768, 'image' => $removal_image], 'delivery_fee' => 60],
        '8-9-ft'  => ['setup' => ['id' => 1758, 'image' => $setup_image], 'removal' => ['id' => 1769, 'image' => $removal_image], 'delivery_fee' => 75],
        '9-10-ft' => ['setup' => ['id' => 1759, 'image' => $setup_image], 'removal' => ['id' => 1770, 'image' => $removal_image], 'delivery_fee' => 75],
        '10-11-ft'=> ['setup' => ['id' => 1760, 'image' => $setup_image], 'removal' => ['id' => 1771, 'image' => $removal_image], 'delivery_fee' => 80],
        '11-12-ft'=> ['setup' => ['id' => 1761, 'image' => $setup_image], 'removal' => ['id' => 1772, 'image' => $removal_image], 'delivery_fee' => 100],
        '12-13-ft'=> ['setup' => ['id' => 1762, 'image' => $setup_image], 'removal' => ['id' => 1773, 'image' => $removal_image], 'delivery_fee' => 120],
    ];
}