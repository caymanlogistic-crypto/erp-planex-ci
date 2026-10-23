<?php
require_once base_path('app/View/components/registry_tabler_icons.php');
require_once base_path('app/Service/RegistryManagerPresentation.php');

$routeExecutorCreateButton = '<button type="button" class="btn btn-primary" data-route-executor-create-btn>Создать исполнителя рейса</button>';
$routeExecutorExistingButton = '<button type="button" class="btn btn-ghost" data-route-executor-existing-create>Выбрать из имеющихся</button>';
$routeExecutorDocumentButton = '<a class="btn btn-ghost" href="' . app_url('/company/document-generator') . '">Создать документ</a>';
$routeExecutorHeaderIcon = registry_tabler_header_icon('users-group');
$routeListContact = static function (?string $phone, ?string $email): void {
    foreach ([$phone, $email] as $value) if (trim((string)$value)!=='') echo '<span class="route-cell-secondary registry-ui-secondary">'.e($value).'</span>';
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
        if ($brand!=='') echo '<span class="route-vehicle-brand registry-ui-primary">'.e($brand).'</span>';
        if ($plate!=='') echo '<span class="route-vehicle-plate registry-ui-primary">'.e(\App\Service\RouteExecutorListPresentation::plate($plate,$role==='secondary')).'</span>';
        if ($capacity!=='') echo '<span class="route-vehicle-metric registry-ui-secondary"><span class="route-vehicle-dot" aria-hidden="true">·</span> '.e($capacity).' т</span>';
        if ($volume!=='') echo '<span class="route-vehicle-metric registry-ui-secondary"><span class="route-vehicle-dot" aria-hidden="true">·</span> '.e($volume).' м³</span>';
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

<?php
$registryHeadTitle = 'Исполнители рейса';
$registryHeadSummary = 'Исполнитель рейса: подрядчик + водитель + транспорт';
$registryHeadIconHtml = $routeExecutorHeaderIcon;
$registryHeadActionsHtml = '';
$registryHeadExtraClass = 'route-executors-page-head';
require base_path('app/View/partials/registry_ui_head.php');
?>

<div class="notice warn">
    Компания находится в статусе «<?= e($company['status']) ?>». Работа с исполнителями рейса недоступна.
</div>

<?php elseif (isset($dbError)): ?>

<?php
$registryHeadTitle = 'Исполнители рейса';
$registryHeadSummary = 'Исполнитель рейса: подрядчик + водитель + транспорт';
$registryHeadIconHtml = $routeExecutorHeaderIcon;
$registryHeadActionsHtml = '';
$registryHeadExtraClass = 'route-executors-page-head';
require base_path('app/View/partials/registry_ui_head.php');
?>

<div class="notice warn">
    <?= e($dbError) ?>
</div>

<?php elseif (empty($executors) && empty($reviewCards)): ?>
<?php $isLogist = ($_SESSION['role_code'] ?? '') === 'logist'; ?>

<?php
$registryHeadTitle = 'Исполнители рейса';
$registryHeadSummary = 'Исполнитель рейса: подрядчик + водитель + транспорт';
$registryHeadIconHtml = $routeExecutorHeaderIcon;
$registryHeadActionsHtml = $routeExecutorDocumentButton . $routeExecutorExistingButton . $routeExecutorCreateButton;
$registryHeadExtraClass = 'route-executors-page-head';
require base_path('app/View/partials/registry_ui_head.php');
?>

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

<?php
$registryHeadTitle = 'Исполнители рейса';
$registryHeadSummary = 'Исполнитель рейса: подрядчик + водитель + транспорт';
$registryHeadIconHtml = $routeExecutorHeaderIcon;
$registryHeadActionsHtml = $routeExecutorDocumentButton . $routeExecutorExistingButton . $routeExecutorCreateButton;
$registryHeadExtraClass = 'route-executors-page-head';
require base_path('app/View/partials/registry_ui_head.php');
require base_path('app/View/partials/registry_ui_state_scope.php');
?>

<div class="table-card table-card--standard route-executors-card registry-ui-card registry-ui-sticky-table registry-ui-scroll" data-erp-grid data-route-executors-grid data-registry-id="route-executors" data-registry-state-scope="<?= e($registryUiStateScope) ?>">
    <div class="table-toolbar registry-ui-toolbar">
        <div class="found-label">Найдено: <b><?= count($executors) + count($reviewCards) ?></b></div>
        <div class="toolbar-right">
            <button type="button" class="registry-ui-reset" hidden>Сбросить фильтр</button>
            <div class="toolbar-sort">
                <select class="toolbar-select" data-erp-grid-sort data-table-reference-select="route" data-registry-field="sort" data-registry-default="date" aria-label="Сортировка исполнителей рейса">
                    <option value="date" selected>По дате добавления</option>
                    <option value="alpha">По алфавиту</option>
                </select>
            </div>
            <div class="registry-ui-search-group"><input type="search" class="toolbar-search registry-ui-search" name="route_executor_search" placeholder="Поиск по таблице" aria-label="Поиск исполнителя рейса" autocomplete="off" data-registry-field="query" data-registry-default=""></div>
        </div>
    </div>
    <div class="table-scroll">
        <table class="table registry-ui-table registry-ui-data registry-ui-table--executors">
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
                <?php $reviewStage=(string)$card['stage']; $reviewFinal=$reviewStage==='final'; $reviewEditing=$reviewStage==='final_edit'; $reviewStatusText=$reviewEditing?'Исправление':($reviewFinal?'Полный комплект':($reviewStage==='partial_failed'?'Ошибка отправки':($reviewStage==='partial'?'Отправлено первое письмо':'Черновик'))); $reviewStatusClass=$reviewFinal?'active':($reviewStage==='partial_failed'?'danger':($reviewStage==='partial'?'mail':'draft')); ?>
                <tr class="route-review-row<?= $reviewFinal ? ' route-review-row-final registry-ui-soft-success' : ($reviewStage === 'partial_failed' ? ' registry-ui-soft-error' : ' registry-ui-warning') ?>" data-route-review-card-id="<?= e($card['card_id']) ?>" <?= $reviewFinal ? '' : 'data-erp-pin="1"' ?> data-erp-sort-date="<?= (int)strtotime((string)$card['updated_at']) ?>" tabindex="0" aria-label="<?= e($reviewStatusText) ?>. Двойное нажатие открывает карточку">
                    <td class="route-person-cell" data-label="Подрядчик"><div class="registry-ui-value"><strong class="registry-ui-name"><?= e($card['contractor_name'] ?: 'Без подрядчика') ?></strong><?php $routeListContact($card['contractor_phone']??'', $card['contractor_email']??''); ?></div></td>
                    <td class="route-person-cell" data-label="Водитель"><div class="registry-ui-value"><strong class="registry-ui-name"><?= e($card['driver_name'] ?: '—') ?></strong><?php $routeListContact($card['driver_phone']??'', $card['driver_email']??''); ?></div></td>
                    <td class="route-vehicle-cell" data-label="Комплект ТС"><div class="registry-ui-value"><?php $routeListVehicles($card); ?></div></td>
                    <?php $managerName=(string)($responsibleLogistNames[(int)($card['responsible_membership_id']??0)]??'—'); ?>
                    <td data-label="Логист" title="<?= e($managerName) ?>"><div class="registry-ui-value"><span class="registry-ui-primary" aria-label="<?= e($managerName) ?>"><?= e(\App\Service\RegistryManagerPresentation::name($managerName)) ?></span><span hidden aria-hidden="true"> <?= e($managerName) ?></span></div></td>
                    <td data-label="Статус"><div class="registry-ui-value route-review-actions"><span class="route-status registry-ui-status route-status--<?= $reviewStatusClass ?><?= $reviewStatusClass==='active'?' registry-ui-success':($reviewStatusClass==='danger'?' registry-ui-error':'') ?>" title="<?= e($reviewStatusText) ?>"<?= $reviewStage==='partial'?' data-full-mail-label':'' ?>><?= e($reviewStatusText) ?></span><?php if ($reviewFinal): ?><button type="button" class="btn btn-ghost route-review-materialize" data-route-review-materialize="<?= e($card['card_id']) ?>">Внести в справочники</button><?php endif; ?><button type="button" class="route-review-delete row-actions-btn" data-route-review-delete="<?= e($card['card_id']) ?>" aria-label="Действия с карточкой" aria-haspopup="menu" aria-expanded="false">⋯</button></div></td>
                </tr>
                <?php endforeach; ?>
                <?php foreach ($executors as $e): ?>
                <tr data-route-executor-id="<?= (int) $e['crew_id'] ?>" data-erp-sort-date="<?= (int) $e['crew_id'] ?>">
                    <td class="route-person-cell" data-label="Подрядчик"><div class="registry-ui-value"><strong class="registry-ui-name"><?= e($e['contractor_name'] ?? '—') ?></strong><?php $routeListContact($e['contractor_phone']??'', $e['contractor_email']??''); ?></div></td>
                    <td class="route-person-cell" data-label="Водитель"><div class="registry-ui-value"><strong class="registry-ui-name"><?= e($e['driver_name'] ?? '—') ?></strong><?php $routeListContact($e['driver_phone']??'', $e['driver_email']??''); ?></div></td>
                    <td class="route-vehicle-cell" data-label="Комплект ТС"><div class="registry-ui-value"><?php $routeListVehicles($e); ?></div></td>
                    <?php $managerName=(string)($e['responsible_name']??'—'); ?>
                    <td data-label="Логист" title="<?= e($managerName) ?>"><div class="registry-ui-value"><span class="registry-ui-primary" aria-label="<?= e($managerName) ?>"><?= e(\App\Service\RegistryManagerPresentation::name($managerName)) ?></span><span hidden aria-hidden="true"> <?= e($managerName) ?></span></div></td>
                    <td data-label="Статус"><div class="registry-ui-value"><span class="route-status registry-ui-status route-status--<?= $e['crew_status'] === 'active' ? 'active registry-ui-success' : 'neutral' ?>">
                            <?= $e['crew_status'] === 'active' ? 'Активен' : 'Неактивен' ?>
                        </span></div>
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
