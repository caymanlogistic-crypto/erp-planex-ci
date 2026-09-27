<?php
$fmtKg = static fn(int $value): string => number_format($value, 0, ',', ' ');
$fmtDate = static fn(string $value): string => $value !== '' ? ui_date($value) : '—';
$warehouseFilter = (int)($_GET['warehouse'] ?? 0);
$directionFilter = in_array((string)($_GET['operation'] ?? ''), ['in', 'out'], true) ? (string)$_GET['operation'] : '';
$search = trim((string)($_GET['q'] ?? ''));
$visible = [];
foreach ($data['groups'] as $warehouseId => $groups) {
    if ($warehouseFilter > 0 && $warehouseFilter !== (int)$warehouseId) continue;
    foreach ($groups as $group) {
        if ($directionFilter !== '' && $directionFilter !== $group['direction']) continue;
        if ($search !== '' && !str_contains(mb_strtolower(implode(' ', [
            $group['flight_id'], $group['name'], $group['driver'], $group['vehicle'], implode(' ', $group['request_ids'])
        ])), mb_strtolower(ltrim($search, '#')))) continue;
        $visible[$warehouseId][] = $group;
    }
}
$visibleCount = array_sum(array_map('count', $visible));
?>
<?php foreach (['linear-trip-registry', 'waste-trip-registry', 'warehouse-stock'] as $stylesheet): ?>
<link rel="stylesheet" href="<?=app_url('/assets/css/'.$stylesheet.'.css')?>?v=<?=filemtime(base_path('public/assets/css/'.$stylesheet.'.css'))?>">
<?php endforeach; ?>
<section class="wh-page">
<div class="page-head"><div class="page-head-left"><h1 class="page-title">Заявки на складе</h1><div class="page-summary"><span>Складские рейсы и остатки заявок после каждого рейса.</span></div></div></div>
<?php if ($dbError !== null): ?>
<div class="notice warn" role="alert"><?=e($dbError)?></div>
<?php else: ?>
<?php if ($data['issue_count'] > 0): ?><div class="notice warn wh-alert" role="status"><strong>Расхождения по <?=(int)$data['issue_count']?> рейсам.</strong> <a href="#warehouse-issues">Показать подробности</a></div><?php endif; ?>
<form class="table-toolbar wh-filter" method="get" action="<?=app_url('/company/warehouses/stock')?>"><div class="toolbar-right" style="margin-left:0;width:100%">
<select class="toolbar-select" name="warehouse" aria-label="Склад"><option value="0">Все склады</option><?php foreach ($data['warehouses'] as $id => $name): ?><option value="<?=(int)$id?>" <?=$warehouseFilter===(int)$id?'selected':''?>><?=e($name)?></option><?php endforeach; ?></select>
<select class="toolbar-select" name="operation" aria-label="Операция"><option value="">Все операции</option><option value="in" <?=$directionFilter==='in'?'selected':''?>>Приход на склад</option><option value="out" <?=$directionFilter==='out'?'selected':''?>>Вывоз со склада</option></select>
<input class="toolbar-search" name="q" type="search" value="<?=e($search)?>" placeholder="Рейс, заявка, наименование, водитель" aria-label="Поиск по рейсу, заявке, наименованию или водителю">
<button class="btn btn-secondary" type="submit">Найти</button><?php if ($warehouseFilter || $directionFilter || $search !== ''): ?><a class="btn btn-ghost" href="<?=app_url('/company/warehouses/stock')?>">Сбросить</a><?php endif; ?>
<?php if ($data['issue_count'] > 0): ?><a class="btn btn-ghost" href="#warehouse-issues">Расхождения: <?=(int)$data['issue_count']?></a><?php endif; ?>
</div></form>
<div class="found-label wh-total">Найдено: <b><?=$visibleCount?></b></div>
<?php if ($visibleCount === 0): ?><div class="table-card table-card--standard"><div class="wh-empty">Рейсы не найдены.</div></div><?php endif; ?>
<?php foreach ($visible as $warehouseId => $groups): $stock = $data['current'][$warehouseId] ?? ['request_count'=>0,'weight_kg'=>0]; ?>
<div class="table-card table-card--standard wh-warehouse-table" data-erp-grid><div class="table-toolbar"><div class="found-label"><b><?=e($data['warehouses'][$warehouseId] ?? 'Склад #'.$warehouseId)?></b> · Сейчас на складе заявки: <b><?=(int)$stock['request_count']?></b> · Общий вес: <b><?=e($fmtKg((int)$stock['weight_kg']))?></b> кг</div></div>
<div class="table-scroll waste-table-scroll"><table class="table waste-registry wh-registry"><colgroup><col class="wh-col-id"><col class="wh-col-name"><col class="wh-col-driver"><col class="wh-col-vehicle"><col class="wh-col-operation"><col class="wh-col-date"><col class="wh-col-requests"><col class="wh-col-count"><col class="wh-col-mass"><col class="wh-col-stock-count"><col class="wh-col-stock-weight"></colgroup><thead><tr><th>ID</th><th>НАИМЕНОВАНИЕ</th><th>ВОДИТЕЛЬ</th><th>МАШИНА</th><th>ОПЕРАЦИЯ</th><th>ДАТА ДВИЖЕНИЯ</th><th>ЗАЯВКИ</th><th>КОЛ-ВО</th><th>МАССА, КГ</th><th>ЗАЯВКИ НА СКЛАДЕ</th><th>ВЕС НА СКЛАДЕ, КГ</th></tr></thead><tbody>
<?php foreach ($groups as $group): $problem = isset($data['issues'][$group['flight_id']]); ?>
<tr class="waste-row <?=$problem?'wh-row-error':''?>" tabindex="0" role="button" data-wh-trip="<?=e($group['key'])?>" aria-label="Подробнее о рейсе #<?=(int)$group['flight_id']?>"><td class="col-mono registry-id" <?=$problem?'title="Ошибка сверки"':''?>>#<?=(int)$group['flight_id']?></td><td class="wh-name"><?=e($group['name'])?></td><td class="wh-driver"><?=e($group['driver'])?></td><td class="wh-vehicle"><?=e($group['vehicle'])?></td><td class="wh-operation"><?=$group['direction']==='in'?'Приход на склад':'Вывоз со склада'?></td><td class="registry-date"><?=e($fmtDate($group['date']))?></td><td class="wh-request-cell"><?php foreach ($group['request_ids'] as $index => $requestId): ?><span class="wh-request-id"><?=e($requestId)?><?=$index<count($group['request_ids'])-1?',':''?></span> <?php endforeach; ?></td><td class="waste-count wh-traffic <?=e($group['direction'])?>"><?=(int)$group['request_count']?></td><td class="waste-weight wh-traffic <?=e($group['direction'])?>"><?=e($fmtKg((int)$group['mass_kg']))?></td><td class="wh-stock-count"><?=(int)$group['stock_count']?></td><td class="wh-stock-weight"><?=e($fmtKg((int)$group['stock_kg']))?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php endforeach; ?>
<div class="wh-source">Остаток рассчитан по активным движениям старого журнала складов в порядке даты и ID записи. Масса нетто, кг.</div>
<?php if ($data['issue_count'] > 0): ?><div class="table-card table-card--standard wh-issues" id="warehouse-issues"><div class="table-toolbar"><div class="found-label">Расхождения: <b><?=(int)$data['issue_count']?></b></div></div><div class="table-scroll"><table class="table wh-issue-table"><thead><tr><th>РЕЙС</th><th>СКЛАД В СТАРОЙ БАЗЕ</th><th>РАСХОЖДЕНИЕ</th><th>СКЛАДСКИЕ ДВИЖЕНИЯ</th></tr></thead><tbody><?php foreach ($data['issues'] as $issue): ?><tr><td class="col-mono">#<?=(int)$issue['flight_id']?></td><td><?=e($data['warehouses'][$issue['warehouse_id']] ?? '—')?></td><td><?=e(implode(' ', $issue['messages']))?></td><td><?=$issue['has_movements']?'Есть':'Нет в журнале'?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php foreach ($visible as $groups): foreach ($groups as $group): ?>
<div class="modal-overlay wh-trip-modal" id="wh-trip-<?=e(str_replace(':','-',$group['key']))?>" data-close-on-overlay="0" role="dialog" aria-modal="true" aria-label="Рейс #<?=(int)$group['flight_id']?>"><div class="modal modal-lg"><div class="modal-head"><span class="modal-title">Рейс #<?=(int)$group['flight_id']?> · <?=$group['direction']==='in'?'приход на склад':'вывоз со склада'?></span><button class="modal-close" type="button" aria-label="Закрыть" data-wh-close>&times;</button></div><div class="modal-body waste-modal-scroll">
<?php if (isset($data['issues'][$group['flight_id']])): ?><div class="notice warn"><strong>Ошибка сверки:</strong> <?=e(implode(' ', $data['issues'][$group['flight_id']]['messages']))?></div><?php endif; ?>
<table class="trip-view-table wh-summary"><tbody><tr><td>Наименование</td><td><?=e($group['name'])?></td></tr><tr><td>Водитель</td><td><?=e($group['driver'])?></td></tr><tr><td>Машина</td><td><?=e($group['vehicle'])?></td></tr><tr><td>Склад</td><td><?=e($data['warehouses'][$group['warehouse_id']] ?? '—')?></td></tr><tr><td>Дата движения</td><td><?=e($fmtDate($group['date']))?></td></tr><tr><td>Окончание рейса</td><td><?=e($fmtDate((string)($group['actual_end_date'] ?? '')))?></td></tr><tr><td>Заявки / масса нетто</td><td><?=(int)$group['request_count']?> / <?=e($fmtKg((int)$group['mass_kg']))?> кг</td></tr></tbody></table>
<h3 class="wh-section-title">Заявки <?=$group['direction']==='in'?'после выгрузки':'после вывоза'?></h3><div class="table-scroll"><table class="table wh-request-table"><thead><tr><th>ЗАЯВКА ФЭО</th><th><?=$group['direction']==='in'?'ПРИВЕЗЕНО, КГ':'ЗАБРАНО, КГ'?></th><th>ОСТАТОК ДО, КГ</th><th>ОСТАТОК ПОСЛЕ, КГ</th></tr></thead><tbody><?php foreach ($group['requests'] as $request): ?><tr><td class="col-mono">#<?=e($request['id'])?></td><td><?=e($fmtKg((int)$request['mass_kg']))?></td><td><?=e($fmtKg((int)$request['before_kg']))?></td><td><strong><?=e($fmtKg((int)$request['after_kg']))?></strong></td></tr><?php endforeach; ?></tbody></table></div>
</div><div class="modal-foot"><button class="btn btn-secondary" type="button" data-wh-close>Закрыть</button></div></div></div>
<?php endforeach; endforeach; ?>
<script>(function(){var previous=null;function close(){var m=document.querySelector('.wh-trip-modal.is-open');if(!m)return;m.classList.remove('is-open');document.body.classList.remove('modal-open');if(previous)previous.focus()}document.querySelectorAll('[data-wh-trip]').forEach(function(row){function open(){previous=row;var m=document.getElementById('wh-trip-'+row.dataset.whTrip.replaceAll(':','-'));if(m){m.classList.add('is-open');document.body.classList.add('modal-open');m.querySelector('[data-wh-close]').focus()}}row.addEventListener('dblclick',open);row.addEventListener('click',function(){if(matchMedia('(max-width:700px)').matches)open()});row.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();open()}})});document.querySelectorAll('.wh-trip-modal').forEach(function(m){m.querySelectorAll('[data-wh-close]').forEach(function(button){button.addEventListener('click',close)});m.addEventListener('click',function(e){if(e.target===m)close()})});document.addEventListener('keydown',function(e){if(e.key==='Escape')close()})})();</script>
<?php endif; ?>
</section>
