<?php
require_once base_path('app/View/components/view_formatters.php');
require_once base_path('app/View/components/registry_tabler_icons.php');
$registryHeadTitle = 'Список перевозчиков';
$registryHeadSummary = 'Компании-перевозчики и ИП · Управление договорами и документами';
$registryHeadIconHtml = registry_tabler_header_icon('truck-delivery');
$contractorRegistryAvailable = $company !== null && ($company['status'] ?? '') === 'active' && !isset($dbError);
$registryHeadActionsHtml = $contractorRegistryAvailable
    ? '<a href="' . e(app_url('/company/contractors/create-full')) . '" class="btn btn-secondary">Создать перевозчика + Водителя + Транспорт</a><button type="button" class="btn btn-primary" onclick="openModal(\'le-contractor-modal\')">Создать перевозчика</button>'
    : '';
require base_path('app/View/partials/registry_ui_head.php');
?>
<?php if ($company === null): ?>
<div class="notice warn">Компания не найдена. Укажите корректный company_id.</div>
<?php elseif ($company['status'] !== 'active'): ?>
<div class="notice warn">Компания находится в статусе «<?= e($company['status']) ?>». Создание перевозчиков недоступно.</div>
<?php elseif (isset($dbError)): ?>
<div class="notice warn"><?= e($dbError) ?></div>
<?php elseif (empty($contractors)): ?>
<div class="panel"><div class="panel-body"><div class="empty-state">
    <p class="empty-title">Нет доступных перевозчиков</p>
    <p class="empty-desc">У вас пока нет созданных перевозчиков, либо руководитель ещё не выдал вам доступ к существующим.</p>
</div></div></div>
<?php else: ?>
<?php require base_path('app/View/partials/registry_ui_state_scope.php'); ?>
<div class="table-card table-card--standard registry-ui-card registry-ui-sticky-table registry-ui-scroll" data-erp-grid data-registry-id="contractors" data-registry-state-scope="<?= e($registryUiStateScope) ?>">
    <div class="table-toolbar registry-ui-toolbar">
        <div class="found-label">Найдено: <b><?= count($contractors) ?></b></div>
        <div class="toolbar-right">
            <button type="button" class="registry-ui-reset" hidden>Сбросить фильтр</button>
            <div class="toolbar-sort"><select class="toolbar-select" data-erp-grid-sort data-table-reference-select="contractors" data-registry-field="sort" data-registry-default="date" aria-label="Сортировка перевозчиков">
                <option value="date" selected>По дате добавления</option><option value="alpha">По алфавиту</option>
            </select></div>
            <div class="registry-ui-search-group"><input type="search" class="toolbar-search registry-ui-search" placeholder="Поиск по таблице" aria-label="Поиск перевозчика" autocomplete="off" data-registry-field="query" data-registry-default=""></div>
        </div>
    </div>
    <div class="table-scroll">
        <table class="table contractors-table registry-ui-table registry-ui-data">
            <colgroup><col class="registry-ui-id"><col><col><?php if (($_SESSION['role_code'] ?? '') === 'company_owner'): ?><col><?php endif; ?><col></colgroup>
            <thead><tr><th class="registry-ui-id">ID</th><th>Перевозчик</th><th>Контакт</th><?php if (($_SESSION['role_code'] ?? '') === 'company_owner'): ?><th>Создал</th><?php endif; ?><th>Статус</th></tr></thead>
            <tbody>
                <?php foreach ($contractors as $c): ?>
                <?php
                    $contactName = (string)($c['primary_contact_person'] ?? '');
                    $contactDetail = (string)(($c['primary_contact_phone'] ?? '') ?: ($c['doc_email'] ?? ''));
                    $creator = ui_actor($c['created_by_role'] ?? null, $c['created_by_user_id'] ?? null, $c['created_by_name'] ?? null);
                    $contractorTypeLabel = !empty($c['contractor_type']) ? ui_contractor_type($c['contractor_type']) : '—';
                    $statusLabel = $c['status'] === 'active' ? 'Активен' : 'Неактивен';
                    $fullSearch = implode(' ', [(string)$c['id'], $c['name'] ?? '', 'ИНН ' . ($c['inn'] ?? '') . ' · ' . $contractorTypeLabel,
                        $contactName ?: '—', $contactDetail ?: 'Контакт не указан',
                        (($_SESSION['role_code'] ?? '') === 'company_owner' ? $creator : ''), $statusLabel,
                        $c['contractor_type'] ?? '', $c['status'] ?? '']);
                ?>
                <tr data-erp-sort-date="<?= (int)$c['id'] ?>" data-contractor-id="<?= (int)$c['id'] ?>" data-registry-search="<?= e($fullSearch) ?>" tabindex="0" aria-label="<?= e($c['name']) ?>. Enter или двойное нажатие открывает карточку">
                    <td class="registry-ui-id"><?= (int)$c['id'] ?></td>
                    <td><span class="cell-main registry-ui-name" title="<?= e($c['name']) ?>"><?= e($c['name']) ?></span><span class="cell-sub registry-ui-secondary">ИНН <?= e($c['inn']) ?> · <?= e($contractorTypeLabel) ?></span></td>
                    <td><span class="cell-main <?= $contactName !== '' ? 'registry-ui-primary' : 'registry-ui-missing' ?>"><?= e($contactName ?: '—') ?></span><span class="cell-sub registry-ui-secondary"><?= e($contactDetail ?: 'Контакт не указан') ?></span></td>
                    <?php if (($_SESSION['role_code'] ?? '') === 'company_owner'): ?><td><span class="registry-ui-secondary"><?= e($creator) ?></span></td><?php endif; ?>
                    <td><span class="registry-ui-status" data-status="<?= e($c['status']) ?>"><?= e($statusLabel) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($contractorRegistryAvailable): ?>
<div class="modal-overlay driver-view-overlay" id="le-contractor-modal" data-close-on-overlay="0" data-close-on-escape="0">
  <div class="modal modal-lg driver-view-modal-inner">
    <div class="modal-head">
      <span class="modal-title">Создать перевозчика</span>
      <button type="button" class="modal-close" onclick="closeModal('le-contractor-modal')">&times;</button>
    </div>
    <div class="modal-body" id="le-contractor-modal-body"><div class="driver-modal-loading">Загрузка...</div></div>
  </div>
</div>
<script>
(function initContractorLegalEntityModal() {
    window.ERP_BASE_PATH = '<?= app_base_path() ?>';
    var config = { modalId: 'le-contractor-modal', formAction: '<?= app_url('/company/contractors/create') ?>', typeField: 'contractor_type' };
    if (window.LegalEntityModal && typeof window.LegalEntityModal.init === 'function') { window.LegalEntityModal.init(config); return; }
    window.addEventListener('load', function () { if (window.LegalEntityModal && typeof window.LegalEntityModal.init === 'function') window.LegalEntityModal.init(config); }, { once: true });
})();
</script>
<?php endif; ?>
