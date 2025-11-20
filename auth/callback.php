<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/session.php';
init_shopify_session();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/hmac.php';
require_once __DIR__ . '/../helpers/ShopifyClient.php';

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

// 2. Verify state (with better error message for debugging)
if (empty($_SESSION['shopify_oauth_state'])) {
    http_response_code(400);
    // Debug info (remove in production)
    $debugInfo = [
        'session_id' => session_id(),
        'session_data' => $_SESSION,
        'received_state' => $state,
        'session_save_path' => session_save_path(),
        'session_status' => session_status(),
    ];
    error_log('OAuth state error: ' . json_encode($debugInfo));
    echo "Invalid OAuth state: Session state not found. Please try installing again.";
    echo "<br><small>Debug: Session ID = " . htmlspecialchars(session_id()) . "</small>";
    exit;
}

if ($state !== $_SESSION['shopify_oauth_state']) {
    http_response_code(400);
    error_log('OAuth state mismatch: Expected ' . ($_SESSION['shopify_oauth_state'] ?? 'null') . ', got ' . $state);
    echo "Invalid OAuth state: State mismatch. Please try installing again.";
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
    echo "Failed to get access token from Shopify.";
    exit;
}

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
}

// 6. Redirect back into embedded app inside shop admin
$appUrl = 'https://' . parse_url(SHOPIFY_REDIRECT_URI, PHP_URL_HOST) . '/index.php';
$redirectUrl = $appUrl . '?shop=' . urlencode($shop);

header('Location: ' . $redirectUrl);
exit;
