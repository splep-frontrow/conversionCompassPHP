<?php
declare(strict_types=1);

/**
 * Session Token Debug Endpoint
 * 
 * This endpoint helps diagnose session token issues before Shopify's automated check.
 * 
 * ⚠️ IMPORTANT: This endpoint MUST be accessed FROM WITHIN your embedded app in Shopify Admin.
 * If accessed directly (not through Shopify Admin), it will show "FAIL" because:
 * - No host parameter (required for App Bridge)
 * - No session token header (App Bridge only sends it in embedded context)
 * 
 * Usage:
 * 1. Access from within your embedded app in Shopify Admin:
 *    - Go to your app in Shopify Admin
 *    - Navigate to a page (e.g., conversion.php)
 *    - In browser console, run: fetch('/debug-session-token.php?shop=YOUR_SHOP.myshopify.com').then(r => r.json()).then(console.log)
 *    - OR add a link in your app that opens this endpoint
 * 
 * 2. Or use the HTML viewer (also must be accessed through Shopify Admin):
 *    https://yourdomain.com/debug-session-token-viewer.html?shop=yourstore.myshopify.com&host=HOST_PARAM
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

// Special note if accessed directly (not through Shopify Admin)
$isDirectAccess = !isset($queryParams['host']) && !isset($headers['X-Shopify-Session-Token']);
if ($isDirectAccess) {
    $debug['access_method'] = 'DIRECT';
    $debug['warning'] = 'This endpoint was accessed directly, not through Shopify Admin. Session tokens are only sent when accessed through the embedded app context.';
    $debug['how_to_test'] = 'To properly test session tokens, access this endpoint FROM WITHIN your embedded app in Shopify Admin. You can do this by: 1) Adding a link in your app, 2) Using fetch() from browser console while in your app, or 3) Navigating to this URL while already in your embedded app.';
} else {
    $debug['access_method'] = 'EMBEDDED';
}

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
    if ($isDirectAccess) {
        $debug['next_steps'] = [
            '⚠️ This endpoint was accessed DIRECTLY (not through Shopify Admin)',
            'Session tokens are ONLY sent when accessed through the embedded app context.',
            '',
            'To properly test session tokens:',
            '1. Open your app in Shopify Admin (https://admin.shopify.com/store/YOUR_STORE/apps/YOUR_APP)',
            '2. While in your app, open browser console',
            '3. Run: fetch("/debug-session-token.php?shop=YOUR_STORE.myshopify.com").then(r => r.json()).then(console.log)',
            '4. OR add a link/button in your app that navigates to this endpoint',
            '',
            'When accessed through Shopify Admin, you should see:',
            '- host parameter in URL',
            '- X-Shopify-Session-Token header in request',
            '- overall_status: PASS (if everything is configured correctly)'
        ];
    } else {
        $debug['next_steps'] = [
            '1. Verify JWT library is installed: composer install',
            '2. Check browser Network tab for X-Shopify-Session-Token header',
            '3. Verify App Bridge is initialized in <head> with host parameter',
            '4. Check server error logs for detailed validation messages',
            '5. Ensure the request is being made through App Bridge\'s fetch (not direct fetch)'
        ];
    }
} else {
    $debug['next_steps'] = [
        '✓ All checks passed! Session tokens are working correctly.',
        'Interact with your app in a dev store to trigger Shopify\'s automated check.',
        'Check will run automatically every 2 hours after interaction.'
    ];
}

echo json_encode($debug, JSON_PRETTY_PRINT);

