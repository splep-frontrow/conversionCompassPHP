<?php
declare(strict_types=1);

session_start();

// Allow embedding in iframe (required for Shopify embedded apps)
header('X-Frame-Options: ALLOWALL');
header('Content-Security-Policy: frame-ancestors https://*.myshopify.com https://admin.shopify.com');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/hmac.php';
require_once __DIR__ . '/helpers/ShopifyClient.php';
require_once __DIR__ . '/helpers/SubscriptionHelper.php';
require_once __DIR__ . '/helpers/ConversionHelper.php';

$shop = isset($_GET['shop']) ? sanitize_shop_domain($_GET['shop']) : null;

if (!$shop) {
    http_response_code(400);
    echo "Missing or invalid 'shop' parameter.";
    exit;
}

$db = get_db();

// Look up shop in DB
$stmt = $db->prepare('SELECT access_token FROM shops WHERE shop_domain = :shop LIMIT 1');
$stmt->execute(['shop' => $shop]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    // Not installed yet, send to install flow
    $installUrl = '/install.php?shop=' . urlencode($shop);
    header('Location: ' . $installUrl);
    exit;
}

$accessToken = $row['access_token'];

// Update daily usage tracking
SubscriptionHelper::updateUsage($shop);

// Fetch shop info from Shopify
$response = ShopifyClient::apiRequest($shop, $accessToken, '/admin/api/2024-01/shop.json', 'GET');

if ($response['status'] !== 200) {
    http_response_code(500);
    echo "Failed to load shop info from Shopify.";
    exit;
}

$shopInfo = $response['body']['shop'] ?? [];
$shopName = $shopInfo['name'] ?? $shop;

// Get plan status
$planStatus = SubscriptionHelper::getPlanStatus($shop);

// Handle date range selection
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;
$dateRange = $_GET['range'] ?? null;

// Process date range
if ($dateRange) {
    $now = time();
    switch ($dateRange) {
        case '24h':
            $startDate = date('Y-m-d H:i:s', $now - 86400);
            $endDate = date('Y-m-d H:i:s', $now);
            break;
        case '7d':
            $startDate = date('Y-m-d H:i:s', $now - (7 * 86400));
            $endDate = date('Y-m-d H:i:s', $now);
            break;
        case '14d':
            $startDate = date('Y-m-d H:i:s', $now - (14 * 86400));
            $endDate = date('Y-m-d H:i:s', $now);
            break;
        case '30d':
            $startDate = date('Y-m-d H:i:s', $now - (30 * 86400));
            $endDate = date('Y-m-d H:i:s', $now);
            break;
    }
} elseif ($startDate && $endDate) {
    // Custom date range - ensure proper format
    $startDate = date('Y-m-d H:i:s', strtotime($startDate));
    $endDate = date('Y-m-d H:i:s', strtotime($endDate . ' 23:59:59'));
}

// Fetch orders if date range is set
$orders = [];
$statistics = [
    'total_orders' => 0,
    'total_revenue' => 0.0,
    'referrer_summary' => [
        'Social Media' => 0,
        'Direct Links' => 0,
        'Email' => 0,
        'Other' => 0,
    ],
];
$error = null;

if ($startDate && $endDate) {
    try {
        // Format dates for GraphQL query
        // Shopify expects ISO 8601 format: YYYY-MM-DDTHH:mm:ssZ
        $startDateFormatted = ConversionHelper::formatDateForQuery($startDate);
        $endDateFormatted = ConversionHelper::formatDateForQuery($endDate);
        
        error_log("Fetching orders for shop {$shop} from {$startDateFormatted} to {$endDateFormatted}");
        
        $orders = ConversionHelper::getOrdersWithConversionData($shop, $accessToken, $startDateFormatted, $endDateFormatted);
        $statistics = ConversionHelper::calculateStatistics($orders);
        
        error_log("Retrieved " . count($orders) . " orders, calculated statistics: " . json_encode($statistics));
        
        // If no orders found, log more details for debugging
        if (empty($orders)) {
            error_log("No orders found for date range. Start: {$startDate} ({$startDateFormatted}), End: {$endDate} ({$endDateFormatted})");
            $error = 'No orders found for the selected date range. Please check your server error logs for details.';
        }
    } catch (Exception $e) {
        $error = 'Failed to fetch conversion data: ' . $e->getMessage();
        error_log("Exception in conversion.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}

// Prepare order data for display
$orderData = [];
foreach ($orders as $order) {
    $conversionData = ConversionHelper::extractConversionData($order);
    $totalPriceSet = $order['totalPriceSet'] ?? null;
    $total = 'N/A';
    $currency = '';
    
    if ($totalPriceSet && isset($totalPriceSet['shopMoney'])) {
        $amount = (float)($totalPriceSet['shopMoney']['amount'] ?? 0);
        $currency = $totalPriceSet['shopMoney']['currencyCode'] ?? '';
        $total = number_format($amount, 2) . ' ' . $currency;
    }
    
    // Extract order number from name (e.g., "#1001" from "Order #1001")
    $orderName = $order['name'] ?? '';
    $orderNumber = preg_replace('/[^0-9]/', '', $orderName);
    
    // Format date
    $createdAt = $order['createdAt'] ?? '';
    $orderDate = $createdAt ? date('Y-m-d H:i', strtotime($createdAt)) : 'N/A';
    
    // Build order URL
    $orderUrl = "https://{$shop}/admin/orders/{$order['id']}";
    
    $orderData[] = [
        'id' => $order['id'],
        'name' => $orderName,
        'number' => $orderNumber,
        'date' => $orderDate,
        'total' => $total,
        'currency' => $currency,
        'url' => $orderUrl,
        'campaign' => $conversionData['campaign'],
        'source' => $conversionData['source'],
        'medium' => $conversionData['medium'],
        'referring_site' => $conversionData['referring_site'],
        'category' => ConversionHelper::categorizeReferrer($conversionData['source'], $conversionData['medium']),
    ];
}

require __DIR__ . '/views/conversion.php';

