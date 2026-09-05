<?php
require_once base_path('app/View/components/status_badge.php');
require_once base_path('app/View/components/view_formatters.php');

$contractorContacts = $contacts ?? [];
$contractorCanEdit = (bool) ($canEdit ?? false);
$contractorCanArchive = (bool) ($canArchive ?? false);
$contractorArchiveBlocked = (string) ($archiveBlockedMessage ?? '');
$contractorDocumentsUrl = '/company/documents?entity_type=contractor&entity_id=' . (int) ($contractor['id'] ?? 0);
?>
<div class="modal-body driver-modal-body">
  <div class="driver-modal-layout">
    <div class="driver-modal-main">
      <h3 class="driver-view-name"><?= e($contractor['name'] ?? 'Перевозчик') ?></h3>

      <div class="driver-view-card">
        <div class="driver-view-grid">
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">ИНН</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($contractor['inn']) ? '<code>' . e($contractor['inn']) . '</code>' : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Тип</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($contractor['contractor_type']) ? e(ui_contractor_type($contractor['contractor_type'])) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Статус</div>
            <div class="driver-view-cell driver-view-cell-value"><?= renderStatusBadge($contractor['status'] ?? null) ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">КПП</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($contractor['kpp']) ? e($contractor['kpp']) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">ОГРН</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($contractor['ogrn']) ? e($contractor['ogrn']) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Руководитель</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($contractor['director_full_name']) ? e($contractor['director_full_name']) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Должность</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($contractor['director_position']) ? e($contractor['director_position']) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row driver-view-row-wide">
            <div class="driver-view-cell driver-view-cell-label">Юридический адрес</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($contractor['legal_address']) ? nl2br(e($contractor['legal_address'])) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row driver-view-row-wide">
            <div class="driver-view-cell driver-view-cell-label">Фактический адрес</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($contractor['physical_address']) ? nl2br(e($contractor['physical_address'])) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row driver-view-row-wide">
            <div class="driver-view-cell driver-view-cell-label">Расчётный счёт</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($contractor['bank_account']) ? e($contractor['bank_account']) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">Банк</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($contractor['bank_name']) ? e($contractor['bank_name']) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row">
            <div class="driver-view-cell driver-view-cell-label">БИК</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($contractor['bank_bik']) ? e($contractor['bank_bik']) : '<span class="is-na">—</span>' ?></div>
          </div>
          <div class="driver-view-row driver-view-row-wide">
            <div class="driver-view-cell driver-view-cell-label">Корр. счёт</div>
            <div class="driver-view-cell driver-view-cell-value"><?= !empty($contractor['bank_corr_account']) ? e($contractor['bank_corr_account']) : '<span class="is-na">—</span>' ?></div>
          </div>
          <?php if (!empty($contractor['comments'])): ?>
          <div class="driver-view-row driver-view-row-wide">
            <div class="driver-view-cell driver-view-cell-label">Комментарий</div>
            <div class="driver-view-cell driver-view-cell-value driver-view-comments"><?= e($contractor['comments']) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="driver-modal-docs">
      <div class="driver-doc-section-title">Контакты</div>
      <?php if (empty($contractorContacts)): ?>
      <div class="driver-docs-empty">Контакты не указаны.</div>
      <?php else: ?>
      <div class="file-list">
        <?php foreach ($contractorContacts as $contact): ?>
        <div class="file-item file-item-predef document-file-row has-file has-existing-file driver-doc-view-item">
          <div class="file-type-badge is-doc"><?= !empty($contact['is_primary']) ? 'MAIN' : 'INFO' ?></div>
          <div class="file-info">
            <div class="file-name"><?= e($contact['contact_person'] ?? 'Контакт') ?></div>
            <div class="file-meta">
              <?= e($contact['phone'] ?? '') ?: '—' ?>
              <?php if (!empty($contact['email'])): ?> · <?= e($contact['email']) ?><?php endif; ?>
              <?php if (!empty($contact['is_document_email'])): ?> · для документов<?php endif; ?>
              <?php if (!empty($contact['comment'])): ?> · <?= e($contact['comment']) ?><?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="form-actions mt-3">
        <a href="<?= e($contractorDocumentsUrl) ?>" class="btn btn-secondary">Документы</a>
      </div>
      <?php if ($contractorArchiveBlocked !== ''): ?>
      <div class="notice warn mt-3"><?= e($contractorArchiveBlocked) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php
  $assignmentEntityType = 'contractor';
  $assignmentEntityId = (int) ($contractor['id'] ?? 0);
  require base_path('app/View/partials/company_entity_assignment_block.php');
  ?>
</div>

<div class="modal-foot is-spaced">
  <div class="modal-foot-actions">
    <?php if ($contractorCanArchive): ?>
    <button type="button" class="btn btn-ghost" data-contractor-archive-btn>Удалить</button>
    <?php endif; ?>
  </div>
  <div class="modal-foot-actions">
    <button type="button" class="btn btn-ghost" data-contractor-view-close-btn>Закрыть</button>
    <?php if ($contractorCanEdit): ?>
    <button type="button" class="btn btn-primary" data-contractor-edit-btn>Редактировать</button>
    <?php endif; ?>
  </div>
</div>
