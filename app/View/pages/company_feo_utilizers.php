<style>
.feo-util-page{display:flex;flex-direction:column;gap:14px}.feo-util-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.feo-util-head h1{margin:0 0 3px}.feo-domain-tabs{display:flex;gap:4px;border-bottom:1px solid #e2e8f0}.feo-domain-tabs a{padding:8px 11px;text-decoration:none;color:#64748b;font-size:13px;font-weight:600;border-bottom:2px solid transparent}.feo-domain-tabs a.is-active{color:#0f766e;border-color:#0f766e}.feo-util-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px}.feo-util-card h2{margin:0 0 10px;font-size:15px}.feo-util-grid{display:grid;grid-template-columns:2fr 1fr 2fr 1fr 1fr 1fr 1fr 100px;gap:8px;align-items:end}.feo-util-grid .wide{grid-column:span 2}.feo-util-grid label,.feo-rule-grid label,.feo-override-grid label{font-size:11px;color:#64748b}.feo-util-grid input,.feo-rule-grid input,.feo-rule-grid select,.feo-override-grid input,.feo-override-grid select{width:100%;box-sizing:border-box}.feo-check{display:flex;gap:6px;align-items:center;height:34px}.feo-check input{width:auto}.feo-util-table{width:100%;border-collapse:collapse;font-size:12px}.feo-util-table th,.feo-util-table td{padding:8px;border-bottom:1px solid #edf2f7;text-align:left;vertical-align:top}.feo-util-table th{font-size:10px;color:#64748b;text-transform:uppercase}.feo-color{display:inline-block;width:12px;height:12px;border-radius:50%;vertical-align:-2px;margin-right:5px;border:1px solid rgba(15,23,42,.18)}.feo-edit{display:grid;grid-template-columns:1.35fr .85fr 1.35fr .62fr .62fr .72fr .72fr 1.15fr 58px auto;gap:6px;align-items:center}.feo-edit input{min-width:0;width:100%;box-sizing:border-box}.feo-rule-grid{display:grid;grid-template-columns:1.3fr 1fr 1fr 2.5fr .7fr .7fr auto;gap:8px;align-items:end}.feo-override-grid{display:grid;grid-template-columns:1fr 2fr auto;gap:8px;align-items:end}.feo-inline-actions{display:flex;gap:5px;align-items:center}.feo-mini{font-size:11px;padding:5px 8px}.feo-empty{color:#64748b;font-size:12px;padding:8px 0}@media(max-width:1100px){.feo-util-grid,.feo-rule-grid,.feo-override-grid{grid-template-columns:1fr 1fr}.feo-edit{grid-template-columns:repeat(2,minmax(0,1fr));padding:8px 0}.feo-util-table thead{display:none}.feo-util-table tr,.feo-util-table td{display:block}}
</style>
<div class="feo-util-page">
    <div class="feo-util-head">
        <div><h1 class="page-title">Утилизаторы</h1><div class="text-muted">Master-data карты, правила классификации и ручные назначения.</div></div>
        <a class="btn btn-secondary" href="<?= app_url('/company/feo/requests/map') ?>">Вернуться на карту</a>
    </div>
    <nav class="feo-domain-tabs" aria-label="Разделы заявок ФЭО">
        <a href="<?= app_url('/company/feo/requests') ?>">Все заявки</a>
        <a href="<?= app_url('/company/feo/requests/map-placement') ?>">Размещение на карте</a>
        <a class="is-active" href="<?= app_url('/company/feo/requests/map') ?>">Карта</a>
    </nav>
    <?php if ($successFlash): ?><div class="alert alert-success"><?= e($successFlash) ?></div><?php endif; ?>
    <?php if ($errorFlash): ?><div class="alert alert-error"><?= e($errorFlash) ?></div><?php endif; ?>
    <?php if ($dbError): ?><div class="alert alert-error"><?= e($dbError) ?></div><?php endif; ?>

    <section class="feo-util-card">
        <h2>Добавить утилизатора</h2>
        <form method="post" action="<?= app_url('/company/feo/utilizers') ?>" class="feo-util-grid">
            <label>Полное название<input class="form-control" name="full_name" maxlength="255" required></label>
            <label>Короткое название<input class="form-control" name="short_name" maxlength="80" required></label>
            <label class="wide">Адрес<input class="form-control" name="address" maxlength="500"></label>
            <label>Широта<input class="form-control" name="latitude" inputmode="decimal"></label>
            <label>Долгота<input class="form-control" name="longitude" inputmode="decimal"></label>
            <label>Цвет<input class="form-control" name="color_hex" value="#2563EB" pattern="#[0-9A-Fa-f]{6}"></label>
            <label>Порядок<input class="form-control" name="sort_order" type="number" value="100"></label>
            <label class="wide">ФККО / классификация<input class="form-control" name="fkko_classification" maxlength="500"></label>
            <label class="feo-check"><input name="adr" type="checkbox" value="1"> ADR</label>
            <label class="feo-check"><input name="is_active" type="checkbox" value="1" checked> Активен</label>
            <button class="btn btn-primary" type="submit">Добавить</button>
        </form>
    </section>

    <section class="feo-util-card">
        <h2>Справочник</h2>
        <?php if (!$utilizers): ?><div class="feo-empty">Утилизаторы ещё не заведены.</div><?php endif; ?>
        <table class="feo-util-table">
            <thead><tr><th>Название</th><th>Адрес / координаты</th><th>Признаки</th><th>Редактирование</th></tr></thead>
            <tbody>
            <?php foreach ($utilizers as $u): ?>
            <tr>
                <td><b><span class="feo-color" style="background:<?= e((string)$u['color_hex']) ?>"></span><?= e((string)$u['short_name']) ?></b><br><?= e((string)$u['full_name']) ?><br><span class="text-muted">#<?= (int)$u['id'] ?> · порядок <?= (int)$u['sort_order'] ?></span></td>
                <td><?= e((string)($u['address'] ?? '—')) ?><br><span class="text-muted"><?= e((string)($u['latitude'] ?? '—')) ?>, <?= e((string)($u['longitude'] ?? '—')) ?></span></td>
                <td><?= (int)$u['adr'] === 1 ? 'ADR' : 'не ADR' ?> · <?= (int)$u['is_active'] === 1 ? 'активен' : 'отключён' ?><br><span class="text-muted"><?= e((string)($u['fkko_classification'] ?? '')) ?></span></td>
                <td>
                    <form method="post" action="<?= app_url('/company/feo/utilizers/' . (int)$u['id'] . '/update') ?>" class="feo-edit">
                        <input class="form-control" name="full_name" value="<?= e((string)$u['full_name']) ?>" maxlength="255" required title="Полное название">
                        <input class="form-control" name="short_name" value="<?= e((string)$u['short_name']) ?>" maxlength="80" required title="Короткое название">
                        <input class="form-control" name="address" value="<?= e((string)($u['address'] ?? '')) ?>" maxlength="500" title="Адрес">
                        <input class="form-control" name="latitude" value="<?= e((string)($u['latitude'] ?? '')) ?>" title="Широта">
                        <input class="form-control" name="longitude" value="<?= e((string)($u['longitude'] ?? '')) ?>" title="Долгота">
                        <input class="form-control" name="color_hex" value="<?= e((string)$u['color_hex']) ?>" pattern="#[0-9A-Fa-f]{6}" title="Цвет">
                        <input class="form-control" name="sort_order" type="number" value="<?= (int)$u['sort_order'] ?>" title="Порядок">
                        <input class="form-control" name="fkko_classification" value="<?= e((string)($u['fkko_classification'] ?? '')) ?>" maxlength="500" title="ФККО / классификация">
                        <input name="adr" type="checkbox" value="1" <?= (int)$u['adr'] === 1 ? 'checked' : '' ?> title="ADR">
                        <div class="feo-inline-actions">
                            <label class="feo-check"><input name="is_active" type="checkbox" value="1" <?= (int)$u['is_active'] === 1 ? 'checked' : '' ?>> активен</label>
                            <button class="btn btn-secondary feo-mini" type="submit">Сохранить</button>
                        </div>
                    </form>
                    <form method="post" action="<?= app_url('/company/feo/utilizers/' . (int)$u['id'] . '/delete') ?>" data-utilizer-confirm data-confirm-title="Удалить утилизатора?" data-confirm-message="Удаление будет выполнено только если у утилизатора нет правил сопоставления и ручных назначений." style="margin-top:5px">
                        <button class="btn btn-ghost feo-mini" type="submit">Удалить безопасно</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="feo-util-card">
        <h2>Правила автоматического сопоставления</h2>
        <form method="post" action="<?= app_url('/company/feo/utilizers/rules') ?>" class="feo-rule-grid">
            <label>Утилизатор<select class="form-control" name="utilizer_id" required><option value="">—</option><?php foreach ($utilizers as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e((string)$u['short_name']) ?><?= (int)$u['is_active'] === 1 ? '' : ' (отключён)' ?></option><?php endforeach; ?></select></label>
            <label>Источник<select class="form-control" name="source_field"><option value="recipient">Грузополучатель</option><option value="delivery_address">Адрес доставки</option></select></label>
            <label>Тип<select class="form-control" name="match_type"><option value="exact">Точное совпадение</option><option value="contains">Содержит</option></select></label>
            <label>Шаблон<input class="form-control" name="pattern" maxlength="500" required></label>
            <label>Приоритет<input class="form-control" name="priority" type="number" value="100" min="0"></label>
            <label class="feo-check"><input name="is_active" type="checkbox" value="1" checked> Активно</label>
            <button class="btn btn-primary" type="submit">Добавить правило</button>
        </form>
        <table class="feo-util-table" style="margin-top:10px"><thead><tr><th>Приоритет</th><th>Утилизатор</th><th>Источник</th><th>Условие</th><th></th></tr></thead><tbody>
        <?php foreach ($rules as $r): ?><tr><td><?= (int)$r['priority'] ?></td><td><?= e((string)$r['utilizer_short_name']) ?></td><td><?= ($r['source_field'] ?? '') === 'recipient' ? 'Грузополучатель' : 'Адрес доставки' ?></td><td><?= ($r['match_type'] ?? '') === 'exact' ? '=' : 'содержит' ?> «<?= e((string)$r['pattern']) ?>»<?= (int)$r['is_active'] === 1 ? '' : ' · отключено' ?></td><td><form method="post" action="<?= app_url('/company/feo/utilizers/rules/' . (int)$r['id'] . '/delete') ?>" data-utilizer-confirm data-confirm-title="Удалить правило?" data-confirm-message="После удаления заявки перестанут сопоставляться по этому условию."><button class="btn btn-ghost feo-mini" type="submit">Удалить</button></form></td></tr><?php endforeach; ?>
        </tbody></table>
    </section>

    <section class="feo-util-card">
        <h2>Ручное назначение заявки</h2>
        <div class="text-muted" style="margin-bottom:8px">Override имеет приоритет над автоматическими rules/aliases.</div>
        <form method="post" action="<?= app_url('/company/feo/utilizers/overrides') ?>" class="feo-override-grid">
            <label>ID заявки<input class="form-control" name="zayavka_id" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" required></label>
            <label>Утилизатор<select class="form-control" name="utilizer_id" required><option value="">—</option><?php foreach ($utilizers as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e((string)$u['short_name']) ?></option><?php endforeach; ?></select></label>
            <button class="btn btn-primary" type="submit">Назначить</button>
        </form>
        <table class="feo-util-table" style="margin-top:10px"><thead><tr><th>Заявка</th><th>Утилизатор</th><th>Обновлено</th><th></th></tr></thead><tbody>
        <?php foreach ($overrides as $o): ?><tr><td><b><?= e((string)$o['zayavka_id']) ?></b></td><td><?= e((string)$o['utilizer_short_name']) ?><?= (int)$o['utilizer_is_active'] === 1 ? '' : ' · отключён' ?></td><td><?= e((string)$o['updated_at']) ?></td><td><form method="post" action="<?= app_url('/company/feo/utilizers/overrides/' . rawurlencode((string)$o['zayavka_id']) . '/delete') ?>" data-utilizer-confirm data-confirm-title="Удалить ручное назначение?" data-confirm-message="Для заявки снова будут применяться автоматические правила сопоставления."><button class="btn btn-ghost feo-mini" type="submit">Удалить</button></form></td></tr><?php endforeach; ?>
        </tbody></table>
    </section>
</div>
<div class="modal-overlay" id="feo-utilizer-confirm-modal" role="dialog" aria-modal="true" data-close-on-overlay="0" data-close-on-escape="1">
    <div class="modal">
        <div class="modal-head">
            <span class="modal-title" data-utilizer-confirm-title>Подтвердите действие</span>
            <button type="button" class="modal-close" data-utilizer-confirm-cancel>&times;</button>
        </div>
        <div class="modal-body">
            <div class="notice warn modal-notice" data-utilizer-confirm-message></div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-ghost" data-utilizer-confirm-cancel>Отмена</button>
            <button type="button" class="btn btn-danger" data-utilizer-confirm-action>Подтвердить</button>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('feo-utilizer-confirm-modal');
    if (!modal) return;
    var activeForm = null;
    var title = modal.querySelector('[data-utilizer-confirm-title]');
    var message = modal.querySelector('[data-utilizer-confirm-message]');
    var action = modal.querySelector('[data-utilizer-confirm-action]');
    function open(form) {
        activeForm = form;
        if (title) title.textContent = form.dataset.confirmTitle || 'Подтвердите действие';
        if (message) message.textContent = form.dataset.confirmMessage || 'Подтвердите выполнение операции.';
        if (typeof window.openModal === 'function') window.openModal(modal.id); else modal.classList.add('is-open');
    }
    function close() {
        if (typeof window.closeModal === 'function') window.closeModal(modal.id); else modal.classList.remove('is-open');
        activeForm = null;
    }
    document.querySelectorAll('form[data-utilizer-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; }
            event.preventDefault();
            open(form);
        });
    });
    modal.querySelectorAll('[data-utilizer-confirm-cancel]').forEach(function (button) {
        button.addEventListener('click', close);
    });
    if (action) action.addEventListener('click', function () {
        if (!activeForm) return;
        var form = activeForm;
        form.dataset.confirmed = '1';
        close();
        form.requestSubmit();
    });
});
</script>

