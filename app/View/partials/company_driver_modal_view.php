<?php
/**
 * Modal view partial - driver view mode.
 *
 * Variables expected:
 *   $driver       array   driver row
 *   $phones       array   driver_phones rows
 *   $mainPhone    ?string main phone (string or null)
 *   $docsByType   array   ['passport'=>[], 'license'=>[], 'snils'=>[], 'other'=>[]]
 *   $zipAvailable bool    ZipArchive class exists
 *   $canEdit      bool
 *   $canDelete    bool
 */
require_once base_path('app/View/components/status_badge.php');
require_once base_path('app/View/components/view_formatters.php');

$na = '—';
$passportIssueDate = ui_date($driver['passport_issue_date'] ?? null);
$licenseIssueDate = ui_date($driver['license_issue_date'] ?? null);

$totalDocs = count($docsByType['passport'] ?? [])
           + count($docsByType['license'] ?? [])
           + count($docsByType['snils'] ?? [])
           + count($docsByType['other'] ?? []);

$docSections = [
    'Паспорт' => $docsByType['passport'] ?? [],
    'Водительское удостоверение' => $docsByType['license'] ?? [],
    'СНИЛС' => $docsByType['snils'] ?? [],
    'Прочие документы' => $docsByType['other'] ?? [],
];

$passportView = trim(implode(' ', array_filter([
    !empty($driver['passport_number']) ? $driver['passport_number'] : null,
    !empty($driver['passport_issued_by']) ? $driver['passport_issued_by'] : null,
    !empty($driver['passport_department_code']) ? $driver['passport_department_code'] : null,
    $passportIssueDate !== $na ? $passportIssueDate : null,
], static fn($value) => $value !== null && trim((string) $value) !== '')));

