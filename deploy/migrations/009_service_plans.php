<?php
declare(strict_types=1);
/*
 * Migration 009 — service plans (v0.19.0).
 * ADD-ONLY: one new table, two new columns on services; no existing value is changed.
 *
 *  plans               the plan list: code (= the Xero item code), name, kind, today's price for a NEW client ex GST
 *                      per price_months, Xero account, active; xero_item_id once the plan has been created in Xero.
 *                      Seeded with the six plans agreed on 5 Nov 2026 (prices editable on More › Plans).
 *  services.plan_id    which plan a service is on (set on the service form, or by More › Plans › Match services).
 *                      The service's own price_ex_gst stays what that client pays (old clients keep old prices).
 *  services.mailboxes  mailboxes provisioned on the service (shown on the Xero description only when > 0).
 *
 * Existing services are NOT linked to a plan here: More › Plans › Match services shows the proposed matches first.
 */
return [
    'id' => '009_service_plans',
    'description' => 'Service plans (plans table, services.plan_id, services.mailboxes)',
    'columns' => [
        'services' => [
            'plan_id' => 'BIGINT UNSIGNED NULL',
            'mailboxes' => 'SMALLINT UNSIGNED NULL',
        ],
    ],
    'tables' => [
        'plans' => "CREATE TABLE IF NOT EXISTS plans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NOT NULL, name VARCHAR(50) NOT NULL, kind VARCHAR(20) NOT NULL,
 price_ex_gst DECIMAL(12,2) NULL, price_months TINYINT UNSIGNED NOT NULL DEFAULT 6, xero_account VARCHAR(20) NULL,
 active TINYINT(1) NOT NULL DEFAULT 1, sort_order SMALLINT UNSIGNED NULL, notes VARCHAR(500) NULL, xero_item_id VARCHAR(40) NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 UNIQUE KEY uq_plan_code (code)
) ENGINE=InnoDB",
    ],
    'backfill_sql' => [
        "INSERT INTO plans (code,name,kind,price_ex_gst,price_months,xero_account,active,sort_order,notes,created_at) VALUES
 ('HOST-ESS','Website hosting - Essential','hosting',135.00,6,NULL,1,10,'Simple and HTML sites',NOW()),
 ('HOST-STD','Website hosting - Standard','hosting',299.70,6,NULL,1,20,'CMS sites. Older clients may be on a legacy price (e.g. 201.00)',NOW()),
 ('HOST-ADV','Website hosting - Advanced','hosting',390.00,6,NULL,1,30,'E-commerce, booking, mapping and other special features',NOW()),
 ('MAILBOX','Email mailbox','mailbox',2.95,1,NULL,1,40,'Per provisioned mailbox, per month',NOW()),
 ('DNS-FWD','DNS and forwarding','dns',NULL,6,NULL,1,50,'Price per client (often no charge)',NOW()),
 ('DOMAIN','Domain renewal','domain',49.95,12,NULL,1,60,NULL,NOW())
 ON DUPLICATE KEY UPDATE code=code",
    ],
    'verify_sql' => [
        'plans missing' => "SELECT 6 - COUNT(*) FROM plans WHERE code IN ('HOST-ESS','HOST-STD','HOST-ADV','MAILBOX','DNS-FWD','DOMAIN')",
    ],
];
