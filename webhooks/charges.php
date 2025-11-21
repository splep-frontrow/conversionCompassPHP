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
    error_log("Webhook HMAC verification failed for shop: {$shop}, topic: {$topic}");
    error_log("HMAC header present: " . (!empty($hmacHeader) ? 'yes' : 'no'));
    error_log("Data length: " . strlen($data));
    error_log("Tried API_SECRET and WEBHOOK_SECRET, both failed");
    // Return 400 Bad Request for invalid HMAC as required by Shopify's automated checks
    http_response_code(400);
    echo "Invalid HMAC";
    exit;
} else {
    error_log("Webhook HMAC verification succeeded for shop: {$shop}, topic: {$topic}, using: {$secretUsed}");
}

$payload = json_decode($data, true);

$db = get_db();

if ($topic === 'app/uninstalled') {
    // Mark plan as expired when app is uninstalled
    $stmt = $db->prepare('
        UPDATE shops 
        SET plan_status = :status 
        WHERE shop_domain = :shop
    ');
    $stmt->execute([
        'shop' => $shop,
        'status' => 'expired',
    ]);
    
    http_response_code(200);
    echo "OK";
    exit;
}

if ($topic === 'recurring_application_charges/update' || $topic === 'recurring_application_charges/create') {
    $charge = $payload['recurring_application_charge'] ?? null;
    
    if (!$charge) {
        http_response_code(400);
        echo "Invalid payload";
        exit;
    }
    
    $chargeId = $charge['id'] ?? null;
    $status = $charge['status'] ?? '';
    
    if (!$chargeId) {
        http_response_code(400);
        echo "Missing charge ID";
        exit;
    }
    
    // Determine plan type from charge name or amount
    $planType = 'monthly';
    if (isset($charge['name']) && stripos($charge['name'], 'annual') !== false) {
        $planType = 'annual';
    } elseif (isset($charge['price']) && $charge['price'] >= ANNUAL_PRICE) {
        $planType = 'annual';
    }
    
    // Update shop's plan status
    $planStatus = 'active';
    if ($status === 'cancelled' || $status === 'declined') {
        $planStatus = 'cancelled';
    }
    
    $stmt = $db->prepare('
        UPDATE shops 
        SET plan_type = :plan_type,
            plan_status = :plan_status,
            billing_charge_id = :charge_id
        WHERE shop_domain = :shop
    ');
    $stmt->execute([
        'shop' => $shop,
        'plan_type' => $planType,
        'plan_status' => $planStatus,
        'charge_id' => (string)$chargeId,
    ]);
    
    http_response_code(200);
    echo "OK";
    exit;
}

// Unknown topic
http_response_code(200);
echo "OK";

