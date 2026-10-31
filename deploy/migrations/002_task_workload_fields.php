<?php
declare(strict_types=1);
/*
 * Migration 002 — task workload fields and remaining client/contact fields (v0.10.0 build 2).
 * ADD-ONLY, same rules as 001: new nullable columns filled from legacy_record; nothing existing changes.
 * Field names are those of the Fabrik task, client and contact forms.
 */
return [
    'id' => '002_task_workload_fields',
    'description' => 'Task contact, assigned staff, queue, category, type, domains; client region/credit/comments; contact title',
    'columns' => [
        'tasks' => [
            'contact_id' => 'BIGINT UNSIGNED NULL',
            'assigned_to' => 'VARCHAR(255) NULL',
            'queue' => 'INT NULL',
            'task_category' => 'VARCHAR(80) NULL',
            'task_type' => 'VARCHAR(120) NULL',
            'domains' => 'VARCHAR(255) NULL',
        ],
        'clients' => [
            'postal_region' => 'VARCHAR(80) NULL',
            'credit_approved' => 'VARCHAR(10) NULL',
            'comments' => 'TEXT NULL',
        ],
        'contacts' => [
            'title' => 'VARCHAR(30) NULL',
        ],
    ],
    'backfill' => [
        'tasks' => ['assigned_to' => 'action_for', 'task_category' => 'task_category', 'task_type' => 'task_type', 'domains' => 'domains'],
        'clients' => ['postal_region' => 'postal_region', 'credit_approved' => 'credit_approved', 'comments' => 'comments'],
        'contacts' => ['title' => 'title'],
    ],
    // ["roselyn","geoff"] -> "roselyn, geoff"; ["yes"] -> "yes"; [""] -> empty
    'list_fields' => ['tasks.assigned_to', 'clients.credit_approved'],
    'backfill_sql' => [
        // Fabrik contact id -> this app's contact id (only where that contact exists; no guessing)
        "UPDATE tasks t JOIN contacts k ON k.source_system='fabrik' AND CAST(k.source_id AS BINARY) = CAST({J:t:contact_id} AS BINARY)
         SET t.contact_id = k.id WHERE t.contact_id IS NULL AND t.legacy_record IS NOT NULL",
        "UPDATE tasks SET queue = CAST({J:queue} AS SIGNED) WHERE queue IS NULL AND legacy_record IS NOT NULL AND {J:queue} REGEXP '^-?[0-9]{1,9}$'",
    ],
    'verify_sql' => [
        'task contacts not linked although the contact exists' => "SELECT COUNT(*) FROM tasks t JOIN contacts k ON k.source_system='fabrik' AND CAST(k.source_id AS BINARY) = CAST({J:t:contact_id} AS BINARY) WHERE t.legacy_record IS NOT NULL AND t.contact_id IS NULL",
        'queue values not copied' => "SELECT COUNT(*) FROM tasks WHERE legacy_record IS NOT NULL AND {J:queue} REGEXP '^-?[0-9]{1,9}$' AND (queue IS NULL OR queue <> CAST({J:queue} AS SIGNED))",
    ],
];