$licenseView = trim(implode(' ', array_filter([
    !empty($driver['license_number']) ? $driver['license_number'] : null,
    $licenseIssueDate !== $na ? $licenseIssueDate : null,
], static fn($value) => $value !== null && trim((string) $value) !== '')));
?>
<div class="modal-body driver-modal-body">
  <div class="driver-modal-layout">
    <div class="driver-modal-main">
      <h3 class="driver-view-name"><?= e($driver['full_name']) ?></h3>

      <div class="driver-view-card">
        <div class="driver-view-grid">
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Телефон</div>
            <div class="driver-view-cell driver-view-cell-value"><?= $mainPhone ? e($mainPhone) : '<span class="is-na">' . e($na) . '</span>' ?></div>
          </div>

          <?php if (!empty($phones)): ?>
            <?php foreach ($phones as $ph): ?>
              <?php if ((int) ($ph['is_main'] ?? 0) === 1) continue; ?>
              <?php
              $extraPhoneLine = trim((string) ($ph['phone'] ?? ''));
              $extraPhoneComment = trim((string) ($ph['comment'] ?? ''));
              if ($extraPhoneComment !== '') {
                  $extraPhoneLine .= ' — ' . $extraPhoneComment;
              }
              ?>
              <div class="driver-view-row">
                <div class="driver-view-cell driver-view-cell-label">Доп. телефон</div>
                <div class="driver-view-cell driver-view-cell-value"><?= $extraPhoneLine !== '' ? e($extraPhoneLine) : '<span class="is-na">' . e($na) . '</span>' ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Паспорт</div>
            <div class="driver-view-cell driver-view-cell-value"><?= $passportView !== '' ? e($passportView) : '<span class="is-na">' . e($na) . '</span>' ?></div>
          </div>

          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">ВУ</div>
            <div class="driver-view-cell driver-view-cell-value"><?= $licenseView !== '' ? e($licenseView) : '<span class="is-na">' . e($na) . '</span>' ?></div>
          </div>

          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">СНИЛС</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($driver['snils']) ? e($driver['snils']) : '<span class="is-na">' . e($na) . '</span>' ?></div>
          </div>

          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Email</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($driver['email']) ? e($driver['email']) : '<span class="is-na">' . e($na) . '</span>' ?></div>
          </div>

          <?php if (!empty($driver['comments'])): ?>
          <div class="driver-view-row driver-view-row-wide">
            <div class="driver-view-cell driver-view-cell-label">Комментарий</div>
            <div class="driver-view-cell driver-view-cell-value driver-view-comments"><?= e($driver['comments']) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="driver-modal-docs">
      <?php if ($totalDocs === 0): ?>
        <div class="driver-docs-empty">Документы не загружены.</div>
      <?php else: ?>
        <div class="file-list">
          <?php foreach ($docSections as $secName => $secDocs): ?>
            <?php if (empty($secDocs)) continue; ?>
            <?php foreach ($secDocs as $doc):
              $name = $doc['original_name'] ?? $doc['stored_name'] ?? '';
              $parts = explode('.', $name);
              $ext = count($parts) > 1 ? strtoupper(end($parts)) : '';
              $mime = $doc['mime_type'] ?? '';
              $badgeCls = 'file-type-badge';
              $badgeTxt = $na;
              if ($ext === '') {
                  if (strpos($mime, 'pdf') !== false) {
                      $ext = 'PDF';
                  } elseif (strpos($mime, 'image') !== false) {
                      $ext = 'IMG';
                  }
              }
              if ($ext === 'PDF') {
                  $badgeCls .= ' is-pdf';
                  $badgeTxt = 'PDF';
              } elseif ($ext === 'DOC' || $ext === 'DOCX' || $ext === 'RTF' || $ext === 'ODT') {
                  $badgeCls .= ' is-doc';
                  $badgeTxt = 'DOC';
              } elseif ($ext === 'XLS' || $ext === 'XLSX' || $ext === 'CSV' || $ext === 'ODS') {
                  $badgeCls .= ' is-xls';
                  $badgeTxt = 'XLS';
              } elseif (in_array($ext, ['JPG', 'JPEG', 'PNG', 'WEBP', 'GIF', 'BMP', 'TIF', 'TIFF', 'HEIC', 'HEIF'], true)) {
                  $badgeCls .= ' is-img';
                  $badgeTxt = 'IMG';
              } else {
                  $badgeCls .= ' is-other';
                  $badgeTxt = $ext ?: 'FILE';
              }
              $rowTitle = $secName;
              if ($secName === 'Прочие документы' && !empty($doc['document_type'])) {
                  $rowTitle = (string) $doc['document_type'];
              }
            ?>
            <div class="file-item file-item-predef document-file-row has-file has-existing-file driver-doc-view-item">
              <div class="<?= e($badgeCls) ?>"><?= e($badgeTxt) ?></div>
              <div class="file-info">
                <div class="file-name"><?= e($rowTitle) ?></div>
                <div class="file-meta"><?= e($name ?: 'Файл') ?></div>
              </div>
              <a href="/company/documents/view?id=<?= $doc['id'] ?>" target="_blank" rel="noopener" class="btn btn-secondary file-action-btn js-doc-popup-window">
                <span>Просмотр</span>
              </a>
              <a href="/company/documents/download?id=<?= $doc['id'] ?>" class="predef-file-clear driver-doc-download-btn" download title="Скачать файл" aria-label="Скачать файл">
                <svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                  <path d="M8 2.5V9.5M8 9.5L5.5 7M8 9.5L10.5 7M3 12.5H13" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </a>
            </div>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal-foot is-spaced">
  <div class="modal-foot-actions">
    <?php if ($canDelete): ?>
    <button type="button" class="btn btn-ghost" data-driver-delete-btn>Удалить</button>
    <?php endif; ?>
  </div>
  <div class="modal-foot-actions">
    <button type="button" class="btn btn-ghost" data-driver-view-cancel>Закрыть</button>
    <?php if ($canEdit): ?>
    <button type="button" class="btn btn-primary" data-driver-edit-btn>Редактировать</button>
    <?php endif; ?>
  </div>
</div>
