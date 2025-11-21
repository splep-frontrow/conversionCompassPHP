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
$installCompleteKey = 'shopify_install_complete_' . $shop;
$tokenSource = 'unknown';
$sessionId = session_id();

error_log("index.php: Checking for token for shop: {$shop}, session_id: {$sessionId}");
error_log("index.php: Session keys present: " . (isset($_SESSION[$tempTokenKey]) ? 'yes' : 'no') . ", " . (isset($_SESSION[$tempTokenTimeKey]) ? 'yes' : 'no'));
error_log("index.php: All session keys: " . implode(', ', array_keys($_SESSION ?? [])));

if (isset($_SESSION[$tempTokenKey]) && isset($_SESSION[$tempTokenTimeKey])) {
    $tokenAge = time() - $_SESSION[$tempTokenTimeKey];
    // Token is valid for 30 seconds
    if ($tokenAge < 30) {
        $accessToken = trim($_SESSION[$tempTokenKey]);
        $tokenSource = 'session';
        $isFreshInstall = isset($_SESSION[$installCompleteKey]) && $_SESSION[$installCompleteKey] === true;
        
        error_log("Using temporary token from session for shop: {$shop}, token_age: {$tokenAge}s, fresh_install: " . ($isFreshInstall ? 'yes' : 'no'));
        error_log("Session token preview (first 5, last 5): " . substr($accessToken, 0, 5) . "..." . substr($accessToken, -5));
        
        // Clear the temporary token after use
        unset($_SESSION[$tempTokenKey]);
        unset($_SESSION[$tempTokenTimeKey]);
        unset($_SESSION[$installCompleteKey]);
    } else {
        // Token expired, clear it
        error_log("Session token expired for shop: {$shop}, age: {$tokenAge}s");
        unset($_SESSION[$tempTokenKey]);
        unset($_SESSION[$tempTokenTimeKey]);
        unset($_SESSION[$installCompleteKey]);
    }
}

// If no temp token, look up shop in DB
if (empty($accessToken)) {
    $stmt = $db->prepare('SELECT access_token FROM shops WHERE shop_domain = :shop LIMIT 1');
    $stmt->execute(['shop' => $shop]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        // Not installed yet, send to install flow
        error_log("No token found in session or DB for shop: {$shop}, redirecting to install");
        $installUrl = '/install.php?shop=' . urlencode($shop);
        header('Location: ' . $installUrl);
        exit;
    }
    
    $accessToken = trim($row['access_token'] ?? '');
    $tokenSource = 'database';
    error_log("Using token from database for shop: {$shop}");
    error_log("DB token preview (first 5, last 5): " . substr($accessToken, 0, 5) . "..." . substr($accessToken, -5));
}

// Verify access token exists
if (empty($accessToken)) {
    error_log("ERROR: Empty access token retrieved for shop: {$shop}");
    http_response_code(500);
    echo "Error: Access token not found in database. Please reinstall the app.";
    echo "<br><br><a href='/install.php?shop=" . urlencode($shop) . "'>Reinstall the app</a>";
    exit;
}

// Validate token format
if (!str_starts_with($accessToken, 'shpat') && !str_starts_with($accessToken, 'shpca')) {
    error_log("WARNING: Token format unexpected for shop: {$shop}, starts with: " . substr($accessToken, 0, 5));
}

// Log token info for debugging (don't log the actual token!)
error_log("Loading shop info for {$shop}, token_source: {$tokenSource}, token_length: " . strlen($accessToken));
error_log("Token preview (first 5, last 5): " . substr($accessToken, 0, 5) . "..." . substr($accessToken, -5));

// Update daily usage tracking
SubscriptionHelper::updateUsage($shop);

// Fetch shop info from Shopify
// Use retryOn401=true if token came from session (fresh install) to handle activation delays
$response = ShopifyClient::apiRequest($shop, $accessToken, '/admin/api/2024-10/shop.json', 'GET', null, $tokenSource === 'session');

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
    
    // If it's a 401, check if this was a fresh install - if so, don't delete immediately
    // The token might just need more time to activate
    if ($response['status'] === 401) {
        // Check if we just installed (within last 30 seconds)
        $checkRecentStmt = $db->prepare('SELECT installed_at FROM shops WHERE shop_domain = :shop LIMIT 1');
        $checkRecentStmt->execute(['shop' => $shop]);
        $recentRow = $checkRecentStmt->fetch(PDO::FETCH_ASSOC);
        
        $isRecentInstall = false;
        if ($recentRow && isset($recentRow['installed_at'])) {
            $installTime = strtotime($recentRow['installed_at']);
            $isRecentInstall = (time() - $installTime) < 30; // Installed within last 30 seconds
        }
        
        if ($isRecentInstall) {
            error_log("401 error detected for recently installed shop: {$shop} (installed " . (time() - $installTime) . "s ago)");
            error_log("Token may still be activating - NOT deleting record. User should refresh the page.");
        } else {
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
