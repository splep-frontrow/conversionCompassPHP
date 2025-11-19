<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/ShopifyClient.php';

// Check admin authentication
if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
    header('Location: /admin/login.php');
    exit;
}

$db = get_db();

// Check if migration has been run by checking if plan_type column exists
$migrationError = null;
try {
    $checkStmt = $db->query("SHOW COLUMNS FROM shops LIKE 'plan_type'");
    $columnExists = $checkStmt->rowCount() > 0;
    
    if (!$columnExists) {
        $migrationError = "Database migration not run. Please run migrations_subscriptions.sql on your database.";
    }
} catch (PDOException $e) {
    $migrationError = "Database error: " . $e->getMessage();
}

// Get filter parameters
$filterPlan = $_GET['plan'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

$shops = [];
$stats = [
    'total_installs' => 0,
    'free_plans' => 0,
    'monthly_plans' => 0,
    'annual_plans' => 0,
    'active_plans' => 0,
];

if (!$migrationError) {
    try {
        // Build query - check if columns exist first
        $where = [];
        $params = [];

        if ($filterPlan) {
            $where[] = 'plan_type = :plan';
            $params['plan'] = $filterPlan;
        }

        if ($filterStatus) {
            $where[] = 'plan_status = :status';
            $params['status'] = $filterStatus;
        }

        if ($search) {
            $where[] = 'shop_domain LIKE :search';
            $params['search'] = '%' . $search . '%';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get all shops - use COALESCE for columns that might be NULL
        $query = "
            SELECT id, shop_domain, 
                   COALESCE(plan_type, 'free') as plan_type,
                   COALESCE(plan_status, 'active') as plan_status,
                   billing_charge_id,
                   COALESCE(first_installed_at, installed_at) as first_installed_at,
                   COALESCE(last_reinstalled_at, installed_at) as last_reinstalled_at,
                   last_used_at,
                   COALESCE(admin_granted_free, 0) as admin_granted_free
            FROM shops
            $whereClause
            ORDER BY COALESCE(first_installed_at, installed_at) DESC
        ";
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $shops = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get summary statistics
        $statsQuery = "
            SELECT 
                COUNT(*) as total_installs,
                SUM(CASE WHEN COALESCE(plan_type, 'free') = 'free' THEN 1 ELSE 0 END) as free_plans,
                SUM(CASE WHEN plan_type = 'monthly' THEN 1 ELSE 0 END) as monthly_plans,
                SUM(CASE WHEN plan_type = 'annual' THEN 1 ELSE 0 END) as annual_plans,
                SUM(CASE WHEN COALESCE(plan_status, 'active') = 'active' THEN 1 ELSE 0 END) as active_plans
            FROM shops
        ";
        $statsStmt = $db->query($statsQuery);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $migrationError = "Database query error: " . $e->getMessage();
    }
}

// Fetch shop names from Shopify API (cache in session for performance)
foreach ($shops as &$shop) {
    try {
        $tokenStmt = $db->prepare('SELECT access_token FROM shops WHERE shop_domain = :shop LIMIT 1');
        $tokenStmt->execute(['shop' => $shop['shop_domain']]);
        $tokenRow = $tokenStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tokenRow) {
            $response = ShopifyClient::apiRequest($shop['shop_domain'], $tokenRow['access_token'], '/admin/api/2024-01/shop.json', 'GET');
            if ($response['status'] === 200 && isset($response['body']['shop']['name'])) {
                $shop['store_name'] = $response['body']['shop']['name'];
            } else {
                $shop['store_name'] = 'N/A';
            }
        } else {
            $shop['store_name'] = 'N/A';
        }
    } catch (Exception $e) {
        $shop['store_name'] = 'Error';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 0;
            background: #f6f6f7;
            color: #202223;
        }
        .header {
            background: #ffffff;
            border-bottom: 1px solid #e1e3e5;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            margin: 0;
            font-size: 1.5rem;
        }
        .container {
            max-width: 1400px;
            margin: 24px auto;
            padding: 0 24px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 0 0 1px rgba(63, 63, 68, 0.1), 0 1px 3px 0 rgba(63, 63, 68, 0.15);
            padding: 20px;
        }
        .stat-card h3 {
            margin: 0 0 8px 0;
            font-size: 0.9rem;
            color: #6d7175;
            font-weight: 500;
        }
        .stat-card .value {
            font-size: 2rem;
            font-weight: 600;
            color: #202223;
        }
        .filters {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 0 0 1px rgba(63, 63, 68, 0.1), 0 1px 3px 0 rgba(63, 63, 68, 0.15);
            padding: 16px;
            margin-bottom: 24px;
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        .filters select, .filters input {
            padding: 8px 12px;
            border: 1px solid #c9cccf;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .filters button {
            padding: 8px 16px;
            background: #008060;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        table {
            width: 100%;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 0 0 1px rgba(63, 63, 68, 0.1), 0 1px 3px 0 rgba(63, 63, 68, 0.15);
            border-collapse: collapse;
        }
        th {
            text-align: left;
            padding: 12px 16px;
            border-bottom: 2px solid #e1e3e5;
            font-weight: 600;
            font-size: 0.9rem;
            color: #6d7175;
        }
        td {
            padding: 12px 16px;
            border-bottom: 1px solid #e1e3e5;
        }
        tr:hover {
            background: #f6f6f7;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .badge.free { background: #e7f5f0; color: #008060; }
        .badge.monthly { background: #e3f2fd; color: #1976d2; }
        .badge.annual { background: #f3e5f5; color: #7b1fa2; }
        .badge.active { background: #e7f5f0; color: #008060; }
        .badge.cancelled { background: #ffebee; color: #c62828; }
        .badge.expired { background: #f5f5f5; color: #6d7175; }
        .btn {
            padding: 4px 8px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 0.85rem;
            margin-right: 4px;
        }
        .btn-grant {
            background: #008060;
            color: white;
        }
        .btn-revoke {
            background: #6d7175;
            color: white;
        }
        .logout {
            color: #6d7175;
            text-decoration: none;
        }
        .logout:hover {
            color: #202223;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Admin Dashboard</h1>
        <a href="/admin/login.php?logout=1" class="logout">Logout</a>
    </div>
    
    <div class="container">
        <?php if ($migrationError): ?>
            <div style="background: #ffebee; border-left: 3px solid #d72c0d; padding: 16px; margin-bottom: 24px; border-radius: 4px; color: #c62828;">
                <strong>Error:</strong> <?= htmlspecialchars($migrationError, ENT_QUOTES, 'UTF-8') ?>
                <br><br>
                <strong>To fix this:</strong>
                <ol style="margin: 8px 0; padding-left: 20px;">
                    <li>Connect to your database</li>
                    <li>Run the SQL file: <code>migrations_subscriptions.sql</code></li>
                    <li>Refresh this page</li>
                </ol>
            </div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-card">
                <h3>Total Installs</h3>
                <div class="value"><?= htmlspecialchars($stats['total_installs'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="stat-card">
                <h3>Active Plans</h3>
                <div class="value"><?= htmlspecialchars($stats['active_plans'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="stat-card">
                <h3>Free Plans</h3>
                <div class="value"><?= htmlspecialchars($stats['free_plans'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="stat-card">
                <h3>Monthly Plans</h3>
                <div class="value"><?= htmlspecialchars($stats['monthly_plans'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="stat-card">
                <h3>Annual Plans</h3>
                <div class="value"><?= htmlspecialchars($stats['annual_plans'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

        <div class="filters">
            <form method="GET" action="" style="display: flex; gap: 16px; flex-wrap: wrap; width: 100%;">
                <select name="plan">
                    <option value="">All Plans</option>
                    <option value="free" <?= $filterPlan === 'free' ? 'selected' : '' ?>>Free</option>
                    <option value="monthly" <?= $filterPlan === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    <option value="annual" <?= $filterPlan === 'annual' ? 'selected' : '' ?>>Annual</option>
                </select>
                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    <option value="expired" <?= $filterStatus === 'expired' ? 'selected' : '' ?>>Expired</option>
                </select>
                <input type="text" name="search" placeholder="Search shop domain..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit">Filter</button>
                <a href="/admin/dashboard.php" style="padding: 8px 16px; background: #6d7175; color: white; text-decoration: none; border-radius: 4px;">Clear</a>
            </form>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Shop Domain</th>
                    <th>Store Name</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th>First Installed</th>
                    <th>Last Reinstalled</th>
                    <th>Last Used</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($shops)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 32px; color: #6d7175;">
                            No shops found. <?= $migrationError ? 'Please run the database migration first.' : 'Install the app on a store to see it here.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($shops as $shop): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($shop['shop_domain'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><?= htmlspecialchars($shop['store_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="badge <?= htmlspecialchars($shop['plan_type'] ?? 'free', ENT_QUOTES, 'UTF-8') ?>"><?= ucfirst($shop['plan_type'] ?? 'free') ?></span></td>
                            <td><span class="badge <?= htmlspecialchars($shop['plan_status'] ?? 'active', ENT_QUOTES, 'UTF-8') ?>"><?= ucfirst($shop['plan_status'] ?? 'active') ?></span></td>
                            <td><?= $shop['first_installed_at'] ? date('Y-m-d H:i', strtotime($shop['first_installed_at'])) : 'N/A' ?></td>
                            <td><?= $shop['last_reinstalled_at'] ? date('Y-m-d H:i', strtotime($shop['last_reinstalled_at'])) : 'N/A' ?></td>
                            <td><?= $shop['last_used_at'] ? date('Y-m-d', strtotime($shop['last_used_at'])) : 'Never' ?></td>
                            <td>
                                <?php if (!($shop['admin_granted_free'] ?? false)): ?>
                                    <button class="btn btn-grant" onclick="grantFree('<?= htmlspecialchars($shop['shop_domain'], ENT_QUOTES, 'UTF-8') ?>')">Grant Free</button>
                                <?php else: ?>
                                    <button class="btn btn-revoke" onclick="revokeFree('<?= htmlspecialchars($shop['shop_domain'], ENT_QUOTES, 'UTF-8') ?>')">Revoke Free</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
        function grantFree(shop) {
            if (!confirm('Grant free access to ' + shop + '?')) return;
            
            fetch('/admin/actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=grant_free&shop=' + encodeURIComponent(shop)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            });
        }
        
        function revokeFree(shop) {
            if (!confirm('Revoke free access from ' + shop + '?')) return;
            
            fetch('/admin/actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=revoke_free&shop=' + encodeURIComponent(shop)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            });
        }
    </script>
</body>
</html>

