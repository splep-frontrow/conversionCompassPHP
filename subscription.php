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
require_once __DIR__ . '/helpers/SessionTokenHelper.php';

$shop = isset($_GET['shop']) ? sanitize_shop_domain($_GET['shop']) : null;
$host = isset($_GET['host']) ? $_GET['host'] : null; // Capture host parameter for App Bridge

if (!$shop) {
    http_response_code(400);
    echo "Missing or invalid 'shop' parameter.";
    exit;
}

// Validate session token if present (for embedded app compliance)
$sessionTokenValid = false;
$sessionTokenPayload = SessionTokenHelper::validateRequest($shop);
if ($sessionTokenPayload !== null) {
    $sessionTokenValid = true;
    error_log("subscription.php: Session token validated successfully for shop: {$shop}");
} else {
    error_log("subscription.php: Session token validation failed or missing for shop: {$shop}, falling back to access token method");
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

$accessToken = trim($row['access_token'] ?? '');

// Verify access token exists
if (empty($accessToken)) {
    error_log("ERROR: Empty access token retrieved for shop: {$shop} in subscription.php");
    http_response_code(500);
    echo "Error: Access token not found in database. Please reinstall the app.";
    echo "<br><br><a href='/install.php?shop=" . urlencode($shop) . "'>Reinstall the app</a>";
    exit;
}

$planStatus = SubscriptionHelper::getPlanStatus($shop);
$pendingConfirmationUrl = null;
$successMessage = null;

// Handle charge creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_charge') {
        $planType = $_POST['plan_type'] ?? '';
        $amount = $planType === 'annual' ? ANNUAL_PRICE : MONTHLY_PRICE;
        
        error_log("subscription.php: Attempting to create charge for shop: {$shop}, plan_type: {$planType}, amount: {$amount}");
        
        $response = ShopifyClient::createRecurringCharge($shop, $accessToken, $amount, $planType);
        
        // Log detailed error information
        error_log("subscription.php: Charge creation response status: " . $response['status']);
        if (isset($response['body'])) {
            error_log("subscription.php: Charge creation response body: " . json_encode($response['body']));
        }
        if (isset($response['raw'])) {
            error_log("subscription.php: Charge creation raw response (first 500 chars): " . substr($response['raw'], 0, 500));
        }
        
        // Handle GraphQL response (status 200) or REST response (status 201)
        $confirmationUrl = null;
        if ($response['status'] === 201 && isset($response['body']['recurring_application_charge']['confirmation_url'])) {
            // REST API response format
            $confirmationUrl = $response['body']['recurring_application_charge']['confirmation_url'];
        } elseif ($response['status'] === 200 && isset($response['body']['recurring_application_charge']['confirmation_url'])) {
            // GraphQL response format (transformed to match REST format)
            $confirmationUrl = $response['body']['recurring_application_charge']['confirmation_url'];
        }
        
        if ($confirmationUrl) {
            $pendingConfirmationUrl = $confirmationUrl;
            $successMessage = 'Redirecting you to Shopify to confirm the subscription...';
            error_log("subscription.php: Received confirmation URL, will redirect client-side: {$confirmationUrl}");
        } else {
            // Handle 403 Forbidden error specifically
            if ($response['status'] === 403) {
                $error = 'Your app installation is missing billing permissions. Please reinstall the app to enable subscription purchases. <a href="/install.php?shop=' . urlencode($shop) . '">Click here to reinstall</a>.';
                error_log("subscription.php: 403 Forbidden - Access token missing billing scopes. Shop needs to reinstall app.");
            } else {
                // Extract error message from response (handle both REST and GraphQL formats)
                $errorMessage = 'Failed to create charge.';
                
                // Check for GraphQL userErrors
                if (isset($response['body']['data']['appSubscriptionCreate']['userErrors'])) {
                    $userErrors = $response['body']['data']['appSubscriptionCreate']['userErrors'];
                    $errorMessages = array_column($userErrors, 'message');
                    
                    // Check for specific error about Managed Pricing
                    if (in_array('Managed Pricing Apps cannot use the Billing API (to create charges).', $errorMessages)) {
                        $errorMessage = 'Your app is configured with "Managed Pricing" in the Shopify Partners Dashboard. To enable subscription purchases, you must change the app to "Manual Pricing" in the Partners Dashboard. Go to Apps → Your App → App Setup → Pricing, and change from "Managed Pricing" to "Manual Pricing".';
                    } else {
                        $errorMessage .= ' ' . implode('. ', $errorMessages);
                    }
                }
                // Check for GraphQL errors
                elseif (isset($response['body']['errors'])) {
                    $errors = $response['body']['errors'];
                    $errorMessages = array_column($errors, 'message');
                    
                    // Check for specific error about Managed Pricing
                    if (in_array('Managed Pricing Apps cannot use the Billing API (to create charges).', $errorMessages)) {
                        $errorMessage = 'Your app is configured with "Managed Pricing" in the Shopify Partners Dashboard. To enable subscription purchases, you must change the app to "Manual Pricing" in the Partners Dashboard. Go to Apps → Your App → App Setup → Pricing, and change from "Managed Pricing" to "Manual Pricing".';
                    } else {
                        $errorMessage .= ' ' . implode('. ', $errorMessages);
                    }
                }
                // Check for REST API errors
                elseif (isset($response['body']['error'])) {
                    $errorMessage .= ' ' . $response['body']['error'];
                }
                
                $error = $errorMessage;
            }
        }
    } elseif ($_POST['action'] === 'cancel_charge' && !empty($planStatus['billing_charge_id'])) {
        $response = ShopifyClient::cancelCharge($shop, $accessToken, $planStatus['billing_charge_id']);
        
        if ($response['status'] === 200) {
            // Update plan status
            $updateStmt = $db->prepare('UPDATE shops SET plan_status = :status WHERE shop_domain = :shop');
            $updateStmt->execute([
                'shop' => $shop,
                'status' => 'cancelled',
            ]);
            header('Location: /subscription.php?shop=' . urlencode($shop));
            exit;
        } else {
            $error = 'Failed to cancel charge. Please try again.';
        }
    } elseif ($_POST['action'] === 'change_plan') {
        $newPlanType = $_POST['plan_type'] ?? '';
        
        // Validate new plan type
        if (!in_array($newPlanType, ['monthly', 'annual'])) {
            $error = 'Invalid plan type selected.';
        } elseif ($planStatus['plan_type'] === $newPlanType) {
            $error = 'You are already on the ' . ucfirst($newPlanType) . ' plan.';
        } elseif ($planStatus['plan_type'] === 'free') {
            // If on free plan, just create a new charge (fallback to create_charge flow)
            $amount = $newPlanType === 'annual' ? ANNUAL_PRICE : MONTHLY_PRICE;
            error_log("subscription.php: Change plan from free, creating new charge for shop: {$shop}, plan_type: {$newPlanType}, amount: {$amount}");
            
            $response = ShopifyClient::createRecurringCharge($shop, $accessToken, $amount, $newPlanType);
            
            // Handle confirmation URL
            $confirmationUrl = null;
            if ($response['status'] === 201 && isset($response['body']['recurring_application_charge']['confirmation_url'])) {
                $confirmationUrl = $response['body']['recurring_application_charge']['confirmation_url'];
            } elseif ($response['status'] === 200 && isset($response['body']['recurring_application_charge']['confirmation_url'])) {
                $confirmationUrl = $response['body']['recurring_application_charge']['confirmation_url'];
            }
            
            if ($confirmationUrl) {
                $pendingConfirmationUrl = $confirmationUrl;
                $successMessage = 'Redirecting you to Shopify to confirm the subscription...';
            } else {
                // Extract error message from response (handle both REST and GraphQL formats)
                $errorMessage = 'Failed to create new subscription.';
                
                // Check for GraphQL userErrors
                if (isset($response['body']['data']['appSubscriptionCreate']['userErrors'])) {
                    $userErrors = $response['body']['data']['appSubscriptionCreate']['userErrors'];
                    $errorMessages = array_column($userErrors, 'message');
                    $errorMessage .= ' ' . implode('. ', $errorMessages);
                }
                // Check for GraphQL errors
                elseif (isset($response['body']['errors'])) {
                    $errors = $response['body']['errors'];
                    $errorMessages = array_column($errors, 'message');
                    $errorMessage .= ' ' . implode('. ', $errorMessages);
                }
                // Check for REST API errors
                elseif (isset($response['body']['error'])) {
                    $errorMessage .= ' ' . $response['body']['error'];
                }
                
                $error = $errorMessage;
            }
        } elseif ($planStatus['plan_status'] !== 'active') {
            // If plan is cancelled or expired, allow creating new charge
            $amount = $newPlanType === 'annual' ? ANNUAL_PRICE : MONTHLY_PRICE;
            error_log("subscription.php: Change plan from inactive plan, creating new charge for shop: {$shop}, plan_type: {$newPlanType}, amount: {$amount}");
            
            $response = ShopifyClient::createRecurringCharge($shop, $accessToken, $amount, $newPlanType);
            
            // Handle confirmation URL
            $confirmationUrl = null;
            if ($response['status'] === 201 && isset($response['body']['recurring_application_charge']['confirmation_url'])) {
                $confirmationUrl = $response['body']['recurring_application_charge']['confirmation_url'];
            } elseif ($response['status'] === 200 && isset($response['body']['recurring_application_charge']['confirmation_url'])) {
                $confirmationUrl = $response['body']['recurring_application_charge']['confirmation_url'];
            }
            
            if ($confirmationUrl) {
                $pendingConfirmationUrl = $confirmationUrl;
                $successMessage = 'Redirecting you to Shopify to confirm the subscription...';
            } else {
                // Extract error message from response (handle both REST and GraphQL formats)
                $errorMessage = 'Failed to create new subscription.';
                
                // Check for GraphQL userErrors
                if (isset($response['body']['data']['appSubscriptionCreate']['userErrors'])) {
                    $userErrors = $response['body']['data']['appSubscriptionCreate']['userErrors'];
                    $errorMessages = array_column($userErrors, 'message');
                    $errorMessage .= ' ' . implode('. ', $errorMessages);
                }
                // Check for GraphQL errors
                elseif (isset($response['body']['errors'])) {
                    $errors = $response['body']['errors'];
                    $errorMessages = array_column($errors, 'message');
                    $errorMessage .= ' ' . implode('. ', $errorMessages);
                }
                // Check for REST API errors
                elseif (isset($response['body']['error'])) {
                    $errorMessage .= ' ' . $response['body']['error'];
                }
                
                $error = $errorMessage;
            }
        } else {
            // Active paid subscription - need to cancel existing and create new
            if (empty($planStatus['billing_charge_id'])) {
                // No active charge ID found, try to create new charge directly
                $amount = $newPlanType === 'annual' ? ANNUAL_PRICE : MONTHLY_PRICE;
                error_log("subscription.php: Change plan - no billing_charge_id found, creating new charge for shop: {$shop}, plan_type: {$newPlanType}, amount: {$amount}");
                
                $response = ShopifyClient::createRecurringCharge($shop, $accessToken, $amount, $newPlanType);
                
                // Handle confirmation URL
                $confirmationUrl = null;
                if ($response['status'] === 201 && isset($response['body']['recurring_application_charge']['confirmation_url'])) {
                    $confirmationUrl = $response['body']['recurring_application_charge']['confirmation_url'];
                } elseif ($response['status'] === 200 && isset($response['body']['recurring_application_charge']['confirmation_url'])) {
                    $confirmationUrl = $response['body']['recurring_application_charge']['confirmation_url'];
                }
                
                if ($confirmationUrl) {
                    $pendingConfirmationUrl = $confirmationUrl;
                    $successMessage = 'Redirecting you to Shopify to confirm the subscription...';
                } else {
                    // Extract error message from response (handle both REST and GraphQL formats)
                    $errorMessage = 'Failed to create new subscription.';
                    
                    // Check for GraphQL userErrors
                    if (isset($response['body']['data']['appSubscriptionCreate']['userErrors'])) {
                        $userErrors = $response['body']['data']['appSubscriptionCreate']['userErrors'];
                        $errorMessages = array_column($userErrors, 'message');
                        $errorMessage .= ' ' . implode('. ', $errorMessages);
                    }
                    // Check for GraphQL errors
                    elseif (isset($response['body']['errors'])) {
                        $errors = $response['body']['errors'];
                        $errorMessages = array_column($errors, 'message');
                        $errorMessage .= ' ' . implode('. ', $errorMessages);
                    }
                    // Check for REST API errors
                    elseif (isset($response['body']['error'])) {
                        $errorMessage .= ' ' . $response['body']['error'];
                    }
                    
                    $error = $errorMessage;
                }
            } else {
                // Cancel existing charge first
                error_log("subscription.php: Change plan - cancelling existing charge for shop: {$shop}, charge_id: {$planStatus['billing_charge_id']}");
                $cancelResponse = ShopifyClient::cancelCharge($shop, $accessToken, $planStatus['billing_charge_id']);
                
                if ($cancelResponse['status'] === 200) {
                    // Successfully cancelled, now create new charge
                    $amount = $newPlanType === 'annual' ? ANNUAL_PRICE : MONTHLY_PRICE;
                    error_log("subscription.php: Change plan - creating new charge for shop: {$shop}, plan_type: {$newPlanType}, amount: {$amount}");
                    
                    $createResponse = ShopifyClient::createRecurringCharge($shop, $accessToken, $amount, $newPlanType);
                    
                    // Handle confirmation URL
                    $confirmationUrl = null;
                    if ($createResponse['status'] === 201 && isset($createResponse['body']['recurring_application_charge']['confirmation_url'])) {
                        $confirmationUrl = $createResponse['body']['recurring_application_charge']['confirmation_url'];
                    } elseif ($createResponse['status'] === 200 && isset($createResponse['body']['recurring_application_charge']['confirmation_url'])) {
                        $confirmationUrl = $createResponse['body']['recurring_application_charge']['confirmation_url'];
                    }
                    
                    if ($confirmationUrl) {
                        $pendingConfirmationUrl = $confirmationUrl;
                        $successMessage = 'Your current subscription has been cancelled. Redirecting you to confirm your new subscription...';
                        error_log("subscription.php: Change plan successful, confirmation URL received: {$confirmationUrl}");
                    } else {
                        // Cancellation succeeded but creation failed
                        $error = 'Your current subscription has been cancelled, but we were unable to create the new subscription. Please try again or contact support if the issue persists.';
                        error_log("subscription.php: Change plan - cancellation succeeded but creation failed for shop: {$shop}");
                        
                        // Update database to reflect cancellation
                        $updateStmt = $db->prepare('UPDATE shops SET plan_status = :status WHERE shop_domain = :shop');
                        $updateStmt->execute([
                            'shop' => $shop,
                            'status' => 'cancelled',
                        ]);
                        
                        // Extract detailed error message
                        $errorDetails = '';
                        // Check for GraphQL userErrors
                        if (isset($createResponse['body']['data']['appSubscriptionCreate']['userErrors'])) {
                            $userErrors = $createResponse['body']['data']['appSubscriptionCreate']['userErrors'];
                            $errorMessages = array_column($userErrors, 'message');
                            $errorDetails = ' Error: ' . implode('. ', $errorMessages);
                        }
                        // Check for GraphQL errors
                        elseif (isset($createResponse['body']['errors'])) {
                            $errors = $createResponse['body']['errors'];
                            $errorMessages = array_column($errors, 'message');
                            $errorDetails = ' Error: ' . implode('. ', $errorMessages);
                        }
                        // Check for REST API errors
                        elseif (isset($createResponse['body']['error'])) {
                            $errorDetails = ' Error: ' . $createResponse['body']['error'];
                        }
                        
                        $error .= $errorDetails;
                    }
                    } else {
                        // Cancellation failed
                        $errorMessage = 'Unable to cancel your current subscription. Please try again or contact support.';
                        error_log("subscription.php: Change plan - cancellation failed for shop: {$shop}, charge_id: {$planStatus['billing_charge_id']}");
                        
                        // Don't create new charge if cancellation failed
                        // Check for GraphQL userErrors
                        if (isset($cancelResponse['body']['data']['appSubscriptionCancel']['userErrors'])) {
                            $userErrors = $cancelResponse['body']['data']['appSubscriptionCancel']['userErrors'];
                            $errorMessages = array_column($userErrors, 'message');
                            $errorMessage .= ' Error: ' . implode('. ', $errorMessages);
                        }
                        // Check for GraphQL errors
                        elseif (isset($cancelResponse['body']['errors'])) {
                            $errors = $cancelResponse['body']['errors'];
                            $errorMessages = array_column($errors, 'message');
                            $errorMessage .= ' Error: ' . implode('. ', $errorMessages);
                        }
                        // Check for REST API errors
                        elseif (isset($cancelResponse['body']['error'])) {
                            $errorMessage .= ' Error: ' . $cancelResponse['body']['error'];
                        }
                        
                        $error = $errorMessage;
                    }
            }
        }
    }
}

