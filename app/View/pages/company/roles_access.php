<?php
$rolesAccessInitialStateJson = $rolesAccessInitialStateJson ?? '{}';
?>
<section class="roles-access" data-roles-access-root>
    <div class="page-head roles-access__head">
        <div class="page-head-left">
            <h1 class="page-title">Роли и доступы</h1>
            <div class="page-summary">Роли задают основной доступ, персональные настройки уточняют его для сотрудника.</div>
        </div>
        <div class="page-head-actions">
            <button type="button" class="btn btn-primary" data-role-create>+ Создать роль</button>
            <button type="button" class="btn btn-primary is-hidden" data-membership-create>+ Добавить пользователя</button>
        </div>
    </div>

    <div class="roles-access__tabs" role="tablist" aria-label="Настройки доступа">
        <button type="button" class="roles-access__tab is-active" role="tab" aria-selected="true" aria-controls="roles-access-roles" data-access-tab="roles">Роли</button>
        <button type="button" class="roles-access__tab" role="tab" aria-selected="false" aria-controls="roles-access-users" data-access-tab="users">Пользователи</button>
        <button type="button" class="roles-access__tab" role="tab" aria-selected="false" aria-controls="roles-access-assignments" data-access-tab="assignments">Закрепления</button>
    </div>

    <div class="roles-access__status" role="status" aria-live="polite" data-access-status></div>

    <section id="roles-access-roles" class="roles-access__panel" role="tabpanel" data-access-panel="roles">
        <div class="table-card roles-access__table-card">
            <div class="table-scroll">
                <table class="table roles-access__table">
                    <thead><tr><th>Роль</th><th>Пользователей</th><th>Доступно страниц</th><th aria-label="Действия"></th></tr></thead>
                    <tbody data-roles-body></tbody>
                </table>
            </div>
        </div>
    </section>

    <section id="roles-access-users" class="roles-access__panel is-hidden" role="tabpanel" data-access-panel="users">
        <div class="table-card roles-access__table-card">
            <div class="table-scroll">
                <table class="table roles-access__table">
                    <thead><tr><th>Пользователь</th><th>Статус</th><th>Роль</th><th>Персональные настройки</th></tr></thead>
                    <tbody data-memberships-body></tbody>
                </table>
            </div>
        </div>
    </section>

    <section id="roles-access-assignments" class="roles-access__panel is-hidden" role="tabpanel" data-access-panel="assignments">
        <div class="panel roles-access__assignments">
            <div class="panel-head">
                <div>
                    <div class="panel-head-title">Закрепления</div>
                    <div class="text-muted">Определяют объекты, которые пользователь видит в режиме «Только свои».</div>
                </div>
                <label class="roles-access__employee-picker">Сотрудник
                    <select class="field-select" data-assignment-membership></select>
                </label>
            </div>
            <div class="panel-body" data-assignment-content>
                <div class="roles-access__loading">Выберите сотрудника.</div>
            </div>
        </div>
    </section>

    <script type="application/json" data-roles-access-state><?= $rolesAccessInitialStateJson ?></script>
</section>

<div class="modal-overlay roles-access-modal" id="roles-access-modal" role="dialog" aria-modal="true" aria-labelledby="roles-access-modal-title" aria-hidden="true">
    <div class="modal modal-lg roles-access-modal__dialog" tabindex="-1">
        <div class="modal-head roles-access-modal__head">
            <div>
                <div class="modal-title" id="roles-access-modal-title" data-access-modal-title>Настройка</div>
                <div class="roles-access-modal__subtitle" data-access-modal-subtitle></div>
            </div>
            <button type="button" class="modal-close" data-access-modal-close aria-label="Закрыть">&times;</button>
        </div>
        <form data-access-modal-form>
            <div class="modal-body roles-access-modal__body" data-access-modal-body></div>
            <div class="modal-foot roles-access-modal__foot is-spaced">
                <div data-access-modal-secondary></div>
                <div class="modal-foot-actions">
                    <button type="button" class="btn btn-ghost" data-access-modal-close>Отмена</button>
                    <button type="submit" class="btn btn-primary" data-access-modal-save>Сохранить</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="<?= app_url('/assets/js/roles-access.js') ?>?v=<?= filemtime(base_path('public/assets/js/roles-access.js')) ?>"></script>
