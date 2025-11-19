<?php
require_once __DIR__ . '/../db.php';

class SubscriptionHelper
{
    /**
     * Get shop's plan status and details
     */
    public static function getPlanStatus(string $shop): array
    {
        $db = get_db();
        $stmt = $db->prepare('
            SELECT plan_type, plan_status, billing_charge_id, admin_granted_free, 
                   first_installed_at, last_reinstalled_at, last_used_at
            FROM shops 
            WHERE shop_domain = :shop 
            LIMIT 1
        ');
        $stmt->execute(['shop' => $shop]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'plan_type' => 'free',
                'plan_status' => 'active',
                'billing_charge_id' => null,
                'admin_granted_free' => false,
                'first_installed_at' => null,
                'last_reinstalled_at' => null,
                'last_used_at' => null,
            ];
        }

        return $row;
    }

    /**
     * Check if shop's plan is active
     */
    public static function isPlanActive(string $shop): bool
    {
        $status = self::getPlanStatus($shop);
        return $status['plan_status'] === 'active';
    }

    /**
     * Check if shop can use the app (active plan or free)
     */
    public static function canUseApp(string $shop): bool
    {
        $status = self::getPlanStatus($shop);
        
        // Free plan is always allowed
        if ($status['plan_type'] === 'free') {
            return true;
        }

        // Paid plans must be active
        return $status['plan_status'] === 'active';
    }

    /**
     * Update last_used_at to today's date (daily tracking)
     */
    public static function updateUsage(string $shop): void
    {
        $db = get_db();
        $today = date('Y-m-d');
        
        $stmt = $db->prepare('
            UPDATE shops 
            SET last_used_at = :today 
            WHERE shop_domain = :shop 
            AND (last_used_at IS NULL OR last_used_at != :today)
        ');
        $stmt->execute([
            'shop' => $shop,
            'today' => $today,
        ]);
    }
}