// Fetch shop info from Shopify
$response = ShopifyClient::apiRequest($shop, $accessToken, '/admin/api/2024-10/shop.json', 'GET');
$shopInfo = $response['status'] === 200 ? ($response['body']['shop'] ?? []) : [];
$shopName = $shopInfo['name'] ?? $shop;

// Build form action URL with shop and host parameters
$formActionParams = ['shop' => $shop];
if ($host) {
    $formActionParams['host'] = $host;
}
$formAction = '?' . http_build_query($formActionParams);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subscription Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Shopify App Bridge -->
    <meta name="shopify-api-key" content="<?= htmlspecialchars(SHOPIFY_API_KEY, ENT_QUOTES, 'UTF-8') ?>" />
    <script>
        // Define initialization function BEFORE loading the script
        function initializeAppBridge() {
            // Wait for Shopify CDN to be available
            var attempts = 0;
            var maxAttempts = 50; // 5 seconds max wait
            
            function tryInit() {
                attempts++;
                
                // Check if Shopify CDN has already auto-initialized the app
                if (window.shopify && window.shopify.app) {
                    console.log('=== APP BRIDGE AUTO-INITIALIZED BY CDN ===');
                    window.shopifyApp = window.shopify.app;
                    // Also expose AppBridge for compatibility
                    window['app-bridge'] = {
                        actions: window.shopifyApp.actions || {},
                        utils: window.shopifyApp.utils || {}
                    };
                    console.log('✓ App Bridge found (auto-initialized by CDN)');
                    return;
                }
                
                // Try initializeAppBridge function if available
                if (window.shopify && typeof window.shopify.initializeAppBridge === 'function') {
                    var params = new URLSearchParams(window.location.search);
                    var shop = params.get('shop');
                    var host = params.get('host');
                    
                    var appConfig = {
                        apiKey: "<?= htmlspecialchars(SHOPIFY_API_KEY, ENT_QUOTES, 'UTF-8') ?>",
                        shopOrigin: shop
                    };
                    
                    if (host) {
                        appConfig.host = host;
                    }
                    
                    // Shopify CDN initializeAppBridge returns a promise
                    window.shopify.initializeAppBridge(appConfig)
                        .then(function(app) {
                            window.shopifyApp = app;
                            // Also expose AppBridge for compatibility
                            window['app-bridge'] = {
                                actions: app.actions || {},
                                utils: app.utils || {}
                            };
                            console.log('✓ App Bridge initialized successfully');
                        })
                        .catch(function(error) {
                            console.error('✗ Error initializing App Bridge:', error);
                        });
                    return;
                }
                
                // Keep trying if not found yet
                if (attempts < maxAttempts) {
                    setTimeout(tryInit, 100);
                } else {
                    console.error('✗ App Bridge failed to load after', maxAttempts * 100, 'ms');
                }
            }
            
            tryInit();
        }
        
        // Also try on DOMContentLoaded as fallback
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeAppBridge);
        } else {
            setTimeout(initializeAppBridge, 100);
        }
    </script>
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js" onload="initializeAppBridge()" onerror="console.error('Failed to load App Bridge script')"></script>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 0;
            background: #f6f6f7;
            color: #202223;
        }
        .app-container {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 240px;
            background: #ffffff;
            border-right: 1px solid #e1e3e5;
            padding: 24px 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        .sidebar h2 {
            font-size: 1rem;
            font-weight: 600;
            color: #202223;
            padding: 0 24px;
            margin: 0 0 16px 0;
        }
        .nav-section {
            margin-bottom: 24px;
        }
        .nav-item {
            display: block;
            padding: 8px 24px;
            color: #6d7175;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background 0.2s;
        }
        .nav-item:hover {
            background: #f6f6f7;
            color: #202223;
        }
        .nav-item.active {
            background: #e7f5f0;
            color: #008060;
            font-weight: 500;
            border-left: 3px solid #008060;
        }
        .nav-subitem {
            display: block;
            padding: 6px 24px 6px 48px;
            color: #6d7175;
            text-decoration: none;
            font-size: 0.875rem;
            transition: background 0.2s;
        }
        .nav-subitem:hover {
            background: #f6f6f7;
            color: #202223;
        }
        .nav-subitem.active {
            color: #008060;
            font-weight: 500;
        }
        .main-content {
            flex: 1;
            margin-left: 240px;
            padding: 24px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0;
        }
        .card {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 0 0 1px rgba(63, 63, 68, 0.1), 0 1px 3px 0 rgba(63, 63, 68, 0.15);
            padding: 24px;
            margin-bottom: 24px;
        }
        h1 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        .plan-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 0.9rem;
            font-weight: 500;
            margin-left: 8px;
        }
        .plan-badge.free {
            background: #e7f5f0;
            color: #008060;
        }
        .plan-badge.monthly {
            background: #e3f2fd;
            color: #1976d2;
        }
        .plan-badge.annual {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        .plan-badge.cancelled {
            background: #ffebee;
            color: #c62828;
        }
        .btn {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            border: none;
            font-size: 0.9rem;
        }
        .btn-primary {
            background: #008060;
            color: white;
        }
        .btn-primary:hover {
            background: #006e52;
        }
        .btn-danger {
            background: #d72c0d;
            color: white;
        }
        .btn-danger:hover {
            background: #bf2600;
        }
        .error {
            background: #ffebee;
            border-left: 3px solid #d72c0d;
            padding: 12px 16px;
            margin: 16px 0;
            border-radius: 4px;
            color: #c62828;
        }
        .success {
            background: #e7f5f0;
            border-left: 3px solid #008060;
            padding: 12px 16px;
            margin: 16px 0;
            border-radius: 4px;
            color: #004c3f;
        }
        .info-row {
            padding: 8px 0;
            border-bottom: 1px solid #e1e3e5;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .pricing {
            display: flex;
            gap: 16px;
            margin-top: 16px;
        }
        .pricing-option {
            flex: 1;
            border: 2px solid #e1e3e5;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
        }
        .pricing-option.selected {
            border-color: #008060;
        }
        .price {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 8px 0;
        }
    </style>
</head>
<body>
<div class="app-container">
    <div class="sidebar">
        <h2>Conversion Compass</h2>
        <div class="nav-section">
            <a href="/conversion.php?shop=<?= urlencode($shop) ?>" class="nav-item">Conversion Data</a>
            <a href="/about.php?shop=<?= urlencode($shop) ?>" class="nav-item">About</a>
            <a href="/subscription.php?shop=<?= urlencode($shop) ?>" class="nav-item active">Subscription</a>
        </div>
    </div>
    
<div class="main-content">
        <div class="container">
    <div class="card">
        <h1>
            Subscription Management
            <span class="plan-badge <?= htmlspecialchars($planStatus['plan_type'], ENT_QUOTES, 'UTF-8') ?> <?= $planStatus['plan_status'] === 'cancelled' ? 'cancelled' : '' ?>">
                <?= strtoupper($planStatus['plan_type']) ?>
                <?= $planStatus['plan_status'] === 'cancelled' ? ' (Cancelled)' : '' ?>
            </span>
        </h1>
        <p>Store: <strong><?= htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8') ?></strong></p>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (!empty($successMessage)): ?>
            <div class="success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="info-row">
            <strong>Current Plan:</strong> <?= ucfirst($planStatus['plan_type']) ?>
        </div>
        <div class="info-row">
            <strong>Status:</strong> <?= ucfirst($planStatus['plan_status']) ?>
        </div>
        <?php if ($planStatus['first_installed_at']): ?>
            <div class="info-row">
                <strong>Subscription Date:</strong> <?= date('F j, Y', strtotime($planStatus['first_installed_at'])) ?>
            </div>
        <?php endif; ?>
        <?php if ($planStatus['admin_granted_free']): ?>
            <div class="info-row">
                <strong>Note:</strong> Free access granted by admin
            </div>
        <?php endif; ?>
    </div>

    <?php if ($planStatus['plan_type'] === 'free' && $planStatus['plan_status'] === 'active'): ?>
        <div class="card">
            <h2>Upgrade Your Plan</h2>
            <p>Choose a subscription plan to unlock premium features:</p>
            
            <form method="POST" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create_charge">
                <div class="pricing">
                    <div class="pricing-option">
                        <h3>Monthly</h3>
                        <div class="price">$<?= number_format(MONTHLY_PRICE, 2) ?>/mo</div>
                        <button type="submit" name="plan_type" value="monthly" class="btn btn-primary">Subscribe Monthly</button>
                    </div>
                    <div class="pricing-option">
                        <h3>Annual</h3>
                        <div class="price">$<?= number_format(ANNUAL_PRICE, 2) ?>/yr</div>
                        <p style="font-size: 0.85rem; color: #6d7175;">Save <?= number_format((MONTHLY_PRICE * 12 - ANNUAL_PRICE) / (MONTHLY_PRICE * 12) * 100, 0) ?>%</p>
                        <button type="submit" name="plan_type" value="annual" class="btn btn-primary">Subscribe Annual</button>
                    </div>
                </div>
            </form>
        </div>
    <?php elseif ($planStatus['plan_type'] !== 'free' && $planStatus['plan_status'] === 'active'): ?>
        <div class="card">
            <h2>Manage Subscription</h2>
            <p>Your <?= ucfirst($planStatus['plan_type']) ?> subscription is active.</p>
            
            <?php
            $currentPrice = $planStatus['plan_type'] === 'annual' ? ANNUAL_PRICE : MONTHLY_PRICE;
            $otherPlanType = $planStatus['plan_type'] === 'annual' ? 'monthly' : 'annual';
            $otherPrice = $otherPlanType === 'annual' ? ANNUAL_PRICE : MONTHLY_PRICE;
            $isUpgrade = $planStatus['plan_type'] === 'monthly' && $otherPlanType === 'annual';
            $savingsPercent = $isUpgrade ? number_format((MONTHLY_PRICE * 12 - ANNUAL_PRICE) / (MONTHLY_PRICE * 12) * 100, 0) : 0;
            ?>
            
            <div style="margin: 24px 0; padding: 16px; background: #f6f6f7; border-radius: 8px;">
                <h3 style="margin-top: 0;">Change Plan</h3>
                <p>Switch to <?= ucfirst($otherPlanType) ?> billing:</p>
                
                <div style="display: flex; align-items: center; gap: 16px; margin: 16px 0;">
                    <div style="flex: 1;">
                        <strong><?= ucfirst($planStatus['plan_type']) ?> Plan</strong><br>
                        <span style="color: #6d7175;">$<?= number_format($currentPrice, 2) ?><?= $planStatus['plan_type'] === 'annual' ? '/yr' : '/mo' ?></span>
                    </div>
                    <div style="font-size: 1.5rem; color: #6d7175;">→</div>
                    <div style="flex: 1;">
                        <strong><?= ucfirst($otherPlanType) ?> Plan</strong><br>
                        <span style="color: #008060; font-weight: 600;">$<?= number_format($otherPrice, 2) ?><?= $otherPlanType === 'annual' ? '/yr' : '/mo' ?></span>
                        <?php if ($isUpgrade): ?>
                            <br><span style="color: #008060; font-size: 0.9rem;">Save <?= $savingsPercent ?>%</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <form method="POST" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm('Are you sure you want to change your plan? Your current subscription will be cancelled and you will need to confirm the new subscription.');" style="margin-top: 16px;">
                    <input type="hidden" name="action" value="change_plan">
                    <input type="hidden" name="plan_type" value="<?= htmlspecialchars($otherPlanType, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn-primary">Switch to <?= ucfirst($otherPlanType) ?> Plan</button>
                </form>
            </div>
            
            <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #e1e3e5;">
                <h3 style="margin-top: 0;">Cancel Subscription</h3>
                <p style="color: #6d7175; font-size: 0.9rem;">Cancel your subscription to stop future charges. You can resubscribe at any time.</p>
                <form method="POST" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm('Are you sure you want to cancel your subscription?');" style="margin-top: 16px;">
                    <input type="hidden" name="action" value="cancel_charge">
                    <button type="submit" class="btn btn-danger">Cancel Subscription</button>
                </form>
            </div>
        </div>
    <?php elseif ($planStatus['plan_status'] === 'cancelled'): ?>
        <div class="card">
            <h2>Subscription Cancelled</h2>
            <p>Your subscription has been cancelled. You can upgrade again at any time.</p>
            
            <form method="POST" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create_charge">
                <div class="pricing">
                    <div class="pricing-option">
                        <h3>Monthly</h3>
                        <div class="price">$<?= number_format(MONTHLY_PRICE, 2) ?>/mo</div>
                        <button type="submit" name="plan_type" value="monthly" class="btn btn-primary">Subscribe Monthly</button>
                    </div>
                    <div class="pricing-option">
                        <h3>Annual</h3>
                        <div class="price">$<?= number_format(ANNUAL_PRICE, 2) ?>/yr</div>
                        <button type="submit" name="plan_type" value="annual" class="btn btn-primary">Subscribe Annual</button>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>

        </div>
    </div>
</div>

<?php if (!empty($pendingConfirmationUrl)): ?>
<script>
    (function() {
        var confirmationUrl = <?= json_encode($pendingConfirmationUrl) ?>;
        var redirected = false;
        var attempts = 0;
        var maxAttempts = 20;

        function redirectWithAppBridge() {
            if (redirected) {
                return true;
            }

            try {
                if (window.shopify && window.shopify.app && typeof window.shopify.app.redirect === 'function') {
                    window.shopify.app.redirect(confirmationUrl);
                    redirected = true;
                    return true;
                }
            } catch (err) {
                console.warn('Shopify CDN redirect failed', err);
            }

            try {
                var appInstance = window.shopifyApp;
                var appBridgeGlobal = window['app-bridge'] || window.appBridge || null;
                if (appInstance && appBridgeGlobal && appBridgeGlobal.actions && appBridgeGlobal.actions.Redirect) {
                    var Redirect = appBridgeGlobal.actions.Redirect;
                    var redirect = Redirect.create(appInstance);
                    redirect.dispatch(Redirect.Action.REMOTE, confirmationUrl);
                    redirected = true;
                    return true;
                }
            } catch (err) {
                console.warn('App Bridge redirect action failed', err);
            }

            try {
                if (window.top && window.top !== window) {
                    window.top.location.href = confirmationUrl;
                } else {
                    window.location.href = confirmationUrl;
                }
                redirected = true;
                return true;
            } catch (err) {
                console.warn('Top-level redirect failed', err);
            }

            return false;
        }

        function ensureRedirect() {
            if (redirected) {
                return;
            }
            attempts++;
            if (!redirectWithAppBridge() && attempts < maxAttempts) {
                setTimeout(ensureRedirect, 250);
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', ensureRedirect);
        } else {
            ensureRedirect();
        }
    })();
</script>
<?php endif; ?>

<script>
    (function() {
        // Wait for App Bridge to initialize
        setTimeout(function() {
            var app = window.shopifyApp;
            if (!app) {
                return;
            }

            // Try to get TitleBar - CDN version might expose it differently
            var TitleBar = null;
            
            // Method 1: Check if actions are on window.shopify.actions
            if (window.shopify && window.shopify.actions && window.shopify.actions.TitleBar) {
                TitleBar = window.shopify.actions.TitleBar;
            }
            // Method 2: Check if TitleBar is directly on window.shopify
            else if (window.shopify && window.shopify.TitleBar) {
                TitleBar = window.shopify.TitleBar;
            }
            // Method 3: Check if actions are on the app instance
            else if (app.actions && app.actions.TitleBar) {
                TitleBar = app.actions.TitleBar;
            }
            
            if (TitleBar) {
                try {
                    TitleBar.create(app, { title: 'Subscription Management' });
                } catch (error) {
                    console.warn('Error creating TitleBar:', error);
                }
            } else {
                // With Shopify's CDN, TitleBar might be handled automatically by the admin
                console.log('TitleBar not found - Shopify Admin may handle it automatically');
            }
        }, 500);
    })();
</script>
</body>
</html>
