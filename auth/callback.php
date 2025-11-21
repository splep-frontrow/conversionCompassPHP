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

// Log API credentials for debugging (first 10 chars only for security)
error_log("API credentials check for shop: {$shop} - API_KEY (first 10): " . substr(SHOPIFY_API_KEY, 0, 10) . "...");
error_log("API_SECRET length: " . strlen(SHOPIFY_API_SECRET));

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

// Enhanced token logging - log full token length and preview
$tokenLength = strlen($accessToken);
error_log("Access token obtained for {$shop}, length: {$tokenLength}");
error_log("Token preview (first 10, last 10): " . substr($accessToken, 0, 10) . "..." . substr($accessToken, -10));

// Validate token format: must start with shpat or shpca
if (!str_starts_with($accessToken, 'shpat') && !str_starts_with($accessToken, 'shpca')) {
    http_response_code(500);
    error_log("CRITICAL: Invalid token format for shop: {$shop}. Token starts with: " . substr($accessToken, 0, 10));
    echo "Error: Invalid access token format received from Shopify. Please try installing again.";
    exit;
}

// Validate token length: must be at least 40 characters (Shopify tokens are typically 50-70 chars)
if ($tokenLength < 40) {
    http_response_code(500);
    error_log("CRITICAL: Token too short for shop: {$shop}. Length: {$tokenLength}, expected at least 40 characters.");
    error_log("Token preview (first 10, last 10): " . substr($accessToken, 0, 10) . "..." . substr($accessToken, -10));
    echo "Error: Access token appears to be truncated or invalid (length: {$tokenLength}). Please try installing again.";
    echo "<br><small>If this persists, verify your API credentials in config.local.php match your Shopify Partners dashboard.</small>";
    exit;
}

// 5. Store or update shop in DB
$db = get_db();

// Verify database column size for access_token
try {
    $columnCheckStmt = $db->query("SHOW COLUMNS FROM shops WHERE Field = 'access_token'");
    $columnInfo = $columnCheckStmt->fetch(PDO::FETCH_ASSOC);
    if ($columnInfo) {
        $columnType = $columnInfo['Type'] ?? 'unknown';
        error_log("Database column 'access_token' type: {$columnType}");
        // Check if it's VARCHAR and extract the size
        if (preg_match('/varchar\((\d+)\)/i', $columnType, $matches)) {
            $columnSize = (int)$matches[1];
            error_log("access_token column size: {$columnSize} characters");
            if ($columnSize < 100) {
                error_log("WARNING: access_token column size ({$columnSize}) may be too small for Shopify tokens (typically 50-70 chars)");
            }
        }
    }
} catch (Exception $e) {
    error_log("Could not check database column info: " . $e->getMessage());
}

