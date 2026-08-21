<?php
$problemCountOnPage = count(array_filter($rows ?? [], static fn(array $row): bool => !empty($row['is_problem'])));
$requirementLabels = \App\Service\ClosingDocumentService::requirementLabels();
?>

<?php if (!empty($successFlash)): ?><div class="notice success"><?= e($successFlash) ?></div><?php endif; ?>
<?php if (!empty($errorFlash)): ?><div class="notice warn"><?= e($errorFlash) ?></div><?php endif; ?>
<?php if (!empty($dbError)): ?><div class="notice warn"><?= e($dbError) ?></div><?php endif; ?>

<div class="page-head">
    <div>
        <h1 class="page-title">Закрывающие документы</h1>
        <div class="page-subtitle">Контроль обязательного комплекта по исходящим банковским расходам PLANEX</div>
    </div>
    <?php if (!empty($schemaReady)): ?>
    <div class="page-actions">
        <?php if (!empty($problemOnly)): ?>
            <a class="btn btn-ghost" href="<?= app_url('/company/finance/closing-documents') ?>">Все операции</a>
        <?php else: ?>
            <a class="btn btn-secondary" href="<?= app_url('/company/finance/closing-documents?problem=1') ?>">Только проблемные</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (empty($schemaReady) && empty($dbError)): ?>
<div class="notice warn">
    <b>Раздел подготовлен, но tenant schema ещё не обновлена.</b>
    Migration 077 должна быть применена отдельно после OWNER approval. Просмотр банковских выписок и остальные финансовые функции продолжают работать без этой migration.
