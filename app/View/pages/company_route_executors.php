<?php
$routeExecutorCreateButton = '<button type="button" class="btn btn-primary" data-route-executor-create-btn>Создать исполнителя рейса</button>';
$routeExecutorExistingButton = '<button type="button" class="btn btn-ghost" data-route-executor-existing-create>Выбрать из имеющихся</button>';
$routeExecutorDocumentButton = '<a class="btn btn-ghost" href="' . app_url('/company/document-generator') . '">Создать документ</a>';
?>

<?php if ($company === null): ?>

<div class="notice warn">
    Компания не найдена. Укажите корректный company_id.
</div>

<?php elseif ($company['status'] !== 'active'): ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Исполнители рейса</h1>
        <div class="page-summary"><span>Исполнитель рейса: подрядчик + водитель + транспорт</span></div>
    </div>
</div>

<div class="notice warn">
    Компания находится в статусе «<?= e($company['status']) ?>». Работа с исполнителями рейса недоступна.
</div>

<?php elseif (isset($dbError)): ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Исполнители рейса</h1>
        <div class="page-summary"><span>Исполнитель рейса: подрядчик + водитель + транспорт</span></div>
    </div>
</div>

<div class="notice warn">
    <?= e($dbError) ?>
</div>

<?php elseif (empty($executors) && empty($reviewCards)): ?>
<?php $isLogist = ($_SESSION['role_code'] ?? '') === 'logist'; ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Исполнители рейса</h1>
        <div class="page-summary"><span>Исполнитель рейса: подрядчик + водитель + транспорт</span></div>
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

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Исполнители рейса</h1>
        <div class="page-summary"><span>Исполнитель рейса: подрядчик + водитель + транспорт</span></div>
    </div>
    <div class="page-head-actions">
        <?= $routeExecutorDocumentButton ?>
        <?= $routeExecutorExistingButton ?>
        <?= $routeExecutorCreateButton ?>
    </div>
</div>

<div class="table-card table-card--standard" data-erp-grid data-route-executors-grid>
    <div class="table-toolbar">
        <div class="found-label">Найдено: <b><?= count($executors) + count($reviewCards) ?></b> карточек</div>
        <div class="toolbar-right">
            <div class="toolbar-sort">
                <span class="toolbar-sort-label">Сортировка по:</span>
                <select class="toolbar-select" data-erp-grid-sort>
                    <option value="date" selected>По дате добавления</option>
                    <option value="alpha">По алфавиту</option>
                </select>
            </div>
            <input type="search" class="toolbar-search" name="route_executor_search" placeholder="Поиск по таблице" aria-label="Поиск исполнителя рейса" autocomplete="off">
        </div>
    </div>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Подрядчик</th>
                    <th>Водитель</th>
                    <th>Телефон</th>
                    <th>ТС</th>
                    <th>Госномер</th>
                    <th>Ответственный логист</th>
                    <th>Статус</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reviewCards as $card): ?>
                <?php $reviewStage=(string)$card['stage']; $reviewLabel=in_array($reviewStage,['partial','partial_failed'],true)?'Отправлено первое письмо':($reviewStage==='final'?'Полный комплект отправлен':'Менеджер сохранил'); ?>
                <tr class="route-review-row" data-route-review-card-id="<?= e($card['card_id']) ?>" data-erp-pin="1" data-erp-sort-date="<?= (int)strtotime((string)$card['updated_at']) ?>" tabindex="0" aria-label="<?= e($reviewLabel) ?>. Двойное нажатие открывает карточку">
                    <td><strong><?= e($card['contractor_name'] ?: 'Без подрядчика') ?></strong><br><small><?= e($reviewLabel) ?></small></td>
                    <td><?= e($card['driver_name'] ?: '—') ?></td>
                    <td class="col-mono"><?= e($card['driver_phone'] ?: '—') ?></td>
                    <td><?= e($card['vehicle_type'] ?: '—') ?></td>
                    <td class="col-mono"><?= e($card['plate'] ?: '—') ?></td>
                    <td><?= e($responsibleLogistNames[(int)($card['responsible_membership_id']??0)]??'—') ?></td>
                    <td><span class="badge route-review-badge"><?= e($reviewLabel) ?></span><button type="button" class="btn btn-ghost route-review-delete" data-route-review-delete="<?= e($card['card_id']) ?>" aria-label="Удалить карточку">Удалить</button></td>
                </tr>
                <?php endforeach; ?>
                <?php foreach ($executors as $e): ?>
                <tr data-route-executor-id="<?= (int) $e['crew_id'] ?>" data-erp-sort-date="<?= (int) $e['crew_id'] ?>">
                    <td><?= e($e['contractor_name'] ?? '—') ?></td>
                    <td><?= e($e['driver_name'] ?? '—') ?></td>
                    <td class="col-mono"><?= e($e['driver_phone'] ?? '—') ?></td>
                    <td class="cell-double">
                        <span class="cell-main"><?= e(ui_set_type($e['set_type'] ?? null)) ?></span>
                        <span class="cell-sub"><?= e($e['plates'] ?? '—') ?></span>
                    </td>
                    <td class="col-mono"><?= e($e['primary_plate'] ?? '—') ?></td>
                    <td><?= e($e['responsible_name'] ?? '—') ?></td>
                    <td>
                        <span class="badge<?= $e['crew_status'] === 'active' ? ' badge-ok' : '' ?>">
                            <span class="dot"></span>
                            <?= $e['crew_status'] === 'active' ? 'Активен' : 'Неактивен' ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="table-footer">
        <span class="footer-label">Показано <b class="footer-range">1–<?= count($executors) + count($reviewCards) ?></b> из <b class="footer-total"><?= count($executors) + count($reviewCards) ?></b></span>
    </div>
</div>

<?php endif; ?>

<?php if ($company !== null && ($company['status'] ?? '') === 'active' && !isset($dbError)): ?>
<?php require base_path('app/View/partials/route_executor_modals.php'); ?>
<?php endif; ?>
