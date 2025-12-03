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
    <script>
        // Define initialization function BEFORE loading the script
        function initializeAppBridge() {
            console.log('=== APP BRIDGE INITIALIZATION STARTED ===');
            console.log('Script onload fired');
            console.log('window.shopify:', typeof window.shopify);
            
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
                    console.log('App instance:', window.shopifyApp);
                    return;
                }
                
                // Try initializeAppBridge function if available
                if (window.shopify && typeof window.shopify.initializeAppBridge === 'function') {
                    var params = new URLSearchParams(window.location.search);
                    var shop = params.get('shop');
                    var host = params.get('host');
                    
                    console.log('=== INITIALIZING APP BRIDGE ===');
                    console.log('Shop:', shop);
                    console.log('Host:', host ? 'present (' + host.substring(0, 20) + '...)' : 'MISSING');
                    
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
                            console.log('App instance:', window.shopifyApp);
                        })
                        .catch(function(error) {
                            console.error('✗ Error initializing App Bridge:', error);
                            console.error('Error message:', error.message);
                            console.error('Error stack:', error.stack);
                        });
                    return;
                }
                
                // Keep trying if not found yet
                if (attempts < maxAttempts) {
                    setTimeout(tryInit, 100);
                } else {
                    console.error('✗ App Bridge failed to load after', maxAttempts * 100, 'ms');
                    console.error('window.shopify:', window.shopify);
                    console.error('window.shopify.app:', window.shopify ? window.shopify.app : 'N/A');
                    console.error('window.shopify.initializeAppBridge:', window.shopify ? typeof window.shopify.initializeAppBridge : 'N/A');
                    console.error('Check Network tab to see if https://cdn.shopify.com/shopifycloud/app-bridge.js loaded successfully');
                }
            }
            
            tryInit();
        }
        
        // Also try on DOMContentLoaded as fallback
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                console.log('DOMContentLoaded fired');
                setTimeout(initializeAppBridge, 100);
            });
        } else {
            // Try immediately
            setTimeout(initializeAppBridge, 100);
        }
    </script>
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js" onload="console.log('App Bridge script onload fired'); initializeAppBridge();" onerror="console.error('✗ Failed to load App Bridge script - check Network tab')"></script>
    
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
        function initTitleBar() {
            var app = window.shopifyApp;
            if (!app) {
                console.warn('App Bridge app instance not found - waiting for initialization...');
                // Wait a bit more and try again
                setTimeout(initTitleBar, 500);
                return;
            }

            // Try to get TitleBar - CDN version might expose it differently
            var TitleBar = null;
            
            // Method 1: Check if actions are on window.shopify.actions
            if (window.shopify && window.shopify.actions && window.shopify.actions.TitleBar) {
                TitleBar = window.shopify.actions.TitleBar;
                console.log('Found TitleBar at window.shopify.actions.TitleBar');
            }
            // Method 2: Check if TitleBar is directly on window.shopify
            else if (window.shopify && window.shopify.TitleBar) {
                TitleBar = window.shopify.TitleBar;
                console.log('Found TitleBar directly on window.shopify');
            }
            // Method 3: Check if actions are on the app instance
            else if (app.actions && app.actions.TitleBar) {
                TitleBar = app.actions.TitleBar;
                console.log('Found TitleBar on app.actions');
            }
            
            if (TitleBar) {
                try {
                    TitleBar.create(app, { title: 'Conversion Compass' });
                    console.log('✓ TitleBar created successfully');
                } catch (error) {
                    console.warn('Error creating TitleBar:', error);
                }
            } else {
                // With Shopify's CDN, TitleBar might be handled automatically by the admin
                // This is not an error - the title bar is often managed by Shopify Admin
                console.log('TitleBar not found - Shopify Admin may handle it automatically');
                console.log('Available on window.shopify:', window.shopify ? Object.keys(window.shopify) : 'N/A');
            }

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
                    appBridgeVersion: app.version || 'unknown',
                    appBridgeUtils: typeof app.utils !== 'undefined',
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
                
                // Method 1: Check if getSessionToken is available in app.utils (CDN version)
                if (app.utils && typeof app.utils.getSessionToken === 'function') {
                    console.log('Using app.utils.getSessionToken');
                    getTokenPromise = app.utils.getSessionToken();
                }
                // Method 2: Check if it's available in window.shopify
                else if (window.shopify && window.shopify.utils && typeof window.shopify.utils.getSessionToken === 'function') {
                    console.log('Using window.shopify.utils.getSessionToken');
                    getTokenPromise = window.shopify.utils.getSessionToken();
                }
                // Method 3: Check if fetch is wrapped by App Bridge CDN (it should auto-inject tokens)
                else {
                    console.warn('getSessionToken not found. App Bridge CDN should handle tokens automatically.');
                    // Try a regular fetch - App Bridge CDN should intercept it and add session token
                    fetch(testUrl)
                        .then(function(response) { return response.json(); })
                        .then(function(data) {
                            console.log('Fetch test result (App Bridge CDN should auto-inject tokens):', data);
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
        }
        
        setTimeout(initTitleBar, 500); // Initial delay to let App Bridge initialize
    })();
</script>
</body>
</html>