</div>
<?php elseif (!empty($schemaReady)): ?>
<div class="table-card table-card--standard bank-finance-card">
    <div class="table-toolbar">
        <div class="table-title">
            <?= !empty($problemOnly) ? 'Проблемные операции' : 'Все релевантные операции' ?>
            <span class="field-note">Проблемных в текущей выборке: <?= (int) $problemCountOnPage ?></span>
        </div>
        <div class="field-note">Только исходящие расходы (дебет); входящие оплаты клиентов, employee flows и внутренние переводы исключены</div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Дата / №</th>
                <th>Операция</th>
                <th>Контрагент</th>
                <th>Профиль</th>
                <th>Комплект</th>
                <th>Документы</th>
                <th>Действия</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7"><div class="empty-state compact"><p><?= !empty($problemOnly) ? 'Проблемных операций нет.' : 'Нет операций для отображения.' ?></p></div></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $counterparty = $row['counterparty'] ?? null;
                $profile = $row['profile'] ?? null;
                $entityKey = $counterparty ? ($counterparty['entity_type'] . ':' . $counterparty['entity_id']) : '';
                $availableDocuments = $documentsByEntity[$entityKey] ?? [];
                $acceptedCodes = [];
                foreach (($profile['requirements'] ?? []) as $requirement) {
                    foreach ($requirement['accepted'] as $code) {
                        $acceptedCodes[$code] = $requirementLabels[$code] ?? $code;
                    }
                }
                $amount = ((float) ($row['debit_amount'] ?? 0)) > 0
                    ? '-' . number_format((float) $row['debit_amount'], 2, ',', ' ') . ' ₽'
                    : '+' . number_format((float) ($row['credit_amount'] ?? 0), 2, ',', ' ') . ' ₽';
                ?>
                <tr data-closing-transaction-id="<?= (int) $row['id'] ?>"<?= !empty($row['is_problem']) ? ' class="is-attention"' : '' ?>>
                    <td class="col-tight">
                        <b><?= e(date('d.m.Y', strtotime((string) $row['operation_date']))) ?></b>
                        <div class="field-note">№ <?= e((string) ($row['document_number'] ?: $row['id'])) ?></div>
                    </td>
                    <td>
                        <b><?= e($amount) ?></b>
                        <div><?= e((string) ($row['counterparty_name'] ?? '')) ?></div>
                        <div class="field-note"><?= e((string) ($row['purpose'] ?? '')) ?></div>
                    </td>
                    <td>
                        <?php if ($counterparty): ?>
                            <b><?= e((string) $counterparty['name']) ?></b>
                            <div class="field-note">ИНН <?= e((string) ($counterparty['inn'] ?? '—')) ?></div>
                        <?php else: ?>
                            <span class="badge badge-warning"><span class="dot"></span>Не определён</span>
                            <div class="field-note">ИНН из банка: <?= e((string) ($row['counterparty_inn'] ?: '—')) ?></div>
                        <?php endif; ?>
                        <details>
                            <summary class="link-button">Изменить</summary>
                            <form method="post" action="<?= app_url('/company/finance/closing-documents/counterparty') ?>" class="stack-sm">
                                <input type="hidden" name="transaction_id" value="<?= (int) $row['id'] ?>">
                                <input type="hidden" name="problem_only" value="<?= !empty($problemOnly) ? '1' : '0' ?>">
                                <select name="entity_selector" class="field-select" onchange="var p=this.value.split(':');this.form.entity_type.value=p[0]||'';this.form.entity_id.value=p[1]||'';">
                                    <option value="">— Выберите контрагента —</option>
                                    <?php foreach ($counterpartyOptions as $option): ?>
                                        <option value="<?= e($option['entity_type'] . ':' . $option['entity_id']) ?>"><?= e($option['name'] . ($option['inn'] ? ' · ИНН ' . $option['inn'] : '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="entity_type" value="">
                                <input type="hidden" name="entity_id" value="">
                                <button class="btn btn-secondary btn-sm" type="submit">Сохранить</button>
                            </form>
                            <button type="button" class="btn btn-ghost btn-sm" onclick="window.openModal('closing-contractor-create-modal')">Создать контрагента</button>
                            <div class="field-note">После создания список обновится; выберите нового контрагента для операции.</div>
                        </details>
                    </td>
                    <td>
                        <?php if ($profile): ?>
                            <b><?= e($profile['label']) ?></b>
                        <?php elseif ($counterparty): ?>
                            <span class="badge badge-warning"><span class="dot"></span>Нет классификации</span>
                            <div class="field-note">Заполните тип контрагента в карточке.</div>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php if (empty($row['is_problem'])): ?>
                            <span class="badge badge-success"><span class="dot"></span>Комплект собран</span>
                        <?php else: ?>
                            <span class="badge badge-warning"><span class="dot"></span>Требует действия</span>
                            <div class="field-note"><?= e((string) ($row['problem_reason'] ?? '')) ?></div>
                            <?php foreach (($row['missing_requirements'] ?? []) as $missing): ?>
                                <div class="field-note">• <?= e($missing['label']) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php foreach (($row['links'] ?? []) as $link): ?>
                            <?php if (!empty($link['document_deleted_at'])) continue; ?>
                            <div class="stack-xs">
                                <span><?= e($requirementLabels[$link['requirement_code']] ?? $link['requirement_code']) ?>: <?= e($link['original_name']) ?></span>
                                <form method="post" action="<?= app_url('/company/finance/closing-documents/unlink-document') ?>" class="inline-form">
                                    <input type="hidden" name="transaction_id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                                    <input type="hidden" name="problem_only" value="<?= !empty($problemOnly) ? '1' : '0' ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm">Отвязать</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($row['links'])): ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($counterparty && $profile): ?>
                            <details>
                                <summary class="link-button">Привязать существующий</summary>
                                <form method="post" action="<?= app_url('/company/finance/closing-documents/link-document') ?>" class="stack-sm">
                                    <input type="hidden" name="transaction_id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="problem_only" value="<?= !empty($problemOnly) ? '1' : '0' ?>">
                                    <select name="requirement_code" class="field-select" required>
                                        <option value="">Тип документа</option>
                                        <?php foreach ($acceptedCodes as $code => $label): ?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach; ?>
                                    </select>
                                    <select name="document_id" class="field-select" required>
                                        <option value="">Существующий документ</option>
                                        <?php foreach ($availableDocuments as $document): ?>
                                            <option value="<?= (int) $document['id'] ?>"><?= e((string) ($document['type_name'] ?: $document['document_type'] ?: 'Документ')) ?> · <?= e($document['original_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-secondary btn-sm" type="submit">Привязать</button>
                                </form>
                            </details>
                            <details>
                                <summary class="link-button">Загрузить новый</summary>
                                <form method="post" enctype="multipart/form-data" action="<?= app_url('/company/finance/closing-documents/upload-document') ?>" class="stack-sm">
                                    <input type="hidden" name="transaction_id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="problem_only" value="<?= !empty($problemOnly) ? '1' : '0' ?>">
                                    <select name="requirement_code" class="field-select" required>
                                        <option value="">Тип документа</option>
                                        <?php foreach ($acceptedCodes as $code => $label): ?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach; ?>
                                    </select>
                                    <input type="file" name="document_file" class="field-input" required>
                                    <button class="btn btn-secondary btn-sm" type="submit">Загрузить и привязать</button>
                                </form>
                            </details>
                        <?php else: ?>
                            <span class="field-note">Сначала определите контрагента и профиль.</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="closing-contractor-create-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="0" data-close-on-escape="1">
    <div class="modal modal-xl">
        <div class="modal-head">
            <span class="modal-title">Создать контрагента</span>
            <button type="button" class="modal-close" data-close-modal="closing-contractor-create-modal">&times;</button>
        </div>
        <div id="closing-contractor-create-modal-body" class="modal-body">
            <div class="empty-state compact"><p>Загрузка формы...</p></div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.LegalEntityModal) return;
    window.LegalEntityModal.init({
        modalId: 'closing-contractor-create-modal',
        formAction: <?= json_encode(app_url('/company/contractors/create'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        typeField: 'contractor_type'
    });
});
</script>
<?php endif; ?>
