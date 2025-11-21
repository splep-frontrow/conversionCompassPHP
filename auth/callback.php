<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
init_shopify_session();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/hmac.php';
require_once __DIR__ . '/../helpers/ShopifyClient.php';
require_once __DIR__ . '/../helpers/OAuthStateHelper.php';

$query = $_GET;

// 1. Validate required params
if (!isset($query['shop'], $query['code'], $query['state'], $query['hmac'])) {
    http_response_code(400);
    echo "Missing required query parameters.";
    exit;
}

$shop  = sanitize_shop_domain($query['shop']);
$code  = $query['code'];
$state = $query['state'];

if (!$shop) {
    http_response_code(400);
    echo "Invalid shop domain.";
    exit;
}

// 2. Verify state - try database first (more reliable), then fallback to session
$stateValid = false;

// First, try database storage (works even if sessions fail)
if (OAuthStateHelper::verifyState($state, $shop)) {
    $stateValid = true;
} 
// Fallback to session (for direct install.php access)
elseif (!empty($_SESSION['shopify_oauth_state']) && $state === $_SESSION['shopify_oauth_state']) {
    $stateValid = true;
    // Clean up session state
    unset($_SESSION['shopify_oauth_state']);
}

if (!$stateValid) {
    http_response_code(400);
    error_log('OAuth state verification failed: state=' . $state . ', shop=' . $shop);
    echo "Invalid OAuth state. Please try installing again.";
    exit;
}

// 3. Verify HMAC
if (!verify_shopify_hmac($query, SHOPIFY_API_SECRET)) {
    http_response_code(400);
    echo "HMAC validation failed.";
    exit;
}

// 4. Exchange code for access token
$accessToken = ShopifyClient::getAccessToken($shop, $code);

if (!$accessToken) {
    http_response_code(500);
    error_log("Failed to get access token for shop: {$shop}, code: " . substr($code, 0, 10) . "...");
    echo "Failed to get access token from Shopify.";
    echo "<br><small>Please verify your API credentials in config.local.php match your Shopify Partners dashboard.</small>";
    echo "<br><small>Also ensure the redirect URI matches exactly: " . htmlspecialchars(SHOPIFY_REDIRECT_URI, ENT_QUOTES, 'UTF-8') . "</small>";
    exit;
}

// Trim whitespace from token (important!)
$accessToken = trim($accessToken);

if (empty($accessToken)) {
    http_response_code(500);
    error_log("Access token is empty after trimming for shop: {$shop}");
    echo "Error: Received empty access token from Shopify. Please try installing again.";
    exit;
}

// Log token length for debugging (don't log the actual token!)
error_log("Access token obtained for {$shop}, length: " . strlen($accessToken));

// 5. Store or update shop in DB
$db = get_db();

// Check if migration columns exist
$checkColumnsStmt = $db->query("SHOW COLUMNS FROM shops LIKE 'plan_type'");
$hasNewColumns = $checkColumnsStmt->rowCount() > 0;

