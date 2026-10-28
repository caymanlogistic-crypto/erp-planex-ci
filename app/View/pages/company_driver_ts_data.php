<?php
require_once base_path('app/View/components/registry_tabler_icons.php');
require_once base_path('app/Service/RouteExecutorListPresentation.php');
require_once base_path('app/Service/RegistryManagerPresentation.php');
$registryHeadTitle='Данные Вод и ТС';
$registryHeadSummary='';
$registryHeadIconHtml=registry_tabler_header_icon('truck');
$registryHeadActionsHtml='';
$registryHeadExtraClass='route-executors-page-head';
require base_path('app/View/partials/registry_ui_head.php');
?>
<?php if ($company === null): ?>
<div class="notice warn">Компания не найдена или недоступна.</div>
<?php elseif ($error !== null): ?>
<div class="notice warn"><?= e($error) ?></div>
<?php elseif ($rows === []): ?>
<div class="panel"><div class="empty-state"><p class="empty-title">Нет исполнителей рейсов «Отходы»</p></div></div>
<?php else: ?>
<div class="table-card table-card--standard route-executors-card registry-ui-card registry-ui-sticky-table registry-ui-scroll driver-ts-card" data-driver-ts-list>
  <div class="table-toolbar registry-ui-toolbar">
    <div class="found-label">Найдено: <b data-driver-ts-count><?= count($rows) ?></b></div>
    <div class="toolbar-right"><button type="button" class="registry-ui-reset" data-driver-ts-reset hidden>Сбросить фильтр</button>
      <div class="toolbar-sort"><select class="toolbar-select" data-driver-ts-sort aria-label="Сортировка данных водителей и ТС"><option value="date">По дате добавления</option><option value="alpha">По алфавиту</option></select></div>
      <div class="registry-ui-search-group"><input class="toolbar-search registry-ui-search" type="search" data-driver-ts-search placeholder="Поиск по таблице" aria-label="Поиск водителя, ТС или рейса" autocomplete="off"></div>
    </div>
  </div>
  <div class="table-scroll"><table class="table registry-ui-table registry-ui-data registry-ui-table--executors driver-ts-data-table">
    <colgroup><col style="width:27%"><col style="width:29%"><col style="width:32%"><col style="width:12%"></colgroup>
    <thead><tr><th>Участвует в рейсах</th><th>Водитель</th><th>Комплект ТС</th><th>Логист</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr data-driver-ts-id="<?= (int)$row['crew_id'] ?>" data-sort-date="<?= (int)strtotime((string)$row['created_at']) ?>" data-sort-alpha="<?= e(mb_strtolower((string)$row['driver_name'])) ?>" tabindex="0" aria-label="<?= e($row['driver_name']) ?>. Двойное нажатие открывает данные водителя и ТС">
        <td data-label="Участвует в рейсах"><div class="registry-ui-value"><?php foreach ($row['trips'] as $i=>$trip): ?><?= $i ? ', ' : '' ?><span class="registry-id">#<?= (int)$trip ?></span><?php endforeach; ?></div></td>
        <td class="route-person-cell" data-label="Водитель"><div class="registry-ui-value"><strong class="registry-ui-name"><?= e($row['driver_name']) ?></strong><?php foreach (['driver_phone','driver_email'] as $field): ?><?php if (trim((string)($row[$field]??''))!==''): ?><span class="route-cell-secondary registry-ui-secondary"><?= e($row[$field]) ?></span><?php endif; ?><?php endforeach; ?></div></td>
        <td class="route-vehicle-cell" data-label="Комплект ТС"><div class="registry-ui-value"><?php foreach (['primary','secondary'] as $role): ?><?php $brand=trim((string)($row[$role.'_brand']??''));$plate=trim((string)($row[$role.'_plate']??''));if($brand===''&&$plate==='')continue; ?><span class="route-vehicle-line"><span class="route-vehicle-brand registry-ui-primary"><?= e($brand) ?></span> <span class="route-vehicle-plate registry-ui-primary"><?= e(\App\Service\RouteExecutorListPresentation::plate($plate,$role==='secondary')) ?></span><?php foreach (['capacity'=>' т','volume'=>' м³'] as $metric=>$suffix): ?><?php $value=\App\Service\RouteExecutorListPresentation::metric($row[$role.'_'.$metric]??null);if($value!==''): ?><span class="route-vehicle-metric registry-ui-secondary">· <?= e($value.$suffix) ?></span><?php endif; ?><?php endforeach; ?></span><?php endforeach; ?></div></td>
        <?php $manager=(string)($row['responsible_name']??'—'); ?>
        <td data-label="Логист" title="<?= e($manager) ?>"><div class="registry-ui-value"><span class="registry-ui-primary"><?= e(\App\Service\RegistryManagerPresentation::name($manager)) ?></span><span hidden aria-hidden="true"><?= e($manager) ?></span></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<dialog class="modal registry-ui-modal registry-ui-modal--reading driver-ts-data-dialog" data-driver-ts-dialog aria-labelledby="driver-ts-data-title">
  <div class="modal-head"><span class="modal-title" id="driver-ts-data-title">Данные водителя и ТС</span><button type="button" class="modal-close" data-driver-ts-close aria-label="Закрыть">×</button></div>
  <div class="modal-body" data-driver-ts-detail></div>
  <div class="modal-foot"><div class="modal-foot-actions"><button type="button" class="btn btn-ghost" data-driver-ts-close>Закрыть</button><a class="btn btn-primary" data-driver-ts-archive href="#">Скачать всё архивом</a></div></div>
</dialog>
<?php endif; ?>
