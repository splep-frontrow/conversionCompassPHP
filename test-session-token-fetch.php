<?php
declare(strict_types=1);

// Allow embedding in iframe (required for Shopify embedded apps)
header('X-Frame-Options: ALLOWALL');
header('Content-Security-Policy: frame-ancestors https://*.myshopify.com https://admin.shopify.com');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/hmac.php';
require_once __DIR__ . '/helpers/SessionTokenHelper.php';

$shop = isset($_GET['shop']) ? sanitize_shop_domain($_GET['shop']) : null;
$headers = getallheaders();

$result = [
    'success' => false,
    'message' => '',
    'session_token_found' => false,
    'session_token_valid' => false,
    'headers_received' => array_keys($headers),
    'has_x_shopify_session_token' => isset($headers['X-Shopify-Session-Token']),
    'x_shopify_session_token_length' => isset($headers['X-Shopify-Session-Token']) 
        ? strlen($headers['X-Shopify-Session-Token']) 
        : 0,
];

$token = SessionTokenHelper::extractSessionToken();

if ($token) {
    $result['session_token_found'] = true;
    $result['message'] = 'Session token found in request';
    
    if ($shop) {
        $payload = SessionTokenHelper::validateSessionToken($token, $shop);
        if ($payload) {
            $result['session_token_valid'] = true;
            $result['message'] = 'Session token is valid';
            $result['success'] = true;
        } else {
            $result['message'] = 'Session token found but validation failed';
        }
    }
} else {
    $result['message'] = 'No session token found in request headers';
}

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);

