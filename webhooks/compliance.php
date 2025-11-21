<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/hmac.php';

// Get raw POST data for HMAC verification
$hmacHeader = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';
$data = file_get_contents('php://input');
$shop = $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? '';
$topic = $_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '';

// For programmatically created webhooks, Shopify uses the app's API secret
// Try API_SECRET first (standard for programmatic webhooks), then WEBHOOK_SECRET as fallback
$hmacValid = false;
$secretUsed = 'none';

// Try with API_SECRET first (this is what Shopify uses for programmatically created webhooks)
$calculatedHmacApiSecret = base64_encode(hash_hmac('sha256', $data, SHOPIFY_API_SECRET, true));
if (hash_equals($calculatedHmacApiSecret, $hmacHeader)) {
    $hmacValid = true;
    $secretUsed = 'API_SECRET';
} else {
    // Try with WEBHOOK_SECRET if configured and different from default
    if (defined('SHOPIFY_WEBHOOK_SECRET') && SHOPIFY_WEBHOOK_SECRET !== 'your_webhook_secret_here' && SHOPIFY_WEBHOOK_SECRET !== SHOPIFY_API_SECRET) {
        $calculatedHmacWebhookSecret = base64_encode(hash_hmac('sha256', $data, SHOPIFY_WEBHOOK_SECRET, true));
        if (hash_equals($calculatedHmacWebhookSecret, $hmacHeader)) {
            $hmacValid = true;
            $secretUsed = 'WEBHOOK_SECRET';
        }
    }
}

if (!$hmacValid) {
    // Log details for debugging (but don't expose secrets)
    error_log("Compliance webhook HMAC verification failed for shop: {$shop}, topic: {$topic}");
    error_log("HMAC header present: " . (!empty($hmacHeader) ? 'yes' : 'no'));
    error_log("Data length: " . strlen($data));
    error_log("Tried API_SECRET and WEBHOOK_SECRET, both failed");
    http_response_code(401);
    echo "Invalid HMAC";
    exit;
} else {
    error_log("Compliance webhook HMAC verification succeeded for shop: {$shop}, topic: {$topic}, using: {$secretUsed}");
}

$payload = json_decode($data, true);

if (!$payload) {
    error_log("Failed to parse compliance webhook payload for shop: {$shop}, topic: {$topic}");
    http_response_code(400);
    echo "Invalid payload";
    exit;
}

$db = get_db();

// Handle customers/data_request webhook
if ($topic === 'customers/data_request') {
    $shopId = $payload['shop_id'] ?? null;
    $shopDomain = $payload['shop_domain'] ?? '';
    $customer = $payload['customer'] ?? [];
    $customerId = $customer['id'] ?? null;
    $customerEmail = $customer['email'] ?? '';
    $ordersRequested = $payload['orders_requested'] ?? [];
    $dataRequestId = $payload['data_request']['id'] ?? null;
    
    error_log("Received customers/data_request webhook for shop: {$shopDomain}, customer_id: {$customerId}, email: {$customerEmail}, orders_requested: " . count($ordersRequested) . ", data_request_id: {$dataRequestId}");
    
    // Since this app doesn't store customer data locally (fetches on-demand from Shopify API),
    // we only need to acknowledge receipt. If we stored customer data, we would need to:
    // 1. Retrieve all stored data for this customer
    // 2. Provide it to the store owner directly (not via API response)
    
    // Log for audit purposes
    error_log("Data request acknowledged for customer {$customerId} ({$customerEmail}) in shop {$shopDomain}");
    
    http_response_code(200);
    echo "OK";
    exit;
}

// Handle customers/redact webhook
if ($topic === 'customers/redact') {
    $shopId = $payload['shop_id'] ?? null;
    $shopDomain = $payload['shop_domain'] ?? '';
    $customer = $payload['customer'] ?? [];
    $customerId = $customer['id'] ?? null;
    $customerEmail = $customer['email'] ?? '';
    $ordersToRedact = $payload['orders_to_redact'] ?? [];
    
    error_log("Received customers/redact webhook for shop: {$shopDomain}, customer_id: {$customerId}, email: {$customerEmail}, orders_to_redact: " . count($ordersToRedact));
    
    // Since this app doesn't store customer data locally (fetches on-demand from Shopify API),
    // we only need to acknowledge receipt. If we stored customer data, we would need to:
    // 1. Delete all stored data for this customer
    // 2. Delete all stored data for the specified orders
    // Note: If legally required to retain data, we should not delete it
    
    // Log for audit purposes
    error_log("Customer redaction request acknowledged for customer {$customerId} ({$customerEmail}) in shop {$shopDomain}");
    
    http_response_code(200);
    echo "OK";
    exit;
}

// Handle shop/redact webhook
if ($topic === 'shop/redact') {
    $shopId = $payload['shop_id'] ?? null;
    $shopDomain = $payload['shop_domain'] ?? '';
    
    error_log("Received shop/redact webhook for shop: {$shopDomain}, shop_id: {$shopId}");
    
    // Delete all shop data from database
    // This is sent 48 hours after app uninstall, so we should remove all data for this shop
    try {
        $stmt = $db->prepare('DELETE FROM shops WHERE shop_domain = :shop');
        $stmt->execute(['shop' => $shopDomain]);
        
        $deletedRows = $stmt->rowCount();
        error_log("Deleted shop data for {$shopDomain}: {$deletedRows} row(s) removed");
        
        // If there are other tables with shop-specific data, delete those too
        // For example, if you had a conversions table: DELETE FROM conversions WHERE shop_domain = :shop
        
    } catch (Exception $e) {
        error_log("Error deleting shop data for {$shopDomain}: " . $e->getMessage());
        // Still return 200 to acknowledge receipt, even if deletion failed
        // The error is logged for manual follow-up
    }
    
    http_response_code(200);
    echo "OK";
    exit;
}

// Unknown compliance topic
error_log("Received unknown compliance webhook topic: {$topic} for shop: {$shop}");
http_response_code(200);
echo "OK";

