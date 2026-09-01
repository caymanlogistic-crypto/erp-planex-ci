<?php

use App\Service\WasteTripService;

$showCreateModal = (($_GET['show_create'] ?? '') === '1') || !empty($old) || !empty($formError);
$selectedStatus = trim((string) ($_GET['status'] ?? ''));
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$oldRequestIds = (string) ($old['request_ids'] ?? '');
$fmtMass = static fn($kg): string => number_format((float) $kg / 1000, 3, ',', ' ') . ' т';
$fmtDate = static fn($date): string => trim((string) $date) !== '' ? ui_date((string) $date) : '—';
$executorLabel = static function (array $row): string {
    $parts = array_values(array_filter([
        trim((string) ($row['executor_contractor_name'] ?? '')),
        trim((string) ($row['executor_driver_names'] ?? '')),
        trim((string) ($row['executor_vehicle_label'] ?? '')),
    ], static fn(string $v): bool => $v !== ''));
    return $parts !== [] ? implode(' · ', $parts) : '—';
};
?>

<?php if ($company === null): ?>
<div class="notice warn"><?= e($dbError ?? 'Компания не найдена.') ?></div>
<?php elseif ($dbError !== null): ?>
<div class="page-head"><div class="page-head-left"><h1 class="page-title">Перевозка отходов</h1><div class="page-summary"><span>Рейсы по заявкам ФЭО.</span></div></div></div>
<div class="notice warn"><?= e($dbError) ?></div>
<?php else: ?>

