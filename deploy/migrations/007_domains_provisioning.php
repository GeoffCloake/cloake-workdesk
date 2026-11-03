<?php
declare(strict_types=1);
/*
 * Migration 007 — domain register and provisioning (v0.16.0).
 * ADD-ONLY: six new columns on services and one task template; no existing value is changed.
 *
 *  services.nameservers             name servers, as text (e.g. "ns1.host100… , ns2…")
 *  services.dns_host                where DNS is managed (Cloudflare, host100, the registrar, …)
 *  services.auto_renew              1 / 0 / NULL (not known)
 *  services.included_in_service_id  a domain included in a hosting service: not billed on its own;
 *                                   listed as "Additional Domains" on that hosting's Xero description
 *  services.provider_ref            the registrar / Vetta portal's own ID, for a later API sync
 *  services.provision_task_id       the task that set this service up
 *  task_templates                   "Set up hosting + domain" — a checklist template, editable like any other
 * Never stored: transfer (EPP/auth) codes or any password.
 */
return [
    'id' => '007_domains_provisioning',
    'description' => 'Domain register fields on services (name servers, DNS host, auto-renew, included in, provider ref, provisioning task) and a set-up template',
    'columns' => [
        'services' => [
            'nameservers' => 'VARCHAR(255) NULL',
            'dns_host' => 'VARCHAR(60) NULL',
            'auto_renew' => 'TINYINT(1) NULL',
            'included_in_service_id' => 'BIGINT UNSIGNED NULL',
            'provider_ref' => 'VARCHAR(100) NULL',
            'provision_task_id' => 'BIGINT UNSIGNED NULL',
        ],
    ],
    'backfill_sql' => [
        "INSERT INTO task_templates (title,instructions,task_category,task_type,pricing,chargeable,active,created_at)
SELECT 'Set up hosting + domain', 'Checklist:\n1. Register or transfer the domain (registrar / Vetta portal) — record expiry, registrar and provider reference on the service\n2. DNS: set name servers / records — record DNS host and name servers on the service\n3. Create the cPanel account — record the cPanel username (never the password)\n4. Email accounts\n5. SSL certificate issued\n6. Site live and checked\n7. Billing: set price, cycle and first period on the service', 'Website design and management', NULL, 'web_service', 'yes', 1, NOW()
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM task_templates WHERE title='Set up hosting + domain')",
    ],
    'verify_sql' => [
        'set-up template missing' => "SELECT 1 - COUNT(*) FROM task_templates WHERE title='Set up hosting + domain'",
    ],
    'rollback_sql' => [
        "DELETE FROM task_templates WHERE title='Set up hosting + domain' AND from_task_id IS NULL AND NOT EXISTS (SELECT 1 FROM enquiry_task_plans p WHERE p.template_id=task_templates.id) AND NOT EXISTS (SELECT 1 FROM breakdown_items b WHERE b.template_id=task_templates.id) AND NOT EXISTS (SELECT 1 FROM billing_milestones m WHERE m.template_id=task_templates.id)",
    ],
];
