<?php
require_once __DIR__ . '/../db.php';

class OAuthStateHelper
{
    /**
     * Store OAuth state in database (more reliable than sessions)
     */
    public static function storeState(string $state, string $shop): bool
    {
        try {
            $db = get_db();
            
            // State expires in 10 minutes
            $expiresAt = date('Y-m-d H:i:s', time() + 600);
            
            $stmt = $db->prepare('
                INSERT INTO oauth_states (state_token, shop_domain, expires_at)
                VALUES (:state_token, :shop_domain, :expires_at)
                ON DUPLICATE KEY UPDATE 
                    shop_domain = VALUES(shop_domain),
                    expires_at = VALUES(expires_at)
            ');
            
            return $stmt->execute([
                'state_token' => $state,
                'shop_domain' => $shop,
                'expires_at' => $expiresAt,
            ]);
        } catch (Exception $e) {
            error_log('Failed to store OAuth state: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verify and retrieve OAuth state from database
     */
    public static function verifyState(string $state, string $shop): bool
    {
        try {
            $db = get_db();
            
            $stmt = $db->prepare('
                SELECT shop_domain, expires_at 
                FROM oauth_states 
                WHERE state_token = :state_token 
                AND expires_at > NOW()
                LIMIT 1
            ');
            
            $stmt->execute(['state_token' => $state]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                return false;
            }
            
            // Verify shop domain matches
            if ($row['shop_domain'] !== $shop) {
                return false;
            }
            
            // Clean up used state
            self::deleteState($state);
            
            return true;
        } catch (Exception $e) {
            error_log('Failed to verify OAuth state: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete OAuth state (after successful verification)
     */
    private static function deleteState(string $state): void
    {
        try {
            $db = get_db();
            $stmt = $db->prepare('DELETE FROM oauth_states WHERE state_token = :state_token');
            $stmt->execute(['state_token' => $state]);
        } catch (Exception $e) {
            error_log('Failed to delete OAuth state: ' . $e->getMessage());
        }
    }
    
    /**
     * Clean up expired states (call periodically via cron or on each request)
     */
    public static function cleanupExpiredStates(): void
    {
        try {
            $db = get_db();
            $db->exec('DELETE FROM oauth_states WHERE expires_at < NOW()');
        } catch (Exception $e) {
            error_log('Failed to cleanup expired OAuth states: ' . $e->getMessage());
        }
    }
}

