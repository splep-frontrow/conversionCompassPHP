<?php
// Rename this file to config.local.php and fill in your real values.

define('SHOPIFY_API_KEY',        'YOUR_REAL_API_KEY');
define('SHOPIFY_API_SECRET',     'YOUR_REAL_API_SECRET');
define('SHOPIFY_SCOPES',         'write_application_charges read_application_charges read_orders'); // Billing and order conversion data access
define('SHOPIFY_REDIRECT_URI',   'https://yourdomain.com/auth/callback.php');

// Database (MySQL) connection
define('DB_DSN',  'mysql:host=localhost;dbname=shopify_app;charset=utf8mb4');
define('DB_USER', 'shopify_user');
define('DB_PASS', 'shopify_password');

// Admin dashboard
define('ADMIN_PASSWORD', 'YOUR_SECURE_ADMIN_PASSWORD');

// Subscription pricing
define('MONTHLY_PRICE', 29.00);
define('ANNUAL_PRICE', 290.00);

// Webhook secret (set in Shopify Partners dashboard)
define('SHOPIFY_WEBHOOK_SECRET', 'YOUR_WEBHOOK_SECRET');
