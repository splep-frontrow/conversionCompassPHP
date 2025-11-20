<?php
/**
 * Initialize session with proper configuration for Shopify OAuth
 */
function init_shopify_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // Configure session settings
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        
        // Use secure cookies if HTTPS is being used
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            ini_set('session.cookie_secure', '1');
        }
        
        ini_set('session.cookie_samesite', 'Lax');
        
        // Set a reasonable session lifetime (1 hour)
        ini_set('session.gc_maxlifetime', '3600');
        
        // Ensure session save path is writable
        $savePath = session_save_path();
        if ($savePath && !is_writable($savePath)) {
            // Try to use a writable directory
            $writablePath = sys_get_temp_dir();
            if (is_writable($writablePath)) {
                session_save_path($writablePath);
            }
        }
        
        session_start();
    }
}

