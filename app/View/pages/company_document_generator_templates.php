<?php
$pageTitle = 'Шаблоны договоров';
$pageContext = 'Генератор договоров';
$topbarCrumbs = [
    ['label' => 'Генератор договоров', 'url' => app_url('/company/document-generator')],
    ['label' => 'Шаблоны договоров', 'url' => null],
];
$bootstrapWarnings = \App\Service\DocumentGeneratorService::builtInTemplateWarnings();
?>
<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Шаблоны договоров</h1>
        <div class="page-summary"><span>DOCX → новая версия. Старые версии сохраняются и не изменяют уже сформированные документы.</span></div>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-primary" href="<?= app_url('/company/document-generator') ?>">Создать документ</a>
        <a class="btn btn-ghost" href="<?= app_url('/company/document-generator/history') ?>">История</a>
    </div>
</div>

<?php if ($flash): ?><div class="notice <?= str_starts_with((string)$flash, 'Ошибка:') ? 'warn' : 'success' ?>"><?= e((string)$flash) ?></div><?php endif; ?>
<?php if ($formError): ?><div class="notice warn"><?= e($formError) ?></div><?php endif; ?>
<?php foreach ($bootstrapWarnings as $warning): ?>
<div class="notice warn">Встроенный шаблон «<?= e((string)($warning['name'] ?? $warning['filename'] ?? 'DOCX')) ?>» временно не инициализирован: <?= e((string)($warning['message'] ?? 'неизвестная ошибка')) ?> Сохранённые исправные шаблоны продолжают работать.</div>
<?php endforeach; ?>

<div class="panel mb-4">
    <div class="panel-head"><strong>Загрузить шаблон договора / новую версию</strong></div>
    <div class="panel-body">
        <form method="post" enctype="multipart/form-data" action="<?= app_url('/company/document-generator/templates/upload') ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            <div class="form-grid-2">
                <div class="field">
                    <label class="field-label">Существующий шаблон</label>
                    <select name="template_id" class="field-select">
                        <option value="0">— новый шаблон —</option>
                        <?php foreach ($templates as $template): ?><option value="<?= (int)$template['id'] ?>"><?= e((string)$template['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label">Тип документа</label>
                    <select name="document_type" class="field-select">
                        <?php foreach ($documentTypes as $code => $label): ?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label class="field-label">Название</label><input type="text" name="name" placeholder="Для новой версии можно оставить пустым" class="field-input"></div>
                <div class="field"><label class="field-label">DOCX, до <?= (int)(\App\Service\DocumentTemplateService::MAX_UPLOAD_BYTES / 1024 / 1024) ?> МБ</label><input type="file" name="template_file" accept=".docx" required class="field-input"></div>
            </div>
            <div class="form-actions mt-3"><button type="submit" class="btn btn-primary">Загрузить и активировать версию</button></div>
        </form>
    </div>
</div>

<?php foreach ($templates as $template): ?>
<div class="panel mb-4">
    <div class="panel-head">
        <strong><?= e((string)$template['name']) ?></strong>
        <span class="text-muted"><?= e($documentTypes[(string)$template['document_type']] ?? (string)$template['document_type']) ?></span>
    </div>
    <div class="panel-body">
        <div class="form-actions mb-3">
            <form method="post" action="<?= app_url('/company/document-generator/templates/' . (int)$template['id'] . '/status') ?>">
                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="active" value="<?= ($template['status'] ?? '') === 'active' ? '0' : '1' ?>">
                <button class="btn btn-ghost" type="submit"><?= ($template['status'] ?? '') === 'active' ? 'Деактивировать' : 'Активировать' ?></button>
            </form>
        </div>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Версия</th><th>Файл</th><th>SHA-256</th><th>Создана</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($versionsByTemplate[(int)$template['id']] ?? [] as $version): ?>
                    <tr>
                        <td>v<?= (int)$version['version_no'] ?> <?= (int)$template['active_version_id'] === (int)$version['id'] ? '· активная' : '' ?></td>
                        <td><?= e((string)$version['original_name']) ?></td>
                        <td><code><?= e(substr((string)$version['sha256'], 0, 16)) ?>…</code></td>
                        <td><?= e((string)$version['created_at']) ?></td>
                        <td class="text-right"><a class="btn btn-ghost" href="<?= app_url('/company/document-generator/templates/' . (int)$template['id'] . '/versions/' . (int)$version['id'] . '/download') ?>">Скачать</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="panel">
    <div class="panel-head"><strong>Доступные переменные</strong></div>
    <div class="panel-body">
        <div class="text-muted mb-2">Юридический текст хранится только в DOCX. Переменные можно вставлять в Word; строка с <code>{{request.*}}</code> является повторяемой.</div>
        <div class="table-scroll"><table class="table"><tbody>
        <?php foreach ($placeholders as $placeholder): ?><tr><td><code>{{<?= e($placeholder) ?>}}</code></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>
</div>
