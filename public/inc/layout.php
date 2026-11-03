<?php
declare(strict_types=1);
/*
 * Shared page chrome: header (with visible version/build), desktop sidebar,
 * fixed five-item phone navigation. Used by index.php AND every form page.
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

/** Cache-busting asset URL tied to the build, so phones never keep stale CSS/JS. */
function asset(string $path): string { return 'assets/' . $path . '?v=' . rawurlencode(CLOAKE_BUILD); }

function cm_version_label(): string { return 'v' . CLOAKE_VERSION . ' · build ' . CLOAKE_BUILD; }

/**
 * @param string $title   Page heading and <title>
 * @param string $section Which navigation item is current: today|clients|tasks|work|more|enquiries|templates|invoicing|items|check
 * @param array  $opt     'back' => [href,label], 'bodyClass' => string, 'h1' => bool (default true)
 */
function cm_page_start(string $title, string $section, array $opt = []): void
{
    $side = ['today' => 'Today', 'clients' => 'Clients', 'tasks' => 'Tasks', 'work' => 'Work Log',
             'enquiries' => 'Enquiries', 'templates' => 'Task templates', 'invoicing' => 'Invoicing', 'items' => 'Account Items'];
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#f6f6f3"><meta name="cloake-build" content="<?=h(CLOAKE_BUILD)?>"><title><?=h($title)?> · Cloake Manager</title><link rel="stylesheet" href="<?=h(asset('app.css'))?>"></head>
<body class="<?=h($opt['bodyClass'] ?? '')?>"><a class="skip" href="#main">Skip to content</a><div class="shell">
<header class="top"><a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true">C</span><span>Cloake <small class="build-label" data-testid="version"><?=h(cm_version_label())?></small></span></a>
<div class="top-actions"><a class="top-find" href="<?=h(route('clients'))?>" aria-label="Find a client" data-finder-shortcut>Find client</a><form method="post" action="index.php" class="signout" data-signout><input type="hidden" name="action" value="logout"><button class="quiet" type="submit">Sign out</button></form></div></header>
<div class="layout"><aside class="side" aria-label="Workspace"><div class="eyebrow">WORKSPACE</div><?php foreach ($side as $k => $v): ?><a class="nav<?=$section === $k ? ' current' : ''?>" href="<?=h(route($k))?>"<?=$section === $k ? ' aria-current="page"' : ''?>><?=h($v)?></a><?php endforeach; ?>
<a class="nav nav-minor<?=$section === 'check' ? ' current' : ''?>" href="<?=h(route('check'))?>">Release check</a><p class="muted small">Imported records stay read-only. No Xero or invoice actions are enabled.</p><p class="muted small side-version"><?=h(cm_version_label())?></p></aside>
<main id="main" tabindex="-1"><?php if (!empty($opt['back'])): ?><a class="back" href="<?=h($opt['back'][0])?>">‹ <?=h($opt['back'][1])?></a><?php endif; ?><?php cm_undo_bar(); ?><?php if (($opt['h1'] ?? true)): ?><h1><?=h($title)?></h1><?php endif;
}

/** True when the undo bar will show on this page (pages then skip their own plain "Saved." line). */
function cm_undo_pending(): bool { $u = $_SESSION['cm_undo'] ?? null; return $u && !empty($u['show']) && time() - (int)$u['at'] <= CM_UNDO_WINDOW; }

/** v0.11: "Saved … Undo" once, on the first page shown after an undoable save (see cm_audit / undo.php). */
function cm_undo_bar(): void
{
    if (!empty($_SESSION['cm_undo_error'])) { ?><div class="alert" role="alert"><?=h((string)$_SESSION['cm_undo_error'])?></div><?php unset($_SESSION['cm_undo_error']); }
    $u = $_SESSION['cm_undo'] ?? null;
    if (!$u || empty($u['show'])) return;
    $_SESSION['cm_undo']['show'] = false;
    if (time() - (int)$u['at'] > CM_UNDO_WINDOW) return;
    $isUndo = str_starts_with((string)$u['summary'], 'Undo: ');
    ?><div class="undo-bar" role="status" data-testid="undo-bar"><span><?=$isUndo ? 'Undone.' : 'Saved.'?> <small class="muted"><?=h($isUndo ? substr((string)$u['summary'], 6) : (string)$u['summary'])?></small></span><form method="post" action="undo.php"><input type="hidden" name="csrf" value="<?=h(m7csrf())?>"><input type="hidden" name="event" value="<?=(int)$u['id']?>"><button type="submit"><?=$isUndo ? 'Redo' : 'Undo'?></button></form></div><?php
}

function cm_page_end(string $section, array $scripts = []): void
{
    $bottom = ['today' => 'Today', 'clients' => 'Clients', 'tasks' => 'Tasks', 'work' => 'Work', 'more' => 'More'];
    $moreGroup = ['more', 'enquiries', 'templates', 'invoicing', 'items', 'check'];
    ?></main></div>
<nav class="bottom" aria-label="Main navigation"><?php foreach ($bottom as $k => $v): $cur = $section === $k || ($k === 'more' && in_array($section, $moreGroup, true)); ?><a class="<?=$cur ? 'current' : ''?>" href="<?=h(route($k))?>"<?=$cur ? ' aria-current="page"' : ''?>><?=h($v)?></a><?php endforeach; ?></nav></div>
<script src="<?=h(asset('app.js'))?>" defer></script><?php foreach ($scripts as $s): ?><script src="<?=h(asset($s))?>" defer></script><?php endforeach; ?></body></html>
<?php
}
