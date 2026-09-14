<?php
$fmtDateTime = static function ($value): string {
    if (!$value) return '—';
    $ts = strtotime((string)$value);
    return $ts === false ? e((string)$value) : date('d.m.Y H:i', $ts);
};
$fmtMass = static function ($value): string {
    $number = (float)$value;
    return number_format($number, abs($number - round($number)) < 0.0005 ? 0 : 3, ',', ' ');
};
?>
<style>
.feo-placement-head{margin-bottom:10px}
.feo-placement-table-card{display:block;min-width:0;border:0!important;box-shadow:none!important;background:transparent!important}
.feo-placement-table-card .table-scroll{overflow-x:hidden}
.feo-placement-toolbar{min-height:42px;height:auto;padding:6px 8px;gap:8px;flex-wrap:wrap}
.feo-placement-toolbar-summary{display:flex;align-items:center;gap:5px;flex-wrap:wrap}
.feo-placement-toolbar-summary .sep{color:var(--text-muted);font-weight:400}
.feo-placement-toolbar-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;max-width:100%;min-width:0}
.feo-placement-hide-disabled{display:flex;align-items:center;gap:6px;min-height:30px;white-space:nowrap;font-size:11px;color:var(--text);cursor:pointer}
.feo-placement-hide-disabled input[type="checkbox"]{width:15px;height:15px;margin:0;accent-color:var(--accent)}
.feo-placement-table-card:has([data-placement-hide-disabled]:checked) .feo-placement-table tbody tr[data-placement-block-status="disabled"]{display:none}
.feo-placement-toolbar .feo-placement-block-search-input{width:600px;min-width:320px;max-width:100%;resize:none;overflow:hidden;line-height:calc(var(--toolbar-h) - 2px);padding:0 9px}
.feo-placement-block-search-results{padding:8px 0 0;border-top:1px solid var(--line-soft);background:var(--surface-form)}
.feo-placement-block-search-results.is-hidden{display:none}
.feo-placement-search-summary{font-size:11px;color:var(--text-muted);margin-bottom:6px;padding:0 8px}
.feo-placement-search-table{width:100%;table-layout:auto;min-width:0!important;margin:0}
.feo-placement-search-table th,.feo-placement-search-table td{vertical-align:middle}
.feo-placement-search-col--request,.feo-placement-search-col--date,.feo-placement-search-col--type{width:1%;white-space:nowrap}
.feo-placement-search-col--block{width:auto;white-space:normal;overflow-wrap:anywhere;word-break:normal}
.feo-placement-search-table tbody tr[data-placement-search-block-id]{cursor:pointer}
.feo-placement-search-table tbody tr[data-placement-search-block-id]:hover{background:var(--accent-bg)}
.feo-placement-table{width:100%;table-layout:fixed;min-width:0!important}
.feo-placement-table th,.feo-placement-table td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.feo-placement-source-cell{white-space:normal!important;overflow-wrap:anywhere;word-break:normal;text-overflow:clip!important}
.feo-placement-table tbody tr[data-placement-block-id]{cursor:pointer}
.feo-placement-table tbody tr[data-placement-block-id]:hover{background:var(--accent-bg)}
.feo-placement-table tbody tr[data-placement-block-type="exclusion"]{background:var(--danger-bg)}
.feo-placement-table tbody tr[data-placement-block-type="exclusion"]:hover,.feo-placement-table tbody tr[data-placement-block-type="exclusion"]:hover>td{background:var(--badge-danger-bg,var(--danger-field))!important}
.feo-placement-status{font-weight:700}
.feo-placement-status--applied{color:var(--success,#2f7d32)}
.feo-placement-status--disabled{color:var(--danger,#b42318)}
.feo-placement-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.feo-placement-wide{grid-column:1/-1}
.feo-placement-radio-row{display:flex;gap:12px;flex-wrap:wrap}
.feo-placement-radio{display:flex;align-items:center;gap:6px;padding:8px 10px;border:1px solid var(--line-soft);background:var(--surface-form);cursor:pointer}
.feo-placement-textarea{min-height:180px;resize:vertical}
.feo-placement-source-file{display:none}
.feo-placement-source-file.is-active{display:block}
.feo-placement-manual.is-hidden{display:none}
.feo-placement-live-validation{margin-top:7px}
.feo-placement-live-validation.success{color:var(--success,#2f7d32)}
.feo-placement-live-validation.warn{display:block!important;width:100%;color:var(--danger,#b42318)}
[data-placement-create-error]:not(.is-hidden){display:block!important;width:100%}
.feo-placement-confirm-copy{display:grid;gap:7px}
.feo-placement-confirm-effect{border:1px solid var(--line-soft);background:var(--surface-form);padding:9px 10px}
.feo-placement-danger{color:var(--danger,#b42318)}
.feo-placement-repeat-title{font-weight:700;margin-bottom:5px}
.feo-placement-repeat-list{display:grid;gap:0;border-top:1px solid color-mix(in srgb,var(--danger,#b42318) 24%,transparent)}
.feo-placement-repeat-item{display:grid;grid-template-columns:92px minmax(0,1fr);gap:10px;padding:7px 0;border-bottom:1px solid color-mix(in srgb,var(--danger,#b42318) 18%,transparent)}
.feo-placement-repeat-id{font-weight:700;white-space:nowrap}
.feo-placement-repeat-blocks{display:grid;gap:3px;min-width:0}
.feo-placement-repeat-block{min-width:0;overflow-wrap:anywhere}
.feo-placement-detail-modal{width:min(760px,calc(100vw - 24px));max-width:760px}
.feo-placement-detail-modal .modal-title{min-width:0;overflow-wrap:anywhere}
.feo-placement-detail-modal-body{padding:0!important;overflow:hidden!important}
.feo-placement-detail-body{min-width:0;max-width:100%;overflow:hidden}
.feo-placement-detail-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0;margin:0;border-bottom:1px solid var(--line-soft)}
.feo-placement-detail-meta>div{min-width:0;border:0;border-right:1px solid var(--line-soft);border-bottom:1px solid var(--line-soft);background:var(--surface-form);padding:8px 10px}
.feo-placement-detail-meta>div:nth-child(2n){border-right:0}
.feo-placement-detail-meta>div:nth-last-child(-n+2){border-bottom:0}
.feo-placement-detail-meta span{display:block;color:var(--text-muted);font-size:11px;margin-bottom:2px}
.feo-placement-detail-meta b{display:block;min-width:0;overflow-wrap:anywhere;word-break:break-word}
.feo-placement-list{max-height:54vh;width:100%;max-width:100%;overflow-y:auto;overflow-x:hidden}
.feo-placement-list table{width:100%;max-width:100%;min-width:0!important;table-layout:fixed}
.feo-placement-list th,.feo-placement-list td{white-space:normal;overflow-wrap:anywhere;word-break:break-word}
.feo-placement-parity-warning{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.feo-placement-parity-warning-copy{min-width:0;display:grid;gap:3px}
.feo-placement-parity-warning-summary{font-size:12px;color:var(--text-muted)}
.feo-placement-parity-modal{width:min(900px,calc(100vw - 24px));max-width:900px}
.feo-placement-parity-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));border:1px solid var(--line-soft);background:var(--surface-form);margin-bottom:12px}
.feo-placement-parity-card{padding:10px 12px;min-width:0;border-right:1px solid var(--line-soft)}
.feo-placement-parity-card:last-child{border-right:0}
.feo-placement-parity-card span{display:block;color:var(--text-muted);font-size:11px;margin-bottom:4px}
.feo-placement-parity-card b{display:block;font-size:14px;overflow-wrap:anywhere}
.feo-placement-parity-section{margin-top:12px}
.feo-placement-parity-section h3{font-size:13px;margin:0 0 6px}
.feo-placement-parity-section-note{font-size:11px;color:var(--text-muted);margin-bottom:6px}
.feo-placement-parity-location{white-space:normal!important;overflow-wrap:anywhere}
.feo-placement-parity-checked{margin-top:10px;color:var(--text-muted);font-size:11px}
@media(max-width:900px){
  .feo-placement-form-grid{grid-template-columns:1fr}
  .feo-placement-wide{grid-column:auto}
  .feo-placement-toolbar-actions{width:100%}
  .feo-placement-toolbar .feo-placement-block-search-input{width:100%;min-width:0;max-width:100%}
  .feo-placement-detail-meta{grid-template-columns:1fr}
  .feo-placement-parity-grid{grid-template-columns:1fr}
  .feo-placement-parity-card{border-right:0;border-bottom:1px solid var(--line-soft)}
  .feo-placement-parity-card:last-child{border-bottom:0}
  .feo-placement-detail-meta>div{border-right:0;border-bottom:1px solid var(--line-soft)!important}
  .feo-placement-detail-meta>div:last-child{border-bottom:0!important}
}
</style>

<?php if (!empty($successFlash)): ?><div class="notice success"><?= e((string)$successFlash) ?></div><?php endif; ?>
<?php if (!empty($errorFlash)): ?><div class="notice warn"><?= e((string)$errorFlash) ?></div><?php endif; ?>
<?php if (!empty($dbError)): ?><div class="notice warn"><?= e((string)$dbError) ?></div><?php endif; ?>

<div class="page-head feo-placement-head">
  <div class="page-head-left">
    <h1 class="page-title">Размещение на карте</h1>
    <div class="page-summary"><span>Рабочий состав заявок ФЭО, переданных в размещение. Состав рассчитывается из применяемых добавляющих и исключающих блоков.</span></div>
  </div>
  <?php if (empty($dbError)): ?><div class="page-head-actions"><button type="button" class="btn btn-primary" data-placement-open-create>Создать блок</button></div><?php endif; ?>
</div>

<?php if (empty($dbError)): ?>
<?php if (empty($consistency['is_consistent'])): ?>
<div class="notice warn" data-placement-consistency-warning>
  <b>Актуальный состав не согласован с применяемыми блоками.</b>
  В рабочем наборе <?= (int)($consistency['actual_count'] ?? 0) ?> заявок, расчёт по блокам даёт <?= (int)($consistency['expected_count'] ?? 0) ?>.
  <button type="button" class="btn btn-secondary btn--toolbar" data-placement-reconcile>Пересчитать состав</button>
</div>
<?php endif; ?>

<?php if (empty($legacyParity['available'])): ?>
<div class="notice warn feo-placement-parity-warning" data-placement-parity-warning>
  <div class="feo-placement-parity-warning-copy">
    <b>Контрольная сверка со старой базой недоступна.</b>
    <span class="feo-placement-parity-warning-summary"><?= e((string)($legacyParity['message'] ?? 'Не удалось выполнить сверку.')) ?></span>
  </div>
  <button type="button" class="btn btn-secondary btn--toolbar" data-placement-open-parity>Системная информация</button>
</div>
<?php elseif ((int)($legacyParity['mismatch_count'] ?? 0) > 0): ?>
<div class="notice warn feo-placement-parity-warning" data-placement-parity-warning>
  <div class="feo-placement-parity-warning-copy">
    <b>Обнаружены расхождения со старой базой: <?= (int)$legacyParity['mismatch_count'] ?> заявок.</b>
    <span class="feo-placement-parity-warning-summary">ERP: <?= (int)($legacyParity['new']['request_count'] ?? 0) ?> заявок / <?= e($fmtMass($legacyParity['new']['total_mass_kg'] ?? 0)) ?> кг · Старая база: <?= (int)($legacyParity['legacy']['request_count'] ?? 0) ?> заявок / <?= e($fmtMass($legacyParity['legacy']['total_mass_kg'] ?? 0)) ?> кг.</span>
  </div>
  <button type="button" class="btn btn-secondary btn--toolbar" data-placement-open-parity>Показать расхождения</button>
</div>
<?php endif; ?>

<div class="table-card table-card--standard feo-placement-table-card">
  <div class="table-toolbar feo-placement-toolbar">
    <div class="found-label feo-placement-toolbar-summary">
      <span>Блоки размещения: <b><?= count($blocks) ?></b></span>
      <span class="sep">·</span>
      <span>Актуальный состав: <b><?= (int)($current['request_count'] ?? 0) ?></b> заявок</span>
      <span class="sep">·</span>
      <span>Масса: <b><?= e($fmtMass($current['total_mass_kg'] ?? 0)) ?> кг</b></span>
    </div>
    <div class="toolbar-right feo-placement-toolbar-actions">
      <label class="feo-placement-hide-disabled"><input type="checkbox" data-placement-hide-disabled checked> Не показывать отключённые</label>
      <textarea rows="1" class="toolbar-search feo-placement-block-search-input" data-placement-block-search placeholder="Найти заявку в блоках" autocomplete="off"></textarea>
      <button type="button" class="btn btn-secondary btn--toolbar" data-placement-open-current>Актуальные заявки</button>
    </div>
  </div>
  <div class="feo-placement-block-search-results is-hidden" data-placement-block-search-results></div>
  <div class="table-scroll">
    <table class="table feo-placement-table">
      <thead><tr><th style="width:6%">ID</th><th style="width:16%">Название</th><th style="width:9%">Тип</th><th style="width:7%">Заявок</th><th style="width:9%">Масса, кг</th><th style="width:24%">Источник</th><th style="width:11%">Дата</th><th style="width:11%">Ответств.</th><th style="width:7%">Статус</th></tr></thead>
      <tbody>
      <?php foreach ($blocks as $block): ?>
        <?php
          $statusApplied = ($block['status'] ?? '') === 'applied';
          $sourceType = (string)($block['source_type'] ?? 'manual');
          $sourceText = $sourceType === 'manual'
              ? trim((string)($block['request_ids_text'] ?? ''))
              : trim((string)($block['source_filename'] ?? ''));
          if ($sourceType === 'manual' && $sourceText !== '') {
              $sourceText = preg_replace('/\s*,\s*/u', ', ', $sourceText) ?? $sourceText;
          }
          if ($sourceText === '') $sourceText = $sourceType === 'manual' ? '—' : strtoupper($sourceType);
        ?>
        <tr data-placement-block-id="<?= (int)$block['id'] ?>" data-placement-block-status="<?= $statusApplied ? 'applied' : 'disabled' ?>" data-placement-block-type="<?= e((string)($block['block_type'] ?? '')) ?>">
          <td>#<?= (int)$block['id'] ?></td>
          <td><?= e((string)($block['name'] ?: '—')) ?></td>
          <td><?= ($block['block_type'] ?? '') === 'addition' ? 'Добавление' : 'Исключение' ?></td>
          <td><?= (int)($block['request_count'] ?? 0) ?></td>
          <td><?= e($fmtMass($block['total_mass_kg'] ?? 0)) ?></td>
          <td<?= $sourceType === 'manual' ? ' class="feo-placement-source-cell"' : '' ?> title="<?= e($sourceText) ?>"><?= e($sourceText) ?></td>
          <td><?= e($fmtDateTime($block['created_at'] ?? null)) ?></td>
          <td title="<?= e((string)($block['created_by_login'] ?? '')) ?>"><?= e((string)($block['created_by_display_name'] ?? '—')) ?></td>
          <td><span class="feo-placement-status <?= $statusApplied ? 'feo-placement-status--applied' : 'feo-placement-status--disabled' ?>"><?= $statusApplied ? 'Применяется' : 'Отключён' ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$blocks): ?><tr><td colspan="9" class="text-muted">Блоки ещё не созданы.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div id="feo-placement-create-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
  <div class="modal modal-lg">
    <div class="modal-head"><span class="modal-title">Новый блок размещения</span><button type="button" class="modal-close" data-close-modal="feo-placement-create-modal">&times;</button></div>
    <div class="modal-body">
      <form data-placement-create-form action="<?= app_url('/company/feo/requests/map-placement/analyze') ?>" method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <div class="feo-placement-form-grid">
          <div class="field"><label class="field-label">Название, необязательно</label><input class="field-input" type="text" name="name" maxlength="255"></div>
          <div class="field"><label class="field-label">Тип блока</label><div class="feo-placement-radio-row"><label class="feo-placement-radio"><input type="radio" name="block_type" value="addition" checked> Добавление</label><label class="feo-placement-radio"><input type="radio" name="block_type" value="exclusion"> Исключение</label></div></div>
          <div class="field feo-placement-wide"><label class="field-label">Источник</label><div class="feo-placement-radio-row"><label class="feo-placement-radio"><input type="radio" name="source_mode" value="manual" checked> Ручной ввод</label><label class="feo-placement-radio"><input type="radio" name="source_mode" value="file"> XLS / XLSX</label></div></div>
          <div class="field feo-placement-wide feo-placement-manual" data-placement-manual>
            <label class="field-label">Заявки</label>
            <textarea class="field-input feo-placement-textarea" name="request_ids" data-placement-ids placeholder="Вставьте любой текст с шестизначными ID. Для Excel/markdown-таблицы используется только первый столбец."></textarea>
            <div class="field-hint">Можно вставить сразу несколько заявок, после вставки список можно вручную дополнять и редактировать.</div>
            <div class="notice is-hidden feo-placement-live-validation" data-placement-live-validation></div>
          </div>
          <div class="field feo-placement-wide feo-placement-source-file" data-placement-file><label class="field-label">Файл XLS / XLSX</label><input class="field-input" type="file" name="source_file" accept=".xls,.xlsx"><div class="field-hint">В файле должен быть ровно один лист. Система читает только первый столбец; в нём должен быть хотя бы один самостоятельный шестизначный номер заявки. Названия колонок не проверяются.</div></div>
        </div>
      </form>
      <div class="notice warn is-hidden" data-placement-create-error></div>
    </div>
    <div class="modal-foot is-spaced"><button type="button" class="btn btn-ghost" data-close-modal="feo-placement-create-modal">Отмена</button><button type="button" class="btn btn-primary" data-placement-analyze>Проверить</button></div>
  </div>
</div>

<div id="feo-placement-confirm-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="0" data-close-on-escape="1">
  <div class="modal">
    <div class="modal-head"><span class="modal-title" data-placement-confirm-title>Подтверждение</span><button type="button" class="modal-close" data-close-modal="feo-placement-confirm-modal">&times;</button></div>
    <div class="modal-body"><div class="feo-placement-confirm-copy" data-placement-confirm-copy></div><div class="notice warn is-hidden" data-placement-confirm-error></div></div>
    <div class="modal-foot is-spaced"><button type="button" class="btn btn-ghost" data-close-modal="feo-placement-confirm-modal">Отмена</button><button type="button" class="btn btn-primary" data-placement-confirm-submit>Подтвердить</button></div>
  </div>
</div>

<div id="feo-placement-detail-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
  <div class="modal modal-lg feo-placement-detail-modal">
    <div class="modal-head"><span class="modal-title" data-placement-detail-title>Блок размещения</span><button type="button" class="modal-close" data-close-modal="feo-placement-detail-modal">&times;</button></div>
    <div class="modal-body feo-placement-detail-modal-body"><div class="feo-placement-detail-body" data-placement-detail-body></div><div class="notice warn is-hidden" data-placement-detail-error></div></div>
    <div class="modal-foot is-spaced"><button type="button" class="btn btn-ghost" data-close-modal="feo-placement-detail-modal">Закрыть</button><div class="modal-foot-actions" data-placement-detail-actions></div></div>
  </div>
</div>

<div id="feo-placement-current-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
  <div class="modal modal-lg">
    <div class="modal-head"><span class="modal-title">Актуальные заявки для карты</span><button type="button" class="modal-close" data-close-modal="feo-placement-current-modal">&times;</button></div>
    <div class="modal-body"><div class="feo-placement-list" data-placement-current-body></div><div class="notice warn is-hidden" data-placement-current-error></div></div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close-modal="feo-placement-current-modal">Закрыть</button></div>
  </div>
</div>

<div id="feo-placement-parity-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
  <div class="modal modal-lg feo-placement-parity-modal">
    <div class="modal-head"><span class="modal-title">Сверка со старой базой</span><button type="button" class="modal-close" data-close-modal="feo-placement-parity-modal">&times;</button></div>
    <div class="modal-body"><div class="feo-placement-list" data-placement-parity-body></div></div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close-modal="feo-placement-parity-modal">Закрыть</button></div>
  </div>
</div>

<script>
window.FEO_PLACEMENT_CONFIG = {
  base: <?= json_encode(app_url('/company/feo/requests/map-placement'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  csrf: <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  legacyParity: <?= json_encode($legacyParity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};
</script>
<script src="<?= app_url('/assets/js/feo-map-placement.js') ?>?v=<?= filemtime(base_path('public/assets/js/feo-map-placement.js')) ?>"></script>