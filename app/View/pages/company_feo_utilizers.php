<link rel="stylesheet" href="<?= app_url('/assets/css/feo-utilizers.css') ?>?v=<?= filemtime(base_path('public/assets/css/feo-utilizers.css')) ?>">
<div class="workspace-shell-1690 feo-utilizer-page" data-feo-utilizer-page-ready>
    <div class="page-head">
        <div class="page-head-left">
            <h1 class="page-title">Утилизаторы</h1>
            <div class="page-summary"><span>Справочник утилизаторов. Название из заявки ФЭО сопоставляется с названием или синонимом утилизатора.</span></div>
        </div>
        <div class="page-head-actions">
            <button type="button" class="btn btn-primary" onclick="openModal('feo-utilizer-create-modal')">Добавить утилизатора</button>
        </div>
    </div>

    <?php if ($successFlash): ?><div class="notice success"><?= e((string)$successFlash) ?></div><?php endif; ?>
    <?php if ($errorFlash): ?><div class="notice warn"><?= e((string)$errorFlash) ?></div><?php endif; ?>
    <?php if ($dbError): ?><div class="notice warn"><?= e((string)$dbError) ?></div><?php endif; ?>

    <?php if ((int)($problemSummary['count'] ?? 0) > 0): ?>
        <section class="feo-utilizer-problem" data-feo-utilizer-problem data-problem-count="<?= (int)$problemSummary['count'] ?>">
            <div class="feo-utilizer-problem-head">
                <strong>Не определён утилизатор: <?= (int)$problemSummary['count'] ?> заявок</strong>
                <span>Добавьте название из заявки в синонимы нужного утилизатора. После этого заявки определятся автоматически.</span>
            </div>
            <div class="feo-utilizer-problem-list">
                <?php foreach (($problemSummary['groups'] ?? []) as $group): ?>
                    <div class="feo-utilizer-problem-row">
                        <div class="feo-utilizer-problem-name"><?= e((string)($group['name'] ?? 'Название не указано')) ?></div>
                        <div class="feo-utilizer-problem-meta">
                            <b><?= (int)($group['count'] ?? 0) ?></b> заявок
                            <?php if (!empty($group['request_ids'])): ?>
                                <span>· ID: <?= e(implode(', ', array_map('strval', $group['request_ids']))) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="table-card table-card--standard" data-erp-grid data-feo-utilizer-grid>
        <div class="table-toolbar">
            <div class="found-label">Найдено: <b><?= count($utilizers) ?></b> утилизаторов</div>
            <div class="toolbar-right">
                <input type="text" class="toolbar-search" placeholder="Поиск: название, адрес, ФККО" aria-label="Поиск утилизаторов">
            </div>
        </div>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Утилизатор</th>
                        <th>Адрес</th>
                        <th>ФККО / классификация</th>
                        <th>ADR</th>
                        <th>Цвет</th>
                        <th>Координаты</th>
                        <th>Статус</th>
                        <th>Порядок</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($utilizers as $u): ?>
                    <?php
                    $hasCoords = ($u['latitude'] ?? null) !== null && ($u['longitude'] ?? null) !== null;
                    $coords = $hasCoords ? trim((string)$u['latitude']) . ', ' . trim((string)$u['longitude']) : '—';
                    $aliasCount = count($u['aliases'] ?? []);
                    ?>
                    <tr data-feo-utilizer-id="<?= (int)$u['id'] ?>">
                        <td class="col-mono"><?= (int)$u['id'] ?></td>
                        <td>
                            <div class="cell-main"><?= e((string)$u['short_name']) ?></div>
                            <?php if ((string)$u['full_name'] !== (string)$u['short_name']): ?><div class="cell-sub"><?= e((string)$u['full_name']) ?></div><?php endif; ?>
                            <?php if ($aliasCount > 0): ?><div class="cell-sub">Синонимов: <?= $aliasCount ?></div><?php endif; ?>
                        </td>
                        <td><?= !empty($u['address']) ? e((string)$u['address']) : '<span class="col-muted">—</span>' ?></td>
                        <td><?= !empty($u['fkko_classification']) ? e((string)$u['fkko_classification']) : '<span class="col-muted">—</span>' ?></td>
                        <td><?= (int)$u['adr'] === 1 ? '<span class="badge badge-ok"><span class="dot"></span>Да</span>' : '<span class="badge"><span class="dot"></span>Нет</span>' ?></td>
                        <td><span class="feo-utilizer-color"><span class="feo-utilizer-color-dot" style="background:<?= e((string)$u['color_hex']) ?>"></span><span class="col-mono"><?= e((string)$u['color_hex']) ?></span></span></td>
                        <td class="col-mono<?= !$hasCoords ? ' col-muted' : '' ?>"><?= e($coords) ?></td>
                        <td>
                            <?php if ((int)$u['is_active'] === 1): ?>
                                <span class="badge badge-ok"><span class="dot"></span>Активен</span>
                            <?php else: ?>
                                <span class="badge"><span class="dot"></span>Отключён</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-mono"><?= (int)$u['sort_order'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer">
            <span class="footer-label">Показано <b class="footer-range"><?= count($utilizers) ? '1–' . count($utilizers) : '0' ?></b> из <b class="footer-total"><?= count($utilizers) ?></b></span>
            <span class="footer-hint">Двойной щелчок по строке — просмотр утилизатора</span>
        </div>
    </div>
