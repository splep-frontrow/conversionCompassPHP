<?php
// Variables expected: $shop, $shopName, $planStatus, $orderData, $statistics, $error, $startDate, $endDate, $dateRange
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Conversion Compass - Order Conversion Data</title>
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
        * {
            box-sizing: border-box;
        }
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
            max-width: 1400px;
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
            font-size: 1.75rem;
            margin: 0 0 24px 0;
            font-weight: 600;
        }
        h2 {
            font-size: 1.25rem;
            margin: 0 0 16px 0;
            font-weight: 600;
        }
        .date-range-selector {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .date-btn {
            padding: 8px 16px;
            border: none;
            background: #5B9BD5;
            color: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .date-btn:hover {
            background: #4A90E2;
            color: white;
            text-decoration: none;
        }
        .date-btn.active {
            background: #4A90E2;
            color: white;
            text-decoration: none;
        }
        .custom-dates {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        .custom-dates input[type="date"] {
            padding: 8px 12px;
            border: 1px solid #c9cccf;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .custom-dates button {
            padding: 8px 16px;
            background: #008060;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .custom-dates button:hover {
            background: #006e52;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #f6f6f7;
            border-radius: 8px;
            padding: 16px;
        }
        .stat-card h3 {
            margin: 0 0 8px 0;
            font-size: 0.875rem;
            color: #6d7175;
            font-weight: 500;
        }
        .stat-card .value {
            font-size: 1.75rem;
            font-weight: 600;
            color: #202223;
        }
        .referrer-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }
        .referrer-item {
            padding: 12px;
            background: #f6f6f7;
            border-radius: 4px;
            text-align: center;
        }
        .referrer-item .label {
            font-size: 0.875rem;
            color: #6d7175;
            margin-bottom: 4px;
        }
        .referrer-item .count {
            font-size: 1.25rem;
            font-weight: 600;
            color: #202223;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
        }
        th {
            text-align: left;
            padding: 12px 16px;
            border-bottom: 2px solid #e1e3e5;
            font-weight: 600;
            font-size: 0.875rem;
            color: #6d7175;
            background: #f6f6f7;
        }
        td {
            padding: 12px 16px;
            border-bottom: 1px solid #e1e3e5;
            font-size: 0.9rem;
        }
        tr:hover {
            background: #f6f6f7;
        }
        .order-link {
            color: #008060;
            text-decoration: none;
            font-weight: 500;
        }
        .order-link:hover {
            text-decoration: underline;
        }
        .error {
            background: #ffebee;
            border-left: 3px solid #d72c0d;
            padding: 12px 16px;
            margin-bottom: 24px;
            border-radius: 4px;
            color: #c62828;
        }
        .info {
            background: #e7f5f0;
            border-left: 3px solid #008060;
            padding: 12px 16px;
            margin-bottom: 24px;
            border-radius: 4px;
            color: #155724;
        }
        .loading {
            text-align: center;
            padding: 40px;
            color: #6d7175;
        }
        .export-btn {
            display: inline-block;
            padding: 8px 16px;
            background: #008060;
            color: white;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            margin-bottom: 16px;
        }
        .export-btn:hover {
            background: #006e52;
            color: white;
            text-decoration: none;
        }
        .export-btn:active {
            background: #005e46;
        }
    </style>
</head>
<body>
<div class="app-container">
    <div class="sidebar">
        <h2>Conversion Compass</h2>
        <div class="nav-section">
            <a href="/conversion.php?shop=<?= urlencode($shop) ?>" class="nav-item active">Conversion Data</a>
            <a href="/about.php?shop=<?= urlencode($shop) ?>" class="nav-item">About</a>
            <a href="/subscription.php?shop=<?= urlencode($shop) ?>" class="nav-item">Subscription</a>
        </div>
    </div>
    
    <div class="main-content">
        <div class="container">
    <div class="card">
        <h1>Order Conversion Data</h1>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        
        <div class="date-range-selector">
            <a href="?shop=<?= urlencode($shop) ?>&range=24h" class="date-btn <?= $dateRange === '24h' ? 'active' : '' ?>">Last 24 Hours</a>
            <a href="?shop=<?= urlencode($shop) ?>&range=7d" class="date-btn <?= $dateRange === '7d' ? 'active' : '' ?>">Last 7 Days</a>
            <a href="?shop=<?= urlencode($shop) ?>&range=14d" class="date-btn <?= $dateRange === '14d' ? 'active' : '' ?>">Last 2 Weeks</a>
            <a href="?shop=<?= urlencode($shop) ?>&range=30d" class="date-btn <?= $dateRange === '30d' ? 'active' : '' ?>">Last 30 Days</a>
        </div>
        
        <div class="custom-dates">
            <form method="GET" action="" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="shop" value="<?= htmlspecialchars($shop, ENT_QUOTES, 'UTF-8') ?>">
                <label>
                    Start Date:
                    <input type="date" name="start_date" value="<?= $startDate ? htmlspecialchars(date('Y-m-d', strtotime($startDate)), ENT_QUOTES, 'UTF-8') : '' ?>" required>
                </label>
                <label>
                    End Date:
                    <input type="date" name="end_date" value="<?= $endDate ? htmlspecialchars(date('Y-m-d', strtotime($endDate)), ENT_QUOTES, 'UTF-8') : '' ?>" required>
                </label>
                <button type="submit">Apply Date Range</button>
            </form>
        </div>
    </div>
    
    <?php if ($startDate && $endDate && empty($error)): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Orders</h3>
                <div class="value"><?= number_format($statistics['total_orders']) ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Revenue</h3>
                <div class="value"><?= number_format($statistics['total_revenue'], 2) ?></div>
            </div>
        </div>
        
        <div class="card">
            <h2>Referrer Summary</h2>
            <div class="referrer-summary">
                <div class="referrer-item">
                    <div class="label">Social Media</div>
                    <div class="count"><?= $statistics['referrer_summary']['Social Media'] ?></div>
                </div>
                <div class="referrer-item">
                    <div class="label">Direct Links</div>
                    <div class="count"><?= $statistics['referrer_summary']['Direct Links'] ?></div>
                </div>
                <div class="referrer-item">
                    <div class="label">Email</div>
                    <div class="count"><?= $statistics['referrer_summary']['Email'] ?></div>
                </div>
                <div class="referrer-item">
                    <div class="label">Other</div>
                    <div class="count"><?= $statistics['referrer_summary']['Other'] ?></div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h2>Order Details</h2>
            <?php if (empty($orderData)): ?>
                <div class="info">No orders found for the selected date range.</div>
            <?php else: ?>
                <?php
                // Build export URL with current query parameters
                $exportParams = [
                    'shop' => $shop,
                ];
                if ($dateRange) {
                    $exportParams['range'] = $dateRange;
                } else {
                    $exportParams['start_date'] = date('Y-m-d', strtotime($startDate));
                    $exportParams['end_date'] = date('Y-m-d', strtotime($endDate));
                }
                $exportUrl = '/export.php?' . http_build_query($exportParams);
                ?>
                <a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>" class="export-btn" target="_blank">
                    Export to CSV
                </a>
                <table>
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Campaign</th>
                            <th>Source</th>
                            <th>Medium</th>
                            <th>Referring Site</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orderData as $order): ?>
                            <tr>
                                <td>
                                    <a href="<?= htmlspecialchars($order['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="order-link">
                                        #<?= htmlspecialchars($order['number'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($order['date'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($order['total'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($order['campaign'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($order['source'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($order['medium'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($order['referring_site'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php elseif (!$startDate || !$endDate): ?>
        <div class="info">Please select a date range to view conversion data.</div>
    <?php endif; ?>
    </div>
    </div>
</div>

<script>
    (function() {
        // Wait a bit for App Bridge to initialize
        setTimeout(function() {
            var app = window.shopifyApp;
            if (!app) {
                console.warn('App Bridge app instance not found - checking if App Bridge is available...');
                console.log('window.app-bridge:', typeof window['app-bridge']);
                console.log('window.shopifyApp:', typeof window.shopifyApp);
                // Try to initialize if not already done
                if (typeof window['app-bridge'] !== 'undefined' && !window.shopifyApp) {
                    console.log('Attempting to initialize App Bridge...');
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
                    app = window.shopifyApp;
                    console.log('App Bridge initialized in fallback');
                }
                
                if (!app) {
                    return;
                }
            }

        var AppBridge = window['app-bridge'];
        if (!AppBridge) {
            console.warn('App Bridge not available');
            return;
        }

        var actions = AppBridge.actions;
        var TitleBar = actions.TitleBar;

        TitleBar.create(app, { title: 'Conversion Compass' });

        // Test session token functionality after page load
        setTimeout(function() {
            // Re-check app instance
            app = window.shopifyApp;
            if (!app) {
                console.error('App Bridge app instance still not found after delay');
                return;
            }
            var params = new URLSearchParams(window.location.search);
            var shop = params.get('shop');
            var host = params.get('host');
            
            console.log('=== APP BRIDGE DEBUG INFO ===');
            console.log('App Bridge Debug Info:', {
                appInitialized: !!app,
                shop: shop,
                host: host,
                hasHost: !!host,
                appBridgeVersion: AppBridge.version || 'unknown',
                appBridgeUtils: typeof AppBridge.utils !== 'undefined',
                windowLocation: window.location.href,
                windowLocationSearch: window.location.search
            });

            // Critical: Check if host parameter is present
            if (!host) {
                console.error('❌ CRITICAL: Host parameter is missing from URL!');
                console.error('This means the app is not being accessed through Shopify Admin.');
                console.error('Session tokens will NOT be sent without the host parameter.');
                console.error('Current URL:', window.location.href);
                console.error('Query string:', window.location.search);
                console.error('Expected URL format: ?shop=xxx.myshopify.com&host=base64encodedhost');
                return;
            }

            // Test fetching with App Bridge
            if (shop && host && typeof fetch !== 'undefined') {
                var testUrl = '/test-session-token-fetch.php?shop=' + encodeURIComponent(shop);
                
                // Try different ways to get session token depending on App Bridge version
                var getTokenPromise = null;
                
                // Method 1: Check if getSessionToken is available in utils
                if (AppBridge.utils && typeof AppBridge.utils.getSessionToken === 'function') {
                    console.log('Using AppBridge.utils.getSessionToken');
                    getTokenPromise = AppBridge.utils.getSessionToken(app);
                }
                // Method 2: Check if it's available directly on AppBridge
                else if (typeof AppBridge.getSessionToken === 'function') {
                    console.log('Using AppBridge.getSessionToken');
                    getTokenPromise = AppBridge.getSessionToken(app);
                }
                // Method 3: Check if fetch is wrapped by App Bridge
                else {
                    console.warn('getSessionToken not found. App Bridge CDN may handle tokens automatically.');
                    // Try a regular fetch - App Bridge CDN might intercept it
                    fetch(testUrl)
                        .then(function(response) { return response.json(); })
                        .then(function(data) {
                            console.log('Fetch test result (App Bridge may auto-inject tokens):', data);
                            if (data.success) {
                                console.log('✓ Session tokens are working!');
                            } else {
                                console.warn('✗ Session token test failed:', data.message);
                            }
                        })
                        .catch(function(error) {
                            console.error('Fetch test error:', error);
                        });
                    return;
                }

                if (getTokenPromise) {
                    getTokenPromise.then(function(token) {
                        console.log('Session token retrieved:', token ? 'Yes (' + token.length + ' chars)' : 'No');
                        
                        if (!token) {
                            console.error('❌ Failed to retrieve session token from App Bridge');
                            return;
                        }
                        
                        // Make a test request with the session token
                        fetch(testUrl, {
                            headers: {
                                'X-Shopify-Session-Token': token
                            }
                        }).then(function(response) {
                            return response.json();
                        }).then(function(data) {
                            console.log('=== SESSION TOKEN TEST RESULT ===');
                            console.log('Session token test result:', data);
                            if (data.success) {
                                console.log('✓✓✓ Session tokens are working correctly! ✓✓✓');
                            } else {
                                console.warn('✗ Session token test failed:', data.message);
                            }
                        }).catch(function(error) {
                            console.error('Session token test error:', error);
                        });
                    }).catch(function(error) {
                        console.error('Failed to get session token:', error);
                    });
                }
            } else {
                console.warn('Cannot test session tokens - missing shop or host parameter');
            }
        }, 2000); // Increased delay to ensure App Bridge is loaded
        }, 500); // Initial delay to let App Bridge initialize
    })();
</script>
</body>
</html>