// Log token length immediately before database save
error_log("About to save token to database for shop: {$shop}, token_length: " . strlen($accessToken));
error_log("Token before save (first 10, last 10): " . substr($accessToken, 0, 10) . "..." . substr($accessToken, -10));

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
    // Log token length right before execute
    error_log("Executing UPDATE for shop: {$shop}, token_length before execute: " . strlen($accessToken));
    
    $stmt->execute([
        'shop_domain'  => $shop,
        'access_token' => $accessToken,
    ]);
    
    if ($stmt->rowCount() === 0) {
        error_log("Failed to update shop record for: {$shop}");
    } else {
        error_log("Successfully updated shop record for: {$shop}");
        // Immediately verify what was saved
        $verifyStmt = $db->prepare('SELECT access_token, CHAR_LENGTH(access_token) as token_len FROM shops WHERE shop_domain = :shop LIMIT 1');
        $verifyStmt->execute(['shop' => $shop]);
        $verifyRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        if ($verifyRow) {
            $savedLen = (int)$verifyRow['token_len'];
            error_log("Immediate verification after UPDATE: saved token length = {$savedLen}, original length = " . strlen($accessToken));
            if ($savedLen !== strlen($accessToken)) {
                error_log("CRITICAL: Token length mismatch immediately after UPDATE! Original: " . strlen($accessToken) . ", Saved: {$savedLen}");
            }
        }
    }
} else {
    // New installation
    if ($hasNewColumns) {
        $stmt = $db->prepare('
            INSERT INTO shops (shop_domain, access_token, installed_at, first_installed_at, last_reinstalled_at, plan_type)
            VALUES (:shop_domain, :access_token, NOW(), NOW(), NOW(), :plan_type)
        ');
        // Log token length right before execute
        error_log("Executing INSERT for shop: {$shop}, token_length before execute: " . strlen($accessToken));
        
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
        
        // Log token length right before execute
        error_log("Executing INSERT (old schema) for shop: {$shop}, token_length before execute: " . strlen($accessToken));
        
        $stmt->execute([
            'shop_domain'  => $shop,
            'access_token' => $accessToken,
        ]);
    }
    
    if ($stmt->rowCount() === 0) {
        error_log("Failed to insert shop record for: {$shop}");
    } else {
        error_log("Successfully inserted shop record for: {$shop}");
        // Immediately verify what was saved
        $verifyStmt = $db->prepare('SELECT access_token, CHAR_LENGTH(access_token) as token_len FROM shops WHERE shop_domain = :shop LIMIT 1');
        $verifyStmt->execute(['shop' => $shop]);
        $verifyRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        if ($verifyRow) {
            $savedLen = (int)$verifyRow['token_len'];
            error_log("Immediate verification after INSERT: saved token length = {$savedLen}, original length = " . strlen($accessToken));
            if ($savedLen !== strlen($accessToken)) {
                error_log("CRITICAL: Token length mismatch immediately after INSERT! Original: " . strlen($accessToken) . ", Saved: {$savedLen}");
            }
        }
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
    $savedTokenLength = strlen($savedToken);
    $originalTokenLength = strlen($accessToken);
    
    error_log("Token verification for shop: {$shop} - Original length: {$originalTokenLength}, Saved length: {$savedTokenLength}");
    error_log("Saved token preview (first 10, last 10): " . substr($savedToken, 0, 10) . "..." . substr($savedToken, -10));
    
    if ($savedToken !== $accessToken) {
        error_log("WARNING: Token mismatch for shop: {$shop}. Expected length: {$originalTokenLength}, Saved length: {$savedTokenLength}");
        error_log("Original token preview (first 10, last 10): " . substr($accessToken, 0, 10) . "..." . substr($accessToken, -10));
        error_log("Saved token preview (first 10, last 10): " . substr($savedToken, 0, 10) . "..." . substr($savedToken, -10));
        
        // Validate saved token before using it
        if (strlen($savedToken) < 40) {
            error_log("CRITICAL: Saved token is too short ({$savedTokenLength} chars). Token may have been truncated in database.");
            http_response_code(500);
            echo "Error: Access token was truncated during storage. Please try installing again.";
            echo "<br><small>If this persists, check database column size for access_token (should be VARCHAR(255) or larger).</small>";
            exit;
        }
        
        // Use the saved token anyway, but log the issue
        $accessToken = $savedToken;
    }
    
    // Final validation of token before proceeding
    if (strlen($accessToken) < 40) {
        error_log("CRITICAL: Token validation failed after database retrieval for shop: {$shop}. Length: " . strlen($accessToken));
        http_response_code(500);
        echo "Error: Access token validation failed. Please try installing again.";
        exit;
    }
    
    error_log("Token verified successfully for shop: {$shop}, final length: " . strlen($accessToken));
} catch (Exception $e) {
    error_log("Error verifying token: " . $e->getMessage());
    // Continue anyway - the token should be saved
}

// Validate token is still valid before registering webhooks
if (empty($accessToken) || strlen($accessToken) < 40) {
    error_log("CRITICAL: Token invalid before webhook registration for shop: {$shop}. Length: " . strlen($accessToken));
    http_response_code(500);
    echo "Error: Access token validation failed before webhook registration. Please try installing again.";
    exit;
}

// Register required webhooks for this shop
try {
    $webhookBaseUrl = 'https://' . parse_url(SHOPIFY_REDIRECT_URI, PHP_URL_HOST);
    
    // List existing webhooks to avoid duplicates
    $existingWebhooks = ShopifyClient::listWebhooks($shop, $accessToken);
    $existingTopics = [];
    $existingComplianceTopics = [];
    
    if ($existingWebhooks['status'] === 200 && isset($existingWebhooks['body']['webhooks'])) {
        foreach ($existingWebhooks['body']['webhooks'] as $webhook) {
            if ($webhook['address'] === $webhookBaseUrl . '/webhooks/charges.php') {
                $existingTopics[] = $webhook['topic'];
            }
            if ($webhook['address'] === $webhookBaseUrl . '/webhooks/compliance.php') {
                $existingComplianceTopics[] = $webhook['topic'];
            }
        }
    }
    
    // Register app/uninstalled webhook if not already registered
    if (!in_array('app/uninstalled', $existingTopics)) {
        $webhookResponse = ShopifyClient::createWebhook(
            $shop,
            $accessToken,
            'app/uninstalled',
            $webhookBaseUrl . '/webhooks/charges.php'
        );
        
        if ($webhookResponse['status'] === 201) {
            error_log("Successfully registered app/uninstalled webhook for shop: {$shop}");
        } else {
            error_log("Failed to register app/uninstalled webhook for shop: {$shop}, status: {$webhookResponse['status']}, response: " . substr($webhookResponse['raw'] ?? '', 0, 200));
        }
    } else {
        error_log("app/uninstalled webhook already exists for shop: {$shop}");
    }
    
    // Register recurring_application_charges webhooks if needed
    $chargeTopics = ['recurring_application_charges/create', 'recurring_application_charges/update'];
    foreach ($chargeTopics as $topic) {
        if (!in_array($topic, $existingTopics)) {
            $webhookResponse = ShopifyClient::createWebhook(
                $shop,
                $accessToken,
                $topic,
                $webhookBaseUrl . '/webhooks/charges.php'
            );
            
            if ($webhookResponse['status'] === 201) {
                error_log("Successfully registered {$topic} webhook for shop: {$shop}");
            } else {
                error_log("Failed to register {$topic} webhook for shop: {$shop}, status: {$webhookResponse['status']}, response: " . substr($webhookResponse['raw'] ?? '', 0, 200));
            }
        } else {
            error_log("{$topic} webhook already exists for shop: {$shop}");
        }
    }
    
    // Register mandatory compliance webhooks (required for App Store listing)
    $complianceTopics = ['customers/data_request', 'customers/redact', 'shop/redact'];
    foreach ($complianceTopics as $topic) {
        if (!in_array($topic, $existingComplianceTopics)) {
            $webhookResponse = ShopifyClient::createWebhook(
                $shop,
                $accessToken,
                $topic,
                $webhookBaseUrl . '/webhooks/compliance.php'
            );
            
            if ($webhookResponse['status'] === 201) {
                error_log("Successfully registered {$topic} compliance webhook for shop: {$shop}");
            } else {
                error_log("Failed to register {$topic} compliance webhook for shop: {$shop}, status: {$webhookResponse['status']}, response: " . substr($webhookResponse['raw'] ?? '', 0, 200));
            }
        } else {
            error_log("{$topic} compliance webhook already exists for shop: {$shop}");
        }
    }
} catch (Exception $e) {
    error_log("Error registering webhooks for shop {$shop}: " . $e->getMessage());
    // Don't fail installation if webhook registration fails
}

// Store token in session temporarily to avoid DB race condition on first load
// This ensures index.php can use it immediately without waiting for DB replication/commit
// Session is already started by init_shopify_session() at the top of this file
$_SESSION['shopify_temp_token_' . $shop] = $accessToken;
$_SESSION['shopify_temp_token_time_' . $shop] = time();
$_SESSION['shopify_install_complete_' . $shop] = true; // Flag to indicate fresh installation

// Final validation before storing in session
if (empty($accessToken) || strlen($accessToken) < 40) {
    error_log("CRITICAL: Token invalid before session storage for shop: {$shop}. Length: " . strlen($accessToken));
    http_response_code(500);
    echo "Error: Access token validation failed. Please try installing again.";
    exit;
}

$sessionId = session_id();
error_log("Stored temporary token in session for shop: {$shop}, session_id: {$sessionId}, token_length: " . strlen($accessToken));
error_log("Session token preview (first 10, last 10): " . substr($accessToken, 0, 10) . "..." . substr($accessToken, -10));

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