// Check if shop already exists
$checkStmt = $db->prepare('SELECT id, first_installed_at FROM shops WHERE shop_domain = :shop LIMIT 1');
$checkStmt->execute(['shop' => $shop]);
$existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    // Update existing shop (reinstall)
    if ($hasNewColumns) {
        $stmt = $db->prepare('
            UPDATE shops 
            SET access_token = :access_token,
                installed_at = NOW(),
                last_reinstalled_at = NOW(),
                first_installed_at = COALESCE(first_installed_at, NOW())
            WHERE shop_domain = :shop_domain
        ');
    } else {
        // Fallback for old schema
        $stmt = $db->prepare('
            UPDATE shops 
            SET access_token = :access_token,
                installed_at = NOW()
            WHERE shop_domain = :shop_domain
        ');
    }
    $stmt->execute([
        'shop_domain'  => $shop,
        'access_token' => $accessToken,
    ]);
    
    if ($stmt->rowCount() === 0) {
        error_log("Failed to update shop record for: {$shop}");
    } else {
        error_log("Successfully updated shop record for: {$shop}");
    }
} else {
    // New installation
    if ($hasNewColumns) {
        $stmt = $db->prepare('
            INSERT INTO shops (shop_domain, access_token, installed_at, first_installed_at, last_reinstalled_at, plan_type)
            VALUES (:shop_domain, :access_token, NOW(), NOW(), NOW(), :plan_type)
        ');
        $stmt->execute([
            'shop_domain'  => $shop,
            'access_token' => $accessToken,
            'plan_type'   => 'free',
        ]);
    } else {
        // Fallback for old schema
        $stmt = $db->prepare('
            INSERT INTO shops (shop_domain, access_token, installed_at)
            VALUES (:shop_domain, :access_token, NOW())
        ');
        $stmt->execute([
            'shop_domain'  => $shop,
            'access_token' => $accessToken,
        ]);
    }
    
    if ($stmt->rowCount() === 0) {
        error_log("Failed to insert shop record for: {$shop}");
    } else {
        error_log("Successfully inserted shop record for: {$shop}");
    }
}

// Force database connection to flush/commit
// PDO autocommit is ON by default, but we'll ensure the connection is flushed
try {
    // Close the connection to force a commit
    $db = null;
    // Reopen connection to ensure we're reading from a fresh connection
    $db = get_db();
    
    // Verify the token was saved with a fresh connection
    $verifyStmt = $db->prepare('SELECT access_token FROM shops WHERE shop_domain = :shop LIMIT 1');
    $verifyStmt->execute(['shop' => $shop]);
    $verifyRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$verifyRow || empty($verifyRow['access_token'])) {
        error_log("CRITICAL: Access token not saved properly for shop: {$shop}");
        http_response_code(500);
        echo "Error: Failed to save access token. Please contact support.";
        exit;
    }
    
    // Verify the saved token matches what we tried to save
    $savedToken = trim($verifyRow['access_token']);
    if ($savedToken !== $accessToken) {
        error_log("WARNING: Token mismatch for shop: {$shop}. Expected length: " . strlen($accessToken) . ", Saved length: " . strlen($savedToken));
        // Use the saved token anyway, but log the issue
        $accessToken = $savedToken;
    }
    
    error_log("Token verified successfully for shop: {$shop}, length: " . strlen($accessToken));
} catch (Exception $e) {
    error_log("Error verifying token: " . $e->getMessage());
    // Continue anyway - the token should be saved
}

// Store token in session temporarily to avoid DB race condition on first load
// This ensures index.php can use it immediately without waiting for DB replication/commit
// Session is already started by init_shopify_session() at the top of this file
$_SESSION['shopify_temp_token_' . $shop] = $accessToken;
$_SESSION['shopify_temp_token_time_' . $shop] = time();
$_SESSION['shopify_install_complete_' . $shop] = true; // Flag to indicate fresh installation

$sessionId = session_id();
error_log("Stored temporary token in session for shop: {$shop}, session_id: {$sessionId}, token_length: " . strlen($accessToken));
error_log("Token preview (first 5, last 5): " . substr($accessToken, 0, 5) . "..." . substr($accessToken, -5));

// Explicitly write and close session to ensure persistence before redirect
session_write_close();
error_log("Session written and closed before redirect for shop: {$shop}");

// Small delay to ensure Shopify token is fully activated
usleep(500000); // 500ms delay

// 6. Redirect back into embedded app inside shop admin
$appUrl = 'https://' . parse_url(SHOPIFY_REDIRECT_URI, PHP_URL_HOST) . '/index.php';
$redirectUrl = $appUrl . '?shop=' . urlencode($shop);

error_log("Redirecting to: {$redirectUrl} for shop: {$shop}");
header('Location: ' . $redirectUrl);
exit;
