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

// Handle charge creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_charge') {
        $planType = $_POST['plan_type'] ?? '';
        $amount = $planType === 'annual' ? ANNUAL_PRICE : MONTHLY_PRICE;
        
        $response = ShopifyClient::createRecurringCharge($shop, $accessToken, $amount, $planType);
        
        if ($response['status'] === 201 && isset($response['body']['recurring_application_charge']['confirmation_url'])) {
            // Redirect to Shopify charge confirmation
            header('Location: ' . $response['body']['recurring_application_charge']['confirmation_url']);
            exit;
        } else {
            $error = 'Failed to create charge. Please try again.';
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
    }
}

// Fetch shop info from Shopify
$response = ShopifyClient::apiRequest($shop, $accessToken, '/admin/api/2024-10/shop.json', 'GET');
$shopInfo = $response['status'] === 200 ? ($response['body']['shop'] ?? []) : [];
$shopName = $shopInfo['name'] ?? $shop;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subscription Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Shopify App Bridge -->
    <meta name="shopify-api-key" content="<?= htmlspecialchars(SHOPIFY_API_KEY, ENT_QUOTES, 'UTF-8') ?>" />
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <script>
        // Initialize App Bridge immediately when script loads
        (function() {
            if (typeof window['app-bridge'] === 'undefined') {
                return;
            }
            var AppBridge = window['app-bridge'];
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
            
            window.shopifyApp = AppBridge.createApp(appConfig);
        })();
    </script>
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
            
            <form method="POST" action="?shop=<?= urlencode($shop) ?>">
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
            
            <form method="POST" action="?shop=<?= urlencode($shop) ?>" onsubmit="return confirm('Are you sure you want to cancel your subscription?');">
                <input type="hidden" name="action" value="cancel_charge">
                <button type="submit" class="btn btn-danger">Cancel Subscription</button>
            </form>
        </div>
    <?php elseif ($planStatus['plan_status'] === 'cancelled'): ?>
        <div class="card">
            <h2>Subscription Cancelled</h2>
            <p>Your subscription has been cancelled. You can upgrade again at any time.</p>
            
            <form method="POST" action="?shop=<?= urlencode($shop) ?>">
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

<script>
    (function() {
        var app = window.shopifyApp;
        if (!app) {
            return;
        }

        var AppBridge = window['app-bridge'];
        if (!AppBridge) {
            return;
        }

        var actions = AppBridge.actions;
        var TitleBar = actions.TitleBar;

        TitleBar.create(app, { title: 'Subscription Management' });
    })();
</script>
</body>
</html>

