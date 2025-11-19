<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/hmac.php';

header('Content-Type: application/json');

// Check admin authentication
if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';
$shop = isset($_POST['shop']) ? sanitize_shop_domain($_POST['shop']) : null;

if (!$shop) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid shop domain']);
    exit;
}

$db = get_db();

switch ($action) {
    case 'grant_free':
        $stmt = $db->prepare('
            UPDATE shops 
            SET plan_type = :plan_type,
                plan_status = :plan_status,
                admin_granted_free = TRUE
            WHERE shop_domain = :shop
        ');
        $stmt->execute([
            'shop' => $shop,
            'plan_type' => 'free',
            'plan_status' => 'active',
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Free access granted']);
        break;
        
    case 'revoke_free':
        $stmt = $db->prepare('
            UPDATE shops 
            SET admin_granted_free = FALSE
            WHERE shop_domain = :shop
        ');
        $stmt->execute([
            'shop' => $shop,
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Free access revoked']);
        break;
        
    case 'update_plan':
        $planType = $_POST['plan_type'] ?? '';
        $planStatus = $_POST['plan_status'] ?? '';
        
        if (!in_array($planType, ['free', 'monthly', 'annual']) || 
            !in_array($planStatus, ['active', 'cancelled', 'expired'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid plan type or status']);
            exit;
        }
        
        $stmt = $db->prepare('
            UPDATE shops 
            SET plan_type = :plan_type,
                plan_status = :plan_status
            WHERE shop_domain = :shop
        ');
        $stmt->execute([
            'shop' => $shop,
            'plan_type' => $planType,
            'plan_status' => $planStatus,
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Plan updated']);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}

