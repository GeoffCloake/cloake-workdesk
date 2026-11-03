<?php
declare(strict_types=1);
/*
 * Scheduling helpers (v0.14): staff, capacity per day, booked time, "Action for" list edits.
 * A task is on someone's schedule when its Action for includes their name and scheduled_on is set.
 * Booked time = scheduled_minutes, else the task's estimate, else nothing (shown as "no estimate").
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

const CM_WEEKDAYS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

function cm_has_schedule(PDO $pdo): bool
{
    static $has = null;
    return $has ??= (bool)oneRow($pdo, "SELECT 1 x FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staff'");
}
function cm_staff(PDO $pdo, bool $activeOnly = true): array
{
    if (!cm_has_schedule($pdo)) return [];
    return allRows($pdo, 'SELECT * FROM staff' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY active DESC, COALESCE(sort_order,65535), name');
}
function cm_staff_label(array $s): string { return (string)($s['display_name'] ?: ucfirst((string)$s['name'])); }
/** Minutes a person is available on a date. */
function cm_capacity(array $s, string $date): int { return (int)($s['min_' . strtolower((new DateTimeImmutable($date))->format('D'))] ?? 0); }
function cm_booked_minutes(array $t): int { return (int)($t['scheduled_minutes'] ?: $t['estimate_minutes'] ?: 0); }
function cm_hm(int $min): string { return $min <= 0 ? '0h' : (intdiv($min, 60) ? intdiv($min, 60) . 'h' : '') . ($min % 60 ? ' ' . ($min % 60) . 'm' : ''); }
/** "roselyn, geoff" → ['roselyn','geoff'] */
function cm_assignees(?string $v): array { return array_values(array_filter(array_map(fn($x) => mb_strtolower(trim($x)), explode(',', (string)$v)), 'strlen')); }
/** Replace one person in an Action for list (or add them); keeps the others. */
function cm_reassign(?string $v, string $from, string $to): ?string
{
    $list = cm_assignees($v); $to = mb_strtolower(trim($to)); $from = mb_strtolower(trim($from));
    if ($to === '' || $to === $from) return $v === '' ? null : $v;
    $out = [];
    foreach ($list as $n) $out[] = $n === $from ? $to : $n;
    if (!in_array($to, $out, true)) $out[] = $to;
    $out = array_values(array_unique($out));
    return $out ? implode(', ', $out) : null;
}
/** Monday of the week containing $date. */
function cm_week_start(string $date): string { $d = new DateTimeImmutable($date); return $d->modify('-' . ((int)$d->format('N') - 1) . ' days')->format('Y-m-d'); }
