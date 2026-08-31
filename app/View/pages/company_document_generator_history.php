<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">История сформированных документов</h1>
        <div class="page-summary"><span>Сохранённые DOCX и immutable snapshot использованных данных.</span></div>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-primary" href="<?= app_url('/company/document-generator') ?>">Создать документ</a>
        <?php if (($_SESSION['role_code'] ?? '') === 'company_owner'): ?><a class="btn btn-ghost" href="<?= app_url('/company/document-generator/templates') ?>">Шаблоны</a><?php endif; ?>
    </div>
</div>

<?php if ($flash): ?><div class="notice success"><?= e((string)$flash) ?></div><?php endif; ?>
<?php if ($formError): ?><div class="notice warn"><?= e($formError) ?></div><?php endif; ?>

<div class="panel">
    <div class="panel-body">
        <?php if ($history === []): ?>
            <div class="text-muted">Сформированных документов пока нет.</div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>ID</th><th>Документ</th><th>Исполнитель</th><th>Заявки / масса</th><th>Создан</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $row): ?>
                        <tr>
                            <td>#<?= (int)$row['id'] ?></td>
                            <td>
                                <strong><?= e((string)($row['_template_name'] ?? $row['document_type'])) ?></strong><br>
                                <span class="text-muted">№ <?= e((string)($row['document_number'] ?? '—')) ?> · <?= e((string)($row['document_date'] ?? '—')) ?> · template v<?= (int)($row['_template_version_no'] ?? 0) ?></span>
                            </td>
                            <td><?= e((string)$row['_executor_name']) ?><br><span class="text-muted"><?= e((string)$row['_driver_name']) ?> · <?= e((string)$row['_vehicle']) ?></span></td>
                            <td><?= count(json_decode((string)$row['request_ids_json'], true) ?: []) ?> · <?= e(\App\Service\DocumentGeneratorService::formatMassKg((float)$row['total_mass_kg'])) ?> кг</td>
                            <td><?= e((string)$row['created_at']) ?></td>
                            <td class="text-right">
                                <a class="btn btn-ghost" href="<?= app_url('/company/document-generator/generated/' . (int)$row['id'] . '/download') ?>">DOCX</a>
                                <a class="btn btn-ghost" href="<?= app_url('/company/document-generator?from=' . (int)$row['id']) ?>">Создать на основе</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
