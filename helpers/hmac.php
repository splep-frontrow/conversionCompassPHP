<?php

/**
 * Verify Shopify HMAC according to docs:
 * https://shopify.dev/docs/apps/auth/oauth/getting-started#step-3-verify-the-installation
 */
function verify_shopify_hmac(array $query, string $shared_secret): bool
{
    if (!isset($query['hmac'])) {
        return false;
    }

    $hmac = $query['hmac'];
    unset($query['hmac'], $query['signature']);

    ksort($query);

    $pairs = [];
    foreach ($query as $key => $value) {
        $pairs[] = $key . '=' . $value;
    }
    $data = implode('&', $pairs);

    $calculated_hmac = hash_hmac('sha256', $data, $shared_secret);

    return hash_equals($calculated_hmac, $hmac);
}

/**
 * Ensure the shop domain is valid and normalized.
 */
function sanitize_shop_domain(?string $shop): ?string
{
    if (!$shop) {
        return null;
    }

    $shop = trim(strtolower($shop));

    if (!str_ends_with($shop, '.myshopify.com')) {
        $shop .= '.myshopify.com';
    }

    // Basic regex: letters, numbers, hyphens, then .myshopify.com
    if (!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop)) {
        return null;
    }

    return $shop;
}
