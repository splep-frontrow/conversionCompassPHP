-- Migration to add subscription and usage tracking columns to shops table
-- Run this after the initial migrations.sql

ALTER TABLE shops
ADD COLUMN plan_type ENUM('free', 'monthly', 'annual') DEFAULT 'free' AFTER access_token,
ADD COLUMN plan_status ENUM('active', 'cancelled', 'expired') DEFAULT 'active' AFTER plan_type,
ADD COLUMN billing_charge_id VARCHAR(255) NULL AFTER plan_status,
ADD COLUMN first_installed_at TIMESTAMP NULL AFTER billing_charge_id,
ADD COLUMN last_reinstalled_at TIMESTAMP NULL AFTER first_installed_at,
ADD COLUMN last_used_at DATE NULL AFTER last_reinstalled_at,
ADD COLUMN admin_granted_free BOOLEAN DEFAULT FALSE AFTER last_used_at;

-- Set first_installed_at for existing records based on installed_at
UPDATE shops SET first_installed_at = installed_at WHERE first_installed_at IS NULL;

-- Set last_reinstalled_at for existing records based on installed_at
UPDATE shops SET last_reinstalled_at = installed_at WHERE last_reinstalled_at IS NULL;

