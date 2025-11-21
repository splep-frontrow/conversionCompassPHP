<?php
declare(strict_types=1);

session_start();

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
    http_response_code(404);
    echo "Shop not found. Please install the app first.";
    exit;
}

$accessToken = trim($row['access_token'] ?? '');

// Verify access token exists
if (empty($accessToken)) {
    http_response_code(500);
    echo "Error: Access token not found in database. Please reinstall the app.";
    exit;
}

// Handle date range selection (same logic as conversion.php)
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

// Validate date range is set
if (!$startDate || !$endDate) {
    http_response_code(400);
    echo "Date range is required. Please select a date range.";
    exit;
}

// Fetch orders
$orders = [];
$error = null;

try {
    // Format dates for GraphQL query
    $startDateFormatted = ConversionHelper::formatDateForQuery($startDate);
    $endDateFormatted = ConversionHelper::formatDateForQuery($endDate);
    
    $orders = ConversionHelper::getOrdersWithConversionData($shop, $accessToken, $startDateFormatted, $endDateFormatted);
    
    if (empty($orders)) {
        http_response_code(404);
        echo "No orders found for the selected date range.";
        exit;
    }
} catch (Exception $e) {
    error_log("Exception in export.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo "Failed to fetch conversion data: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

// Prepare order data for CSV (same structure as conversion.php)
$orderData = [];
foreach ($orders as $order) {
    $conversionData = ConversionHelper::extractConversionData($order);
    $totalPriceSet = $order['totalPriceSet'] ?? null;
    $totalAmount = 0.0;
    $currency = '';
    
    if ($totalPriceSet && isset($totalPriceSet['shopMoney'])) {
        $totalAmount = (float)($totalPriceSet['shopMoney']['amount'] ?? 0);
        $currency = $totalPriceSet['shopMoney']['currencyCode'] ?? '';
    }
    
    // Extract order number from name (e.g., "#1001" from "Order #1001")
    $orderName = $order['name'] ?? '';
    $orderNumber = preg_replace('/[^0-9]/', '', $orderName);
    
    // Format date
    $createdAt = $order['createdAt'] ?? '';
    $orderDate = $createdAt ? date('Y-m-d H:i', strtotime($createdAt)) : 'N/A';
    
    // Build order URL
    // Extract shop name from domain (e.g., "frdmakesapps" from "frdmakesapps.myshopify.com")
    $shopName = str_replace('.myshopify.com', '', $shop);
    // Extract numeric ID from GID format (e.g., "7549207806245" from "gid://shopify/Order/7549207806245")
    $numericOrderId = preg_replace('/[^0-9]/', '', $order['id']);
    $orderUrl = "https://admin.shopify.com/store/{$shopName}/orders/{$numericOrderId}";
    
    $orderData[] = [
        'number' => $orderNumber,
        'date' => $orderDate,
        'total_amount' => $totalAmount,
        'currency' => $currency,
        'campaign' => $conversionData['campaign'],
        'source' => $conversionData['source'],
        'medium' => $conversionData['medium'],
        'referring_site' => $conversionData['referring_site'],
        'category' => ConversionHelper::categorizeReferrer($conversionData['source'], $conversionData['medium']),
        'url' => $orderUrl,
    ];
}

// Generate CSV
$csvContent = ConversionHelper::generateCSV($orderData);

// Set headers for CSV download
$startDateFormatted = date('Y-m-d', strtotime($startDate));
$endDateFormatted = date('Y-m-d', strtotime($endDate));
$filename = "conversion-data-{$startDateFormatted}-to-{$endDateFormatted}.csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Pragma: no-cache');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

// Output UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

// Output CSV content
echo $csvContent;
exit;

