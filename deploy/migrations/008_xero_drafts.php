<?php
declare(strict_types=1);
/*
 * Migration 008 — Xero draft invoices (v0.17.0).
 * ADD-ONLY: one column on charges; no existing value is changed.
 *  charges.xero_invoice_id   Xero's InvoiceID (GUID) when the invoice was created as a DRAFT from Workdesk
 * The Xero app's client ID/secret and access tokens are NOT stored in the database: they live in
 * ~/cloake-manager/private/xero.json (outside the web root, mode 600).
 */
return [
    'id' => '008_xero_drafts',
    'description' => 'charges.xero_invoice_id for invoices created as Xero drafts',
    'columns' => [
        'charges' => [
            'xero_invoice_id' => 'VARCHAR(40) NULL',
        ],
    ],
];
