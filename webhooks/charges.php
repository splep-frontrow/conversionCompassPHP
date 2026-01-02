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
    // Delete shop record when app is uninstalled
    // This ensures that on reinstall, it's treated as a fresh install
    // The shop/redact webhook will handle final cleanup 48 hours later
    try {
        $stmt = $db->prepare('DELETE FROM shops WHERE shop_domain = :shop');
        $stmt->execute(['shop' => $shop]);
        
        $deletedRows = $stmt->rowCount();
        error_log("App uninstalled for shop: {$shop}, deleted {$deletedRows} row(s) from shops table");
        
        // Also delete any related data (e.g., oauth_states, subscriptions, etc.)
        // Add any other cleanup here as needed
        
    } catch (Exception $e) {
        error_log("Error deleting shop record during app/uninstalled webhook for {$shop}: " . $e->getMessage());
        // Still return 200 to acknowledge receipt, even if deletion failed
        // The error is logged for manual follow-up
    }
    
    http_response_code(200);
    echo "OK";
    exit;
}

// Handle both REST API webhooks (legacy) and GraphQL webhooks (new)
if ($topic === 'recurring_application_charges/update' || $topic === 'recurring_application_charges/create' || $topic === 'app_subscriptions/update') {
    // Log full payload structure for debugging
    error_log("Webhook received - Topic: {$topic}, Payload keys: " . implode(', ', array_keys($payload)));
    if (isset($payload['app_subscription'])) {
        error_log("Webhook app_subscription keys: " . implode(', ', array_keys($payload['app_subscription'])));
    }
    if (isset($payload['recurring_application_charge'])) {
        error_log("Webhook recurring_application_charge keys: " . implode(', ', array_keys($payload['recurring_application_charge'])));
    }
    
    // Handle REST API format (legacy)
    $charge = $payload['recurring_application_charge'] ?? null;
    
    // Handle GraphQL format (new) - app_subscriptions/update webhook uses this structure
    if (!$charge) {
        $charge = $payload['app_subscription'] ?? null;
    }
    
    // For app_subscriptions/update, the payload might be nested differently
    // Check if the payload itself is the subscription object
    if (!$charge && ($topic === 'app_subscriptions/update')) {
        // Sometimes the payload IS the subscription object
        if (isset($payload['id']) || isset($payload['status'])) {
            $charge = $payload;
            error_log("Webhook: Using payload as charge object directly");
        }
    }
    
    if (!$charge) {
        error_log("Webhook payload missing charge data. Topic: {$topic}, Payload structure: " . json_encode($payload, JSON_PRETTY_PRINT));
        http_response_code(400);
        echo "Invalid payload";
        exit;
    }
    
    // Extract charge ID (handle both REST numeric ID and GraphQL GID format)
    // For app_subscriptions/update webhook, the ID is in admin_graphql_api_id field
    $chargeId = $charge['id'] ?? $charge['admin_graphql_api_id'] ?? null;
    if ($chargeId && str_starts_with($chargeId, 'gid://')) {
        // Extract numeric ID from GID format: gid://shopify/AppSubscription/123456
        preg_match('/\/(\d+)$/', $chargeId, $matches);
        $chargeId = $matches[1] ?? $chargeId;
    }
    
    // Extract status (REST uses 'status', GraphQL might use different field)
    $status = $charge['status'] ?? '';
    
    if (!$chargeId) {
        error_log("Webhook missing charge ID. Topic: {$topic}, Charge object keys: " . implode(', ', array_keys($charge)));
        error_log("Webhook full charge object: " . json_encode($charge, JSON_PRETTY_PRINT));
        error_log("Webhook full payload: " . json_encode($payload, JSON_PRETTY_PRINT));
        http_response_code(400);
        echo "Missing charge ID";
        exit;
    }
    
    // Determine plan type from charge name, amount, or interval
    $planType = 'monthly';
    $chargeName = $charge['name'] ?? '';
    $chargeAmount = $charge['price'] ?? ($charge['lineItems'][0]['plan']['pricingDetails']['price']['amount'] ?? null);
    $interval = $charge['interval'] ?? ($charge['lineItems'][0]['plan']['pricingDetails']['interval'] ?? null);
    
    // Check interval first (most reliable)
    if ($interval === 'ANNUAL' || $interval === 'EVERY_12_MONTHS') {
        $planType = 'annual';
    } elseif ($interval === 'EVERY_30_DAYS' || $interval === 'MONTHLY') {
        $planType = 'monthly';
    } elseif (stripos($chargeName, 'annual') !== false) {
        $planType = 'annual';
    } elseif ($chargeAmount && $chargeAmount >= ANNUAL_PRICE) {
        $planType = 'annual';
    }
    
    // Check if this shop has a previous subscription (from a plan change)
    $checkPreviousStmt = $db->prepare('SELECT previous_billing_charge_id, previous_plan_type, access_token FROM shops WHERE shop_domain = :shop LIMIT 1');
    $checkPreviousStmt->execute(['shop' => $shop]);
    $previousData = $checkPreviousStmt->fetch(PDO::FETCH_ASSOC);
    
    $hasPreviousSubscription = !empty($previousData['previous_billing_charge_id']) && 
                               !empty($previousData['previous_plan_type']) &&
                               $previousData['previous_billing_charge_id'] !== (string)$chargeId;
    
    // Update shop's plan status based on charge status
    $planStatus = 'active';
    $statusLower = strtolower($status);
    if ($statusLower === 'cancelled' || $statusLower === 'declined' || $statusLower === 'expired') {
        $planStatus = 'cancelled';
        // When cancelled/expired, set plan_type to free
        $planType = 'free';
    } elseif ($statusLower === 'pending' || $statusLower === 'pending_acceptance' || $statusLower === 'pending_acceptance') {
        $planStatus = 'pending';
    } elseif ($statusLower === 'active' || $statusLower === 'accepted') {
        $planStatus = 'active';
        
        // If this is a new subscription becoming active and there's a previous one, cancel the old one
        if ($hasPreviousSubscription && !empty($previousData['access_token'])) {
            require_once __DIR__ . '/../helpers/ShopifyClient.php';
            
            try {
                $cancelResponse = ShopifyClient::cancelCharge($shop, $previousData['access_token'], $previousData['previous_billing_charge_id']);
                if ($cancelResponse['status'] === 200) {
                    error_log("Webhook: Successfully cancelled previous subscription for shop={$shop}, previous_charge_id={$previousData['previous_billing_charge_id']}");
                } else {
                    error_log("Webhook: Failed to cancel previous subscription for shop={$shop}, previous_charge_id={$previousData['previous_billing_charge_id']}, status={$cancelResponse['status']}");
                }
            } catch (Exception $e) {
                error_log("Webhook: Exception cancelling previous subscription for shop={$shop}, error: " . $e->getMessage());
            }
        }
    }
    
    // Update the shop's subscription info
    // If this is a new active subscription replacing an old one, clear the previous tracking
    if ($hasPreviousSubscription && $planStatus === 'active') {
        $stmt = $db->prepare('
            UPDATE shops 
            SET plan_type = :plan_type,
                plan_status = :plan_status,
                billing_charge_id = :charge_id,
                previous_billing_charge_id = NULL,
                previous_plan_type = NULL
            WHERE shop_domain = :shop
        ');
    } else {
        $stmt = $db->prepare('
            UPDATE shops 
            SET plan_type = :plan_type,
                plan_status = :plan_status,
                billing_charge_id = :charge_id
            WHERE shop_domain = :shop
        ');
    }
    
    $stmt->execute([
        'shop' => $shop,
        'plan_type' => $planType,
        'plan_status' => $planStatus,
        'charge_id' => (string)$chargeId,
    ]);
    
    error_log("Webhook processed: shop={$shop}, topic={$topic}, charge_id={$chargeId}, status={$status}, plan_type={$planType}, plan_status={$planStatus}" . ($hasPreviousSubscription ? ", cancelled_previous_charge_id={$previousData['previous_billing_charge_id']}" : ""));
    
    http_response_code(200);
    echo "OK";
    exit;
}

// Unknown topic
http_response_code(200);
echo "OK";

