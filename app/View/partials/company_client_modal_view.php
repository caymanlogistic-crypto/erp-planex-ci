<?php
require_once base_path('app/View/components/status_badge.php');
require_once base_path('app/View/components/view_formatters.php');

$clientContacts = $contacts ?? [];
$clientDocuments = $clientDocuments ?? [];
$clientCanEdit = (bool) ($canEdit ?? false);
$clientCanArchive = (bool) ($canArchive ?? false);
$clientArchiveBlocked = (string) ($archiveBlockedMessage ?? '');
?>
<div class="modal-body driver-modal-body">
  <div class="driver-modal-layout">
    <div class="driver-modal-main">
      <h3 class="driver-view-name"><?= e($client['name'] ?? 'Клиент') ?></h3>

      <div class="driver-view-card">
        <div class="driver-view-grid">
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">ИНН</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($client['inn']) ? '<code>' . e($client['inn']) . '</code>' : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Тип</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($client['entity_type']) ? e(ui_contractor_type($client['entity_type'])) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Статус</div>
            <div class="driver-view-cell driver-view-cell-value"><?= renderStatusBadge($client['status'] ?? null) ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">КПП</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($client['kpp']) ? e($client['kpp']) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">ОГРН</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($client['ogrn']) ? e($client['ogrn']) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Руководитель</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($client['director_full_name']) ? e($client['director_full_name']) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Должность</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($client['director_position']) ? e($client['director_position']) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row driver-view-row-wide">
            <div class="driver-view-cell driver-view-cell-label">Юридический адрес</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($client['legal_address']) ? nl2br(e($client['legal_address'])) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row driver-view-row-wide">
            <div class="driver-view-cell driver-view-cell-label">Фактический адрес</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($client['physical_address']) ? nl2br(e($client['physical_address'])) : '<span class="is-na">—</span>' ?></div>
          </div>
          <?php if (!empty($client['comments'])): ?>
          <div class="driver-view-row driver-view-row-wide">
            <div class="driver-view-cell driver-view-cell-label">Комментарий</div>
            <div class="driver-view-cell driver-view-cell-value driver-view-comments"><?= e($client['comments']) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="driver-modal-docs">
      <div class="driver-doc-section-title">Контакты</div>
      <?php if (empty($clientContacts)): ?>
      <div class="driver-docs-empty">Контакты не указаны.</div>
      <?php else: ?>
      <div class="file-list">
        <?php foreach ($clientContacts as $contact): ?>
        <div class="file-item file-item-predef document-file-row has-file has-existing-file driver-doc-view-item">
          <div class="file-type-badge is-doc"><?= !empty($contact['is_primary']) ? 'MAIN' : 'INFO' ?></div>
          <div class="file-info">
            <div class="file-name"><?= e($contact['contact_person'] ?? 'Контакт') ?></div>
            <div class="file-meta">
              <?= e($contact['phone'] ?? '') ?: '—' ?>
              <?php if (!empty($contact['email'])): ?> · <?= e($contact['email']) ?><?php endif; ?>
              <?php if (!empty($contact['is_document_email'])): ?> · для документов<?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="driver-doc-section-title mt-3">Документы</div>
      <?php $clientDocs = $clientDocuments ?? []; ?>
      <?php if (empty($clientDocs)): ?>
      <div class="driver-docs-empty">Документы не загружены.</div>
      <?php else: ?>
      <div class="file-list">
        <?php foreach ($clientDocs as $doc):
          $docId = (int) ($doc['id'] ?? 0);
          if ($docId <= 0) continue;
          $name = $doc['original_name'] ?? $doc['stored_name'] ?? '';
          $metaText = $name ?: 'Файл';
          $parts = explode('.', $name);
          $ext = count($parts) > 1 ? strtoupper(end($parts)) : '';
          $mime = $doc['mime_type'] ?? '';
          $badgeCls = 'file-type-badge';
          $badgeTxt = '—';
          if ($ext === '') {
              if (strpos($mime, 'pdf') !== false) $ext = 'PDF';
              elseif (strpos($mime, 'image') !== false) $ext = 'IMG';
          }
          if ($ext === 'PDF') { $badgeCls .= ' is-pdf'; $badgeTxt = 'PDF'; }
          elseif ($ext === 'DOC' || $ext === 'DOCX' || $ext === 'RTF' || $ext === 'ODT') { $badgeCls .= ' is-doc'; $badgeTxt = 'DOC'; }
          elseif ($ext === 'XLS' || $ext === 'XLSX' || $ext === 'CSV' || $ext === 'ODS') { $badgeCls .= ' is-xls'; $badgeTxt = 'XLS'; }
          elseif (in_array($ext, ['JPG', 'JPEG', 'PNG', 'WEBP', 'GIF', 'BMP', 'TIF', 'TIFF', 'HEIC', 'HEIF'], true)) { $badgeCls .= ' is-img'; $badgeTxt = 'IMG'; }
          else { $badgeCls .= ' is-other'; $badgeTxt = $ext ?: 'FILE'; }
          $rowTitle = $doc['type_name'] ?: $doc['document_type'] ?: 'Документ';
        ?>
        <div class="file-item file-item-predef document-file-row has-file has-existing-file driver-doc-view-item">
          <div class="<?= $badgeCls ?>"><?= $badgeTxt ?></div>
          <div class="file-info">
            <div class="file-name"><?= e($rowTitle) ?></div>
            <div class="file-meta"><?= e($metaText) ?></div>
          </div>
          <a href="<?= e(app_url('/company/documents/view?id=' . $docId)) ?>" class="btn btn-secondary file-action-btn js-doc-popup-window" target="_blank" rel="noopener">Просмотр</a>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($clientArchiveBlocked !== ''): ?>
      <div class="notice warn mt-3"><?= e($clientArchiveBlocked) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php
  $assignmentEntityType = 'client';
  $assignmentEntityId = (int) ($client['id'] ?? 0);
  require base_path('app/View/partials/company_entity_assignment_block.php');
  ?>
</div>

<div class="modal-foot is-spaced">
  <div class="modal-foot-actions">
    <?php if ($clientCanArchive): ?>
    <button type="button" class="btn btn-ghost" data-client-archive-btn>Удалить</button>
    <?php endif; ?>
  </div>
  <div class="modal-foot-actions">
    <button type="button" class="btn btn-ghost" data-client-view-close-btn>Закрыть</button>
    <?php if ($clientCanEdit): ?>
    <button type="button" class="btn btn-primary" data-client-edit-btn>Редактировать</button>
    <?php endif; ?>
  </div>
</div>
