<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers/session.php';
init_shopify_session();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/hmac.php';
require_once __DIR__ . '/helpers/OAuthStateHelper.php';

$shop = isset($_GET['shop']) ? sanitize_shop_domain($_GET['shop']) : null;

if (!$shop) {
    http_response_code(400);
    echo "Missing or invalid 'shop' parameter.";
    exit;
}

$state = bin2hex(random_bytes(16));

// Store state in both session (for direct access) and database (for Shopify redirects)
$_SESSION['shopify_oauth_state'] = $state;
OAuthStateHelper::storeState($state, $shop);

// Clean up expired states
OAuthStateHelper::cleanupExpiredStates();

// Ensure session is written before redirect
session_write_close();

$params = [
    'client_id'    => SHOPIFY_API_KEY,
    'scope'        => SHOPIFY_SCOPES,
    'redirect_uri' => SHOPIFY_REDIRECT_URI,
    'state'        => $state,
];

$installUrl = "https://{$shop}/admin/oauth/authorize?" . http_build_query($params);

header('Location: ' . $installUrl);
exit;
