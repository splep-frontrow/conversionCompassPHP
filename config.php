<?php
// Copy this file to config.local.php and edit values there.
// config.local.php is gitignored in most setups.
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
    return;
}

// Fallback defaults for development. DO NOT use these in production.
define('SHOPIFY_API_KEY',        'your_api_key_here');
define('SHOPIFY_API_SECRET',     'your_api_secret_here');
define('SHOPIFY_SCOPES',         'write_application_charges read_application_charges read_orders'); // Billing and order conversion data access
define('SHOPIFY_REDIRECT_URI',   'https://yourdomain.com/auth/callback.php');

// Database (MySQL) connection
define('DB_DSN',  'mysql:host=localhost;dbname=shopify_app;charset=utf8mb4');
define('DB_USER', 'shopify_user');
define('DB_PASS', 'shopify_password');

// Admin dashboard
define('ADMIN_PASSWORD', 'change_this_password');

// Subscription pricing
define('MONTHLY_PRICE', 29.00);
define('ANNUAL_PRICE', 290.00);

// Webhook secret (set in Shopify Partners dashboard)
define('SHOPIFY_WEBHOOK_SECRET', 'your_webhook_secret_here');
