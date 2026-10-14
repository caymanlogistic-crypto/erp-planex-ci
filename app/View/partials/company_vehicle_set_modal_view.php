<?php
$na = '—';

$primaryPlateTitle = trim((string) ($vehicleSet['primary_plate'] ?? ($unitsByRole['primary']['plate_number'] ?? '')));
$secondaryPlateTitle = trim((string) ($vehicleSet['secondary_plate'] ?? ($unitsByRole['secondary']['plate_number'] ?? '')));
$vehicleTitle = trim(implode(' + ', array_filter([$primaryPlateTitle, $secondaryPlateTitle])));
if ($vehicleTitle === '') $vehicleTitle = 'Транспорт';

$statusMap = ['active' => 'Активен', 'inactive' => 'Неактивен', 'archived' => 'Удалён'];

$formatMeasure = static function ($value): string {
    if ($value === null || $value === '') return '';
    $number = (float) $value;
    if ($number <= 0) return '';
    return rtrim(rtrim(number_format($number, 2, ',', ' '), '0'), ',');
};

$buildUnitLine = static function (array $unit) use ($na, $formatMeasure): string {
    $parts = [];
    $plate = trim((string) ($unit['plate_number'] ?? ''));
    $brand = trim((string) ($unit['brand'] ?? ''));
    $model = trim((string) ($unit['model'] ?? ''));
    $vin = trim((string) ($unit['vin'] ?? ''));
    if ($plate !== '') $parts[] = $plate;
    $brandModel = trim($brand . ' ' . $model);
    if ($brandModel !== '') $parts[] = $brandModel;
    if ($vin !== '') $parts[] = 'VIN ' . $vin;
    $capacity = $formatMeasure($unit['capacity_tons'] ?? null);
    if ($capacity !== '') $parts[] = 'г/п ' . $capacity . ' т';
    $volume = $formatMeasure($unit['volume_m3'] ?? null);
    if ($volume !== '') $parts[] = 'объём ' . $volume . ' м³';
    return $parts ? implode(' · ', $parts) : $na;
};

$buildDiagLine = static function (array $unit) use ($na): string {
    $parts = [];
    $number = trim((string) ($unit['diagnostic_card_number'] ?? ''));
    $date = ui_date($unit['diagnostic_card_date'] ?? null);
    if ($number !== '') $parts[] = $number;
    if ($date !== $na) $parts[] = $date;
    return $parts ? implode(' · ', $parts) : $na;
};

