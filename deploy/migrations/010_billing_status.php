<?php
declare(strict_types=1);
/*
 * Migration 010 — billing follows the service status (v0.21.0).
 * ADD-ONLY: one new column on services; no existing value is changed.
 *  services.keep_billing   1 = keep invoicing while the service is Suspended or Pending (in development).
 *                          Default 0: from v0.21 only Active services are invoiced unless this is ticked.
 * (The new "Terminated" status needs no database change.)
 */
return [
    'id' => '010_billing_status',
    'description' => 'services.keep_billing (bill while suspended / in development)',
    'columns' => [
        'services' => [
            'keep_billing' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
    ],
];
