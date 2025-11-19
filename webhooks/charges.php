<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/hmac.php';

// Get raw POST data for HMAC verification
$hmacHeader = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';
$data = file_get_contents('php://input');
$shop = $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? '';

// Verify webhook HMAC
$calculatedHmac = base64_encode(hash_hmac('sha256', $data, SHOPIFY_WEBHOOK_SECRET, true));

if (!hash_equals($calculatedHmac, $hmacHeader)) {
    http_response_code(401);
    echo "Invalid HMAC";
    exit;
}

$payload = json_decode($data, true);
$topic = $_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '';

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

