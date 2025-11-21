<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers/session.php';
init_shopify_session();

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

// Check for temporary token in session first (from callback.php redirect)
// This avoids race condition where DB hasn't committed yet
$accessToken = null;
$tempTokenKey = 'shopify_temp_token_' . $shop;
$tempTokenTimeKey = 'shopify_temp_token_time_' . $shop;

if (isset($_SESSION[$tempTokenKey]) && isset($_SESSION[$tempTokenTimeKey])) {
    // Token is valid for 30 seconds
    if (time() - $_SESSION[$tempTokenTimeKey] < 30) {
        $accessToken = trim($_SESSION[$tempTokenKey]);
        // Clear the temporary token after use
        unset($_SESSION[$tempTokenKey]);
        unset($_SESSION[$tempTokenTimeKey]);
        error_log("Using temporary token from session for shop: {$shop}");
    } else {
        // Token expired, clear it
        unset($_SESSION[$tempTokenKey]);
        unset($_SESSION[$tempTokenTimeKey]);
    }
}

// If no temp token, look up shop in DB
if (empty($accessToken)) {
    $stmt = $db->prepare('SELECT access_token FROM shops WHERE shop_domain = :shop LIMIT 1');
    $stmt->execute(['shop' => $shop]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        // Not installed yet, send to install flow
        $installUrl = '/install.php?shop=' . urlencode($shop);
        header('Location: ' . $installUrl);
        exit;
    }
    
    $accessToken = trim($row['access_token'] ?? '');
}

// Verify access token exists
if (empty($accessToken)) {
    error_log("ERROR: Empty access token retrieved for shop: {$shop}");
    http_response_code(500);
    echo "Error: Access token not found in database. Please reinstall the app.";
    echo "<br><br><a href='/install.php?shop=" . urlencode($shop) . "'>Reinstall the app</a>";
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
    error_log("Token info: length=" . strlen($accessToken) . ", first_chars=" . substr($accessToken, 0, 5) . "...");
    error_log("API Key (first 10 chars): " . substr(SHOPIFY_API_KEY, 0, 10) . "...");
    
    // If it's a 401, the token is likely invalid - delete the shop record to force reinstall
    if ($response['status'] === 401) {
        error_log("401 error detected - deleting shop record to force reinstall for shop: {$shop}");
        error_log("NOTE: 401 errors often indicate API credential mismatch. Verify SHOPIFY_API_KEY and SHOPIFY_API_SECRET in config.local.php match your Shopify Partners dashboard.");
        try {
            $deleteStmt = $db->prepare('DELETE FROM shops WHERE shop_domain = :shop');
            $deleteStmt->execute(['shop' => $shop]);
            error_log("Successfully deleted shop record for: {$shop}");
        } catch (Exception $e) {
            error_log("Failed to delete invalid shop record: " . $e->getMessage());
        }
    }
    
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
