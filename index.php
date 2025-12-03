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
require_once __DIR__ . '/helpers/SessionTokenHelper.php';

$shop = isset($_GET['shop']) ? sanitize_shop_domain($_GET['shop']) : null;

if (!$shop) {
    http_response_code(400);
    echo "Missing or invalid 'shop' parameter.";
    exit;
}

// Validate session token if present (for embedded app compliance)
$sessionTokenValid = false;
$sessionTokenPayload = SessionTokenHelper::validateRequest($shop);
if ($sessionTokenPayload !== null) {
    $sessionTokenValid = true;
    error_log("index.php: Session token validated successfully for shop: {$shop}");
} else {
    error_log("index.php: Session token validation failed or missing for shop: {$shop}, falling back to access token method");
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
        $sessionTokenLength = strlen($accessToken);
        
        error_log("Using temporary token from session for shop: {$shop}, token_age: {$tokenAge}s, fresh_install: " . ($isFreshInstall ? 'yes' : 'no') . ", token_length: {$sessionTokenLength}");
        error_log("Session token preview (first 10, last 10): " . substr($accessToken, 0, 10) . "..." . substr($accessToken, -10));
        
        // Validate session token length (minimum 38 chars)
        if ($sessionTokenLength < 38) {
            error_log("CRITICAL: Session token too short for shop: {$shop}. Length: {$sessionTokenLength}");
            // Clear invalid token from session
            unset($_SESSION[$tempTokenKey]);
            unset($_SESSION[$tempTokenTimeKey]);
            unset($_SESSION[$installCompleteKey]);
            $accessToken = null; // Force database lookup
        }
        
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
$isFreshInstall = false;
$installTimestamp = null;

if (empty($accessToken)) {
    // Fetch access_token and install timestamps to detect fresh installs
    $stmt = $db->prepare('SELECT access_token, installed_at, last_reinstalled_at FROM shops WHERE shop_domain = :shop LIMIT 1');
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
    $tokenLength = strlen($accessToken);
    
    // Enhanced logging for token retrieval
    error_log("Using token from database for shop: {$shop}, token_source: {$tokenSource}, token_length: {$tokenLength}");
    error_log("DB token preview (first 10, last 10): " . substr($accessToken, 0, 10) . "..." . substr($accessToken, -10));
    
    // Validate token length - minimum 38 chars for Shopify tokens (shpat_ + 32 chars = 38)
    if ($tokenLength < 38) {
        error_log("CRITICAL: Token retrieved from database is too short for shop: {$shop}. Length: {$tokenLength}, expected at least 38 characters.");
        error_log("Token appears to be truncated. Deleting shop record to force reinstall.");
        
        // Delete the corrupted record
        try {
            $deleteStmt = $db->prepare('DELETE FROM shops WHERE shop_domain = :shop');
            $deleteStmt->execute(['shop' => $shop]);
            error_log("Deleted corrupted shop record for: {$shop}");
        } catch (Exception $e) {
            error_log("Failed to delete corrupted shop record: " . $e->getMessage());
        }
        
        http_response_code(500);
        echo "Error: Access token appears to be corrupted or truncated (length: {$tokenLength}).";
        echo "<br><small>The shop record has been removed. Please reinstall the app.</small>";
        echo "<br><br><a href='/install.php?shop=" . urlencode($shop) . "'>Reinstall the app</a>";
        exit;
    }
    
    // Check if this is a fresh install (within last 30 seconds)
    $installTimestamp = $row['last_reinstalled_at'] ?? $row['installed_at'] ?? null;
    if ($installTimestamp) {
        $installTime = strtotime($installTimestamp);
        $secondsSinceInstall = time() - $installTime;
        $isFreshInstall = $secondsSinceInstall < 30; // Installed within last 30 seconds
        
        if ($isFreshInstall) {
            $tokenSource = 'fresh_install';
            error_log("Fresh install detected for shop: {$shop}, installed {$secondsSinceInstall}s ago");
        }
    }
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
    error_log("WARNING: Token format unexpected for shop: {$shop}, starts with: " . substr($accessToken, 0, 10));
    error_log("Token length: " . strlen($accessToken) . ", preview (first 10, last 10): " . substr($accessToken, 0, 10) . "..." . substr($accessToken, -10));
}

// Final token length validation before API call (minimum 38 chars)
$finalTokenLength = strlen($accessToken);
if ($finalTokenLength < 38) {
    error_log("CRITICAL: Token length validation failed before API call for shop: {$shop}. Length: {$finalTokenLength}");
    http_response_code(500);
    echo "Error: Access token validation failed. Please reinstall the app.";
    echo "<br><br><a href='/install.php?shop=" . urlencode($shop) . "'>Reinstall the app</a>";
    exit;
}

// Log token info for debugging (don't log the actual token!)
error_log("Loading shop info for {$shop}, token_source: {$tokenSource}, token_length: {$finalTokenLength}");
error_log("Token preview (first 10, last 10): " . substr($accessToken, 0, 10) . "..." . substr($accessToken, -10));

// Update daily usage tracking
SubscriptionHelper::updateUsage($shop);

// Fetch shop info from Shopify
// Always enable retry when token comes from database - timestamp detection is unreliable
// If token is fresh and activating, retries will help. If token is old/invalid, retries won't hurt.
// Session tokens are always fresh, so always retry those too.
$shouldRetry = ($tokenSource === 'session' || $tokenSource === 'database' || $tokenSource === 'fresh_install' || $isFreshInstall);

if ($shouldRetry) {
    $reason = match($tokenSource) {
        'session' => 'session token (always fresh)',
        'database' => 'database token (may be fresh, enabling retry as fallback)',
        'fresh_install' => 'fresh install detected',
        default => 'fresh install flag set'
    };
    error_log("Enabling retry logic for shop: {$shop}, reason: {$reason}, token_source: {$tokenSource}");
} else {
    error_log("Retry logic DISABLED for shop: {$shop}, token_source: {$tokenSource}");
}

$response = ShopifyClient::apiRequest($shop, $accessToken, '/admin/api/2024-10/shop.json', 'GET', null, $shouldRetry);

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
    
    // If it's a 401, check if retries were attempted - if so, check if install is old
    // The token might still be activating (fresh install) or it might be invalid (old install)
    if ($response['status'] === 401) {
        $secondsAgo = $installTimestamp ? (time() - strtotime($installTimestamp)) : null;
        $isOldInstall = $secondsAgo !== null && $secondsAgo > 300; // More than 5 minutes old
        
        if ($shouldRetry) {
            error_log("401 error after retry attempts for shop: {$shop} (token_source: {$tokenSource}, installed " . ($secondsAgo ?? 'unknown') . "s ago)");
            
            // If install is old (>5 minutes), the token is likely invalid from a previous installation
            // Delete the record to force a fresh reinstall
            if ($isOldInstall) {
                error_log("Install timestamp is old ({$secondsAgo}s ago) - deleting shop record to force reinstall.");
                error_log("NOTE: This likely indicates an invalid token from a previous installation that wasn't properly cleaned up.");
                $deleted = false;
                try {
                    $deleteStmt = $db->prepare('DELETE FROM shops WHERE shop_domain = :shop');
                    $deleteStmt->execute(['shop' => $shop]);
                    error_log("Successfully deleted old shop record for: {$shop}");
                    $deleted = true;
                } catch (Exception $e) {
                    error_log("Failed to delete invalid shop record: " . $e->getMessage());
                }
                
                // Automatically redirect to install page after deleting old record
                error_log("Redirecting to install page for shop: {$shop} after deleting old record");
                $installUrl = '/install.php?shop=' . urlencode($shop);
                header('Location: ' . $installUrl);
                exit;
            } else {
                // Fresh install (<5 minutes) - token might still be activating
                error_log("Install is fresh ({$secondsAgo}s ago) - token may still be activating. NOT deleting record.");
            }
        } else {
            // Retries weren't enabled, so this is likely an old/invalid token
            error_log("401 error detected - retries were NOT enabled for shop: {$shop} (token_source: {$tokenSource})");
            error_log("Deleting shop record to force reinstall.");
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
    
    // For cases where retries were attempted and install is fresh, show a helpful message
    // If install is old, we already showed reinstall message above
    if ($shouldRetry && $response['status'] === 401) {
        $secondsAgo = $installTimestamp ? (time() - strtotime($installTimestamp)) : null;
        $isOldInstall = $secondsAgo !== null && $secondsAgo > 300;
        
        if (!$isOldInstall) {
            // Fresh install - token might still be activating
            echo "The app is still initializing. Please wait a moment and refresh the page.";
            echo "<br><br><a href='?shop=" . urlencode($shop) . "'>Refresh Page</a>";
            echo "<br><small>If this persists, try <a href='/install.php?shop=" . urlencode($shop) . "'>reinstalling the app</a></small>";
        }
        // If isOldInstall, we already showed reinstall message above
    } else {
        echo "Failed to load shop info from Shopify.";
        echo "<br><small>HTTP Status: {$response['status']}</small>";
        if ($errorDetails) {
            echo "<br><small>" . htmlspecialchars($errorDetails) . "</small>";
        }
        echo "<br><br><a href='/install.php?shop=" . urlencode($shop) . "'>Try reinstalling the app</a>";
    }
    exit;
}

// Redirect to conversion data page (new home screen)
header('Location: /conversion.php?shop=' . urlencode($shop));
exit;
