<?php
$fmtMass = static fn($v): string => number_format((float)$v, 3, ',', ' ');
$fmtMassTable = static fn($v): string => number_format((float)$v, 0, ',', ' ');
$fmtMoney = static fn($v): string => number_format((float)$v, 2, ',', ' ');
$fmtDate = static function ($v): string {
    $t = $v ? strtotime((string)$v) : false;
    return $t === false ? '—' : date('d.m.Y H:i', $t);
};
$typeLabel = static fn(string $type): string => match ($type) {
    'rate_34_5' => '34,5 ₽/кг',
    'rate_38' => '38 ₽/кг',
    default => 'Согласование',
};
?>
<style>
.feo-cost-head{margin-bottom:10px}
.feo-cost-card{display:block;min-width:0;border:0!important;box-shadow:none!important;background:transparent!important}
.feo-cost-toolbar{min-height:42px;height:auto;padding:6px 8px;gap:8px;flex-wrap:wrap}
.feo-cost-toolbar-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.feo-cost-search{width:460px;min-width:320px;max-width:min(460px,80vw);resize:none;overflow:hidden}
.feo-cost-search-results{padding:8px;border-top:1px solid var(--line-soft);background:var(--surface-form)}
.feo-cost-search-results.is-hidden{display:none}
.feo-cost-search-summary{font-size:11px;color:var(--text-muted);margin-bottom:6px}
.feo-cost-search-table{width:100%;table-layout:fixed;min-width:0!important}
.feo-cost-search-table th,.feo-cost-search-table td{white-space:normal;overflow-wrap:anywhere}
.feo-cost-table{width:100%;table-layout:fixed;min-width:0!important}
.feo-cost-table th,.feo-cost-table td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.feo-cost-table tbody tr[data-cost-block-id]{cursor:pointer}
.feo-cost-table tbody tr[data-cost-block-id]:hover{background:var(--accent-bg)}
.feo-cost-table tr.has-integrity-issue{background:color-mix(in srgb,var(--warning) 8%,transparent)}
.feo-cost-warning{font-weight:700;color:var(--warning)}
.feo-cost-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.feo-cost-wide{grid-column:1/-1}
.feo-cost-textarea{min-height:170px;resize:vertical}
.feo-cost-edit-textarea{min-height:120px;resize:vertical}
.feo-cost-validation{margin-top:7px}
.feo-cost-validation.success{color:var(--success)}
.feo-cost-validation.warn{display:block!important;color:var(--danger)}
.feo-cost-summary-box{display:flex;gap:18px;flex-wrap:wrap;padding:9px 10px;border:1px solid var(--line-soft);background:var(--surface-form)}
.feo-cost-rate-picker{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px}
.feo-cost-rate-option{min-height:52px;padding:8px 10px;border:1px solid var(--line-soft);background:var(--surface-strong);border-radius:2px;text-align:left;display:flex;flex-direction:column;gap:2px;transition:border-color 90ms,background 90ms,color 90ms}
.feo-cost-rate-option:hover{border-color:var(--accent-line);background:var(--accent-bg)}
.feo-cost-rate-option.is-active{border-color:var(--accent);background:var(--accent-bg);color:var(--accent-deep);box-shadow:inset 2px 0 0 var(--accent)}
.feo-cost-rate-option b{font-size:12px}
.feo-cost-rate-option span{font-size:10.5px;color:var(--text-faint)}
.feo-cost-calc{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));border:1px solid var(--line-soft);background:var(--surface-form)}
.feo-cost-calc>div{padding:8px 10px;border-right:1px solid var(--line-soft)}
.feo-cost-calc>div:last-child{border-right:0}
.feo-cost-calc span{display:block;font-size:10.5px;color:var(--text-faint);margin-bottom:2px}
.feo-cost-calc b{font-size:13px}
.feo-cost-detail-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border-bottom:1px solid var(--line-soft)}
.feo-cost-detail-meta>div{padding:8px 10px;border-right:1px solid var(--line-soft);background:var(--surface-form);min-width:0}
.feo-cost-detail-meta>div:nth-child(4n){border-right:0}
.feo-cost-detail-meta span{display:block;font-size:11px;color:var(--text-muted);margin-bottom:2px}
.feo-cost-detail-meta b{display:block;min-width:0;overflow-wrap:anywhere}
.feo-cost-detail-comment{padding:8px 10px;border-bottom:1px solid var(--line-soft);background:var(--surface-strong)}
.feo-cost-detail-comment span{font-size:11px;color:var(--text-muted);display:block;margin-bottom:2px}
.feo-cost-detail-list{max-height:30vh;overflow-y:auto;overflow-x:hidden}
.feo-cost-detail-list table{width:100%;min-width:0!important;table-layout:fixed}
.feo-cost-detail-list th,.feo-cost-detail-list td{white-space:normal;overflow-wrap:anywhere}
.feo-cost-delta{font-weight:700}.feo-cost-delta.bad{color:var(--danger)}
.feo-cost-edit{padding:12px;border-top:1px solid var(--line-soft);display:grid;gap:10px;background:var(--surface-form)}
.feo-cost-edit-title{font-size:12px;font-weight:700;color:var(--text-main)}
.feo-cost-confirm-copy{display:grid;gap:7px}
.feo-cost-confirm-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;padding:9px 10px;border:1px solid var(--line-soft);background:var(--surface-form)}
.feo-cost-confirm-grid span{color:var(--text-muted)}
.feo-cost-confirm-diff{padding:9px 10px;border:1px solid var(--line-soft);background:var(--surface-strong);display:grid;gap:4px}
@media(max-width:900px){.feo-cost-grid{grid-template-columns:1fr}.feo-cost-wide{grid-column:auto}.feo-cost-search{width:min(100%,86vw);min-width:240px}.feo-cost-rate-picker,.feo-cost-calc,.feo-cost-detail-meta{grid-template-columns:1fr}.feo-cost-calc>div,.feo-cost-detail-meta>div{border-right:0;border-bottom:1px solid var(--line-soft)}.feo-cost-calc>div:last-child,.feo-cost-detail-meta>div:last-child{border-bottom:0}}
</style>

