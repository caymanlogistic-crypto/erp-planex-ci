<link rel="stylesheet" href="<?= app_url('/assets/css/feo-utilizers.css') ?>?v=<?= filemtime(base_path('public/assets/css/feo-utilizers.css')) ?>">
<div class="feo-utilizer-page" data-feo-utilizer-page-ready>
    <div class="page-head">
        <div class="page-head-left">
            <h1 class="page-title">Утилизаторы</h1>
            <div class="page-summary"><span>Справочник утилизаторов и настройки распределения заявок ФЭО.</span></div>
        </div>
        <div class="page-head-actions">
            <button type="button" class="btn btn-primary" onclick="openModal('feo-utilizer-create-modal')">Добавить утилизатора</button>
        </div>
    </div>

    <nav class="feo-domain-tabs" aria-label="Разделы заявок ФЭО">
        <a href="<?= app_url('/company/feo/requests') ?>">Все заявки</a>
        <a href="<?= app_url('/company/feo/requests/map-placement') ?>">Размещение на карте</a>
        <a href="<?= app_url('/company/feo/requests/map') ?>">Карта</a>
        <a class="is-active" href="<?= app_url('/company/feo/utilizers') ?>" aria-current="page">Утилизаторы</a>
    </nav>

    <?php if ($successFlash): ?><div class="notice success"><?= e((string)$successFlash) ?></div><?php endif; ?>
    <?php if ($errorFlash): ?><div class="notice warn"><?= e((string)$errorFlash) ?></div><?php endif; ?>
    <?php if ($dbError): ?><div class="notice warn"><?= e((string)$dbError) ?></div><?php endif; ?>

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
                    ?>
                    <tr data-feo-utilizer-id="<?= (int)$u['id'] ?>">
                        <td class="col-mono"><?= (int)$u['id'] ?></td>
                        <td>
                            <div class="cell-main"><?= e((string)$u['short_name']) ?></div>
                            <?php if ((string)$u['full_name'] !== (string)$u['short_name']): ?><div class="cell-sub"><?= e((string)$u['full_name']) ?></div><?php endif; ?>
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

    <div class="feo-utilizer-secondary-head">
        <div>
            <h2>Автоматическое сопоставление</h2>
            <p>Активные правила применяются по приоритету. При одинаковом приоритете точное совпадение проверяется раньше условия «содержит».</p>
        </div>
        <button type="button" class="btn btn-secondary" onclick="openModal('feo-utilizer-rule-modal')">Добавить правило</button>
    </div>
    <div class="table-card table-card--standard" data-feo-utilizer-rules>
        <div class="table-toolbar"><div class="found-label">Правил: <b><?= count($rules) ?></b></div></div>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Приоритет</th><th>Утилизатор</th><th>Поле</th><th>Условие</th><th>Значение</th><th>Статус</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rules as $r): ?>
                    <tr>
                        <td class="col-mono"><?= (int)$r['priority'] ?></td>
                        <td><?= e((string)$r['utilizer_short_name']) ?></td>
                        <td><?= ($r['source_field'] ?? '') === 'recipient' ? 'Грузополучатель' : 'Адрес доставки' ?></td>
                        <td><?= ($r['match_type'] ?? '') === 'exact' ? 'Точное совпадение' : 'Содержит' ?></td>
                        <td><?= e((string)$r['pattern']) ?></td>
                        <td><?= (int)($r['is_active'] ?? 0) === 1 ? '<span class="badge badge-ok"><span class="dot"></span>Активно</span>' : '<span class="badge"><span class="dot"></span>Отключено</span>' ?></td>
                        <td class="table-actions-cell"><form method="post" action="<?= app_url('/company/feo/utilizers/rules/' . (int)$r['id'] . '/delete') ?>"><button class="btn btn-ghost btn-sm" type="submit">Удалить</button></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer"><span class="footer-label">Показано <b><?= count($rules) ?></b> правил</span></div>
    </div>

    <div class="feo-utilizer-secondary-head">
        <div>
            <h2>Ручные назначения</h2>
            <p>Ручное назначение используется первым и имеет приоритет над автоматическими правилами.</p>
        </div>
        <button type="button" class="btn btn-secondary" onclick="openModal('feo-utilizer-override-modal')">Добавить назначение</button>
    </div>
    <div class="table-card table-card--standard" data-feo-utilizer-overrides>
        <div class="table-toolbar"><div class="found-label">Назначений: <b><?= count($overrides) ?></b></div></div>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>ID заявки</th><th>Назначенный утилизатор</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($overrides as $o): ?>
                    <tr>
                        <td class="col-mono"><?= e((string)$o['zayavka_id']) ?></td>
                        <td><?= e((string)$o['utilizer_short_name']) ?><?= (int)($o['utilizer_is_active'] ?? 0) === 1 ? '' : ' (отключён)' ?></td>
                        <td class="table-actions-cell"><form method="post" action="<?= app_url('/company/feo/utilizers/overrides/' . e((string)$o['zayavka_id']) . '/delete') ?>"><button class="btn btn-ghost btn-sm" type="submit">Удалить назначение</button></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer"><span class="footer-label">Показано <b><?= count($overrides) ?></b> назначений</span></div>
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
                </div>
            </form>
        </div>
        <div class="modal-foot is-spaced">
            <div class="modal-required-note"><span class="req">*</span> — обязательные поля</div>
            <div class="modal-foot-actions"><button type="button" class="btn btn-secondary" onclick="closeModal('feo-utilizer-create-modal')">Отмена</button><button type="submit" form="feo-utilizer-create-form" class="btn btn-primary">Создать</button></div>
        </div>
    </div>
