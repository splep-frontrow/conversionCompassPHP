<?php
declare(strict_types=1);

/**
 * Session Token Debug Endpoint
 * 
 * This endpoint helps diagnose session token issues before Shopify's automated check.
 * 
 * Usage:
 * 1. Access from within your embedded app in Shopify Admin:
 *    https://yourdomain.com/debug-session-token.php?shop=yourstore.myshopify.com
 * 
 * 2. Or use the HTML viewer:
 *    https://yourdomain.com/debug-session-token-viewer.html?shop=yourstore.myshopify.com
 * 
 * 3. Check browser Network tab to see the JSON response
 * 
 * What it checks:
 * - JWT library installation
 * - Session token presence in headers
 * - Session token validation
 * - App Bridge configuration (host parameter)
 * - API configuration
 * 
 * Returns JSON with detailed diagnostic information.
 */

// Allow embedding in iframe (required for Shopify embedded apps)
header('X-Frame-Options: ALLOWALL');
header('Content-Security-Policy: frame-ancestors https://*.myshopify.com https://admin.shopify.com');
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/hmac.php';
require_once __DIR__ . '/helpers/SessionTokenHelper.php';

$shop = isset($_GET['shop']) ? sanitize_shop_domain($_GET['shop']) : null;
$headers = getallheaders();

$debug = [
    'timestamp' => date('Y-m-d H:i:s'),
    'shop' => $shop ?? 'not_provided',
    'checks' => [],
    'headers' => [],
    'session_token' => [],
    'app_bridge' => [],
    'jwt_library' => [],
    'recommendations' => []
];

// Check 1: JWT Library Installation
$jwtAutoloader = __DIR__ . '/vendor/autoload.php';
$jwtLibraryExists = file_exists($jwtAutoloader);
$jwtClassExists = false;

if ($jwtLibraryExists) {
    require_once $jwtAutoloader;
    $jwtClassExists = class_exists('Firebase\JWT\JWT');
}

$debug['jwt_library'] = [
    'autoloader_exists' => $jwtLibraryExists,
    'autoloader_path' => $jwtAutoloader,
    'firebase_jwt_class_exists' => $jwtClassExists,
    'status' => $jwtClassExists ? 'OK' : 'MISSING'
];

if (!$jwtClassExists) {
    $debug['recommendations'][] = 'Run: composer install (to install firebase/php-jwt)';
}

// Check 2: Headers Received
$debug['headers'] = [
    'all_headers' => array_keys($headers),
    'has_x_shopify_session_token' => isset($headers['X-Shopify-Session-Token']),
    'has_authorization' => isset($headers['Authorization']),
    'x_shopify_session_token_length' => isset($headers['X-Shopify-Session-Token']) 
        ? strlen($headers['X-Shopify-Session-Token']) 
        : 0,
    'authorization_preview' => isset($headers['Authorization']) 
        ? substr($headers['Authorization'], 0, 50) . '...' 
        : null
];

// Check 3: Session Token Extraction
$token = SessionTokenHelper::extractSessionToken();
$debug['session_token'] = [
    'extracted' => $token !== null,
    'token_length' => $token ? strlen($token) : 0,
    'token_preview' => $token ? substr($token, 0, 20) . '...' . substr($token, -10) : null,
    'source' => null
];

if ($token) {
    if (isset($headers['X-Shopify-Session-Token'])) {
        $debug['session_token']['source'] = 'X-Shopify-Session-Token header';
    } elseif (isset($headers['Authorization'])) {
        $debug['session_token']['source'] = 'Authorization Bearer header';
    }
} else {
    $debug['recommendations'][] = 'Session token not found. Ensure App Bridge is initialized with host parameter.';
    $debug['recommendations'][] = 'Check browser Network tab to see if X-Shopify-Session-Token header is being sent.';
}

