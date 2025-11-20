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
    <script src="https://unpkg.com/@shopify/app-bridge@3"></script>
    <script src="https://unpkg.com/@shopify/app-bridge-utils@3"></script>

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
        var AppBridge = window['app-bridge'];
        if (!AppBridge) {
            return;
        }

        var createApp = AppBridge.createApp;
        var actions = AppBridge.actions;
        var TitleBar = actions.TitleBar;

        var params = new URLSearchParams(window.location.search);
        var shop = params.get('shop');

        var app = createApp({
            apiKey: "<?= htmlspecialchars(SHOPIFY_API_KEY, ENT_QUOTES, 'UTF-8') ?>",
            shopOrigin: shop
        });

        TitleBar.create(app, { title: 'Conversion Compass' });
    })();
</script>
</body>
</html>
