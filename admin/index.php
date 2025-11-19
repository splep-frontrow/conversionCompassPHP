<?php
declare(strict_types=1);

session_start();

// Check if user is already authenticated
if (isset($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true) {
    // Already logged in, redirect to dashboard
    header('Location: /admin/dashboard.php');
    exit;
}

// Not authenticated, redirect to login page
header('Location: /admin/login.php');
exit;