</div>

<div class="modal-overlay driver-view-overlay" id="feo-utilizer-rule-modal" data-close-on-overlay="0" data-close-on-escape="0">
    <div class="modal modal-lg driver-view-modal-inner">
        <div class="modal-head"><span class="modal-title">Добавить правило</span><button type="button" class="modal-close" onclick="closeModal('feo-utilizer-rule-modal')">&times;</button></div>
        <div class="modal-body driver-modal-body">
            <form id="feo-utilizer-rule-form" method="post" action="<?= app_url('/company/feo/utilizers/rules') ?>">
                <div class="feo-utilizer-form-grid">
                    <label class="field feo-utilizer-span-2"><span class="field-label">Утилизатор <span class="req">*</span></span><select class="field-select" name="utilizer_id" required><option value="">—</option><?php foreach ($utilizers as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e((string)$u['short_name']) ?><?= (int)$u['is_active'] === 1 ? '' : ' (отключён)' ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span class="field-label">Поле</span><select class="field-select" name="source_field"><option value="recipient">Грузополучатель</option><option value="delivery_address">Адрес доставки</option></select></label>
                    <label class="field"><span class="field-label">Условие</span><select class="field-select" name="match_type"><option value="exact">Точное совпадение</option><option value="contains">Содержит текст</option></select></label>
                    <label class="field feo-utilizer-span-2"><span class="field-label">Значение <span class="req">*</span></span><input class="field-input" name="pattern" maxlength="500" required></label>
                    <label class="field"><span class="field-label">Приоритет</span><input class="field-input" name="priority" type="number" value="100" min="0" max="100000"></label>
                    <div class="feo-utilizer-checks"><label class="feo-utilizer-check"><input name="is_active" type="checkbox" value="1" checked> Активно</label></div>
                </div>
            </form>
        </div>
        <div class="modal-foot is-spaced"><div></div><div class="modal-foot-actions"><button type="button" class="btn btn-secondary" onclick="closeModal('feo-utilizer-rule-modal')">Отмена</button><button type="submit" form="feo-utilizer-rule-form" class="btn btn-primary">Добавить</button></div></div>
    </div>
</div>

<div class="modal-overlay driver-view-overlay" id="feo-utilizer-override-modal" data-close-on-overlay="0" data-close-on-escape="0">
    <div class="modal modal-lg driver-view-modal-inner">
        <div class="modal-head"><span class="modal-title">Добавить ручное назначение</span><button type="button" class="modal-close" onclick="closeModal('feo-utilizer-override-modal')">&times;</button></div>
        <div class="modal-body driver-modal-body">
            <form id="feo-utilizer-override-form" method="post" action="<?= app_url('/company/feo/utilizers/overrides') ?>">
                <div class="feo-utilizer-form-grid">
                    <label class="field"><span class="field-label">ID заявки <span class="req">*</span></span><input class="field-input" name="zayavka_id" inputmode="numeric" pattern="\d{6}" maxlength="6" required></label>
                    <label class="field feo-utilizer-span-2"><span class="field-label">Утилизатор <span class="req">*</span></span><select class="field-select" name="utilizer_id" required><option value="">—</option><?php foreach ($utilizers as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e((string)$u['short_name']) ?><?= (int)$u['is_active'] === 1 ? '' : ' (отключён)' ?></option><?php endforeach; ?></select></label>
                </div>
            </form>
        </div>
        <div class="modal-foot is-spaced"><div></div><div class="modal-foot-actions"><button type="button" class="btn btn-secondary" onclick="closeModal('feo-utilizer-override-modal')">Отмена</button><button type="submit" form="feo-utilizer-override-form" class="btn btn-primary">Сохранить</button></div></div>
    </div>
</div>

<script defer src="<?= app_url('/assets/js/feo-utilizers.js') ?>?v=<?= filemtime(base_path('public/assets/js/feo-utilizers.js')) ?>"></script>
