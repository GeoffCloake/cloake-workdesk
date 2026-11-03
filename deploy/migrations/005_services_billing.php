<?php
declare(strict_types=1);
/*
 * Migration 005 — services register, charges ledger, settings, WHMCS archive (v0.13.0).
 * ADD-ONLY: four new tables and one new column; no existing value is changed.
 *
 *  services       one row per thing a client has (hosting, email, domain, DNS & forwarding, maintenance)
 *  charges        one row per invoiced (or pending) amount: service period, milestone, month-end claim, task fee, manual.
 *                 UNIQUE (source_kind, source_id, period_start): the same period can never be recorded twice.
 *  settings       billing settings (lead weeks, GST, Xero accounts, billing day)
 *  archive_whmcs  every WHMCS export row as imported, read-only, with a fingerprint (no passwords; credential-like
 *                 note lines removed by the import tool). Later exports (invoices, payments, products) add to it.
 *  work_log.charge_id  hours invoiced in a month-end claim are tied to that charge and never billed again.
 *
 * The WHMCS data itself is loaded by deploy/import-whmcs.php (dry run first, undoable by batch), not here.
 */
return [
    'id' => '005_services_billing',
    'description' => 'Services register, charges ledger, billing settings, WHMCS archive; work_log.charge_id',
    'columns' => [
        'work_log' => [
            'charge_id' => 'BIGINT UNSIGNED NULL',
        ],
    ],
    'tables' => [
        'services' => "CREATE TABLE IF NOT EXISTS services (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, client_id BIGINT UNSIGNED NULL, kind VARCHAR(20) NOT NULL,
 is_internal TINYINT(1) NOT NULL DEFAULT 0, billable TINYINT(1) NOT NULL DEFAULT 1,
 label VARCHAR(255) NULL, domain VARCHAR(255) NULL, plan_name VARCHAR(120) NULL, status VARCHAR(20) NOT NULL DEFAULT 'active',
 cycle_months TINYINT UNSIGNED NULL, price_ex_gst DECIMAL(12,2) NULL, xero_account VARCHAR(20) NULL,
 next_period_start DATE NULL, expiry_date DATE NULL, registrar VARCHAR(60) NULL, cpanel_username VARCHAR(60) NULL,
 check_status VARCHAR(20) NULL, check_note VARCHAR(500) NULL, notes TEXT NULL,
 source_system VARCHAR(16) NULL, source_id VARCHAR(40) NULL, import_batch_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 UNIQUE KEY uq_service_source (source_system, source_id), KEY ix_service_client (client_id), KEY ix_service_next (next_period_start),
 FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB",
        'charges' => "CREATE TABLE IF NOT EXISTS charges (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, client_id BIGINT UNSIGNED NULL, source_kind VARCHAR(16) NOT NULL, source_id BIGINT UNSIGNED NOT NULL,
 period_start DATE NOT NULL, period_end DATE NULL, description TEXT NULL, amount_ex_gst DECIMAL(12,2) NOT NULL, xero_account VARCHAR(20) NULL,
 status VARCHAR(10) NOT NULL DEFAULT 'pending', xero_invoice_number VARCHAR(40) NULL, recorded_by VARCHAR(100) NULL, recorded_at DATETIME NULL,
 void_reason VARCHAR(255) NULL, origin VARCHAR(16) NOT NULL DEFAULT 'manager', import_batch_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NULL,
 UNIQUE KEY uq_charge_period (source_kind, source_id, period_start), KEY ix_charge_client (client_id), KEY ix_charge_invoice (xero_invoice_number),
 FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB",
        'settings' => "CREATE TABLE IF NOT EXISTS settings (
 k VARCHAR(60) NOT NULL PRIMARY KEY, v VARCHAR(255) NULL, updated_at DATETIME NULL, updated_by VARCHAR(100) NULL
) ENGINE=InnoDB",
        'archive_whmcs' => "CREATE TABLE IF NOT EXISTS archive_whmcs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_table VARCHAR(40) NOT NULL, source_id VARCHAR(40) NOT NULL,
 whmcs_client_id VARCHAR(40) NULL, client_id BIGINT UNSIGNED NULL, domain VARCHAR(255) NULL, status VARCHAR(40) NULL,
 source_record JSON NOT NULL, removed VARCHAR(255) NULL, row_sha256 CHAR(64) NOT NULL, import_batch_id BIGINT UNSIGNED NULL,
 UNIQUE KEY uq_archive_source (source_table, source_id), KEY ix_archive_client (client_id)
) ENGINE=InnoDB",
    ],
    'backfill_sql' => [
        "INSERT INTO settings (k,v,updated_at,updated_by) VALUES
 ('lead_weeks','8',NOW(),'migration'),('gst_rate','0.15',NOW(),'migration'),('invoice_due','period_start',NOW(),'migration'),
 ('xero_account_hosting','43504',NOW(),'migration'),('xero_account_domain','43601',NOW(),'migration'),('xero_account_default','',NOW(),'migration'),
 ('billing_day','month_end',NOW(),'migration'),('hourly_rate_ex_gst','',NOW(),'migration')
 ON DUPLICATE KEY UPDATE k=k",
    ],
    'verify_sql' => [
        'settings missing' => "SELECT 8 - COUNT(*) FROM settings WHERE k IN ('lead_weeks','gst_rate','invoice_due','xero_account_hosting','xero_account_domain','xero_account_default','billing_day','hourly_rate_ex_gst')",
    ],
];
