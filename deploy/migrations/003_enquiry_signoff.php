<?php
declare(strict_types=1);
/*
 * Migration 003 — enquiry details and task sign-off (v0.11.0).
 * ADD-ONLY: new nullable columns; nothing existing changes. received_at is filled from
 * created_at (the moment the enquiry was saved), so every existing enquiry keeps its date.
 */
return [
    'id' => '003_enquiry_signoff',
    'description' => 'Enquiry received date, contact, category/type, duplicate/withdrawn reason; task sign-off',
    'columns' => [
        'enquiries' => [
            'received_at' => 'DATETIME NULL',
            'contact_id' => 'BIGINT UNSIGNED NULL',
            'task_category' => 'VARCHAR(80) NULL',
            'task_type' => 'VARCHAR(120) NULL',
            'duplicate_of' => 'BIGINT UNSIGNED NULL',
            'closed_reason' => 'VARCHAR(255) NULL',
        ],
        'tasks' => [
            'signed_off_at' => 'DATETIME NULL',
            'signed_off_by' => 'VARCHAR(100) NULL',
            'sign_off_note' => 'VARCHAR(500) NULL',
        ],
    ],
    'backfill_sql' => [
        'UPDATE enquiries SET received_at = created_at WHERE received_at IS NULL AND created_at IS NOT NULL',
    ],
    'verify_sql' => [
        'enquiries without a received date' => 'SELECT COUNT(*) FROM enquiries WHERE received_at IS NULL AND created_at IS NOT NULL',
        'received date differs from saved date' => 'SELECT COUNT(*) FROM enquiries WHERE created_at IS NOT NULL AND received_at <> created_at',
    ],
];
