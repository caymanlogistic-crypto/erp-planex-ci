<?php
$routeExecutorCreateButton = '<button type="button" class="btn btn-primary" data-route-executor-create-btn>Создать исполнителя рейса</button>';
$routeExecutorExistingButton = '<button type="button" class="btn btn-ghost" data-route-executor-existing-create>Выбрать из имеющихся</button>';
$routeExecutorDocumentButton = '<a class="btn btn-ghost" href="' . app_url('/company/document-generator') . '">Создать документ</a>';
// Tabler Icons users-group (@tabler/icons 3.48.0); MIT notice: company_route_executors.tabler.LICENSE.txt.
$routeExecutorHeaderIcon = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-users-group page-head-tabler-icon" aria-hidden="true" focusable="false">
  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
  <path d="M10 13a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
  <path d="M8 21v-1a2 2 0 0 1 2 -2h4a2 2 0 0 1 2 2v1" />
  <path d="M15 5a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
  <path d="M17 10h2a2 2 0 0 1 2 2v1" />
  <path d="M5 5a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
  <path d="M3 13v-1a2 2 0 0 1 2 -2h2" />
</svg>
SVG;
$routeListContact = static function (?string $phone, ?string $email): void {
    foreach ([$phone, $email] as $value) if (trim((string)$value)!=='') echo '<span class="route-cell-secondary">'.e($value).'</span>';
};
$routeListVehicles = static function (array $row): void {
    $shown=false;
    foreach (['primary','secondary'] as $role) {
        $brand=trim((string)($row[$role.'_brand']??''));
        $plate=trim((string)($row[$role.'_plate']??''));
        $capacity=\App\Service\RouteExecutorListPresentation::metric($row[$role.'_capacity']??null);
        $volume=\App\Service\RouteExecutorListPresentation::metric($row[$role.'_volume']??null);
        if ($brand==='' && $plate==='' && $capacity==='' && $volume==='') continue;
        $shown=true;
        echo '<span class="route-vehicle-line">';
        if ($brand!=='') echo '<span class="route-vehicle-brand">'.e($brand).'</span>';
        if ($plate!=='') echo '<span class="route-vehicle-plate">'.e(\App\Service\RouteExecutorListPresentation::plate($plate,$role==='secondary')).'</span>';
        if ($capacity!=='') echo '<span class="route-vehicle-metric"><span class="route-vehicle-dot">·</span> '.e($capacity).' т</span>';
        if ($volume!=='') echo '<span class="route-vehicle-metric"><span class="route-vehicle-dot">·</span> '.e($volume).' м³</span>';
        echo '</span>';
    }
    if (!$shown) echo '—';
};
?>

<?php if ($company === null): ?>

<div class="notice warn">
    Компания не найдена. Укажите корректный company_id.
</div>

<?php elseif ($company['status'] !== 'active'): ?>

<div class="page-head route-executors-page-head registry-ui-head">
    <div class="page-head-left">
        <span class="rp-identity-icon"><?= $routeExecutorHeaderIcon ?></span>
        <span class="rp-head-text"><h1 class="page-title">Исполнители рейса</h1>
        <span class="page-summary">Исполнитель рейса: подрядчик + водитель + транспорт</span></span>
    </div>
</div>

<div class="notice warn">
    Компания находится в статусе «<?= e($company['status']) ?>». Работа с исполнителями рейса недоступна.
</div>

<?php elseif (isset($dbError)): ?>

<div class="page-head route-executors-page-head registry-ui-head">
    <div class="page-head-left">
        <span class="rp-identity-icon"><?= $routeExecutorHeaderIcon ?></span>
        <span class="rp-head-text"><h1 class="page-title">Исполнители рейса</h1>
        <span class="page-summary">Исполнитель рейса: подрядчик + водитель + транспорт</span></span>
    </div>
</div>

<div class="notice warn">
    <?= e($dbError) ?>
</div>

<?php elseif (empty($executors) && empty($reviewCards)): ?>
<?php $isLogist = ($_SESSION['role_code'] ?? '') === 'logist'; ?>

<div class="page-head route-executors-page-head registry-ui-head">
    <div class="page-head-left">
        <span class="rp-identity-icon"><?= $routeExecutorHeaderIcon ?></span>
        <span class="rp-head-text"><h1 class="page-title">Исполнители рейса</h1>
        <span class="page-summary">Исполнитель рейса: подрядчик + водитель + транспорт</span></span>
    </div>
    <div class="page-head-actions">
        <?= $routeExecutorDocumentButton ?>
        <?= $routeExecutorExistingButton ?>
        <?= $routeExecutorCreateButton ?>
    </div>
</div>

<div class="panel">
    <?php if ($isLogist && !empty($hasGrantsButAllArchived)): ?>
    <div class="empty-state">
        <div class="empty-icon">📦</div>
        <p class="empty-title">Нет доступных исполнителей рейса</p>
        <p class="empty-desc">У вас пока нет созданных исполнителей рейса, либо руководитель ещё не выдал вам доступ к существующим.</p>
    </div>
    <?php elseif ($isLogist): ?>
    <div class="empty-state">
        <p class="empty-title">Исполнители рейса ещё не созданы или пока не доступны.</p>
        <p class="empty-desc">Можно создать исполнителя рейса из доступных вам подрядчиков, водителей и транспорта. Если нужных данных нет в списках, запросите доступ у руководителя.</p>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <p class="empty-title">Исполнители рейса ещё не созданы.</p>
        <p class="empty-desc">Создайте исполнителя рейса: выберите подрядчика, водителя и транспорт — система автоматически создаст связку.</p>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>