<style>
.waste-trip-table .waste-requests{max-width:260px;white-space:normal;overflow-wrap:anywhere}
.waste-trip-table .waste-executor{min-width:230px;white-space:normal}
.waste-trip-table .waste-actions{white-space:nowrap}
.waste-trip-table .waste-actions .btn{padding:6px 9px}
.waste-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}
.waste-form-grid .span-2{grid-column:1/-1}
.waste-form-field label{display:block;font-weight:600;margin-bottom:6px}
.waste-form-field input,.waste-form-field select,.waste-form-field textarea{width:100%}
.waste-request-help{font-size:12px;color:var(--text-muted,#667085);margin-top:5px}
.waste-date-mode{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.waste-modal-scroll{max-height:min(70vh,720px);overflow:auto}
@media(max-width:900px){.waste-form-grid{grid-template-columns:1fr}.waste-form-grid .span-2{grid-column:auto}.waste-date-mode{grid-template-columns:1fr}}
</style>

<div class="page-head">
  <div class="page-head-left">
    <h1 class="page-title">Перевозка отходов</h1>
    <div class="page-summary"><span>Реестр рейсов, сформированных из canonical заявок ФЭО.</span></div>
  </div>
  <div class="page-head-actions">
    <a class="btn btn-secondary" href="<?= app_url('/company/trips/departures/import') ?>">Импорт из старой системы</a>
    <button type="button" class="btn btn-primary" onclick="openModal('waste-trip-create-modal')">Создать рейс</button>
  </div>
</div>

<?php if ($formSuccess): ?><div class="notice success"><?= e($formSuccess) ?></div><?php endif; ?>
<?php if ($formError): ?><div class="notice warn"><?= e($formError) ?></div><?php endif; ?>

<form method="get" action="<?= app_url('/company/trips/departures') ?>" class="table-toolbar" style="margin-bottom:12px">
  <div class="toolbar-right" style="margin-left:0;width:100%">
    <select name="status" class="toolbar-select">
      <option value="">Все статусы</option>
      <?php foreach (WasteTripService::STATUS_LABELS as $value => $label): ?>
        <option value="<?= e($value) ?>" <?= $selectedStatus === $value ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <input name="q" class="toolbar-search" value="<?= e($searchQuery) ?>" placeholder="ID/legacy ID, заявка, клиент, исполнитель, водитель, госномер, дата">
    <button class="btn btn-secondary" type="submit">Найти</button>
    <?php if ($selectedStatus !== '' || $searchQuery !== ''): ?><a class="btn btn-ghost" href="<?= app_url('/company/trips/departures') ?>">Сбросить</a><?php endif; ?>
  </div>
</form>

<?php if ($trips === []): ?>
<div class="panel"><div class="panel-body"><div class="empty-state"><p class="empty-title">Рейсы перевозки отходов ещё не созданы.</p><p class="empty-desc">Создайте рейс и добавьте заявки из текущего пула ФЭО либо импортируйте исторический рейс.</p></div></div></div>
<?php else: ?>
<div class="table-card table-card--standard" data-erp-grid>
  <div class="table-toolbar"><div class="found-label">Найдено: <b><?= count($trips) ?></b> рейсов</div><div class="toolbar-right"><input type="text" class="toolbar-search" placeholder="Фильтр по таблице"></div></div>
  <div class="table-scroll">
    <table class="table waste-trip-table">
      <thead><tr><th>ID</th><th>План</th><th>Заказчик</th><th>Заявки ФЭО</th><th>Масса</th><th>Назначение</th><th>Исполнитель</th><th>Ответственный</th><th>Статус</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($trips as $trip): ?>
        <?php
          $planned = $trip['planned_start_date'] ?: (($trip['planned_start_date_from'] && $trip['planned_start_date_to']) ? $fmtDate($trip['planned_start_date_from']) . '–' . $fmtDate($trip['planned_start_date_to']) : '—');
          if ($trip['planned_start_date']) { $planned = $fmtDate($trip['planned_start_date']); }
          $destination = ($trip['destination_type'] ?? '') === 'warehouse' ? 'Склад' : trim((string) ($trip['utilizer_short_name'] ?? $trip['utilizer_full_name'] ?? 'Утилизатор'));
        ?>
        <tr data-erp-sort-date="<?= (int) $trip['id'] ?>">
          <td class="col-mono">#<?= (int) $trip['id'] ?></td>
          <td><?= e($planned) ?></td>
          <td><?= e((string) ($trip['client_name'] ?? '—')) ?></td>
          <td class="waste-requests"><?= e((string) ($trip['request_ids'] ?? '—')) ?></td>
          <td class="col-mono"><?= e($fmtMass($trip['snapshot_total_mass_kg'] ?? 0)) ?></td>
          <td><?= e($destination !== '' ? $destination : '—') ?></td>
          <td class="waste-executor"><?= e($executorLabel($trip)) ?></td>
          <td><?= e((string) ($trip['responsible_name'] ?? '—')) ?></td>
          <td><span class="<?= e(WasteTripService::statusBadgeClass((string) $trip['status'])) ?>"><?= e(WasteTripService::statusLabel((string) $trip['status'])) ?></span></td>
          <td class="waste-actions">
            <button type="button" class="btn btn-ghost" data-waste-view="<?= (int) $trip['id'] ?>">Открыть</button>
            <?php if (!in_array((string) $trip['status'], [WasteTripService::STATUS_COMPLETED, WasteTripService::STATUS_CANCELLED], true)): ?>
              <button type="button" class="btn btn-secondary" data-waste-edit="<?= (int) $trip['id'] ?>">Изменить</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="modal-overlay" id="waste-trip-create-modal" data-close-on-overlay="0">
  <div class="modal modal-lg">
    <div class="modal-head"><span class="modal-title">Создать рейс перевозки отходов</span><button type="button" class="modal-close" onclick="closeModal('waste-trip-create-modal')">&times;</button></div>
    <form method="post" action="<?= app_url('/company/trips/departures/create') ?>">
      <div class="modal-body waste-modal-scroll">
        <div class="waste-form-grid">
          <div class="waste-form-field span-2"><label>Заявки ФЭО *</label><textarea name="request_ids" rows="3" required data-waste-request-ids placeholder="Например: 285664, 281861, 257387"><?= e($oldRequestIds) ?></textarea><div class="waste-request-help">Можно разделять пробелами, новой строкой, запятыми или точкой с запятой. Перед сохранением обязательна проверка текущего пула и конфликтов.</div><div style="display:flex;gap:8px;align-items:center;margin-top:8px"><button type="button" class="btn btn-secondary" data-waste-preview>Проверить заявки</button><span class="waste-request-help" data-waste-preview-result>Проверка ещё не выполнена.</span></div></div>
          <div class="waste-form-field"><label>Заказчик *</label><select name="client_id" required><option value="">— Выберите —</option><?php foreach ($clients as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) ($old['client_id'] ?? 0) === (int) $item['id'] ? 'selected' : '' ?>><?= e((string) $item['name']) ?></option><?php endforeach; ?></select></div>
          <div class="waste-form-field"><label>Ответственный *</label><select name="responsible_user_id" required><option value="">— Выберите —</option><?php foreach ($responsibleUsers as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) ($old['responsible_user_id'] ?? ($_SESSION['user_id'] ?? 0)) === (int) $item['id'] ? 'selected' : '' ?>><?= e((string) $item['full_name']) ?></option><?php endforeach; ?></select></div>
          <div class="waste-form-field"><label>Статус *</label><select name="status" data-waste-status required><?php foreach (WasteTripService::STATUS_LABELS as $value => $label): if ($value === WasteTripService::STATUS_CANCELLED) continue; ?><option value="<?= e($value) ?>" <?= (string) ($old['status'] ?? WasteTripService::STATUS_SEARCHING) === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
          <div class="waste-form-field"><label>Исполнитель рейса</label><select name="route_executor_id"><option value="">— Пока не назначен —</option><?php foreach ($routeExecutors as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) ($old['route_executor_id'] ?? 0) === (int) $item['id'] ? 'selected' : '' ?>><?= e(trim((string) ($item['contractor_name'] ?? '')) . ' · ' . trim((string) ($item['driver_name'] ?? ''))) ?></option><?php endforeach; ?></select></div>
          <div class="waste-form-field"><label>Назначение *</label><select name="destination_type" data-waste-destination required><option value="utilizer" <?= (string) ($old['destination_type'] ?? 'utilizer') === 'utilizer' ? 'selected' : '' ?>>Утилизатор</option><option value="warehouse" <?= (string) ($old['destination_type'] ?? '') === 'warehouse' ? 'selected' : '' ?>>Склад</option></select></div>
          <div class="waste-form-field" data-waste-utilizer-field><label>Утилизатор *</label><select name="utilizer_id"><option value="">— Выберите —</option><?php foreach ($utilizers as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) ($old['utilizer_id'] ?? 0) === (int) $item['id'] ? 'selected' : '' ?>><?= e((string) ($item['short_name'] ?: $item['full_name'])) ?></option><?php endforeach; ?></select></div>
          <?php $oldPlanMode = (string) ($old['planned_start_mode'] ?? 'exact'); ?>
          <div class="waste-form-field span-2"><label>Планирование *</label><select name="planned_start_mode" data-waste-plan-mode required><option value="exact" <?= $oldPlanMode === 'exact' ? 'selected' : '' ?>>Точная дата</option><option value="range" <?= $oldPlanMode === 'range' ? 'selected' : '' ?>>Диапазон дат</option></select><div class="waste-date-mode" style="margin-top:8px"><input type="date" name="planned_start_date" data-waste-plan-exact value="<?= e((string) ($old['planned_start_date'] ?? '')) ?>" title="Точная дата"><input type="date" name="planned_start_date_from" data-waste-plan-from value="<?= e((string) ($old['planned_start_date_from'] ?? '')) ?>" title="Диапазон от"><input type="date" name="planned_start_date_to" data-waste-plan-to value="<?= e((string) ($old['planned_start_date_to'] ?? '')) ?>" title="Диапазон до"></div><div class="waste-request-help">Режим хранится явно: точная дата или диапазон.</div></div>
          <div class="waste-form-field"><label>Фактическое начало</label><input type="date" name="actual_start_date" value="<?= e((string) ($old['actual_start_date'] ?? '')) ?>"></div>
          <div class="waste-form-field"><label>Фактическое окончание</label><input type="date" name="actual_end_date" value="<?= e((string) ($old['actual_end_date'] ?? '')) ?>"></div>
          <div class="waste-form-field span-2"><label>Комментарий</label><textarea name="comment" rows="3"><?= e((string) ($old['comment'] ?? '')) ?></textarea></div>
        </div>
      </div>
      <div class="modal-foot is-spaced"><div></div><div class="modal-foot-actions"><button type="button" class="btn btn-ghost" onclick="closeModal('waste-trip-create-modal')">Отмена</button><button type="submit" class="btn btn-primary" data-waste-create-submit disabled>Создать рейс</button></div></div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="waste-trip-view-modal" data-close-on-overlay="0"><div class="modal modal-lg"><div class="modal-head"><span class="modal-title" id="waste-trip-view-title">Просмотр рейса</span><button type="button" class="modal-close" onclick="closeModal('waste-trip-view-modal')">&times;</button></div><div id="waste-trip-view-body"><div class="modal-body">Загрузка...</div></div></div></div>
<div class="modal-overlay" id="waste-trip-edit-modal" data-close-on-overlay="0"><div class="modal modal-lg"><div class="modal-head"><span class="modal-title" id="waste-trip-edit-title">Изменить рейс</span><button type="button" class="modal-close" onclick="closeModal('waste-trip-edit-modal')">&times;</button></div><div id="waste-trip-edit-body"><div class="modal-body">Загрузка...</div></div></div></div>

<script>
(function(){
  function syncDestination(root){
    var select=root.querySelector('[data-waste-destination]');
    var field=root.querySelector('[data-waste-utilizer-field]');
    if(!select||!field)return;
    field.style.display=select.value==='utilizer'?'block':'none';
    var util=field.querySelector('select[name="utilizer_id"]');
    if(util) util.required=select.value==='utilizer';
  }
  function syncPlanMode(root){
    var mode=root.querySelector('[data-waste-plan-mode]');
    if(!mode)return;
    var exact=root.querySelector('[data-waste-plan-exact]');
    var from=root.querySelector('[data-waste-plan-from]');
    var to=root.querySelector('[data-waste-plan-to]');
    var isExact=mode.value==='exact';
    var isRange=mode.value==='range';
    if(exact){exact.style.display=isExact?'block':'none'; exact.required=isExact; if(isRange) exact.value='';}
    if(from){from.style.display=isRange?'block':'none'; from.required=isRange; if(isExact) from.value='';}
    if(to){to.style.display=isRange?'block':'none'; to.required=isRange; if(isExact) to.value='';}
  }
  async function previewRequests(form){
    var result=form.querySelector('[data-waste-preview-result]');
    var submit=form.querySelector('[data-waste-create-submit]');
    if(result) result.textContent='Проверка...';
    if(submit) submit.disabled=true;
    try{
      var fd=new FormData(form);
      var response=await fetch('<?= app_url('/company/trips/departures/preview') ?>',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
      var payload=await response.json();
      if(!response.ok||!payload.success){
        var parts=[];
        if(payload.message)parts.push(payload.message);
        if(payload.missing&&payload.missing.length)parts.push('Не найдены: '+payload.missing.join(', '));
        if(payload.occupied&&Object.keys(payload.occupied).length)parts.push('Уже в рейсах: '+Object.entries(payload.occupied).map(function(x){return x[0]+' → #'+x[1];}).join(', '));
        if(result) result.textContent=parts.join(' · ')||'Есть блокирующие конфликты.';
        return;
      }
      var tonnes=(Number(payload.total_mass_kg||0)/1000).toLocaleString('ru-RU',{minimumFractionDigits:3,maximumFractionDigits:3});
      if(result) result.textContent='Найдено '+payload.found_count+'/'+payload.input_count+' · масса '+tonnes+' т · конфликтов нет.';
      if(submit) submit.disabled=false;
    }catch(e){
      if(result) result.textContent='Не удалось выполнить проверку заявок.';
    }
  }
  document.querySelectorAll('form').forEach(function(form){
    syncDestination(form);
    syncPlanMode(form);
    var sel=form.querySelector('[data-waste-destination]');
    if(sel)sel.addEventListener('change',function(){syncDestination(form);});
    var plan=form.querySelector('[data-waste-plan-mode]');
    if(plan)plan.addEventListener('change',function(){syncPlanMode(form);});
    var preview=form.querySelector('[data-waste-preview]');
    if(preview)preview.addEventListener('click',function(){previewRequests(form);});
    var ids=form.querySelector('[data-waste-request-ids]');
    if(ids)ids.addEventListener('input',function(){var submit=form.querySelector('[data-waste-create-submit]');if(submit)submit.disabled=true;var r=form.querySelector('[data-waste-preview-result]');if(r)r.textContent='Состав изменён — выполните проверку снова.';});
  });
  async function loadModal(kind,id){
    var modalId='waste-trip-'+kind+'-modal';
    var body=document.getElementById('waste-trip-'+kind+'-body');
    body.innerHTML='<div class="modal-body">Загрузка...</div>';
    openModal(modalId);
    try{
      var url='<?= app_url('/company/trips/departures') ?>/'+encodeURIComponent(id)+'/modal-'+(kind==='view'?'view':'edit');
      var response=await fetch(url,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
      body.innerHTML=await response.text();
      if(!response.ok) return;
      var title=body.querySelector('[data-waste-modal-title]');
      if(title) document.getElementById('waste-trip-'+kind+'-title').textContent=title.getAttribute('data-waste-modal-title');
      syncDestination(body);
      syncPlanMode(body);
      var sel=body.querySelector('[data-waste-destination]');
      if(sel)sel.addEventListener('change',function(){syncDestination(body);});
      var plan=body.querySelector('[data-waste-plan-mode]');
      if(plan)plan.addEventListener('change',function(){syncPlanMode(body);});
    }catch(e){body.innerHTML='<div class="modal-body"><div class="notice warn">Не удалось загрузить данные рейса.</div></div>';}
  }
  document.querySelectorAll('[data-waste-view]').forEach(function(btn){btn.addEventListener('click',function(){loadModal('view',btn.getAttribute('data-waste-view'));});});
  document.querySelectorAll('[data-waste-edit]').forEach(function(btn){btn.addEventListener('click',function(){loadModal('edit',btn.getAttribute('data-waste-edit'));});});
  <?php if ($showCreateModal): ?>openModal('waste-trip-create-modal');<?php endif; ?>
})();
</script>
<?php endif; ?>
