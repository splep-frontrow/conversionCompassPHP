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

// Verify access token exists
if (empty($accessToken)) {
    http_response_code(500);
    echo "Error: Access token not found in database. Please reinstall the app.";
    exit;
}

// Log token info for debugging (don't log the actual token!)
error_log("Loading shop info for {$shop}, token length: " . strlen($accessToken));

// Update daily usage tracking
SubscriptionHelper::updateUsage($shop);

// Fetch shop info from Shopify
$response = ShopifyClient::apiRequest($shop, $accessToken, '/admin/api/2024-01/shop.json', 'GET');

if ($response['status'] !== 200) {
    http_response_code(500);
    $errorDetails = '';
    
    // Extract error message from response
    if (isset($response['body']['errors'])) {
        $errorDetails = ' Error: ' . json_encode($response['body']['errors']);
    } elseif (isset($response['body']['error'])) {
        $errorDetails = ' Error: ' . $response['body']['error'];
    } elseif (!empty($response['raw'])) {
        $errorDetails = ' Response: ' . substr($response['raw'], 0, 200);
    }
    
    error_log("Shopify API error for shop {$shop}: Status {$response['status']}{$errorDetails}");
    
    echo "Failed to load shop info from Shopify.";
    echo "<br><small>HTTP Status: {$response['status']}</small>";
    if ($errorDetails) {
        echo "<br><small>" . htmlspecialchars($errorDetails) . "</small>";
    }
    echo "<br><br><a href='/install.php?shop=" . urlencode($shop) . "'>Try reinstalling the app</a>";
    exit;
}

$shopInfo  = $response['body']['shop'] ?? [];
$shopName  = $shopInfo['name'] ?? $shop;
$shopEmail = $shopInfo['email'] ?? '';

// Get plan status
$planStatus = SubscriptionHelper::getPlanStatus($shop);

require __DIR__ . '/views/embedded.php';
