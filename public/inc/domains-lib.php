<?php
declare(strict_types=1);
/*
 * Domain register and provisioning (v0.16) — shared helpers.
 * A domain is a services row with kind 'domain'. "Included in" links it to a hosting service of the same client:
 * it is then not billed on its own and appears as an Additional Domain on that hosting's Xero description.
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

const CM_PROVISION_TEMPLATE = 'Set up hosting + domain';
const CM_EXPIRY_WARN_DAYS = 60;

function cm_has_domains(PDO $pdo): bool
{
    static $has = null;
    return $has ??= (bool)oneRow($pdo, "SELECT 1 x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='services' AND COLUMN_NAME='included_in_service_id'");
}

/** Domains included in a hosting service (live ones), for its Xero description. */
function cm_included_domains(PDO $pdo, int $serviceId): array
{
    if (!cm_has_domains($pdo)) return [];
    return array_column(allRows($pdo, "SELECT COALESCE(domain,label) d FROM services WHERE included_in_service_id=? AND status IN ('active','pending','suspended') ORDER BY domain", [$serviceId]), 'd');
}

/** Hosting services a domain of this client could be included in. */
function cm_hosting_choices(PDO $pdo, ?int $clientId, int $exceptId = 0): array
{
    if (!$clientId) return [];
    return allRows($pdo, "SELECT id, COALESCE(domain,label) name, status FROM services WHERE client_id=? AND kind='hosting' AND id<>? ORDER BY status<>'active', domain", [$clientId, $exceptId]);
}

/** Validate an "included in" choice: a hosting service of the same client (not itself). */
function cm_valid_included(PDO $pdo, string $raw, ?int $clientId, int $selfId): ?int
{
    if ($raw === '') return null;
    if (!ctype_digit($raw)) throw new InvalidArgumentException('Choose the hosting service it is included in.');
    $h = oneRow($pdo, "SELECT id, client_id, kind FROM services WHERE id=?", [(int)$raw]);
    if (!$h || $h['kind'] !== 'hosting' || (int)$h['id'] === $selfId) throw new InvalidArgumentException('“Included in” must be a hosting service.');
    if ((int)$h['client_id'] !== (int)$clientId) throw new InvalidArgumentException('“Included in” must be a hosting service of the same client.');
    return (int)$h['id'];
}

/** Expiry state of a domain: '', 'soon' (within 60 days) or 'expired'. */
function cm_expiry_state(?string $expiry, string $today): string
{
    if (!$expiry) return '';
    if ($expiry < $today) return 'expired';
    return $expiry <= (new DateTimeImmutable($today))->modify('+' . CM_EXPIRY_WARN_DAYS . ' days')->format('Y-m-d') ? 'soon' : '';
}

function cm_provision_template(PDO $pdo): ?array
{
    return oneRow($pdo, 'SELECT * FROM task_templates WHERE title=? AND active=1 ORDER BY id LIMIT 1', [CM_PROVISION_TEMPLATE]);
}

/**
 * Create a task from a template for a client (title given), copying its fields and price lines/milestones.
 * Returns the new task id. Audited as task_created.
 */
function cm_task_from_template(PDO $pdo, int $templateId, ?int $clientId, string $title): int
{
    $tt = oneRow($pdo, 'SELECT * FROM task_templates WHERE id=?', [$templateId]);
    if (!$tt) throw new InvalidArgumentException('Template not found.');
    if (!(int)$tt['active']) throw new InvalidArgumentException('That template is archived. Restore it first to use it.');
    $pdo->prepare("INSERT INTO tasks (source_system,source_id,received_at,client_id,title,instructions,task_category,task_type,assigned_to,pricing,chargeable,estimate_minutes,billing_mode,status)
        VALUES ('app',?,NOW(),?,?,?,?,?,?,?,?,?,?,'planned')")
        ->execute([cm_new_source_id(), $clientId, $title, $tt['instructions'], $tt['task_category'], $tt['task_type'], $tt['assigned_to'], $tt['pricing'], $tt['chargeable'], $tt['estimate_minutes'], $tt['billing_mode']]);
    $taskId = (int)$pdo->lastInsertId();
    cm_copy_pricing($pdo, 'template_id', $templateId, ['task_id' => $taskId]);
    cm_audit($pdo, 'task_created', ['client_id' => $clientId, 'task_id' => $taskId], 'Task created from template “' . $tt['title'] . '”: ' . $title, ['row_id' => $taskId, 'template_id' => $templateId]);
    return $taskId;
}