</div>

<div class="modal-overlay driver-view-overlay" id="feo-utilizer-create-modal" data-close-on-overlay="0" data-close-on-escape="0">
    <div class="modal modal-lg driver-view-modal-inner">
        <div class="modal-head">
            <span class="modal-title">Добавить утилизатора</span>
            <button type="button" class="modal-close" onclick="closeModal('feo-utilizer-create-modal')">&times;</button>
        </div>
        <div class="modal-body driver-modal-body">
            <form id="feo-utilizer-create-form" method="post" action="<?= app_url('/company/feo/utilizers') ?>">
                <div class="feo-utilizer-form-grid">
                    <label class="field feo-utilizer-span-2"><span class="field-label">Полное название <span class="req">*</span></span><input class="field-input" name="full_name" maxlength="255" required></label>
                    <label class="field"><span class="field-label">Короткое название <span class="req">*</span></span><input class="field-input" name="short_name" maxlength="80" required></label>
                    <label class="field"><span class="field-label">Порядок</span><input class="field-input" name="sort_order" type="number" min="-100000" max="100000" value="100"></label>
                    <label class="field feo-utilizer-span-2"><span class="field-label">Адрес</span><input class="field-input" name="address" maxlength="500"></label>
                    <label class="field"><span class="field-label">Широта</span><input class="field-input" name="latitude" inputmode="decimal" placeholder="55.7558000"></label>
                    <label class="field"><span class="field-label">Долгота</span><input class="field-input" name="longitude" inputmode="decimal" placeholder="37.6176000"></label>
                    <label class="field feo-utilizer-span-2"><span class="field-label">ФККО / классификация</span><input class="field-input" name="fkko_classification" maxlength="500"></label>
                    <label class="field"><span class="field-label">Цвет маркера</span><div class="feo-utilizer-color-input"><input name="color_hex" type="color" value="#2563EB" data-feo-color-input><span class="col-mono" data-feo-color-value>#2563EB</span></div></label>
                    <div class="feo-utilizer-checks"><label class="feo-utilizer-check"><input name="adr" type="checkbox" value="1"> ADR</label><label class="feo-utilizer-check"><input name="is_active" type="checkbox" value="1" checked> Активен</label></div>
                    <label class="field feo-utilizer-span-2"><span class="field-label">Синонимы названия</span><textarea class="field-input feo-utilizer-alias-input" name="aliases" rows="4" placeholder="По одному варианту названия на строку"></textarea><span class="field-hint">Используются только для точного сопоставления названия грузополучателя из заявки.</span></label>
                </div>
            </form>
        </div>
        <div class="modal-foot is-spaced">
            <div class="modal-required-note"><span class="req">*</span> — обязательные поля</div>
            <div class="modal-foot-actions"><button type="button" class="btn btn-secondary" onclick="closeModal('feo-utilizer-create-modal')">Отмена</button><button type="submit" form="feo-utilizer-create-form" class="btn btn-primary">Создать</button></div>
        </div>
    </div>
</div>

<script defer src="<?= app_url('/assets/js/feo-utilizers.js') ?>?v=<?= filemtime(base_path('public/assets/js/feo-utilizers.js')) ?>"></script>
