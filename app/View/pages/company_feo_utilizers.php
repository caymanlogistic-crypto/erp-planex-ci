<style>
.feo-util-page{display:flex;flex-direction:column;gap:14px}.feo-util-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.feo-util-head h1{margin:0 0 3px}.feo-domain-tabs{display:flex;gap:4px;border-bottom:1px solid #e2e8f0;flex-wrap:wrap}.feo-domain-tabs a{padding:8px 11px;text-decoration:none;color:#64748b;font-size:13px;font-weight:600;border-bottom:2px solid transparent}.feo-domain-tabs a.is-active{color:#0f766e;border-color:#0f766e}.feo-util-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px}.feo-util-card h2{margin:0 0 5px;font-size:15px}.feo-section-help{font-size:12px;color:#64748b;margin:0 0 12px;line-height:1.45}.feo-util-grid,.feo-edit-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;align-items:end}.feo-util-grid .span-2,.feo-edit-grid .span-2{grid-column:span 2}.feo-field{display:flex;flex-direction:column;gap:4px;min-width:0;font-size:11px;color:#64748b}.feo-field>span{font-weight:600;color:#475569}.feo-field input,.feo-field select{width:100%;box-sizing:border-box;min-width:0}.feo-check{display:flex;gap:6px;align-items:center;min-height:34px;font-size:12px;color:#475569}.feo-check input{width:auto}.feo-color-control{display:flex;align-items:center;gap:8px}.feo-color-control input[type=color]{width:48px;min-width:48px;height:34px;padding:2px;border:1px solid #cbd5e1;border-radius:6px;background:#fff}.feo-color-control code{font-size:11px;color:#475569}.feo-util-list{display:flex;flex-direction:column;gap:10px}.feo-util-item{border:1px solid #e2e8f0;border-radius:10px;padding:12px;background:#fbfdff}.feo-util-item-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:10px}.feo-util-title{font-size:14px;font-weight:700;color:#0f172a}.feo-util-meta{font-size:11px;color:#64748b;margin-top:3px;line-height:1.45}.feo-color{display:inline-block;width:12px;height:12px;border-radius:50%;vertical-align:-2px;margin-right:5px;border:1px solid rgba(15,23,42,.18)}.feo-item-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.feo-rule-grid{display:grid;grid-template-columns:1.2fr 1fr 1fr 2.2fr .65fr .7fr auto;gap:8px;align-items:end}.feo-override-grid{display:grid;grid-template-columns:1fr 2fr auto;gap:8px;align-items:end}.feo-rule-grid label,.feo-override-grid label{font-size:11px;color:#64748b}.feo-rule-grid input,.feo-rule-grid select,.feo-override-grid input,.feo-override-grid select{width:100%;box-sizing:border-box}.feo-util-table{width:100%;border-collapse:collapse;font-size:12px}.feo-util-table th,.feo-util-table td{padding:8px;border-bottom:1px solid #edf2f7;text-align:left;vertical-align:top}.feo-util-table th{font-size:10px;color:#64748b;text-transform:uppercase}.feo-mini{font-size:11px;padding:5px 8px}.feo-empty{color:#64748b;font-size:12px;padding:8px 0}.feo-edit-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:10px;padding-top:10px;border-top:1px solid #edf2f7}.feo-status-pills{display:flex;gap:12px;align-items:center;flex-wrap:wrap}@media(max-width:1100px){.feo-util-grid,.feo-edit-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.feo-rule-grid,.feo-override-grid{grid-template-columns:1fr 1fr}.feo-util-table thead{display:none}.feo-util-table tr,.feo-util-table td{display:block}.feo-util-item-head,.feo-edit-footer{align-items:stretch;flex-direction:column}}@media(max-width:650px){.feo-util-grid,.feo-edit-grid,.feo-rule-grid,.feo-override-grid{grid-template-columns:1fr}.feo-util-grid .span-2,.feo-edit-grid .span-2{grid-column:span 1}}
</style>
<div class="feo-util-page" data-feo-utilizer-page-ready>
    <div class="feo-util-head">
        <div>
            <h1 class="page-title">Утилизаторы</h1>
            <div class="text-muted">Справочник утилизаторов, условия автоматического сопоставления и ручные назначения заявок.</div>
        </div>
        <a class="btn btn-secondary" href="<?= app_url('/company/feo/requests/map') ?>">Вернуться на карту</a>
    </div>
    <nav class="feo-domain-tabs" aria-label="Разделы заявок ФЭО">
        <a href="<?= app_url('/company/feo/requests') ?>">Все заявки</a>
        <a href="<?= app_url('/company/feo/requests/map-placement') ?>">Размещение на карте</a>
        <a href="<?= app_url('/company/feo/requests/map') ?>">Карта</a>
        <a class="is-active" href="<?= app_url('/company/feo/utilizers') ?>" aria-current="page">Утилизаторы</a>
    </nav>
    <?php if ($successFlash): ?><div class="alert alert-success"><?= e($successFlash) ?></div><?php endif; ?>
    <?php if ($errorFlash): ?><div class="alert alert-error"><?= e($errorFlash) ?></div><?php endif; ?>
    <?php if ($dbError): ?><div class="alert alert-error"><?= e($dbError) ?></div><?php endif; ?>

    <section class="feo-util-card">
        <h2>Добавить утилизатора</h2>
        <p class="feo-section-help">Координаты можно оставить пустыми. Если они указаны, нужны оба значения; точка 0,0 не принимается. Цвет используется для уже согласованного вида маркеров карты.</p>
        <form method="post" action="<?= app_url('/company/feo/utilizers') ?>" class="feo-util-grid">
            <label class="feo-field span-2"><span>Полное название</span><input class="form-control" name="full_name" maxlength="255" required></label>
            <label class="feo-field"><span>Короткое название на карте</span><input class="form-control" name="short_name" maxlength="80" required></label>
            <label class="feo-field"><span>Порядок в списке</span><input class="form-control" name="sort_order" type="number" value="100"></label>
            <label class="feo-field span-2"><span>Адрес</span><input class="form-control" name="address" maxlength="500"></label>
            <label class="feo-field"><span>Широта</span><input class="form-control" name="latitude" inputmode="decimal" placeholder="55.7558000"></label>
            <label class="feo-field"><span>Долгота</span><input class="form-control" name="longitude" inputmode="decimal" placeholder="37.6176000"></label>
            <label class="feo-field span-2"><span>ФККО / классификация</span><input class="form-control" name="fkko_classification" maxlength="500"></label>
            <label class="feo-field"><span>Цвет маркера</span><span class="feo-color-control"><input name="color_hex" type="color" value="#2563EB" data-color-input><code data-color-value>#2563EB</code></span></label>
            <div class="feo-status-pills"><label class="feo-check"><input name="adr" type="checkbox" value="1"> ADR</label><label class="feo-check"><input name="is_active" type="checkbox" value="1" checked> Активен</label></div>
            <button class="btn btn-primary" type="submit">Добавить</button>
        </form>
    </section>

    <section class="feo-util-card">
        <h2>Справочник</h2>
        <p class="feo-section-help">Каждый утилизатор редактируется в отдельной форме с постоянными подписями полей. Отключение сохраняет историю и связи; удаление разрешено только при отсутствии правил и ручных назначений.</p>
        <?php if (!$utilizers): ?><div class="feo-empty">Утилизаторы ещё не заведены.</div><?php endif; ?>
        <div class="feo-util-list">
        <?php foreach ($utilizers as $u): ?>
            <article class="feo-util-item" data-utilizer-id="<?= (int)$u['id'] ?>">
                <div class="feo-util-item-head">
                    <div>
                        <div class="feo-util-title"><span class="feo-color" style="background:<?= e((string)$u['color_hex']) ?>"></span><?= e((string)$u['short_name']) ?></div>
                        <div class="feo-util-meta"><?= e((string)$u['full_name']) ?> · #<?= (int)$u['id'] ?> · <?= (int)$u['adr'] === 1 ? 'ADR' : 'не ADR' ?> · <?= (int)$u['is_active'] === 1 ? 'активен' : 'отключён' ?></div>
                    </div>
                    <form method="post" action="<?= app_url('/company/feo/utilizers/' . (int)$u['id'] . '/delete') ?>" data-utilizer-confirm data-confirm-title="Удалить утилизатора?" data-confirm-message="Удаление будет выполнено только если у утилизатора нет правил сопоставления и ручных назначений.">
                        <button class="btn btn-ghost feo-mini" type="submit">Удалить безопасно</button>
                    </form>
                </div>
                <form method="post" action="<?= app_url('/company/feo/utilizers/' . (int)$u['id'] . '/update') ?>">
                    <div class="feo-edit-grid">
                        <label class="feo-field span-2"><span>Полное название</span><input class="form-control" name="full_name" value="<?= e((string)$u['full_name']) ?>" maxlength="255" required></label>
                        <label class="feo-field"><span>Короткое название на карте</span><input class="form-control" name="short_name" value="<?= e((string)$u['short_name']) ?>" maxlength="80" required></label>
                        <label class="feo-field"><span>Порядок в списке</span><input class="form-control" name="sort_order" type="number" value="<?= (int)$u['sort_order'] ?>"></label>
                        <label class="feo-field span-2"><span>Адрес</span><input class="form-control" name="address" value="<?= e((string)($u['address'] ?? '')) ?>" maxlength="500"></label>
                        <label class="feo-field"><span>Широта</span><input class="form-control" name="latitude" value="<?= e((string)($u['latitude'] ?? '')) ?>" inputmode="decimal"></label>
                        <label class="feo-field"><span>Долгота</span><input class="form-control" name="longitude" value="<?= e((string)($u['longitude'] ?? '')) ?>" inputmode="decimal"></label>
                        <label class="feo-field span-2"><span>ФККО / классификация</span><input class="form-control" name="fkko_classification" value="<?= e((string)($u['fkko_classification'] ?? '')) ?>" maxlength="500"></label>
                        <label class="feo-field"><span>Цвет маркера</span><span class="feo-color-control"><input name="color_hex" type="color" value="<?= e((string)$u['color_hex']) ?>" data-color-input><code data-color-value><?= e((string)$u['color_hex']) ?></code></span></label>
                    </div>
                    <div class="feo-edit-footer">
                        <div class="feo-status-pills"><label class="feo-check"><input name="adr" type="checkbox" value="1" <?= (int)$u['adr'] === 1 ? 'checked' : '' ?>> ADR</label><label class="feo-check"><input name="is_active" type="checkbox" value="1" <?= (int)$u['is_active'] === 1 ? 'checked' : '' ?>> Активен</label></div>
                        <button class="btn btn-secondary feo-mini" type="submit">Сохранить изменения</button>
                    </div>
                </form>
            </article>
        <?php endforeach; ?>
        </div>
    </section>

    <section class="feo-util-card">
        <h2>Автоматическое сопоставление</h2>
        <p class="feo-section-help">Система проверяет активные условия по приоритету: сначала меньший номер. Точное совпадение безопаснее условия «содержит» и рекомендуется для новых записей.</p>
        <form method="post" action="<?= app_url('/company/feo/utilizers/rules') ?>" class="feo-rule-grid">
            <label>Утилизатор<select class="form-control" name="utilizer_id" required><option value="">—</option><?php foreach ($utilizers as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e((string)$u['short_name']) ?><?= (int)$u['is_active'] === 1 ? '' : ' (отключён)' ?></option><?php endforeach; ?></select></label>
            <label>Проверять<select class="form-control" name="source_field"><option value="recipient">Грузополучателя</option><option value="delivery_address">Адрес доставки</option></select></label>
            <label>Условие<select class="form-control" name="match_type"><option value="exact">Точное совпадение</option><option value="contains">Содержит текст</option></select></label>
            <label>Текст для сопоставления<input class="form-control" name="pattern" maxlength="500" required></label>
            <label>Приоритет<input class="form-control" name="priority" type="number" value="100" min="0"></label>
            <label class="feo-check"><input name="is_active" type="checkbox" value="1" checked> Активно</label>
            <button class="btn btn-primary" type="submit">Добавить условие</button>
        </form>
        <table class="feo-util-table" style="margin-top:10px"><thead><tr><th>Приоритет</th><th>Утилизатор</th><th>Проверяется</th><th>Условие</th><th></th></tr></thead><tbody>
        <?php foreach ($rules as $r): ?><tr><td><?= (int)$r['priority'] ?></td><td><?= e((string)$r['utilizer_short_name']) ?></td><td><?= ($r['source_field'] ?? '') === 'recipient' ? 'Грузополучатель' : 'Адрес доставки' ?></td><td><?= ($r['match_type'] ?? '') === 'exact' ? 'равно' : 'содержит' ?> «<?= e((string)$r['pattern']) ?>»<?= (int)$r['is_active'] === 1 ? '' : ' · отключено' ?></td><td><form method="post" action="<?= app_url('/company/feo/utilizers/rules/' . (int)$r['id'] . '/delete') ?>" data-utilizer-confirm data-confirm-title="Удалить условие?" data-confirm-message="После удаления заявки перестанут сопоставляться по этому условию."><button class="btn btn-ghost feo-mini" type="submit">Удалить</button></form></td></tr><?php endforeach; ?>
        </tbody></table>
    </section>

    <section class="feo-util-card">
        <h2>Ручное назначение заявки</h2>
        <p class="feo-section-help">Ручное назначение используется первым. Если его нет, система применяет автоматические условия сопоставления.</p>
        <form method="post" action="<?= app_url('/company/feo/utilizers/overrides') ?>" class="feo-override-grid">
            <label>ID заявки<input class="form-control" name="zayavka_id" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" required></label>
            <label>Утилизатор<select class="form-control" name="utilizer_id" required><option value="">—</option><?php foreach ($utilizers as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e((string)$u['short_name']) ?></option><?php endforeach; ?></select></label>
            <button class="btn btn-primary" type="submit">Назначить</button>
        </form>
        <table class="feo-util-table" style="margin-top:10px"><thead><tr><th>Заявка</th><th>Утилизатор</th><th>Обновлено</th><th></th></tr></thead><tbody>
        <?php foreach ($overrides as $o): ?><tr><td><b><?= e((string)$o['zayavka_id']) ?></b></td><td><?= e((string)$o['utilizer_short_name']) ?><?= (int)$o['utilizer_is_active'] === 1 ? '' : ' · отключён' ?></td><td><?= e((string)$o['updated_at']) ?></td><td><form method="post" action="<?= app_url('/company/feo/utilizers/overrides/' . rawurlencode((string)$o['zayavka_id']) . '/delete') ?>" data-utilizer-confirm data-confirm-title="Удалить ручное назначение?" data-confirm-message="Для заявки снова будут применяться автоматические условия сопоставления."><button class="btn btn-ghost feo-mini" type="submit">Удалить</button></form></td></tr><?php endforeach; ?>
        </tbody></table>
    </section>
</div>
<div class="modal-overlay" id="feo-utilizer-confirm-modal" role="dialog" aria-modal="true" aria-hidden="true" data-close-on-overlay="0" data-close-on-escape="1">
    <div class="modal">
        <div class="modal-head"><span class="modal-title" data-utilizer-confirm-title>Подтвердите действие</span><button type="button" class="modal-close" data-utilizer-confirm-cancel>&times;</button></div>
        <div class="modal-body"><div class="notice warn modal-notice" data-utilizer-confirm-message></div></div>
        <div class="modal-foot"><button type="button" class="btn btn-ghost" data-utilizer-confirm-cancel>Отмена</button><button type="button" class="btn btn-danger" data-utilizer-confirm-action>Подтвердить</button></div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-color-input]').forEach(function (input) {
        var output = input.parentElement ? input.parentElement.querySelector('[data-color-value]') : null;
        function sync() { if (output) output.textContent = String(input.value || '').toUpperCase(); }
        input.addEventListener('input', sync); sync();
    });
    var modal = document.getElementById('feo-utilizer-confirm-modal');
    if (!modal) return;
    var activeForm = null;
    var title = modal.querySelector('[data-utilizer-confirm-title]');
    var message = modal.querySelector('[data-utilizer-confirm-message]');
    var action = modal.querySelector('[data-utilizer-confirm-action]');
    function open(form) { activeForm = form; if (title) title.textContent = form.dataset.confirmTitle || 'Подтвердите действие'; if (message) message.textContent = form.dataset.confirmMessage || 'Подтвердите выполнение операции.'; if (typeof window.openModal === 'function') window.openModal(modal.id); else { modal.classList.add('is-open'); modal.setAttribute('aria-hidden','false'); } }
    function close() { if (typeof window.closeModal === 'function') window.closeModal(modal.id); else { modal.classList.remove('is-open'); modal.setAttribute('aria-hidden','true'); } activeForm = null; }
    document.querySelectorAll('form[data-utilizer-confirm]').forEach(function (form) { form.addEventListener('submit', function (event) { if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; } event.preventDefault(); open(form); }); });
    modal.querySelectorAll('[data-utilizer-confirm-cancel]').forEach(function (button) { button.addEventListener('click', close); });
    if (action) action.addEventListener('click', function () { if (!activeForm) return; var form = activeForm; form.dataset.confirmed = '1'; close(); form.requestSubmit(); });
});
</script>
