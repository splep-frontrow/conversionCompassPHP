<?php
// Variables expected: $shop, $shopName, $shopEmail, $planStatus
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Conversion Compass</title>
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
                
                // Shopify CDN exposes initializeAppBridge function
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
                        })
                        .catch(function(error) {
                            console.error('✗ Error initializing App Bridge:', error);
                        });
                } else if (attempts < maxAttempts) {
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
        .app {
            max-width: 640px;
            margin: 24px auto;
            padding: 24px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 0 0 1px rgba(63, 63, 68, 0.1), 0 1px 3px 0 rgba(63, 63, 68, 0.15);
        }
        h1 {
            font-size: 1.4rem;
            margin-bottom: 0.5rem;
        }
        p {
            margin: 0.2rem 0;
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
        .nav-links {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            flex-wrap: wrap;
        }
        .nav-link {
            display: inline-block;
            padding: 8px 16px;
            background: #008060;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.9rem;
            transition: background 0.2s;
        }
        .nav-link:hover {
            background: #006e52;
        }
        .nav-link.secondary {
            background: #ffffff;
            color: #008060;
            border: 1px solid #008060;
        }
        .nav-link.secondary:hover {
            background: #f6f6f7;
        }
    </style>
</head>
<body>
<div class="app">
    <h1>Conversion Compass</h1>
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

    <div class="nav-links">
        <a href="/conversion.php?shop=<?= urlencode($shop) ?>" class="nav-link">View Conversion Data</a>
        <a href="/subscription.php?shop=<?= urlencode($shop) ?>" class="nav-link secondary">Manage Subscription</a>
    </div>

    <p class="meta" style="margin-top: 24px;">
        Get started by clicking "View Conversion Data" to see your order conversion statistics with UTM parameters, referrer data, and traffic source categorization.
    </p>
</div>

<script>
    (function() {
        // Wait for App Bridge to initialize
        setTimeout(function() {
            var app = window.shopifyApp;
            if (!app) {
                console.warn('App Bridge app instance not found');
                return;
            }

            // Get actions from app instance (CDN version)
            if (!app.actions) {
                console.warn('App Bridge actions not available');
                return;
            }

            var TitleBar = app.actions.TitleBar;
            if (!TitleBar) {
                console.warn('TitleBar action not available');
                return;
            }

            TitleBar.create(app, { title: 'Conversion Compass' });

            // Test session token functionality after page load
            // This helps verify App Bridge is sending session tokens
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
                    windowLocation: window.location.href
                });

            // Critical: Check if host parameter is present
            if (!host) {
                console.error('❌ CRITICAL: Host parameter is missing from URL!');
                console.error('This means the app is not being accessed through Shopify Admin.');
                console.error('Session tokens will NOT be sent without the host parameter.');
                console.error('Current URL:', window.location.href);
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
                            console.log('Session token test result:', data);
                            if (data.success) {
                                console.log('✓ Session tokens are working correctly!');
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
            }, 1000);
        }, 500); // Initial delay to let App Bridge initialize
    })();
</script>
</body>
</html>
