<?php
declare(strict_types=1);
/*
 * Migration 001 — real, editable columns for client and contact details (v0.10.0).
 *
 * ADD-ONLY: creates new nullable columns and fills them from each imported row's
 * legacy_record JSON. No existing column or value is changed, so:
 *   - v0.9.0 code keeps working against the migrated database (code rollback needs no DB rollback);
 *   - legacy_record stays the untouched provenance copy.
 * The JSON field names below are exactly those present in the Fabrik export.
 */
return [
    'id' => '001_editable_details',
    'description' => 'Editable client and contact detail columns, filled from imported records',
    'columns' => [
        'clients' => [
            'business_name' => 'VARCHAR(255) NULL',
            'email' => 'VARCHAR(255) NULL',
            'phone' => 'VARCHAR(64) NULL',
            'physical_address_1' => 'VARCHAR(255) NULL',
            'physical_address_2' => 'VARCHAR(255) NULL',
            'physical_city_town' => 'VARCHAR(120) NULL',
            'physical_country' => 'VARCHAR(80) NULL',
            'postal_address_1' => 'VARCHAR(255) NULL',
            'postal_address_2' => 'VARCHAR(255) NULL',
            'postal_address_3' => 'VARCHAR(255) NULL',
            'postal_city_town' => 'VARCHAR(120) NULL',
            'postal_code' => 'VARCHAR(20) NULL',
            'postal_country' => 'VARCHAR(80) NULL',
            'contact_method' => 'VARCHAR(40) NULL',
            'notes' => 'TEXT NULL',
            'updated_at' => 'DATETIME NULL',
        ],
        'contacts' => [
            'first_name' => 'VARCHAR(100) NULL',
            'middle_name' => 'VARCHAR(100) NULL',
            'last_name' => 'VARCHAR(100) NULL',
            'mobile' => 'VARCHAR(64) NULL',
            'position' => 'VARCHAR(150) NULL',
            'contact_type' => 'VARCHAR(255) NULL',
            'contact_method' => 'VARCHAR(40) NULL',
            'status' => 'VARCHAR(30) NULL',
            'notes' => 'TEXT NULL',
            'updated_at' => 'DATETIME NULL',
        ],
    ],
    // column => JSON field in legacy_record (same name unless stated)
    'backfill' => [
        'clients' => [
            'business_name' => 'business_name', 'email' => 'email', 'phone' => 'phone',
            'physical_address_1' => 'physical_address_1', 'physical_address_2' => 'physical_address_2',
            'physical_city_town' => 'physical_city_town', 'physical_country' => 'physical_country',
            'postal_address_1' => 'postal_address_1', 'postal_address_2' => 'postal_address_2', 'postal_address_3' => 'postal_address_3',
            'postal_city_town' => 'postal_city_town', 'postal_code' => 'postal_code', 'postal_country' => 'postal_country',
            'contact_method' => 'contact_method', 'notes' => 'notes',
        ],
        'contacts' => [
            'first_name' => 'first_name', 'middle_name' => 'mid_name', 'last_name' => 'last_name',
            'mobile' => 'mobile', 'position' => 'position', 'contact_type' => 'contact_type',
            'contact_method' => 'contact_method', 'status' => 'status', 'notes' => 'notes',
        ],
    ],
    // Values stored as JSON-encoded lists in the export (e.g. ["accounts","project liaison"])
    // are written as readable "accounts, project liaison". The original stays in legacy_record.
    'list_fields' => ['contacts.contact_type'],
];
