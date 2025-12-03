<?php
// Variables expected: $shop, $shopName, $shopEmail, $planStatus
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Conversion Compass - About</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Shopify App Bridge -->
    <meta name="shopify-api-key" content="<?= htmlspecialchars(SHOPIFY_API_KEY, ENT_QUOTES, 'UTF-8') ?>" />
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js" onload="initializeAppBridge()"></script>
    <script>
        // Initialize App Bridge when script loads
        function initializeAppBridge() {
            // Wait for App Bridge to be available (it may load asynchronously)
            var attempts = 0;
            var maxAttempts = 50; // 5 seconds max wait
            
            function tryInit() {
                attempts++;
                
                if (typeof window['app-bridge'] !== 'undefined') {
                    var AppBridge = window['app-bridge'];
                    var params = new URLSearchParams(window.location.search);
                    var shop = params.get('shop');
                    var host = params.get('host');
                    
                    console.log('Initializing App Bridge:', { shop: shop, host: host ? 'present' : 'missing' });
                    
                    var appConfig = {
                        apiKey: "<?= htmlspecialchars(SHOPIFY_API_KEY, ENT_QUOTES, 'UTF-8') ?>",
                        shopOrigin: shop
                    };
                    
                    if (host) {
                        appConfig.host = host;
                    }
                    
                    window.shopifyApp = AppBridge.createApp(appConfig);
                    console.log('App Bridge initialized successfully');
                } else if (attempts < maxAttempts) {
                    setTimeout(tryInit, 100);
                } else {
                    console.error('App Bridge failed to load after', maxAttempts * 100, 'ms');
                }
            }
            
            tryInit();
        }
        
        // Also try on DOMContentLoaded as fallback
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeAppBridge);
        } else {
            initializeAppBridge();
        }
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
        .app {
            max-width: 800px;
            margin: 0 auto;
            padding: 24px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 0 0 1px rgba(63, 63, 68, 0.1), 0 1px 3px 0 rgba(63, 63, 68, 0.15);
        }
        h1 {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }
        p {
            margin: 0.2rem 0;
            line-height: 1.6;
        }
        .meta {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e1e3e5;
            font-size: 0.9rem;
            color: #6d7175;
        }
        code {
            background: #f4f6f8;
            padding: 2px 4px;
            border-radius: 3px;
            font-size: 0.85rem;
        }
        .plan-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
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
        .plan-badge.expired {
            background: #f5f5f5;
            color: #6d7175;
        }
        .warning {
            background: #fff4e5;
            border-left: 3px solid #f57c00;
            padding: 12px 16px;
            margin: 16px 0;
            border-radius: 4px;
            color: #e65100;
        }
        ul {
            line-height: 1.8;
            margin: 16px 0;
            padding-left: 24px;
        }
        li {
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
            <a href="/about.php?shop=<?= urlencode($shop) ?>" class="nav-item active">About</a>
            <a href="/subscription.php?shop=<?= urlencode($shop) ?>" class="nav-item">Subscription</a>
        </div>
    </div>
    
    <div class="main-content">
        <div class="app">
            <h1>About Conversion Compass</h1>
            <p>✓ <strong>Welcome to Conversion Compass!</strong> Track and analyze your order conversion data with detailed UTM parameter insights.</p>

            <div class="meta">
                <p><strong>Store:</strong> <?= htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8') ?></p>
                <p><strong>Plan:</strong> 
                    <span class="plan-badge <?= htmlspecialchars($planStatus['plan_type'] ?? 'free', ENT_QUOTES, 'UTF-8') ?> <?= ($planStatus['plan_status'] ?? 'active') === 'cancelled' ? 'cancelled' : '' ?> <?= ($planStatus['plan_status'] ?? 'active') === 'expired' ? 'expired' : '' ?>">
                        <?= strtoupper($planStatus['plan_type'] ?? 'free') ?>
                        <?= ($planStatus['plan_status'] ?? 'active') === 'cancelled' ? ' (Cancelled)' : '' ?>
                        <?= ($planStatus['plan_status'] ?? 'active') === 'expired' ? ' (Expired)' : '' ?>
                    </span>
                </p>
            </div>

            <?php if (($planStatus['plan_status'] ?? 'active') === 'cancelled' || ($planStatus['plan_status'] ?? 'active') === 'expired'): ?>
                <div class="warning">
                    <strong>⚠️ Subscription Issue:</strong> Your subscription is <?= htmlspecialchars($planStatus['plan_status'] ?? 'active', ENT_QUOTES, 'UTF-8') ?>. 
                    Please update your subscription to continue using the app.
                </div>
            <?php endif; ?>

            <div style="margin-top: 24px;">
                <h2>What is Conversion Compass?</h2>
                <p>Conversion Compass is a powerful utility app that helps Shopify store owners track and analyze their order conversion data. With Conversion Compass, you can:</p>
                <ul>
                    <li>View detailed conversion statistics for your orders within customizable date ranges</li>
                    <li>Track UTM parameters (campaign, source, medium, content) associated with each order</li>
                    <li>Analyze referrer data to understand where your customers are coming from</li>
                    <li>Get insights into your marketing campaign performance</li>
                    <li>Categorize traffic sources (Social Media, Direct Links, Email, Other) for better reporting</li>
                    <li>Export your conversion data to CSV for further analysis</li>
                </ul>
                <p>Whether you're running paid ads, email campaigns, or social media marketing, Conversion Compass gives you the insights you need to optimize your conversion strategy and grow your business.</p>
            </div>

            <div style="margin-top: 32px;">
                <h2>Getting Started</h2>
                <p>Get started by clicking <strong>"Conversion Data"</strong> in the sidebar to see your order conversion statistics with UTM parameters, referrer data, and traffic source categorization.</p>
            </div>

            <div style="margin-top: 32px;">
                <h2>Support</h2>
                <p>Need help? Contact our support team:</p>
                <p><a href="mailto:support@shopconversionhistory.com" style="color: #008060; text-decoration: none; font-weight: 500;">support@shopconversionhistory.com</a></p>
            </div>
        </div>
    </div>
</div>

<script>
    (function() {
        var app = window.shopifyApp;
        if (!app) {
            console.warn('App Bridge app instance not found');
            return;
        }

        var AppBridge = window['app-bridge'];
        if (!AppBridge) {
            console.warn('App Bridge not available');
            return;
        }

        var actions = AppBridge.actions;
        var TitleBar = actions.TitleBar;

        TitleBar.create(app, { title: 'Conversion Compass - About' });

        // Debug session token functionality
        setTimeout(function() {
            var params = new URLSearchParams(window.location.search);
            var shop = params.get('shop');
            var host = params.get('host');
            
            console.log('=== APP BRIDGE DEBUG INFO (About Page) ===');
            console.log({
                appInitialized: !!app,
                shop: shop,
                host: host,
                hasHost: !!host,
                windowLocation: window.location.href
            });

            if (!host) {
                console.error('❌ CRITICAL: Host parameter is missing from URL!');
            }
        }, 1000);
    })();
</script>
</body>
</html>