$badgeMeta = static function (array $doc) use ($na): array {
    $name = (string) ($doc['original_name'] ?? $doc['stored_name'] ?? '');
    $mime = (string) ($doc['mime_type'] ?? '');
    $parts = explode('.', $name);
    $ext = count($parts) > 1 ? strtoupper((string) end($parts)) : '';
    if ($ext === '') {
        if (strpos($mime, 'pdf') !== false) $ext = 'PDF';
        elseif (strpos($mime, 'image') !== false) $ext = 'IMG';
    }
    $badgeCls = 'file-type-badge'; $badgeTxt = $na;
    if ($ext === 'PDF') { $badgeCls .= ' is-pdf'; $badgeTxt = 'PDF'; }
    elseif (in_array($ext, ['DOC','DOCX','RTF','ODT'], true)) { $badgeCls .= ' is-doc'; $badgeTxt = 'DOC'; }
    elseif (in_array($ext, ['XLS','XLSX','CSV','ODS'], true)) { $badgeCls .= ' is-xls'; $badgeTxt = 'XLS'; }
    elseif (in_array($ext, ['JPG','JPEG','PNG','WEBP','GIF','BMP','TIF','TIFF','HEIC','HEIF'], true)) { $badgeCls .= ' is-img'; $badgeTxt = 'IMG'; }
    else { $badgeCls .= ' is-other'; $badgeTxt = $ext !== '' ? $ext : 'FILE'; }
    return [$badgeCls, $badgeTxt, $name !== '' ? $name : 'Файл'];
};
?>
<div class="modal-body driver-modal-body">
  <div class="driver-modal-layout">
    <div class="driver-modal-main">
      <h3 class="driver-view-name"><?= e($vehicleTitle) ?></h3>
      <div class="driver-view-card">
        <div class="driver-view-grid">
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Тип комплекта</div>
            <div class="driver-view-cell driver-view-cell-value"><?= e(ui_set_type($vehicleSet['set_type'] ?? null) ?: $na) ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label"><?= e($unitTitles['primary'] ?? 'Основная единица') ?></div>
            <div class="driver-view-cell driver-view-cell-value"><?= e($buildUnitLine($unitsByRole['primary'] ?? [])) ?></div>
          </div>
          <?php if (!empty($unitsByRole['secondary'])): ?>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label"><?= e($unitTitles['secondary'] ?? 'Доп. единица') ?></div>
            <div class="driver-view-cell driver-view-cell-value"><?= e($buildUnitLine($unitsByRole['secondary'])) ?></div>
          </div>
          <?php endif; ?>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Статус</div>
            <div class="driver-view-cell driver-view-cell-value"><?= e($statusMap[$vehicleSet['status'] ?? ''] ?? $na) ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Диагностическая карта</div>
            <div class="driver-view-cell driver-view-cell-value"><?= e($buildDiagLine($unitsByRole['primary'] ?? [])) ?></div>
          </div>
          <?php if (!empty($unitsByRole['secondary'])): ?>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Диагностическая карта 2</div>
            <div class="driver-view-cell driver-view-cell-value"><?= e($buildDiagLine($unitsByRole['secondary'])) ?></div>
          </div>
          <?php endif; ?>
          <?php if (!empty($vehicleSet['comments'])): ?>
          <div class="driver-view-row driver-view-row-wide">
            <div class="driver-view-cell driver-view-cell-label">Комментарий</div>
            <div class="driver-view-cell driver-view-cell-value driver-view-comments"><?= e((string) $vehicleSet['comments']) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="driver-modal-docs">
      <div class="file-list">
        <?php $hasDocs = false; ?>
        <?php foreach (['primary', 'secondary'] as $role): ?>
          <?php if (empty($docsByRole[$role])) continue; ?>
          <?php $hasDocs = true; ?>
          <div class="driver-doc-section-title"><?= e($unitTitles[$role] ?? ($role === 'secondary' ? 'Доп. единица' : 'Основная единица')) ?></div>
          <?php foreach ($docsByRole[$role] as $doc): ?>
            <?php [$badgeCls, $badgeTxt, $fileLabel] = $badgeMeta($doc); ?>
            <div class="file-item file-item-predef document-file-row has-file has-existing-file driver-doc-view-item">
              <div class="<?= e($badgeCls) ?>"><?= e($badgeTxt) ?></div>
              <div class="file-info">
                <div class="file-name"><?= e((string) ($doc['document_type'] ?? 'Документ')) ?></div>
                <div class="file-meta"><?= e($fileLabel) ?></div>
              </div>
              <a href="/company/documents/view?id=<?= (int) $doc['id'] ?>" target="_blank" rel="noopener" class="btn btn-secondary file-action-btn js-doc-popup-window"><span>Просмотр</span></a>
              <a href="/company/documents/download?id=<?= (int) $doc['id'] ?>" class="predef-file-clear driver-doc-download-btn" download title="Скачать файл" aria-label="Скачать файл">
                <svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 2.5V9.5M8 9.5L5.5 7M8 9.5L10.5 7M3 12.5H13" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </a>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (!$hasDocs): ?><div class="driver-docs-empty">Документы не загружены.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="modal-foot is-spaced">
  <div class="modal-foot-actions"><?php if ($canDelete): ?><button type="button" class="btn btn-ghost" data-vehicle-set-delete-btn>Удалить</button><?php endif; ?></div>
  <div class="modal-foot-actions">
    <button type="button" class="btn btn-ghost" data-vehicle-set-close-btn>Закрыть</button>
    <?php if ($canEdit): ?><button type="button" class="btn btn-primary" data-vehicle-set-edit-btn>Редактировать</button><?php endif; ?>
  </div>
</div>