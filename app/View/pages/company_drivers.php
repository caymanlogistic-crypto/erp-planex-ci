<?php
require_once base_path('app/View/components/view_formatters.php');
?>
<style>
.drivers-table-card,
.drivers-table-card > .table-scroll {
    min-width: 0;
    max-width: 100%;
}
.drivers-table-card > .table-scroll {
    overflow-x: hidden;
}
.drivers-table-card .drivers-table {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    table-layout: fixed;
}
.drivers-table-card .drivers-table th,
.drivers-table-card .drivers-table td {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
}
.drivers-table-card .drivers-table th:nth-child(1) { width: 4%; }
.drivers-table-card .drivers-table th:nth-child(2) { width: 16%; }
.drivers-table-card .drivers-table th:nth-child(3) { width: 11%; }
.drivers-table-card .drivers-table th:nth-child(4) { width: 12%; }
.drivers-table-card .drivers-table th:nth-child(5) { width: 24%; }
.drivers-table-card .drivers-table th:nth-child(6) { width: 13%; }
.drivers-table-card .drivers-table th:nth-child(7) { width: 13%; }
.drivers-table-card .drivers-table th:nth-child(8) { width: 7%; }
</style>
<?php if ($company === null): ?>

<div class="notice warn">
    Компания не найдена. Укажите корректный company_id.
</div>

<?php elseif ($company['status'] !== 'active'): ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Список водителей</h1>
        <div class="page-summary"><span>Реестр водителей транспортных средств · Управление доступами и документами</span></div>
    </div>
</div>

<div class="notice warn">
    Компания находится в статусе «<?= e($company['status']) ?>». Работа с водителями недоступна.
</div>

<?php elseif (isset($dbError)): ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Список водителей</h1>
        <div class="page-summary"><span>Реестр водителей транспортных средств · Управление доступами и документами</span></div>
    </div>
</div>

<div class="notice warn">
    <?= e($dbError) ?>
</div>

<?php elseif (empty($drivers)): ?>
<?php $isLogist = ($_SESSION['role_code'] ?? '') === 'logist'; ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Список водителей</h1>
        <div class="page-summary"><span>Реестр водителей транспортных средств · Управление доступами и документами</span></div>
    </div>
    <div class="page-head-actions">
        <button type="button" class="btn btn-primary" onclick="openModal('driver-create-modal')">Добавить нового водителя</button>
    </div>
</div>

<div class="panel">
    <div class="panel-body">
        <div class="empty-state">
            <p class="empty-title">Нет доступных водителей</p>
            <p class="empty-desc">У вас пока нет созданных водителей, либо руководитель ещё не выдал вам доступ к существующим.</p>
        </div>
    </div>
</div>

<!-- Modal: create driver -->
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

<?php else: ?>
<?php
// Helper: limit text to N characters, normalising whitespace and appending ellipsis
function driver_table_limit_text(string $text, int $limit = 150): string {
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
}
?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Список водителей</h1>
        <div class="page-summary"><span>Реестр водителей транспортных средств · Управление доступами и документами</span></div>
    </div>
    <div class="page-head-actions">
        <button type="button" class="btn btn-primary" onclick="openModal('driver-create-modal')">Добавить нового водителя</button>
    </div>
</div>

<div class="table-card table-card--standard drivers-table-card" data-erp-grid>
    <div class="table-toolbar">
        <div class="found-label">Найдено: <b><?= count($drivers) ?></b> водителей</div>
        <div class="toolbar-right">
            <div class="toolbar-sort">
                <span class="toolbar-sort-label">Сортировка по:</span>
                <select class="toolbar-select" data-erp-grid-sort>
                    <option value="date" selected>По дате добавления</option>
                    <option value="alpha">По алфавиту</option>
                </select>
            </div>
            <input type="text" class="toolbar-search" placeholder="Поиск по таблице">
        </div>
    </div>
    <div class="table-scroll">
        <table class="table drivers-table">
            <thead>
                <tr>
                    <th class="col-id">ID</th>
                    <th>ФИО</th>
                    <th>КОНТАКТЫ</th>
                    <th>ПОЧТА</th>
                    <th>ПАСПОРТ</th>
                    <th>ВОД. УДОСТОВ.</th>
                    <th>СНИЛС</th>
                    <th>ФАЙЛЫ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($drivers as $d): ?>
                <tr data-driver-id="<?= $d['id'] ?>" data-erp-sort-date="<?= $d['id'] ?>">
                    <!-- ID -->
                    <td class="col-id"><?= (int)$d['id'] ?></td>

                    <!-- ФИО -->
                    <td><?= !empty($d['full_name']) ? e($d['full_name']) : '—' ?></td>

                    <!-- КОНТАКТЫ -->
                    <td>
                        <?php
                        $mainPhone = $d['main_phone'] ?? null;
                        $extraCount = (int)($d['extra_phones_count'] ?? 0);
                        if (!empty($mainPhone)):
                            echo e($mainPhone);
                            if ($extraCount > 0): echo ' (+' . $extraCount . ' доп. тел.)'; endif;
                        elseif ($extraCount > 0):
                            echo '— (+' . $extraCount . ' доп. тел.)';
                        else:
                            echo '—';
                        endif;
                        ?>
                    </td>

                    <!-- ПОЧТА -->
                    <td><?= !empty($d['email']) ? e($d['email']) : '—' ?></td>

                    <!-- ПАСПОРТ -->
                    <td>
                        <?php
                        $passportParts = [];
                        if (!empty($d['passport_number'])) $passportParts[] = $d['passport_number'];
                        if (!empty($d['passport_issued_by'])) $passportParts[] = $d['passport_issued_by'];
                        $passportIssueDate = ui_date($d['passport_issue_date'] ?? null);
                        if ($passportIssueDate !== '—') $passportParts[] = 'от ' . $passportIssueDate;

                        if (!empty($passportParts)):
                            $fullPassport = implode(' ', $passportParts);
                            $passportText = driver_table_limit_text($fullPassport, 55);
                            echo '<span title="' . e($fullPassport) . '">' . e($passportText) . '</span>';
                        else:
                            echo '—';
                        endif;
                        ?>
                    </td>

                    <!-- ВОД. УДОСТОВ. -->
                    <td>
                        <?php
                        $licenseParts = [];
                        if (!empty($d['license_number'])) $licenseParts[] = $d['license_number'];
                        $licenseIssueDate = ui_date($d['license_issue_date'] ?? null);
                        if ($licenseIssueDate !== '—') $licenseParts[] = 'от ' . $licenseIssueDate;
                        echo !empty($licenseParts) ? e(implode(' ', $licenseParts)) : '—';
                        ?>
                    </td>

                    <!-- СНИЛС -->
                    <td><?= !empty($d['snils']) ? e($d['snils']) : '—' ?></td>

                    <!-- ФАЙЛЫ -->
                    <td><?= (int)($d['files_count'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="table-footer">
        <span class="footer-label">Показано <b class="footer-range">1–<?= count($drivers) ?></b> из <b class="footer-total"><?= count($drivers) ?></b></span>
    </div>
</div>

<!-- Modal: create driver -->
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
