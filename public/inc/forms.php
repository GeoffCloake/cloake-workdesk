<?php
declare(strict_types=1);
/*
 * Shared form widgets.
 * The client picker is a normal <select name="client_id"> (works with no JS);
 * app.js enhances it into a type-ahead that uses the same instant client index
 * as the finder. The select stays the submitted field either way.
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

function cm_client_options(PDO $pdo): array
{
    return $pdo->query("SELECT id,name,status FROM clients ORDER BY (LOWER(COALESCE(status,''))='archive'), name LIMIT 2000")->fetchAll();
}

function cm_client_picker(array $clients, string $selected, bool $required, string $emptyLabel = 'Choose a client'): void
{
    $archivedStarted = false;
    ?><div class="client-picker" data-client-picker>
  <select id="client" name="client_id"<?=$required ? ' required' : ''?> data-client-select><option value=""><?=h($emptyLabel)?></option><?php foreach ($clients as $c):
      $arch = strtolower((string)$c['status']) === 'archive';
      if ($arch && !$archivedStarted) { $archivedStarted = true; echo '<optgroup label="Archived clients">'; } ?>
    <option value="<?=(int)$c['id']?>"<?=$selected === (string)$c['id'] ? ' selected' : ''?>><?=h($c['name'])?></option><?php endforeach; if ($archivedStarted) echo '</optgroup>'; ?>
  </select>
</div><?php
}