// Check 4: Session Token Validation
if ($token && $shop) {
    $payload = SessionTokenHelper::validateSessionToken($token, $shop);
    $debug['session_token']['validation'] = [
        'valid' => $payload !== null,
        'payload_keys' => $payload ? array_keys($payload) : null,
        'shop_match' => $payload && isset($payload['dest']) ? ($payload['dest'] === $shop) : null,
        'api_key_match' => $payload && isset($payload['aud']) ? ($payload['aud'] === SHOPIFY_API_KEY) : null,
        'expiration' => $payload && isset($payload['exp']) ? date('Y-m-d H:i:s', $payload['exp']) : null,
        'expired' => $payload && isset($payload['exp']) ? ($payload['exp'] < time()) : null
    ];
    
    if ($payload) {
        $debug['checks'][] = '✓ Session token is valid';
    } else {
        $debug['checks'][] = '✗ Session token validation failed';
        $debug['recommendations'][] = 'Check server error logs for validation details.';
    }
} elseif (!$token) {
    $debug['checks'][] = '✗ No session token found in request';
} elseif (!$shop) {
    $debug['checks'][] = '✗ Shop parameter missing';
}

// Check 5: App Bridge Configuration (from query params)
$queryParams = $_GET;
$debug['app_bridge'] = [
    'shop_param' => $queryParams['shop'] ?? null,
    'host_param' => $queryParams['host'] ?? null,
    'has_host_param' => isset($queryParams['host']),
    'status' => isset($queryParams['host']) ? 'OK' : 'MISSING_HOST'
];

if (!isset($queryParams['host'])) {
    $debug['recommendations'][] = 'Host parameter missing from URL. Ensure app is accessed through Shopify Admin.';
    $debug['recommendations'][] = 'App Bridge requires host parameter to send session tokens.';
}

// Check 6: API Configuration
$debug['api_config'] = [
    'api_key_set' => defined('SHOPIFY_API_KEY') && SHOPIFY_API_KEY !== 'your_api_key_here',
    'api_secret_set' => defined('SHOPIFY_API_SECRET') && SHOPIFY_API_SECRET !== 'your_api_secret_here',
    'api_key_preview' => defined('SHOPIFY_API_KEY') ? substr(SHOPIFY_API_KEY, 0, 10) . '...' : 'not_set'
];

if (!$debug['api_config']['api_key_set'] || !$debug['api_config']['api_secret_set']) {
    $debug['recommendations'][] = 'Check config.php - API key and secret must be set.';
}

// Overall Status
$allChecksPass = 
    $jwtClassExists &&
    $token !== null &&
    ($shop ? SessionTokenHelper::validateSessionToken($token, $shop) !== null : false) &&
    isset($queryParams['host']) &&
    $debug['api_config']['api_key_set'] &&
    $debug['api_config']['api_secret_set'];

$debug['overall_status'] = $allChecksPass ? 'PASS' : 'FAIL';
$debug['summary'] = [
    'jwt_library' => $jwtClassExists ? 'OK' : 'FAIL',
    'session_token_present' => $token !== null ? 'OK' : 'FAIL',
    'session_token_valid' => ($token && $shop && SessionTokenHelper::validateSessionToken($token, $shop) !== null) ? 'OK' : 'FAIL',
    'host_parameter' => isset($queryParams['host']) ? 'OK' : 'FAIL',
    'api_config' => ($debug['api_config']['api_key_set'] && $debug['api_config']['api_secret_set']) ? 'OK' : 'FAIL'
];

// Add helpful next steps
if ($debug['overall_status'] === 'FAIL') {
    $debug['next_steps'] = [
        '1. Verify JWT library is installed: composer install',
        '2. Access app through Shopify Admin (not directly) to get host parameter',
        '3. Check browser Network tab for X-Shopify-Session-Token header',
        '4. Verify App Bridge is initialized in <head> with host parameter',
        '5. Check server error logs for detailed validation messages'
    ];
} else {
    $debug['next_steps'] = [
        'All checks passed! Session tokens should be working.',
        'Interact with your app in a dev store to trigger Shopify\'s automated check.',
        'Check will run automatically every 2 hours after interaction.'
    ];
}

echo json_encode($debug, JSON_PRETTY_PRINT);

