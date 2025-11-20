-- Migration to add oauth_states table for storing OAuth state tokens
-- This provides a more reliable alternative to session-based state storage

CREATE TABLE IF NOT EXISTS oauth_states (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_token VARCHAR(64) NOT NULL UNIQUE,
    shop_domain VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX idx_state_token (state_token),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Clean up expired states (older than 1 hour)
DELETE FROM oauth_states WHERE expires_at < NOW();

