<?php
$profile = $row['profile'] ?? null;
$inn = (string)($row['normalized_inn'] ?? '');
$acceptedCodes = [];
foreach (($profile['requirements'] ?? []) as $requirement) {
    foreach (($requirement['accepted'] ?? []) as $code) {
        $acceptedCodes[$code] = $requirementLabels[$code] ?? $code;
    }
}
$visibleLinks = array_values(array_filter($row['links'] ?? [], static fn(array $link): bool => empty($link['document_deleted_at'])));
$isIncoming = (string)($row['direction'] ?? 'outgoing') === 'incoming';
$rawAmount = $isIncoming ? (float)($row['credit_amount'] ?? 0) : (float)($row['debit_amount'] ?? 0);
$amount = ($isIncoming ? '+' : '-') . number_format($rawAmount, 2, ',', ' ') . ' ₽';
$directionLabel = $isIncoming ? 'Входящий платёж' : 'Исходящий платёж';
$statuses = $row['requirement_statuses'] ?? [];
$columns = \App\Service\ClosingDocumentService::requirementColumns();
$renderStatus = static function (array $status, bool $isExempt): string {
    if ($isExempt) return '<span class="text-muted">—</span>';
    if (!empty($status['exempt_for_inn'])) return '<span class="text-muted" title="Этот документ не требуется для данного ИНН">—</span>';
    if (empty($status['applicable'])) return '<span class="text-muted">—</span>';
    $source = (string)($status['source'] ?? '');
    if ($source === 'edo') {
        return '<span class="closing-doc-edo" title="Документ получен по ЭДО" aria-label="ЭДО">ЭДО</span>';
    }
    if ($source === 'manual') {
        return '<span class="table-check closing-doc-status closing-doc-status--manual" title="Документ отмечен как собранный вручную" aria-label="Собран вручную">✓</span>';
    }
    if (!empty($status['satisfied'])) {
        return '<span class="table-check table-check--yes closing-doc-status" title="Документ загружен" aria-label="Документ есть">✓</span>';
    }
    return '<span class="table-check table-check--no closing-doc-status" title="Документ не загружен" aria-label="Документа нет">×</span>';
};
$fileBadge = static function (string $name): array {
    $ext = strtoupper((string) pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'PDF') return ['file-type-badge is-pdf', 'PDF'];
    if (in_array($ext, ['DOC','DOCX','RTF','ODT'], true)) return ['file-type-badge is-doc', 'DOC'];
    if (in_array($ext, ['XLS','XLSX','CSV','ODS'], true)) return ['file-type-badge is-xls', 'XLS'];
    if (in_array($ext, ['JPG','JPEG','PNG','WEBP','GIF','BMP','TIF','TIFF','HEIC','HEIF'], true)) return ['file-type-badge is-img', 'IMG'];
    return ['file-type-badge is-other', $ext !== '' ? $ext : 'FILE'];
};
?>
<style>
[data-closing-modal-transaction] .closing-status-option{display:grid!important;grid-template-columns:14px minmax(0,1fr)!important;align-items:start!important;column-gap:7px!important;row-gap:0!important;margin-top:7px!important;cursor:pointer!important;line-height:1.25!important}
[data-closing-modal-transaction] .closing-status-option input[type="checkbox"]{-webkit-appearance:checkbox!important;appearance:auto!important;width:14px!important;height:14px!important;min-width:14px!important;max-width:14px!important;min-height:14px!important;max-height:14px!important;padding:0!important;margin:1px 0 0!important;box-sizing:border-box!important;flex:0 0 14px!important;accent-color:var(--accent)}
[data-closing-modal-transaction] .closing-status-option span{display:block;min-width:0}
[data-closing-modal-transaction] .closing-edo-option{display:inline-grid!important;width:auto!important;grid-template-columns:14px auto!important;align-self:start!important;margin:0!important}
</style>
<div data-closing-modal-transaction="<?= (int)$row['id'] ?>">
    <div class="entity-form-layout closing-document-layout">
        <div class="entity-form-main closing-document-main">
            <div class="section-title">Платёж</div>
            <div class="closing-payment-grid">
                <div class="closing-payment-field"><div class="field-note">Дата / №</div><div class="closing-payment-value"><?= e(date('d.m.Y', strtotime((string)$row['operation_date']))) ?> · № <?= e((string)($row['document_number'] ?: $row['id'])) ?></div></div>
                <div class="closing-payment-field"><div class="field-note">Сумма</div><div class="closing-payment-value"><?= e($amount) ?> · <?= e($directionLabel) ?></div></div>
                <div class="closing-payment-field"><div class="field-note">Контрагент</div><div class="closing-payment-value"><?= e((string)($row['counterparty_name'] ?: 'Без наименования')) ?></div><div class="field-note">ИНН <?= e($inn !== '' ? $inn : '—') ?></div></div>
                <div class="closing-payment-field"><div class="field-note">Тип контрагента</div><div class="closing-payment-value"><?php if(!empty($row['is_exempt'])):?><span class="text-muted">Не определяется: действует исключение</span><?php else:?><?= e((string)($profile['label'] ?? '—')) ?><?php endif;?></div></div>
            </div>

            <div class="field-note">Назначение платежа</div>
            <div class="closing-purpose"><?= e((string)($row['purpose'] ?? '—')) ?></div>

            <?php if(!empty($row['is_exempt'])): ?>
                <div class="form-alert alert-success">
                    <div class="alert-mark">✓</div>
                    <div class="alert-body">
                        <div class="alert-body-title">Документы не требуются</div>
                        <div class="alert-body-sub">Для этого ИНН действует правило исключения<?= !empty($row['exception_comment']) ? ': ' . e((string)$row['exception_comment']) : '.' ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="section-title">Статус документов</div>
            <?php if(empty($row['is_exempt'])): ?>
            <form method="post" action="<?= e(app_url('/company/finance/closing-documents/requirement-overrides')) ?>" class="closing-requirement-form">
                <input type="hidden" name="transaction_id" value="<?= (int)$row['id'] ?>">
                <div class="closing-status-grid">
                    <?php foreach ($columns as $key => $label): $status = $statuses[$key] ?? ['applicable'=>false,'satisfied'=>false,'accepted'=>[]]; ?>
                    <div class="closing-status-card">
                        <div class="closing-status-card-head">
                            <div class="field-note"><?= e($label) ?></div>
                            <?= $renderStatus($status, false) ?>
                        </div>
                        <?php if(!empty($status['accepted'])): ?>
                        <label class="closing-status-option">
                            <input type="checkbox" name="manual_collected[<?= e($key) ?>]" value="1"<?= !empty($status['manual_collected']) ? ' checked' : '' ?>>
                            <span>Считать собранным</span>
                        </label>
                        <label class="closing-status-option">
                            <input type="checkbox" name="inn_exempt[<?= e($key) ?>]" value="1"<?= !empty($status['exempt_for_inn']) ? ' checked' : '' ?><?= $inn === '' ? ' disabled' : '' ?>>
                            <span>Не требовать для этого ИНН</span>
                        </label>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="closing-requirement-actions"><button class="btn btn-secondary btn-sm" type="submit">Сохранить статусы</button></div>
            </form>
            <?php else: ?>
                <div class="closing-status-grid">
                    <?php foreach ($columns as $key => $label): $status = $statuses[$key] ?? ['applicable'=>false,'satisfied'=>false]; ?>
                    <div class="closing-status-card"><div class="field-note"><?= e($label) ?></div><?= $renderStatus($status, true) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="entity-form-docs closing-document-docs">
            <div class="section-title">Файлы</div>
            <?php if(!empty($row['is_exempt'])): ?>
                <div class="field-msg">Для операции действует исключение. Загрузка файлов не требуется.</div>
                <div style="margin-top:10px"><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/company/finance/closing-documents/exceptions')) ?>">Открыть исключения</a></div>
            <?php else: ?>
                <div class="file-list">
                    <?php if(empty($visibleLinks)): ?>
                        <div class="field-msg">Привязанных файлов пока нет.</div>
                    <?php endif; ?>
                    <?php foreach($visibleLinks as $link): [$badgeClass, $badgeText] = $fileBadge((string)$link['original_name']); ?>
                        <div class="file-item document-file-row has-file has-existing-file">
                            <div class="<?= e($badgeClass) ?>"><?= e($badgeText) ?></div>
                            <div class="file-info">
                                <div class="file-name"><?= e((string)($requirementLabels[$link['requirement_code']] ?? $link['requirement_code'])) ?><?= !empty($link['is_edo']) ? ' · ЭДО' : '' ?></div>
                                <div class="file-meta" title="<?= e((string)$link['original_name']) ?>"><?= e((string)$link['original_name']) ?></div>
                            </div>
                            <form method="post" action="<?= e(app_url('/company/finance/closing-documents/unlink-document')) ?>" class="inline-form closing-file-unlink" onsubmit="return confirm('Отвязать документ от этого платежа?');">
                                <input type="hidden" name="transaction_id" value="<?= (int)$row['id'] ?>">
                                <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" title="Отвязать файл">×</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>

                <form method="post" enctype="multipart/form-data" action="<?= e(app_url('/company/finance/closing-documents/upload-document')) ?>" class="closing-doc-form">
                    <div class="field-note">Загрузить новый файл</div>
                    <input type="hidden" name="transaction_id" value="<?= (int)$row['id'] ?>">
                    <select name="requirement_code" class="field-select" required><option value="">Тип документа</option><?php foreach($acceptedCodes as $code=>$label):?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach;?></select>
                    <input type="file" name="document_file" class="field-input" required>
                    <label class="closing-status-option closing-edo-option"><input type="checkbox" name="is_edo" value="1"><span>ЭДО</span></label>
                    <button class="btn btn-primary btn-sm" type="submit">Загрузить и привязать</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