<div data-cost-page-ready></div>
<?php if (!empty($successFlash)): ?><div class="notice success"><?= e((string)$successFlash) ?></div><?php endif; ?>
<?php if (!empty($errorFlash)): ?><div class="notice warn"><?= e((string)$errorFlash) ?></div><?php endif; ?>
<?php if (!empty($dbError)): ?><div class="notice warn"><?= e((string)$dbError) ?></div><?php endif; ?>

<div class="page-head feo-cost-head">
  <div class="page-head-left">
    <h1 class="page-title">Стоимость заявок</h1>
    <div class="page-summary">Стоимость рассчитывается для заявок, находящихся в текущем составе «Размещения на карте». Одна заявка может входить только в один блок стоимости.</div>
  </div>
  <?php if (empty($dbError)): ?><div class="page-head-actions"><button type="button" class="btn btn-primary" data-cost-open-create>+ Добавить стоимость</button></div><?php endif; ?>
</div>

<?php if (empty($dbError)): ?>
<?php if (!empty($integrity['has_issues'])): ?>
<div class="notice warn"><b>Обнаружены расхождения сохранённых снимков.</b> Проблемных блоков: <?= (int)$integrity['block_count'] ?>, заявок: <?= (int)$integrity['request_count'] ?>. Откройте блок, чтобы увидеть изменение веса или отсутствие заявки в актуальном размещении.</div>
<?php endif; ?>
<div class="table-card table-card--standard feo-cost-card">
  <div class="table-toolbar feo-cost-toolbar">
    <div class="found-label">Блоков стоимости: <b><?= count($blocks) ?></b><?php if (!empty($integrity['has_issues'])): ?> · <span class="feo-cost-warning">с расхождениями: <?= (int)$integrity['block_count'] ?></span><?php endif; ?> · <span class="text-muted">двойной щелчок по строке — редактирование</span></div>
    <div class="toolbar-right feo-cost-toolbar-actions"><textarea rows="1" class="toolbar-search feo-cost-search" data-cost-search placeholder="Найти одну или несколько заявок" autocomplete="off"></textarea></div>
  </div>
  <div class="feo-cost-search-results is-hidden" data-cost-search-results></div>
  <div class="table-scroll">
    <table class="table feo-cost-table">
      <thead><tr><th style="width:7%">ID</th><th style="width:14%">Тип</th><th style="width:9%">Заявок</th><th style="width:14%">Вес, кг</th><th style="width:16%">Стоимость</th><th style="width:25%">Комментарий</th><th style="width:15%">Дата</th></tr></thead>
      <tbody>
      <?php foreach ($blocks as $block): ?>
        <tr data-cost-block-id="<?= (int)$block['id'] ?>" title="Двойной щелчок — открыть и редактировать" class="<?= !empty($block['has_integrity_issue']) ? 'has-integrity-issue' : '' ?>">
          <td>#<?= (int)$block['id'] ?><?= !empty($block['has_integrity_issue']) ? ' ⚠' : '' ?></td>
          <td><?= e($typeLabel((string)$block['pricing_type'])) ?></td>
          <td><?= (int)$block['snapshot_request_count'] ?></td>
          <td><?= e($fmtMassTable($block['snapshot_total_mass_kg'])) ?></td>
          <td><?= e($fmtMoney($block['total_cost'])) ?> ₽</td>
          <td title="<?= e((string)($block['comment'] ?? '')) ?>"><?= e(trim((string)($block['comment'] ?? '')) ?: '—') ?></td>
          <td><?= e($fmtDate($block['created_at'] ?? null)) ?><?= !empty($block['has_integrity_issue']) ? ' · ⚠' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$blocks): ?><tr><td colspan="7" class="text-muted">Стоимость заявок ещё не добавлена.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div id="feo-cost-create-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" data-close-on-overlay="1" data-close-on-escape="1">
  <div class="modal modal-lg">
    <div class="modal-head"><span class="modal-title">Добавить стоимость</span><button type="button" class="modal-close" data-close-modal="feo-cost-create-modal" aria-label="Закрыть">&times;</button></div>
    <div class="modal-body">
      <form data-cost-create-form action="<?= app_url('/company/feo/requests/costs/analyze') ?>" method="post">
        <?= csrfField() ?>
        <input type="hidden" name="pricing_type" value="agreement" data-cost-pricing-type>
        <div class="feo-cost-grid">
          <div class="field feo-cost-wide">
            <label class="field-label">Заявки</label>
            <textarea class="field-input feo-cost-textarea" name="request_ids" data-cost-ids placeholder="Вставьте обычный текст, список ID или данные из Excel. В табличных строках используется только первый столбец."></textarea>
            <div class="field-hint">Система извлекает самостоятельные шестизначные ID. После вставки список можно вручную дополнить или исправить.</div>
            <div class="notice is-hidden feo-cost-validation" data-cost-validation></div>
          </div>
          <div class="feo-cost-wide feo-cost-summary-box"><span>Выбрано: <b data-cost-count>0</b> заявок</span><span>Общий вес: <b data-cost-mass>0</b> кг</span></div>
          <div class="field feo-cost-wide">
            <label class="field-label">Тип стоимости</label>
            <div class="feo-cost-rate-picker" data-cost-rate-picker>
              <button type="button" class="feo-cost-rate-option" data-cost-type-choice="rate_34_5"><b>34,5 ₽/кг</b><span>Расчёт от сохранённого веса</span></button>
              <button type="button" class="feo-cost-rate-option" data-cost-type-choice="rate_38"><b>38 ₽/кг</b><span>Расчёт от сохранённого веса</span></button>
              <button type="button" class="feo-cost-rate-option is-active" data-cost-type-choice="agreement"><b>Согласование</b><span>Итоговая сумма вручную</span></button>
            </div>
          </div>
          <div class="feo-cost-wide feo-cost-calc">
            <div><span>Вес</span><b data-cost-calc-mass>0 кг</b></div>
            <div><span>Ставка</span><b data-cost-calc-rate>—</b></div>
            <div><span>Итого</span><b data-cost-calc-total>0,00 ₽</b></div>
          </div>
          <div class="field feo-cost-wide" data-cost-agreement-field>
            <label class="field-label">Стоимость, ₽</label>
            <input class="field-input" type="text" name="total_cost" data-cost-total placeholder="0,00" inputmode="decimal">
          </div>
          <div class="field feo-cost-wide"><label class="field-label">Комментарий, необязательно</label><textarea class="field-input" name="comment" maxlength="4000" rows="3"></textarea></div>
        </div>
      </form>
      <div class="notice warn is-hidden" data-cost-create-error></div>
    </div>
    <div class="modal-foot is-spaced"><button type="button" class="btn btn-ghost" data-close-modal="feo-cost-create-modal">Отмена</button><button type="button" class="btn btn-primary" data-cost-analyze disabled>Проверить и продолжить</button></div>
  </div>