<div class="page-head route-executors-page-head registry-ui-head">
    <div class="page-head-left">
        <span class="rp-identity-icon"><?= $routeExecutorHeaderIcon ?></span>
        <span class="rp-head-text"><h1 class="page-title">Исполнители рейса</h1>
        <span class="page-summary">Исполнитель рейса: подрядчик + водитель + транспорт</span></span>
    </div>
    <div class="page-head-actions">
        <?= $routeExecutorDocumentButton ?>
        <?= $routeExecutorExistingButton ?>
        <?= $routeExecutorCreateButton ?>
    </div>
</div>

<div class="table-card table-card--standard route-executors-card registry-ui-card" data-erp-grid data-route-executors-grid>
    <div class="table-toolbar registry-ui-toolbar">
        <div class="found-label">Найдено: <b><?= count($executors) + count($reviewCards) ?></b></div>
        <div class="toolbar-right">
            <div class="toolbar-sort">
                <span class="toolbar-sort-label">Сортировка по:</span>
                <select class="toolbar-select" data-erp-grid-sort data-table-reference-select="route">
                    <option value="date" selected>По дате добавления</option>
                    <option value="alpha">По алфавиту</option>
                </select>
            </div>
            <input type="search" class="toolbar-search registry-ui-search" name="route_executor_search" placeholder="Поиск по таблице" aria-label="Поиск исполнителя рейса" autocomplete="off">
        </div>
    </div>
    <div class="table-scroll">
        <table class="table registry-ui-table">
            <colgroup><col style="width:26%"><col style="width:23%"><col style="width:22%"><col style="width:13%"><col style="width:16%"></colgroup>
            <thead>
                <tr>
                    <th>Подрядчик</th>
                    <th>Водитель</th>
                    <th>Комплект ТС</th>
                    <th>Логист</th>
                    <th>Статус</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reviewCards as $card): ?>
                <?php $reviewStage=(string)$card['stage']; $reviewFinal=$reviewStage==='final'; $reviewEditing=$reviewStage==='final_edit'; $reviewLabel=$reviewEditing?'Исправление после отправки':(in_array($reviewStage,['partial','partial_failed'],true)?'Отправлено первое письмо':($reviewFinal?'Полный комплект отправлен':'Менеджер сохранил')); $reviewStatusText=$reviewEditing?'Исправление':($reviewFinal?'Полный комплект':(in_array($reviewStage,['partial','partial_failed'],true)?'Первое письмо':'Черновик')); $reviewStatusClass=$reviewFinal?'active':($reviewStage==='partial_failed'?'danger':($reviewStage==='partial'?'mail':'draft')); ?>
                <tr class="route-review-row<?= $reviewFinal ? ' route-review-row-final' : '' ?>" data-route-review-card-id="<?= e($card['card_id']) ?>" <?= $reviewFinal ? '' : 'data-erp-pin="1"' ?> data-erp-sort-date="<?= (int)strtotime((string)$card['updated_at']) ?>" tabindex="0" aria-label="<?= e($reviewLabel) ?>. Двойное нажатие открывает карточку">
                    <td class="route-person-cell"><strong><?= e($card['contractor_name'] ?: 'Без подрядчика') ?></strong><?php if (!$reviewFinal): ?><small><?= e($reviewLabel) ?></small><?php endif; ?><?php $routeListContact($card['contractor_phone']??'', $card['contractor_email']??''); ?></td>
                    <td class="route-person-cell"><strong><?= e($card['driver_name'] ?: '—') ?></strong><?php $routeListContact($card['driver_phone']??'', $card['driver_email']??''); ?></td>
                    <td class="route-vehicle-cell"><?php $routeListVehicles($card); ?></td>
                    <td><?= e($responsibleLogistNames[(int)($card['responsible_membership_id']??0)]??'—') ?></td>
                    <td><div class="route-review-actions"><span class="route-status route-status--<?= $reviewStatusClass ?>" title="<?= e($reviewLabel) ?>"><?= e($reviewStatusText) ?></span><?php if ($reviewFinal): ?><button type="button" class="btn btn-ghost route-review-materialize" data-route-review-materialize="<?= e($card['card_id']) ?>">Внести в справочники</button><?php endif; ?><button type="button" class="route-review-delete row-actions-btn" data-route-review-delete="<?= e($card['card_id']) ?>" aria-label="Действия с карточкой" aria-haspopup="menu" aria-expanded="false">⋯</button></div></td>
                </tr>
                <?php endforeach; ?>
                <?php foreach ($executors as $e): ?>
                <tr data-route-executor-id="<?= (int) $e['crew_id'] ?>" data-erp-sort-date="<?= (int) $e['crew_id'] ?>">
                    <td class="route-person-cell"><strong><?= e($e['contractor_name'] ?? '—') ?></strong><?php $routeListContact($e['contractor_phone']??'', $e['contractor_email']??''); ?></td>
                    <td class="route-person-cell"><strong><?= e($e['driver_name'] ?? '—') ?></strong><?php $routeListContact($e['driver_phone']??'', $e['driver_email']??''); ?></td>
                    <td class="route-vehicle-cell"><?php $routeListVehicles($e); ?></td>
                    <td><?= e($e['responsible_name'] ?? '—') ?></td>
                    <td>
                        <span class="route-status route-status--<?= $e['crew_status'] === 'active' ? 'active' : 'neutral' ?>">
                            <?= $e['crew_status'] === 'active' ? 'Активен' : 'Неактивен' ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php if ($company !== null && ($company['status'] ?? '') === 'active' && !isset($dbError)): ?>
<?php require base_path('app/View/partials/route_executor_modals.php'); ?>
<?php endif; ?>
