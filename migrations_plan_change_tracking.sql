-- Migration to add previous_billing_charge_id for tracking plan changes
-- This allows us to restore the previous active subscription if a pending plan change is cancelled

ALTER TABLE shops
ADD COLUMN previous_billing_charge_id VARCHAR(255) NULL AFTER billing_charge_id,
ADD COLUMN previous_plan_type ENUM('free', 'monthly', 'annual') NULL AFTER previous_billing_charge_id;
