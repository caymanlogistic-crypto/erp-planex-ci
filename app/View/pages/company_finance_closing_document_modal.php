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
$amount = '-' . number_format((float)($row['debit_amount'] ?? 0), 2, ',', ' ') . ' ₽';
?>
<div data-closing-modal-transaction="<?= (int)$row['id'] ?>">
    <div class="closing-modal-summary">
        <div><div class="field-note">Дата / №</div><strong><?= e(date('d.m.Y', strtotime((string)$row['operation_date']))) ?> · № <?= e((string)($row['document_number'] ?: $row['id'])) ?></strong></div>
        <div><div class="field-note">Сумма</div><strong><?= e($amount) ?></strong></div>
        <div><div class="field-note">Получатель</div><strong><?= e((string)($row['counterparty_name'] ?: 'Без наименования')) ?></strong><div class="field-note">ИНН <?= e($inn !== '' ? $inn : '—') ?></div></div>
        <div><div class="field-note">Тип / статус</div><?php if(!empty($row['is_exempt'])):?><span class="badge badge-success"><span class="dot"></span>Документы не требуются</span><?php else:?><strong><?= e((string)($profile['label'] ?? '—')) ?></strong><?php endif;?></div>
    </div>
    <div class="field-note"><?= e((string)($row['purpose'] ?? '')) ?></div>

    <?php if(!empty($row['is_exempt'])): ?>
        <div class="closing-modal-section">
            <h3>Исключение</h3>
            <div class="notice success">По этому ИНН закрывающие документы не требуются<?= !empty($row['exception_comment']) ? ': ' . e((string)$row['exception_comment']) : '.' ?></div>
            <a class="btn btn-secondary btn-sm" href="<?= e(app_url('/company/finance/closing-documents/exceptions')) ?>">Открыть исключения</a>
        </div>
    <?php else: ?>
        <div class="closing-modal-section">
            <h3>Требуемый комплект</h3>
            <?php foreach(($profile['requirements'] ?? []) as $requirement):
                $satisfied = false;
                foreach ($visibleLinks as $link) {
                    if (in_array((string)($link['requirement_code'] ?? ''), $requirement['accepted'] ?? [], true)) { $satisfied = true; break; }
                }
            ?>
                <div class="stack-xs"><span class="badge <?= $satisfied ? 'badge-success' : 'badge-warning' ?>"><span class="dot"></span><?= e((string)$requirement['label']) ?> — <?= $satisfied ? 'есть' : 'не хватает' ?></span></div>
            <?php endforeach; ?>
        </div>

        <div class="closing-modal-section">
            <h3>Привязанные документы</h3>
            <?php if(empty($visibleLinks)): ?><div class="text-muted">Документы пока не привязаны.</div><?php endif; ?>
            <?php foreach($visibleLinks as $link): ?>
                <div class="stack-xs" style="margin-bottom:8px">
                    <strong><?= e((string)($requirementLabels[$link['requirement_code']] ?? $link['requirement_code'])) ?></strong>
                    <span><?= e((string)$link['original_name']) ?></span>
                    <form method="post" action="<?= e(app_url('/company/finance/closing-documents/unlink-document')) ?>" class="inline-form" onsubmit="return confirm('Отвязать документ от этого платежа?');">
                        <input type="hidden" name="transaction_id" value="<?= (int)$row['id'] ?>">
                        <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm">Отвязать</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if($inn !== '' && !empty($availableDocuments)): ?>
        <div class="closing-modal-section">
            <h3>Привязать существующий документ</h3>
            <form method="post" action="<?= e(app_url('/company/finance/closing-documents/link-document')) ?>" class="stack-sm">
                <input type="hidden" name="transaction_id" value="<?= (int)$row['id'] ?>">
                <select name="requirement_code" class="field-select" required><option value="">Тип документа</option><?php foreach($acceptedCodes as $code=>$label):?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach;?></select>
                <select name="document_id" class="field-select" required><option value="">Документ этого ИНН</option><?php foreach($availableDocuments as $document):?><option value="<?= (int)$document['id'] ?>"><?= e((string)($document['type_name'] ?: $document['document_type'] ?: 'Документ')) ?> · <?= e((string)$document['original_name']) ?></option><?php endforeach;?></select>
                <button class="btn btn-secondary" type="submit">Привязать</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="closing-modal-section">
            <h3>Загрузить новый документ</h3>
            <form method="post" enctype="multipart/form-data" action="<?= e(app_url('/company/finance/closing-documents/upload-document')) ?>" class="stack-sm">
                <input type="hidden" name="transaction_id" value="<?= (int)$row['id'] ?>">
                <select name="requirement_code" class="field-select" required><option value="">Тип документа</option><?php foreach($acceptedCodes as $code=>$label):?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach;?></select>
                <input type="file" name="document_file" class="field-input" required>
                <button class="btn btn-primary" type="submit">Загрузить и привязать</button>
            </form>
        </div>
    <?php endif; ?>
</div>
