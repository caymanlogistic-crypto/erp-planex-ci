<?php
require_once base_path('app/View/components/view_formatters.php');
require_once base_path('app/View/components/registry_tabler_icons.php');
$registryHeadTitle = 'Список транспорта';
$registryHeadSummary = 'Тягачи и полуприцепы · Транспортные средства перевозчиков';
$registryHeadIconHtml = registry_tabler_header_icon('truck');
$vehicleRegistryAvailable = $company !== null && ($company['status'] ?? '') === 'active' && !isset($dbError);
$registryHeadActionsHtml = $vehicleRegistryAvailable ? '<button type="button" class="btn btn-primary" onclick="openModal(\'vehicle-set-create-modal\')">Добавить новый транспорт</button>' : '';
require base_path('app/View/partials/registry_ui_head.php');
?>
<?php if ($company === null): ?>

<div class="notice warn">
    Компания не найдена. Укажите корректный `company_id`.
</div>

<?php elseif (($company['status'] ?? '') !== 'active'): ?>

<div class="notice warn">
    Компания находится в статусе «<?= e($company['status']) ?>». Работа с транспортом недоступна.
</div>

<?php elseif (isset($dbError)): ?>

<div class="notice warn">
    <?= e($dbError) ?>
</div>

<?php elseif (empty($vehicleSets)): ?>

<div class="panel">
    <div class="panel-body">
        <div class="empty-state">
            <p class="empty-title">Транспорт ещё не создан.</p>
            <p class="empty-desc">Создайте транспорт, чтобы он появился в списке и стал доступен для просмотра и редактирования.</p>
        </div>
    </div>
</div>

<?php else: ?>
<?php
function vehicle_table_num($value): string {
    if ($value === null || $value === '') return '—';
    $num = (float)$value;
    return rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
}
?>

