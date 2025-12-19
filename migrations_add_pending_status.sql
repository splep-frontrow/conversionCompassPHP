-- Migration to add 'pending' status to plan_status enum
-- Run this after migrations_subscriptions.sql

ALTER TABLE shops
MODIFY COLUMN plan_status ENUM('active', 'cancelled', 'expired', 'pending') DEFAULT 'active';
