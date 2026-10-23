<?php
require_once base_path('app/View/components/registry_tabler_icons.php');

require_once base_path('app/View/components/view_formatters.php');
$registryHeadTitle = 'Список водителей';
$registryHeadSummary = 'Реестр водителей транспортных средств · Управление доступами и документами';
$registryHeadIconHtml = registry_tabler_header_icon('user');
$driverRegistryAvailable = $company !== null && ($company['status'] ?? '') === 'active' && !isset($dbError);
$registryHeadActionsHtml = $driverRegistryAvailable ? '<button type="button" class="btn btn-primary" onclick="openModal(\'driver-create-modal\')">Добавить нового водителя</button>' : '';
require base_path('app/View/partials/registry_ui_head.php');
?>
<?php if ($company === null): ?>
<div class="notice warn">Компания не найдена. Укажите корректный company_id.</div>
<?php elseif ($company['status'] !== 'active'): ?>
<div class="notice warn">Компания находится в статусе «<?= e($company['status']) ?>». Работа с водителями недоступна.</div>
<?php elseif (isset($dbError)): ?>
<div class="notice warn"><?= e($dbError) ?></div>
<?php elseif (empty($drivers)): ?>
<div class="panel"><div class="panel-body"><div class="empty-state">
    <p class="empty-title">Нет доступных водителей</p>
    <p class="empty-desc">У вас пока нет созданных водителей, либо руководитель ещё не выдал вам доступ к существующим.</p>
</div></div></div>
<?php else: ?>
<?php require base_path('app/View/partials/registry_ui_state_scope.php'); ?>
<div class="table-card table-card--standard drivers-table-card registry-ui-card registry-ui-sticky-table registry-ui-scroll" data-erp-grid data-registry-id="drivers" data-registry-state-scope="<?= e($registryUiStateScope) ?>">
    <div class="table-toolbar registry-ui-toolbar">
        <div class="found-label">Найдено: <b><?= count($drivers) ?></b></div>
        <div class="toolbar-right">
            <button type="button" class="registry-ui-reset" hidden>Сбросить фильтр</button>
            <div class="toolbar-sort">
                <select class="toolbar-select" data-erp-grid-sort data-table-reference-select="drivers" data-registry-field="sort" data-registry-default="date" aria-label="Сортировка водителей">
                    <option value="date" selected>По дате добавления</option>
                    <option value="alpha">По алфавиту</option>
                </select>
            </div>
            <div class="registry-ui-search-group"><input type="search" class="toolbar-search registry-ui-search" placeholder="Поиск по таблице" aria-label="Поиск водителя" autocomplete="off" data-registry-field="query" data-registry-default=""></div>
        </div>
    </div>
    <div class="table-scroll">
        <table class="table drivers-table registry-ui-table registry-ui-data">
            <colgroup><col class="registry-ui-id"><col><col><col><col><col><col><col></colgroup>
            <thead><tr><th>ID</th><th>ФИО</th><th>КОНТАКТЫ</th><th>ПОЧТА</th><th>ПАСПОРТ</th><th>ВОД. УДОСТОВ.</th><th>СНИЛС</th><th>ФАЙЛЫ</th></tr></thead>
            <tbody>
            <?php foreach ($drivers as $d): ?>
            <?php
            $passportParts = [];
            if (!empty($d['passport_number'])) $passportParts[] = $d['passport_number'];
            if (!empty($d['passport_issued_by'])) $passportParts[] = $d['passport_issued_by'];
            $passportIssueDate = ui_date($d['passport_issue_date'] ?? null);
            if ($passportIssueDate !== '—') $passportParts[] = 'от ' . $passportIssueDate;
            $passport = implode(' ', $passportParts);
            $licenseParts = [];
            if (!empty($d['license_number'])) $licenseParts[] = $d['license_number'];
            $licenseIssueDate = ui_date($d['license_issue_date'] ?? null);
            if ($licenseIssueDate !== '—') $licenseParts[] = 'от ' . $licenseIssueDate;
            $license = implode(' ', $licenseParts);
            $phone = (string)($d['main_phone'] ?? '');
            $extraCount = (int)($d['extra_phones_count'] ?? 0);
            $phoneText = ($phone !== '' ? $phone : '—') . ($extraCount > 0 ? ' (+' . $extraCount . ' доп. тел.)' : '');
            $fullSearch = implode(' ', [(string)$d['id'], $d['full_name'] ?? '', $phoneText, $d['email'] ?? '', $passport, $license, $d['snils'] ?? '', (string)($d['files_count'] ?? 0)]);
            ?>
            <tr data-driver-id="<?= (int)$d['id'] ?>" data-erp-sort-date="<?= (int)$d['id'] ?>" data-registry-search="<?= e($fullSearch) ?>" tabindex="0" aria-label="<?= e($d['full_name'] ?? 'Водитель') ?>. Enter или двойное нажатие открывает карточку">
                <td class="registry-ui-id"><?= (int)$d['id'] ?></td>
                <td><span class="cell-main <?= !empty($d['full_name']) ? 'registry-ui-name' : 'registry-ui-missing' ?>"><?= e($d['full_name'] ?: '—') ?></span></td>
                <td><span class="<?= $phone !== '' || $extraCount > 0 ? 'registry-ui-primary' : 'registry-ui-missing' ?>"><?= e($phoneText) ?></span></td>
                <td><span class="<?= !empty($d['email']) ? 'registry-ui-secondary' : 'registry-ui-missing' ?>"><?= e($d['email'] ?: '—') ?></span></td>
                <td><span class="<?= $passport !== '' ? 'registry-ui-secondary' : 'registry-ui-missing' ?>"><?= e($passport ?: '—') ?></span></td>
                <td><span class="<?= $license !== '' ? 'registry-ui-primary' : 'registry-ui-missing' ?>"><?= e($license ?: '—') ?></span></td>
                <td><span class="<?= !empty($d['snils']) ? 'registry-ui-primary' : 'registry-ui-missing' ?>"><?= e($d['snils'] ?: '—') ?></span></td>
                <td><span class="registry-ui-primary"><?= (int)($d['files_count'] ?? 0) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php if ($driverRegistryAvailable): ?>
<div class="modal-overlay" id="driver-create-modal" data-close-on-overlay="0" data-close-on-escape="0" data-reset-on-close="1">
  <div class="modal modal-lg driver-create-modal">
    <div class="modal-head">
      <span class="modal-title">Добавить нового водителя</span>
      <button type="button" class="modal-close" onclick="closeModal('driver-create-modal')">✕</button>
    </div>
    <div class="modal-body">
      <?php $driverCreateFormMode = 'modal'; $driverFormAction = '/company/drivers/modal-create'; require base_path('app/View/partials/company_driver_create_form.php'); ?>
    </div>
    <div class="modal-foot is-spaced">
      <div class="modal-required-note"><span class="req">*</span> — обязательные поля</div>
      <div class="modal-foot-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('driver-create-modal')">Отмена</button>
        <button type="submit" form="driver-create-form" class="btn btn-primary">Добавить нового водителя</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