</div>

<div id="feo-cost-detail-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" data-close-on-overlay="1" data-close-on-escape="1">
  <div class="modal modal-lg">
    <div class="modal-head"><span class="modal-title" data-cost-detail-title>Блок стоимости</span><button type="button" class="modal-close" data-close-modal="feo-cost-detail-modal" aria-label="Закрыть">&times;</button></div>
    <div class="modal-body" style="padding:0">
      <div data-cost-detail-body></div>
      <form class="feo-cost-edit" data-cost-edit-form>
        <?= csrfField() ?>
        <div class="feo-cost-edit-title">Редактирование блока</div>
        <div class="field">
          <label class="field-label">Заявки блока</label>
          <textarea class="field-input feo-cost-edit-textarea" name="request_ids" data-cost-edit-ids placeholder="Добавьте или удалите шестизначные ID заявок"></textarea>
          <div class="field-hint">Добавляемые заявки должны находиться в текущем «Размещении на карте» и не иметь стоимости в другом блоке.</div>
          <div class="notice is-hidden feo-cost-validation" data-cost-edit-validation></div>
        </div>
        <div class="feo-cost-summary-box"><span>Заявок: <b data-cost-edit-count>0</b></span><span>Вес для расчёта: <b data-cost-edit-mass>0</b> кг</span></div>
        <input type="hidden" name="pricing_type" value="agreement" data-cost-edit-type>
        <div class="field">
          <label class="field-label">Тип стоимости</label>
          <div class="feo-cost-rate-picker">
            <button type="button" class="feo-cost-rate-option" data-cost-edit-type-choice="rate_34_5"><b>34,5 ₽/кг</b><span>Пересчитать по весу блока</span></button>
            <button type="button" class="feo-cost-rate-option" data-cost-edit-type-choice="rate_38"><b>38 ₽/кг</b><span>Пересчитать по весу блока</span></button>
            <button type="button" class="feo-cost-rate-option" data-cost-edit-type-choice="agreement"><b>Согласование</b><span>Итоговая сумма вручную</span></button>
          </div>
        </div>
        <div class="feo-cost-calc">
          <div><span>Вес</span><b data-cost-edit-calc-mass>0 кг</b></div>
          <div><span>Ставка</span><b data-cost-edit-calc-rate>—</b></div>
          <div><span>Итого</span><b data-cost-edit-calc-total>0,00 ₽</b></div>
        </div>
        <div class="field" data-cost-edit-agreement-field><label class="field-label">Стоимость, ₽</label><input class="field-input" name="total_cost" data-cost-edit-total inputmode="decimal"></div>
        <div class="field"><label class="field-label">Комментарий</label><textarea class="field-input" name="comment" maxlength="4000" rows="3" data-cost-edit-comment></textarea></div>
        <div class="field-hint">При изменении состава snapshot-масса оставшихся заявок сохраняется; добавленные заявки получают snapshot по их текущей массе. Итоговый snapshot-вес блока пересчитывается из этих значений. Ставки 34,5/38 пересчитывают стоимость автоматически.</div>
      </form>
      <div class="notice warn is-hidden" data-cost-detail-error></div>
    </div>
    <div class="modal-foot is-spaced"><button type="button" class="btn btn-ghost" data-close-modal="feo-cost-detail-modal">Закрыть</button><button type="button" class="btn btn-primary" data-cost-edit-analyze>Проверить и сохранить</button></div>
  </div>
</div>

<div id="feo-cost-confirm-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" data-close-on-overlay="0" data-close-on-escape="1">
  <div class="modal modal-md">
    <div class="modal-head"><span class="modal-title" data-cost-confirm-title>Подтверждение</span><button type="button" class="modal-close" data-close-modal="feo-cost-confirm-modal" aria-label="Закрыть">&times;</button></div>
    <div class="modal-body"><div class="feo-cost-confirm-copy" data-cost-confirm-copy></div><div class="notice warn is-hidden" data-cost-confirm-error></div></div>
    <div class="modal-foot is-spaced"><button type="button" class="btn btn-ghost" data-close-modal="feo-cost-confirm-modal">Отмена</button><button type="button" class="btn btn-primary" data-cost-confirm-submit>Подтвердить</button></div>
  </div>
</div>

<script>window.FEO_REQUEST_COST_CONFIG={base:<?= json_encode(app_url('/company/feo/requests/costs'), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,csrf:<?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>};</script>
<script src="<?= e(app_url('/assets/js/feo-request-costs.js')) ?>?v=<?= filemtime(base_path('public/assets/js/feo-request-costs.js')) ?>"></script>