<?php require base_path('app/View/partials/registry_ui_state_scope.php'); ?>
<div class="table-card table-card--standard registry-ui-card registry-ui-sticky-table registry-ui-scroll" data-erp-grid data-vehicle-sets-grid data-registry-id="vehicle-sets" data-registry-state-scope="<?= e($registryUiStateScope) ?>">
    <div class="table-toolbar registry-ui-toolbar">
        <div class="found-label">Найдено: <b><?= count($vehicleSets) ?></b></div>
        <div class="toolbar-right">
            <button type="button" class="registry-ui-reset" hidden>Сбросить фильтр</button>
            <div class="toolbar-sort">
                <select class="toolbar-select" data-erp-grid-sort data-table-reference-select="vehicle-sets" data-registry-field="sort" data-registry-default="date" aria-label="Сортировка транспорта">
                    <option value="date" selected>По дате добавления</option>
                    <option value="alpha">По алфавиту</option>
                </select>
            </div>
            <div class="registry-ui-search-group"><input type="search" class="toolbar-search registry-ui-search" placeholder="Поиск по таблице" aria-label="Поиск транспорта" autocomplete="off" data-registry-field="query" data-registry-default=""></div>
        </div>
    </div>
    <div class="table-scroll">
        <table class="table company-vehicle-sets-table registry-ui-table registry-ui-data registry-ui-data--wide">
            <colgroup><col class="registry-ui-id"><col class="col-type"><col class="col-main-unit"><col class="col-volume"><col class="col-capacity"><col class="col-check"><col class="col-check"><col class="col-check"><col class="col-secondary-unit"><col class="col-volume"><col class="col-capacity"><col class="col-check"><col class="col-check"><col class="col-check"></colgroup>
            <thead>
                <tr>
                    <th class="registry-ui-id">ID</th>
                    <th class="col-type">ТИП</th>
                    <th class="col-main-unit">ОСНОВНОЕ ТС</th>
                    <th class="col-volume">ОБЪЕМ (м3.)</th>
                    <th class="col-capacity">ГП (т.)</th>
                    <th class="col-check">СТС</th>
                    <th class="col-check">ДК</th>
                    <th class="col-check">ФОТО</th>
                    <th class="col-secondary-unit">ДОПОЛНИТЕЛЬНОЕ ТС</th>
                    <th class="col-volume">ОБЪЕМ (м3.)</th>
                    <th class="col-capacity">ГП (т.)</th>
                    <th class="col-check">СТС</th>
                    <th class="col-check">ДК</th>
                    <th class="col-check">ФОТО</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vehicleSets as $vs):
                    $setLabel = ui_set_type($vs['set_type'] ?? null);
                    $hasSecondary = !empty($vs['secondary_vehicle_unit_id']);
                    $secondaryLabel = '—';
                    $secVolume = '—';
                    $secCapacity = '—';
                    // Основное ТС
                    $primaryParts = [];
                    if (!empty($vs['primary_brand'])) $primaryParts[] = trim($vs['primary_brand']);
                    if (!empty($vs['primary_model'])) $primaryParts[] = trim($vs['primary_model']);
                    if (!empty($vs['primary_plate'])) $primaryParts[] = trim($vs['primary_plate']);
                    $primaryLabel = !empty($primaryParts) ? implode(' ', $primaryParts) : '—';
                    // Объём / ГП (primary)
                    $volume = vehicle_table_num($vs['primary_volume_m3'] ?? null);
                    $capacity = vehicle_table_num($vs['primary_capacity_tons'] ?? null);
                    // СТС / ДК / Фото (primary)
                    $hasSts = !empty($vs['has_sts']);
                    $hasDk = !empty($vs['has_diagnostic_card_doc']);
                    $hasPhoto = !empty($vs['has_photo']);
                    // Дополнительное ТС
                    if ($hasSecondary):
                        $secParts = [];
                        if (!empty($vs['secondary_brand'])) $secParts[] = trim($vs['secondary_brand']);
                        if (!empty($vs['secondary_model'])) $secParts[] = trim($vs['secondary_model']);
                        if (!empty($vs['secondary_plate'])) $secParts[] = trim($vs['secondary_plate']);
                        $secondaryLabel = !empty($secParts) ? implode(' ', $secParts) : '—';
                        $secVolume = vehicle_table_num($vs['secondary_volume_m3'] ?? null);
                        $secCapacity = vehicle_table_num($vs['secondary_capacity_tons'] ?? null);
                        $hasStsSec = !empty($vs['has_sts_sec']);
                        $hasDkSec = !empty($vs['has_diagnostic_card_doc_sec']);
                        $hasPhotoSec = !empty($vs['has_photo_sec']);
                    endif;
                    $primaryDocumentLabels = [$hasSts ? 'Есть' : 'НЕТ', $hasDk ? 'Есть' : 'НЕТ', $hasPhoto ? 'Есть' : 'НЕТ'];
                    $secondaryDocumentLabels = $hasSecondary
                        ? [$hasStsSec ? 'Есть' : 'НЕТ', $hasDkSec ? 'Есть' : 'НЕТ', $hasPhotoSec ? 'Есть' : 'НЕТ']
                        : ['—', '—', '—'];
                    $fullSearch = implode(' ', array_merge([(string)$vs['id'], $setLabel ?: '—', $primaryLabel, $volume, $capacity],
                        $primaryDocumentLabels, [$secondaryLabel, $secVolume, $secCapacity], $secondaryDocumentLabels));
                ?>
                <tr data-vehicle-set-id="<?= (int)$vs['id'] ?>" data-erp-sort-date="<?= (int)$vs['id'] ?>" data-registry-search="<?= e($fullSearch) ?>" tabindex="0" aria-label="Транспорт <?= (int)$vs['id'] ?>. Enter или двойное нажатие открывает карточку">
                    <td class="registry-ui-id"><?= (int)$vs['id'] ?></td>
                    <td class="col-type"><span class="<?= $setLabel !== '' ? 'registry-ui-primary' : 'registry-ui-missing' ?>"><?= e($setLabel !== '' ? $setLabel : '—') ?></span></td>
                    <td class="col-main-unit"><span class="cell-main <?= $primaryLabel !== '—' ? 'registry-ui-name' : 'registry-ui-missing' ?>" title="<?= e($primaryLabel) ?>"><?= e($primaryLabel) ?></span></td>
                    <td class="col-volume"><span class="<?= $volume !== '—' ? 'registry-ui-secondary' : 'registry-ui-missing' ?>"><?= e($volume) ?></span></td>
                    <td class="col-capacity"><span class="<?= $capacity !== '—' ? 'registry-ui-secondary' : 'registry-ui-missing' ?>"><?= e($capacity) ?></span></td>
                    <td class="col-check">
                        <?php if ($hasSts): ?>
                        <span class="table-check table-check--yes registry-ui-status registry-ui-success" title="СТС загружен">Есть</span>
                        <?php else: ?>
                        <span class="table-check table-check--no registry-ui-status registry-ui-error" title="СТС не загружен">НЕТ</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-check">
                        <?php if ($hasDk): ?>
                        <span class="table-check table-check--yes registry-ui-status registry-ui-success" title="Диагностическая карта">Есть</span>
                        <?php else: ?>
                        <span class="table-check table-check--no registry-ui-status registry-ui-error" title="Диагностическая карта не загружена">НЕТ</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-check">
                        <?php if ($hasPhoto): ?>
                        <span class="table-check table-check--yes registry-ui-status registry-ui-success" title="Фото загружено">Есть</span>
                        <?php else: ?>
                        <span class="table-check table-check--no registry-ui-status registry-ui-error" title="Фото не загружено">НЕТ</span>
                        <?php endif; ?>
                    </td>
                    <!-- Дополнительное ТС -->
                    <td class="col-secondary-unit"><span class="<?= $hasSecondary && $secondaryLabel !== '—' ? 'registry-ui-name' : 'registry-ui-missing' ?>" title="<?= $hasSecondary ? e($secondaryLabel) : '' ?>"><?= $hasSecondary ? e($secondaryLabel) : '—' ?></span></td>
                    <td class="col-volume"><span class="<?= $hasSecondary && $secVolume !== '—' ? 'registry-ui-secondary' : 'registry-ui-missing' ?>"><?= $hasSecondary ? e($secVolume) : '—' ?></span></td>
                    <td class="col-capacity"><span class="<?= $hasSecondary && $secCapacity !== '—' ? 'registry-ui-secondary' : 'registry-ui-missing' ?>"><?= $hasSecondary ? e($secCapacity) : '—' ?></span></td>
                    <td class="col-check">
                        <?php if (!$hasSecondary): ?>
                        <span class="registry-ui-missing">—</span>
                        <?php elseif ($hasStsSec): ?>
                        <span class="table-check table-check--yes registry-ui-status registry-ui-success" title="СТС загружен">Есть</span>
                        <?php else: ?>
                        <span class="table-check table-check--no registry-ui-status registry-ui-error" title="СТС не загружен">НЕТ</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-check">
                        <?php if (!$hasSecondary): ?>
                        <span class="registry-ui-missing">—</span>
                        <?php elseif ($hasDkSec): ?>
                        <span class="table-check table-check--yes registry-ui-status registry-ui-success" title="Диагностическая карта">Есть</span>
                        <?php else: ?>
                        <span class="table-check table-check--no registry-ui-status registry-ui-error" title="Диагностическая карта не загружена">НЕТ</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-check">
                        <?php if (!$hasSecondary): ?>
                        <span class="registry-ui-missing">—</span>
                        <?php elseif ($hasPhotoSec): ?>
                        <span class="table-check table-check--yes registry-ui-status registry-ui-success" title="Фото загружено">Есть</span>
                        <?php else: ?>
                        <span class="table-check table-check--no registry-ui-status registry-ui-error" title="Фото не загружено">НЕТ</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>


<?php endif; ?>

<?php if (($company ?? null) !== null && (($company['status'] ?? '') === 'active') && !isset($dbError)): ?>
<div class="modal-overlay" id="vehicle-set-create-modal" data-close-on-overlay="0" data-close-on-escape="0" data-reset-on-close="1">
  <div class="modal modal-lg driver-create-modal">
    <div class="modal-head">
      <span class="modal-title">Добавить новый транспорт</span>
      <button type="button" class="modal-close" onclick="closeModal('vehicle-set-create-modal')">✕</button>
    </div>
    <div class="modal-body">
      <?php
      $vehicleSetCreateFormMode = 'modal';
      $vehicleSetFormId = 'vehicle-set-create-form';
      $vehicleSetFormAction = '/company/vehicle-sets/create';
      require base_path('app/View/partials/company_vehicle_set_create_form.php');
      ?>
    </div>
    <div class="modal-foot is-spaced">
      <div class="modal-required-note"><span class="req">*</span> — обязательные поля</div>
      <div class="modal-foot-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('vehicle-set-create-modal')">Отмена</button>
        <button type="submit" form="vehicle-set-create-form" class="btn btn-primary">Добавить новый транспорт</button>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>
