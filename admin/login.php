<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';

// Handle logout
if (isset($_GET['logout'])) {
    $_SESSION['admin_authenticated'] = false;
    unset($_SESSION['admin_authenticated']);
    session_destroy();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    
    if ($password === ADMIN_PASSWORD) {
        $_SESSION['admin_authenticated'] = true;
        header('Location: /admin/dashboard.php');
        exit;
    } else {
        $error = 'Invalid password';
    }
}

// If already authenticated, redirect to dashboard
if (isset($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true) {
    header('Location: /admin/dashboard.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 0;
            background: #f6f6f7;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .login-container {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 0 0 1px rgba(63, 63, 68, 0.1), 0 1px 3px 0 rgba(63, 63, 68, 0.15);
            padding: 32px;
            width: 100%;
            max-width: 400px;
        }
        h1 {
            font-size: 1.5rem;
            margin-bottom: 24px;
            text-align: center;
        }
        .form-group {
            margin-bottom: 16px;
        }
        label {
            display: block;
            margin-bottom: 4px;
            font-weight: 500;
            color: #202223;
        }
        input[type="password"] {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #c9cccf;
            border-radius: 4px;
            font-size: 1rem;
            box-sizing: border-box;
        }
        input[type="password"]:focus {
            outline: none;
            border-color: #008060;
            box-shadow: 0 0 0 1px #008060;
        }
        .btn {
            width: 100%;
            padding: 10px 16px;
            background: #008060;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
        }
        .btn:hover {
            background: #006e52;
        }
        .error {
            background: #ffebee;
            border-left: 3px solid #d72c0d;
            padding: 12px 16px;
            margin-bottom: 16px;
            border-radius: 4px;
            color: #c62828;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Admin Login</h1>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autofocus>
            </div>
            <button type="submit" class="btn">Login</button>
        </form>
    </div>
</body>
</html>

