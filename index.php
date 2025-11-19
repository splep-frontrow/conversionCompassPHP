<?php
declare(strict_types=1);

session_start();

// Allow embedding in iframe (required for Shopify embedded apps)
header('X-Frame-Options: ALLOWALL');
header('Content-Security-Policy: frame-ancestors https://*.myshopify.com https://admin.shopify.com');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/hmac.php';
require_once __DIR__ . '/helpers/ShopifyClient.php';
require_once __DIR__ . '/helpers/SubscriptionHelper.php';

$shop = isset($_GET['shop']) ? sanitize_shop_domain($_GET['shop']) : null;

if (!$shop) {
    http_response_code(400);
    echo "Missing or invalid 'shop' parameter.";
    exit;
}

$db = get_db();

// Look up shop in DB
$stmt = $db->prepare('SELECT access_token FROM shops WHERE shop_domain = :shop LIMIT 1');
$stmt->execute(['shop' => $shop]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    // Not installed yet, send to install flow
    $installUrl = '/install.php?shop=' . urlencode($shop);
    header('Location: ' . $installUrl);
    exit;
}

$accessToken = $row['access_token'];

// Update daily usage tracking
SubscriptionHelper::updateUsage($shop);

// Fetch shop info from Shopify
$response = ShopifyClient::apiRequest($shop, $accessToken, '/admin/api/2024-01/shop.json', 'GET');

if ($response['status'] !== 200) {
    http_response_code(500);
    echo "Failed to load shop info from Shopify.";
    exit;
}

$shopInfo  = $response['body']['shop'] ?? [];
$shopName  = $shopInfo['name'] ?? $shop;
$shopEmail = $shopInfo['email'] ?? '';

// Get plan status
$planStatus = SubscriptionHelper::getPlanStatus($shop);

require __DIR__ . '/views/embedded.php';
