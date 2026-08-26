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
.feo-placement-summary{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.feo-placement-kpi{border:1px solid var(--line-soft);background:var(--surface-form);padding:9px 11px;min-width:180px}
.feo-placement-kpi b{font-size:18px}
.feo-placement-kpi span{display:block;font-size:11px;color:var(--text-muted);margin-top:2px}
.feo-placement-table-card{display:block;min-width:0;border:0!important;box-shadow:none!important;background:transparent!important}
.feo-placement-toolbar{min-height:42px;height:auto;padding:6px 8px;gap:8px;flex-wrap:wrap}
.feo-placement-toolbar-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.feo-placement-block-search-input{width:460px;min-width:320px;max-width:min(460px,80vw)}
.feo-placement-block-search-results{padding:8px;border-top:1px solid var(--line-soft);background:var(--surface-form)}
.feo-placement-block-search-results.is-hidden{display:none}
.feo-placement-search-summary{font-size:11px;color:var(--text-muted);margin-bottom:6px}
.feo-placement-search-table{width:100%;table-layout:fixed}
.feo-placement-search-table th,.feo-placement-search-table td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.feo-placement-search-table tbody tr[data-placement-search-block-id]{cursor:pointer}
.feo-placement-search-table tbody tr[data-placement-search-block-id]:hover{background:var(--accent-bg)}
.feo-placement-table{width:100%;table-layout:fixed}
.feo-placement-table th,.feo-placement-table td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.feo-placement-table tbody tr[data-placement-block-id]{cursor:pointer}
.feo-placement-table tbody tr[data-placement-block-id]:hover{background:var(--accent-bg)}
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
.feo-placement-live-validation.warn{color:var(--danger,#b42318)}
.feo-placement-confirm-copy{display:grid;gap:7px}
.feo-placement-confirm-effect{border:1px solid var(--line-soft);background:var(--surface-form);padding:9px 10px}
.feo-placement-danger{color:var(--danger,#b42318)}
.feo-placement-detail-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:10px}
.feo-placement-detail-meta>div{border:1px solid var(--line-soft);background:var(--surface-form);padding:8px 10px}
.feo-placement-detail-meta span{display:block;color:var(--text-muted);font-size:11px;margin-bottom:2px}
.feo-placement-list{max-height:54vh;overflow:auto}
.feo-placement-list table{width:100%;table-layout:fixed}
.feo-placement-list th,.feo-placement-list td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:900px){
  .feo-placement-form-grid{grid-template-columns:1fr}
  .feo-placement-wide{grid-column:auto}
  .feo-placement-block-search-input{width:min(100%,86vw);min-width:240px}
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
<div class="feo-placement-summary">
  <div class="feo-placement-kpi"><b><?= (int)($current['request_count'] ?? 0) ?></b><span>Актуальный состав: заявок</span></div>
  <div class="feo-placement-kpi"><b><?= e($fmtMass($current['total_mass_kg'] ?? 0)) ?> кг</b><span>Общая масса актуального состава</span></div>
  <div class="feo-placement-kpi"><b><?= count($blocks) ?></b><span>Всего блоков</span></div>
</div>

<?php if (empty($consistency['is_consistent'])): ?>
<div class="notice warn" data-placement-consistency-warning>
  <b>Актуальный состав не согласован с применяемыми блоками.</b>
  В рабочем наборе <?= (int)($consistency['actual_count'] ?? 0) ?> заявок, расчёт по блокам даёт <?= (int)($consistency['expected_count'] ?? 0) ?>.
  <button type="button" class="btn btn-secondary btn--toolbar" data-placement-reconcile>Пересчитать состав</button>
</div>
<?php endif; ?>

<div class="table-card table-card--standard feo-placement-table-card">
  <div class="table-toolbar feo-placement-toolbar">
    <div class="found-label">Блоки размещения: <b><?= count($blocks) ?></b></div>
    <div class="toolbar-right feo-placement-toolbar-actions">
      <input type="text" class="toolbar-search feo-placement-block-search-input" data-placement-block-search placeholder="Найти заявку в блоках" autocomplete="off">
      <button type="button" class="btn btn-secondary btn--toolbar" data-placement-open-current>Актуальные заявки</button>
    </div>
  </div>
  <div class="feo-placement-block-search-results is-hidden" data-placement-block-search-results></div>
  <div class="table-scroll">
    <table class="table feo-placement-table">
      <thead><tr><th style="width:8%">ID</th><th style="width:23%">Название</th><th style="width:13%">Тип</th><th style="width:12%">Заявок</th><th style="width:14%">Масса, кг</th><th style="width:14%">Источник</th><th style="width:16%">Дата / статус</th></tr></thead>
      <tbody>
      <?php foreach ($blocks as $block): ?>
        <?php $statusApplied = ($block['status'] ?? '') === 'applied'; ?>
        <tr data-placement-block-id="<?= (int)$block['id'] ?>">
          <td>#<?= (int)$block['id'] ?></td>
          <td><?= e((string)($block['name'] ?: '—')) ?></td>
          <td><?= ($block['block_type'] ?? '') === 'addition' ? 'Добавление' : 'Исключение' ?></td>
          <td><?= (int)($block['request_count'] ?? 0) ?></td>
          <td><?= e($fmtMass($block['total_mass_kg'] ?? 0)) ?></td>
          <td><?= ($block['source_type'] ?? '') === 'manual' ? 'Ручной ввод' : strtoupper((string)$block['source_type']) ?></td>
          <td><?= e($fmtDateTime($block['created_at'] ?? null)) ?> · <span class="feo-placement-status <?= $statusApplied ? 'feo-placement-status--applied' : 'feo-placement-status--disabled' ?>"><?= $statusApplied ? 'Применяется' : 'Отключён' ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$blocks): ?><tr><td colspan="7" class="text-muted">Блоки ещё не созданы.</td></tr><?php endif; ?>
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
          <div class="field feo-placement-wide feo-placement-source-file" data-placement-file><label class="field-label">Файл XLS / XLSX</label><input class="field-input" type="file" name="source_file" accept=".xls,.xlsx"><div class="field-hint">Система читает только первый столбец и отбирает самостоятельные последовательности ровно из 6 цифр.</div></div>
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
  <div class="modal modal-lg">
    <div class="modal-head"><span class="modal-title" data-placement-detail-title>Блок размещения</span><button type="button" class="modal-close" data-close-modal="feo-placement-detail-modal">&times;</button></div>
    <div class="modal-body"><div data-placement-detail-body></div><div class="notice warn is-hidden" data-placement-detail-error></div></div>
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

<script>
window.FEO_PLACEMENT_CONFIG = {
  base: <?= json_encode(app_url('/company/feo/requests/map-placement'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  csrf: <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="<?= app_url('/assets/js/feo-map-placement.js') ?>?v=<?= filemtime(base_path('public/assets/js/feo-map-placement.js')) ?>"></script>
