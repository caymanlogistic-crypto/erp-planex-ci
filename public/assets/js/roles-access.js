(function () {
    'use strict';

    var root = document.querySelector('[data-roles-access-root]');
    if (!root) return;
    document.body.classList.add('roles-access-page');

    var stateNode = root.querySelector('[data-roles-access-state]');
    var state;
    try { state = JSON.parse(stateNode ? stateNode.textContent : '{}'); } catch (_) { state = {}; }
    state.roles = Array.isArray(state.roles) ? state.roles : [];
    state.memberships = Array.isArray(state.memberships) ? state.memberships : [];
    state.pages = Array.isArray(state.pages) ? state.pages : [];
    state.page_tree = state.page_tree || {};
    state.regulated_entities = Array.isArray(state.regulated_entities) ? state.regulated_entities : [];

    var base = (document.querySelector('meta[name="erp-base-path"]') || {}).content || '';
    var url = function (path) { return base.replace(/\/$/, '') + path; };
    var labels = {
        client: 'Клиенты', contractor: 'Перевозчики', vehicle_unit: 'ТС', driver: 'Водители',
        linear_trip: 'Линейные рейсы', waste_trip: 'Рейсы отходов'
    };
    var scopeLabels = { all: 'Все', own: 'Только свои', none: 'Нет доступа' };
    var statusBox = root.querySelector('[data-access-status]');
    var assignmentCache = null;
    var activeAssignmentMembershipId = 0;

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>'"]/g, function (char) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char];
        });
    }

    function announce(message, kind) {
        statusBox.className = 'roles-access__status notice ' + (kind === 'error' ? 'warn' : 'success');
        statusBox.textContent = message;
        window.clearTimeout(announce.timer);
        announce.timer = window.setTimeout(function () { statusBox.textContent = ''; statusBox.className = 'roles-access__status'; }, 5000);
    }

    function jsonRequest(path, options) {
        options = options || {};
        options.credentials = 'same-origin';
        options.headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
        if (options.body && typeof options.body !== 'string') {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }
        return fetch(url(path), options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (payload) {
                if (!response.ok || payload.ok === false || payload.success === false) {
                    throw new Error(payload.message || payload.error || 'Не удалось выполнить операцию.');
                }
                return payload;
            });
        });
    }

    function refreshSnapshot(message) {
        return jsonRequest('/company/roles-access/data').then(function (payload) {
            state = payload;
            state.roles = state.roles || [];
            state.memberships = state.memberships || [];
            state.pages = state.pages || [];
            state.page_tree = state.page_tree || {};
            state.regulated_entities = state.regulated_entities || [];
            assignmentCache = null;
            renderAll();
            if (message) announce(message);
        });
    }

    function roleById(id) {
        return state.roles.find(function (role) { return Number(role.id) === Number(id); }) || null;
    }

    function renderRoles() {
        var body = root.querySelector('[data-roles-body]');
        body.innerHTML = state.roles.map(function (role) {
            var system = role.is_system || role.system_key;
            var available = system ? 'Все' : Number(role.available_page_count || 0);
            return '<tr class="roles-access__row" tabindex="0" data-role-id="' + Number(role.id) + '">' +
                '<td><div class="roles-access__name">' + esc(role.name) + (system ? '<span class="roles-access__badge">Системная</span>' : '') + '</div></td>' +
                '<td>' + Number(role.membership_count || 0) + '</td><td>' + esc(available) + '</td>' +
                '<td><button type="button" class="btn btn-ghost roles-access__menu-btn" data-role-menu aria-label="Действия с ролью">⋯</button></td></tr>';
        }).join('') || '<tr><td colspan="4" class="roles-access__empty">Роли не найдены.</td></tr>';
    }

    function renderMemberships() {
        var body = root.querySelector('[data-memberships-body]');
        body.innerHTML = state.memberships.map(function (membership) {
            var active = membership.status === 'active';
            var count = Number(membership.personal_settings_count || 0);
            return '<tr class="roles-access__row" tabindex="0" data-membership-id="' + Number(membership.id) + '">' +
                '<td><div class="roles-access__name">' + esc(membership.full_name) + '</div><span class="text-muted">' + esc(membership.login) + '</span></td>' +
                '<td><span class="roles-access__badge' + (active ? '' : ' is-disabled') + '">' + (active ? 'Активен' : 'Отключён') + '</span></td>' +
                '<td>' + esc(membership.role_name || '—') + '</td><td>' + (count ? count : '—') + '</td></tr>';
        }).join('') || '<tr><td colspan="4" class="roles-access__empty">Пользователи не найдены.</td></tr>';
    }

    function renderAssignmentPicker() {
        var select = root.querySelector('[data-assignment-membership]');
        var previous = Number(select.value || activeAssignmentMembershipId || 0);
        select.innerHTML = state.memberships.map(function (membership) {
            return '<option value="' + Number(membership.id) + '">' + esc(membership.full_name) + (membership.status === 'active' ? '' : ' — Отключён') + '</option>';
        }).join('');
        if (state.memberships.some(function (item) { return Number(item.id) === previous; })) select.value = String(previous);
        activeAssignmentMembershipId = Number(select.value || 0);
    }

    function renderAll() { renderRoles(); renderMemberships(); renderAssignmentPicker(); }

    root.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-access-tab]');
        if (tab) { activateTab(tab.dataset.accessTab); return; }
        var roleRow = event.target.closest('[data-role-id]');
        if (roleRow) {
            var role = roleById(roleRow.dataset.roleId);
            if (event.target.closest('[data-role-menu]') && role && !role.is_system && !role.system_key) openRoleActions(role);
            else if (role) openRoleModal(role);
            return;
        }
        var membershipRow = event.target.closest('[data-membership-id]');
        if (membershipRow) {
            var membership = state.memberships.find(function (item) { return Number(item.id) === Number(membershipRow.dataset.membershipId); });
            if (membership) openMembershipModal(membership);
        }
    });
    root.addEventListener('keydown', function (event) {
        if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('[data-role-id], [data-membership-id]')) {
            event.preventDefault(); event.target.click();
        }
    });

    function activateTab(name) {
        root.querySelectorAll('[data-access-tab]').forEach(function (tab) {
            var active = tab.dataset.accessTab === name;
            tab.classList.toggle('is-active', active); tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        root.querySelectorAll('[data-access-panel]').forEach(function (panel) { panel.classList.toggle('is-hidden', panel.dataset.accessPanel !== name); });
        root.querySelector('[data-role-create]').classList.toggle('is-hidden', name !== 'roles');
        root.querySelector('[data-membership-create]').classList.toggle('is-hidden', name !== 'users');
        if (name === 'assignments') loadAssignmentMatrix();
    }

    var modal = document.getElementById('roles-access-modal');
    var modalDialog = modal.querySelector('.roles-access-modal__dialog');
    var modalForm = modal.querySelector('[data-access-modal-form]');
    var modalBody = modal.querySelector('[data-access-modal-body]');
    var modalTitle = modal.querySelector('[data-access-modal-title]');
    var modalSubtitle = modal.querySelector('[data-access-modal-subtitle]');
    var modalSecondary = modal.querySelector('[data-access-modal-secondary]');
    var modalSave = modal.querySelector('[data-access-modal-save]');
    var lastFocus = null;
    var submitHandler = null;

    function openModal(config) {
        lastFocus = document.activeElement;
        modalTitle.textContent = config.title;
        modalSubtitle.textContent = config.subtitle || '';
        modalBody.innerHTML = config.body;
        modalSecondary.innerHTML = config.secondary || '';
        modalSave.classList.toggle('is-hidden', config.readonly === true);
        submitHandler = config.submit || null;
        modal.classList.add('is-open'); modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('roles-access-modal-open');
        window.setTimeout(function () { (modalBody.querySelector('input:not([disabled]),select:not([disabled])') || modalDialog).focus(); }, 0);
    }
    function closeModal() {
        modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('roles-access-modal-open'); submitHandler = null;
        if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
    }
    modal.querySelectorAll('[data-access-modal-close]').forEach(function (button) { button.addEventListener('click', closeModal); });
    modal.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { event.preventDefault(); closeModal(); return; }
        if (event.key !== 'Tab') return;
        var focusable = Array.prototype.slice.call(modal.querySelectorAll('button:not([disabled]):not(.is-hidden),input:not([disabled]),select:not([disabled]),[tabindex="0"]'));
        if (!focusable.length) return;
        var first = focusable[0], last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    modalForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!submitHandler || modalSave.disabled) return;
        modalSave.disabled = true; modalSave.textContent = 'Сохранение…';
        Promise.resolve(submitHandler()).catch(function (error) { announce(error.message, 'error'); })
            .finally(function () { modalSave.disabled = false; modalSave.textContent = 'Сохранить'; });
    });

    function pageTreeHtml(mode, values, effective, disabled) {
        return '<div class="roles-access__page-tree">' + Object.keys(state.page_tree).map(function (section) {
            var groups = state.page_tree[section] || {};
            return '<div class="roles-access__tree-section"><div class="roles-access__tree-section-title">' + esc(section) + '</div>' +
                Object.keys(groups).map(function (group) {
                    var pages = groups[group] || [];
                    var groupId = 'access-group-' + Math.random().toString(36).slice(2);
                    var controls = pages.map(function (page) {
                        var key = page.key, current = values[key];
                        if (mode === 'role') {
                            return '<label class="roles-access__tree-page roles-access__tree-page--role"><input type="checkbox" data-page-key="' + esc(key) + '" data-group="' + groupId + '"' + (current ? ' checked' : '') + (disabled ? ' disabled' : '') + '><span>' + esc(page.label) + '</span></label>';
                        }
                        var inherited = effective[key] ? 'разрешено' : 'закрыто';
                        var chosen = current == null ? 'inherit' : current;
                        return '<div class="roles-access__tree-page roles-access__tree-page--member"><span class="roles-access__tree-page-name">' + esc(page.label) + '</span>' +
                            '<div class="roles-access__tri" role="radiogroup" aria-label="Доступ: ' + esc(page.label) + '" data-page-key="' + esc(key) + '" data-value="' + esc(chosen) + '" data-inherited="' + esc(inherited) + '">' +
                            '<button type="button" class="roles-access__tri-btn' + (chosen === 'inherit' ? ' is-active' : '') + '" data-page-choice="inherit" title="По роли: ' + esc(inherited) + '"' + (disabled ? ' disabled' : '') + '>По роли</button>' +
                            '<button type="button" class="roles-access__tri-btn is-allow' + (chosen === 'allow' ? ' is-active' : '') + '" data-page-choice="allow"' + (disabled ? ' disabled' : '') + '>Разрешить</button>' +
                            '<button type="button" class="roles-access__tri-btn is-deny' + (chosen === 'deny' ? ' is-active' : '') + '" data-page-choice="deny"' + (disabled ? ' disabled' : '') + '>Запретить</button></div></div>';
                    }).join('');
                    var groupHead = mode === 'role' ? '<label class="roles-access__tree-group-head"><input type="checkbox" data-page-group="' + groupId + '"' + (disabled ? ' disabled' : '') + '><span>' + esc(group || 'Страницы') + '</span></label>' : '<div class="roles-access__tree-group-head roles-access__tree-group-head--member"><span>' + esc(group || 'Страницы') + '</span></div>'; return '<div class="roles-access__tree-group">' + groupHead + '<div class="roles-access__tree-pages roles-access__tree-pages--' + mode + '">' + controls + '</div></div>';
                }).join('') + '</div>';
        }).join('') + '</div>';
    }

    function scopeHelp(entity, scope) {
        if (scope === 'all') return 'Пользователь видит все объекты этого типа внутри компании. Закрепления сохраняются.';
        if (scope === 'none') return 'Пользователь не может работать с данными этого типа. Закрепления сохраняются.';
        return ['linear_trip', 'waste_trip'].indexOf(entity) >= 0 ? 'Пользователь видит рейсы, которые создал сам.' : 'Пользователь видит объекты, закреплённые за ним.';
    }

    function scopesHtml(mode, values, effective, disabled) {
        return '<div class="roles-access__scope-table">' + state.regulated_entities.map(function (entity) {
            var current = values[entity];
            var options = mode === 'member'
                ? '<option value="inherit"' + (current == null ? ' selected' : '') + '>По роли · ' + esc(scopeLabels[effective[entity]] || 'Нет доступа') + '</option>'
                : '';
            ['all', 'own', 'none'].forEach(function (scope) {
                options += '<option value="' + scope + '"' + (current === scope ? ' selected' : '') + '>' + esc(scopeLabels[scope]) + (mode === 'member' ? ' · Персонально' : '') + '</option>';
            });
            var shown = current == null ? effective[entity] : current;
            return '<label class="roles-access__scope-row"><span class="roles-access__scope-name">' + esc(labels[entity] || entity) + '<small class="roles-access__scope-help" data-scope-help="' + esc(entity) + '">' + esc(scopeHelp(entity, shown)) + '</small></span>' +
                '<select class="field-select" data-scope-key="' + esc(entity) + '"' + (disabled ? ' disabled' : '') + '>' + options + '</select></label>';
        }).join('') + '</div>';
    }

    function bindTreeAndScopeControls() {
        modalBody.querySelectorAll('[data-page-choice]').forEach(function (button) {
            button.addEventListener('click', function () {
                var control = button.closest('[data-page-key]');
                if (!control || button.disabled) return;
                control.dataset.value = button.dataset.pageChoice;
                control.querySelectorAll('[data-page-choice]').forEach(function (item) {
                    item.classList.toggle('is-active', item === button);
                    item.setAttribute('aria-pressed', item === button ? 'true' : 'false');
                });
            });
        });
        modalBody.querySelectorAll('[data-page-group]').forEach(function (group) {
            var children = modalBody.querySelectorAll('[data-group="' + group.dataset.pageGroup + '"]');
            var update = function () {
                var checked = Array.prototype.filter.call(children, function (item) { return item.checked; }).length;
                group.checked = checked === children.length && children.length > 0; group.indeterminate = checked > 0 && checked < children.length;
            };
            group.addEventListener('change', function () { children.forEach(function (item) { item.checked = group.checked; }); update(); });
            children.forEach(function (item) { item.addEventListener('change', update); }); update();
        });
        modalBody.querySelectorAll('[data-scope-key]').forEach(function (select) {
            select.addEventListener('change', function () {
                var entity = select.dataset.scopeKey;
                var effective = select.options[select.selectedIndex].text.indexOf('По роли') === 0
                    ? ((select.options[select.selectedIndex].text.split('·')[1] || '').trim()) : scopeLabels[select.value];
                var key = Object.keys(scopeLabels).find(function (scope) { return scopeLabels[scope] === effective; }) || select.value;
                var help = modalBody.querySelector('[data-scope-help="' + entity + '"]');
                if (help) help.textContent = scopeHelp(entity, key);
            });
        });
    }

    function collectPageValues(mode) {
        var result = {};
        modalBody.querySelectorAll('[data-page-key]').forEach(function (control) {
            if (mode === 'role') result[control.dataset.pageKey] = control.checked;
            else {
                var value = control.dataset.value || 'inherit';
                if (value !== 'inherit') result[control.dataset.pageKey] = value;
            }
        });
        return result;
    }
    function collectScopeValues() {
        var result = {};
        modalBody.querySelectorAll('[data-scope-key]').forEach(function (control) { if (control.value !== 'inherit') result[control.dataset.scopeKey] = control.value; });
        return result;
    }

    function openRoleModal(role, copyMode) {
        var system = !!(role.is_system || role.system_key);
        var readonlySystem = system && !copyMode;
        var title = copyMode ? 'Копировать роль' : (system ? 'Роль «Руководитель»' : 'Настройка роли');
        var body = '<section class="roles-access-modal__section"><label class="field"><span class="field-label">Название</span><input class="field-input" data-role-name maxlength="100" value="' + (copyMode ? '' : esc(role.name)) + '"' + (readonlySystem ? ' disabled' : '') + '></label></section>' +
            '<section class="roles-access-modal__section"><h3 class="roles-access-modal__section-head">Доступ к страницам</h3>' + pageTreeHtml('role', role.pages || {}, {}, readonlySystem) + '</section>' +
            '<section class="roles-access-modal__section"><h3 class="roles-access-modal__section-head">Доступ к данным</h3>' + scopesHtml('role', role.data_scopes || {}, {}, readonlySystem) + '</section>';
        openModal({ title: title, subtitle: readonlySystem ? 'Системная роль всегда имеет полный доступ.' : 'Все изменения применятся одной кнопкой.', body: body, readonly: readonlySystem,
            secondary: !copyMode ? '<button type="button" class="btn btn-ghost" data-role-copy>Копировать</button>' + (!system ? ' <button type="button" class="btn btn-danger" data-role-delete>Удалить</button>' : '') : '',
            submit: function () {
                var name = modalBody.querySelector('[data-role-name]').value.trim();
                var pages = collectPageValues('role'), dataScopes = collectScopeValues();
                var request = copyMode
                    ? jsonRequest('/company/roles-access/roles/' + Number(role.id) + '/copy', { method:'POST', body:{ name:name } }).then(function (payload) { return jsonRequest('/company/roles-access/roles/' + Number(payload.role.id) + '/save', { method:'POST', body:{ name:name, pages:pages, data_scopes:dataScopes } }); })
                    : jsonRequest('/company/roles-access/roles/' + Number(role.id) + '/save', { method:'POST', body:{ name:name, pages:pages, data_scopes:dataScopes } });
                return request.then(function () { closeModal(); return refreshSnapshot(copyMode ? 'Роль скопирована.' : 'Роль сохранена.'); });
            }
        });
        bindTreeAndScopeControls();
        var copy = modal.querySelector('[data-role-copy]'); if (copy) copy.addEventListener('click', function () { openRoleModal(role, true); });
        var remove = modal.querySelector('[data-role-delete]'); if (remove) remove.addEventListener('click', function () {
            if (!window.confirm('Удалить роль «' + role.name + '»?')) return;
            remove.disabled = true;
            jsonRequest('/company/roles-access/roles/' + Number(role.id) + '/delete', { method:'POST', body:{} })
                .then(function () { closeModal(); return refreshSnapshot('Роль удалена.'); }).catch(function (error) { remove.disabled = false; announce(error.message, 'error'); });
        });
    }

    function openRoleActions(role) { openRoleModal(role); }

    function openNewRoleModal() {
        var blank = { id:0, name:'', pages:{}, data_scopes:{} };
        state.pages.forEach(function (page) { blank.pages[page.key] = false; });
        state.regulated_entities.forEach(function (key) { blank.data_scopes[key] = 'none'; });
        var body = '<section class="roles-access-modal__section"><label class="field"><span class="field-label">Название</span><input class="field-input" data-role-name maxlength="100" autocomplete="off"></label></section>' +
            '<section class="roles-access-modal__section"><h3 class="roles-access-modal__section-head">Доступ к страницам</h3>' + pageTreeHtml('role', blank.pages, {}, false) + '</section>' +
            '<section class="roles-access-modal__section"><h3 class="roles-access-modal__section-head">Доступ к данным</h3>' + scopesHtml('role', blank.data_scopes, {}, false) + '</section>';
        openModal({ title:'Новая роль', subtitle:'По умолчанию все страницы и данные закрыты.', body:body, submit:function () {
            var name = modalBody.querySelector('[data-role-name]').value.trim(), pages = collectPageValues('role'), scopes = collectScopeValues();
            return jsonRequest('/company/roles-access/roles', { method:'POST', body:{name:name} }).then(function (payload) {
                return jsonRequest('/company/roles-access/roles/' + Number(payload.role.id) + '/save', {method:'POST', body:{name:name,pages:pages,data_scopes:scopes}});
            }).then(function () { closeModal(); return refreshSnapshot('Роль создана.'); });
        }});
        bindTreeAndScopeControls();
    }

    function openMembershipModal(membership) {
        var system = !!membership.is_system_owner;
        var roles = state.roles.map(function (role) { return '<option value="' + Number(role.id) + '"' + (Number(role.id) === Number(membership.role_id) ? ' selected' : '') + '>' + esc(role.name) + '</option>'; }).join('');
        var body = '<section class="roles-access-modal__section roles-access-modal__identity-section"><div class="roles-access-modal__identity"><label class="field"><span class="field-label">Пользователь</span><input class="field-input" value="' + esc(membership.full_name) + '" disabled></label>' +
            '<label class="field"><span class="field-label">Роль</span><select class="field-select" data-member-role>' + roles + '</select></label>' +
            '<label class="field"><span class="field-label">Статус</span><select class="field-select" data-member-status' + (system ? '' : '') + '><option value="active"' + (membership.status === 'active' ? ' selected' : '') + '>Активен</option><option value="blocked"' + (membership.status !== 'active' ? ' selected' : '') + '>Отключён</option></select></label></div><div class="notice info roles-access__warning is-hidden" data-role-change-warning>У пользователя есть персональные настройки. После смены роли они сохранятся.</div></section>' +
            '<section class="roles-access-modal__section"><h3 class="roles-access-modal__section-head">Персональные настройки страниц</h3><p class="roles-access-modal__help">«По роли» использует настройку основной роли. Персональное значение действует только для этого пользователя.</p>' + pageTreeHtml('member', membership.page_overrides || {}, roleById(membership.role_id).pages || {}, system) + '</section>' +
            '<section class="roles-access-modal__section"><h3 class="roles-access-modal__section-head">Персональные настройки данных</h3><p class="roles-access-modal__help">«По роли» использует настройку основной роли.</p>' + scopesHtml('member', membership.data_scope_overrides || {}, roleById(membership.role_id).data_scopes || {}, system) + '</section>';
        openModal({title:'Настройка пользователя',subtitle:membership.login || '',body:body,readonly:false,
            secondary: system ? '' : '<button type="button" class="btn btn-danger" data-membership-remove>Исключить из компании</button>',
            submit:function () {
                return jsonRequest('/company/roles-access/memberships/' + Number(membership.id) + '/save', {method:'POST',body:{
                    role_id:Number(modalBody.querySelector('[data-member-role]').value), status:modalBody.querySelector('[data-member-status]').value,
                    page_overrides:collectPageValues('member'), data_scope_overrides:collectScopeValues()
                }}).then(function () { closeModal(); return refreshSnapshot('Пользователь сохранён.'); });
            }});
        bindTreeAndScopeControls();
        modalBody.querySelector('[data-member-role]').addEventListener('change', function () {
            if (Number(membership.personal_settings_count || 0) > 0) modalBody.querySelector('[data-role-change-warning]').classList.remove('is-hidden');
        });
        var remove = modal.querySelector('[data-membership-remove]'); if (remove) remove.addEventListener('click', function () {
            if (!window.confirm('Исключить пользователя из компании?')) return;
            remove.disabled = true;
            jsonRequest('/company/roles-access/memberships/' + Number(membership.id) + '/remove', {method:'POST',body:{}})
                .then(function () { closeModal(); return refreshSnapshot('Пользователь исключён.'); }).catch(function (error) { remove.disabled=false; announce(error.message,'error'); });
        });
    }

    function openNewMembershipModal() {
        var roles = state.roles.map(function (role) { return '<option value="' + Number(role.id) + '">' + esc(role.name) + '</option>'; }).join('');
        var body = '<section class="roles-access-modal__section"><p class="roles-access-modal__help">Если логин уже существует, система добавит существующую учётную запись в эту компанию и не создаст дубль. Пароль для существующей учётной записи можно не вводить.</p><div class="roles-access-modal__identity">' +
            '<label class="field"><span class="field-label">ФИО</span><input class="field-input" data-new-full-name required></label><label class="field"><span class="field-label">Логин</span><input class="field-input" data-new-login required autocomplete="off"></label>' +
            '<label class="field"><span class="field-label">Пароль</span><input type="password" class="field-input" data-new-password autocomplete="new-password"></label><label class="field"><span class="field-label">Email</span><input type="email" class="field-input" data-new-email></label>' +
            '<label class="field"><span class="field-label">Телефон</span><input class="field-input" data-new-phone></label><label class="field"><span class="field-label">Статус</span><select class="field-select" data-new-status><option value="active">Активен</option><option value="blocked">Отключён</option></select></label>' +
            '<label class="field is-wide"><span class="field-label">Роль</span><select class="field-select" data-new-role>' + roles + '</select></label></div></section>';
        openModal({title:'Добавить пользователя',subtitle:'Новая или существующая глобальная учётная запись',body:body,submit:function () {
            return jsonRequest('/company/roles-access/memberships', {method:'POST',body:{full_name:modalBody.querySelector('[data-new-full-name]').value,login:modalBody.querySelector('[data-new-login]').value,password:modalBody.querySelector('[data-new-password]').value,email:modalBody.querySelector('[data-new-email]').value,phone:modalBody.querySelector('[data-new-phone]').value,role_id:Number(modalBody.querySelector('[data-new-role]').value),status:modalBody.querySelector('[data-new-status]').value}})
                .then(function () { closeModal(); return refreshSnapshot('Пользователь добавлен.'); });
        }});
    }

    function loadAssignmentMatrix(force) {
        var content = root.querySelector('[data-assignment-content]');
        var membershipId = Number(root.querySelector('[data-assignment-membership]').value || 0);
        activeAssignmentMembershipId = membershipId;
        if (!membershipId) { content.innerHTML = '<div class="roles-access__loading">Нет сотрудников для закрепления.</div>'; return; }
        if (assignmentCache && !force) { renderAssignments(); return; }
        content.innerHTML = '<div class="roles-access__loading">Загрузка закреплений…</div>';
        Promise.all(state.memberships.map(function (membership) {
            return jsonRequest('/company/roles-access/assignments?membership_id=' + Number(membership.id));
        })).then(function (payloads) {
            assignmentCache = { memberships: payloads[0] ? payloads[0].memberships : [], entities: {}, sets: {} };
            payloads.forEach(function (payload) {
                Object.keys(payload.entities || {}).forEach(function (type) {
                    assignmentCache.entities[type] = assignmentCache.entities[type] || payload.entities[type];
                    payload.entities[type].forEach(function (entity) {
                        var key = type + ':' + Number(entity.id); assignmentCache.sets[key] = assignmentCache.sets[key] || [];
                        if (entity.assigned) assignmentCache.sets[key].push(Number(payload.selected_membership_id));
                    });
                });
            });
            renderAssignments();
        }).catch(function (error) { content.innerHTML = '<div class="notice warn">' + esc(error.message) + ' <button type="button" class="btn btn-ghost" data-assignment-retry>Повторить</button></div>'; });
    }

    function renderAssignments() {
        var content = root.querySelector('[data-assignment-content]');
        if (!assignmentCache) return;
        var order = ['client','contractor','vehicle_unit','driver'];
        content.innerHTML = '<div class="roles-access__assignment-grid">' + order.map(function (type) {
            var entities = assignmentCache.entities[type] || [];
            var selected = entities.filter(function (entity) { return (assignmentCache.sets[type + ':' + Number(entity.id)] || []).indexOf(activeAssignmentMembershipId) >= 0; }).length;
            return '<section class="roles-access__assignment-group" data-assignment-group="' + type + '"><div class="roles-access__assignment-title"><span>' + esc(labels[type]) + '</span><span class="roles-access__assignment-count" data-assignment-count>' + selected + ' / ' + entities.length + '</span></div>' +
                '<div class="roles-access__assignment-tools"><input class="field-input roles-access__assignment-search" type="search" placeholder="Поиск…" aria-label="Поиск: ' + esc(labels[type]) + '" data-assignment-search>' +
                '<div class="roles-access__assignment-actions"><button type="button" class="btn btn-ghost" data-assignment-bulk="select">Выбрать все</button><button type="button" class="btn btn-ghost" data-assignment-bulk="clear">Снять все</button></div></div>' +
                '<div class="roles-access__assignment-list">' +
                (entities.map(function (entity) {
                    var checked = (assignmentCache.sets[type + ':' + Number(entity.id)] || []).indexOf(activeAssignmentMembershipId) >= 0;
                    return '<label class="roles-access__assignment-row" data-assignment-label="' + esc(String(entity.label).toLocaleLowerCase('ru-RU')) + '"><input type="checkbox" data-assignment-type="' + type + '" data-assignment-id="' + Number(entity.id) + '"' + (checked ? ' checked' : '') + '><span>' + esc(entity.label) + '</span></label>';
                }).join('') || '<div class="roles-access__empty">Нет объектов.</div>') + '<div class="roles-access__empty is-hidden" data-assignment-search-empty>Ничего не найдено.</div></div></section>';
        }).join('') + '</div>';
    }

    function refreshAssignmentBulkLabels(group) {
        if (!group) return;
        var search = group.querySelector('[data-assignment-search]');
        var filtered = !!(search && search.value.trim());
        var select = group.querySelector('[data-assignment-bulk="select"]');
        var clear = group.querySelector('[data-assignment-bulk="clear"]');
        if (select) select.textContent = filtered ? 'Выбрать найденные' : 'Выбрать все';
        if (clear) clear.textContent = filtered ? 'Снять найденные' : 'Снять все';
    }

    function setAssignmentBulkBusy(group, busy) {
        if (!group) return;
        group.dataset.assignmentBulkBusy = busy ? '1' : '0';
        group.querySelectorAll('[data-assignment-bulk]').forEach(function (button) { button.disabled = !!busy; });
    }

    function refreshAssignmentGroupUi(group) {
        if (!group) return;
        var rows = Array.prototype.slice.call(group.querySelectorAll('.roles-access__assignment-row'));
        var count = group.querySelector('[data-assignment-count]');
        var selected = rows.filter(function (row) { var box = row.querySelector('[data-assignment-type]'); return box && box.checked; }).length;
        if (count) count.textContent = selected + ' / ' + rows.length;
        var empty = group.querySelector('[data-assignment-search-empty]');
        if (empty) empty.classList.toggle('is-hidden', rows.some(function (row) { return !row.classList.contains('is-hidden'); }));
        refreshAssignmentBulkLabels(group);
    }

    function saveAssignmentCheckbox(checkbox, nextChecked, options) {
        options = options || {};
        if (!checkbox || !assignmentCache || checkbox.disabled) return Promise.resolve({ok:true, skipped:true});
        var row = checkbox.closest('.roles-access__assignment-row');
        var group = checkbox.closest('[data-assignment-group]');
        var key = checkbox.dataset.assignmentType + ':' + Number(checkbox.dataset.assignmentId);
        var before = (assignmentCache.sets[key] || []).slice();
        var after = before.filter(function (id) { return id !== activeAssignmentMembershipId; });
        checkbox.checked = nextChecked;
        if (nextChecked) after.push(activeAssignmentMembershipId);
        assignmentCache.sets[key] = after;
        row.classList.add('is-saving'); checkbox.disabled = true; refreshAssignmentGroupUi(group);
        return jsonRequest('/company/responsible-assignments/reassign', {method:'POST', body:{entity_type:checkbox.dataset.assignmentType,entity_id:Number(checkbox.dataset.assignmentId),membership_ids:after}})
            .then(function (payload) { assignmentCache.sets[key] = (payload.membership_ids || after).map(Number); return {ok:true}; })
            .catch(function (error) {
                assignmentCache.sets[key] = before;
                checkbox.checked = before.indexOf(activeAssignmentMembershipId) >= 0;
                if (!options.silent) announce(error.message,'error');
                return {ok:false, error:error};
            })
            .finally(function () { checkbox.disabled = false; row.classList.remove('is-saving'); refreshAssignmentGroupUi(group); });
    }

    root.querySelector('[data-assignment-membership]').addEventListener('change', function () { activeAssignmentMembershipId=Number(this.value||0); renderAssignments(); });
    root.querySelector('[data-assignment-content]').addEventListener('click', function (event) {
        if (event.target.closest('[data-assignment-retry]')) { assignmentCache=null; loadAssignmentMatrix(true); return; }
        var bulk = event.target.closest('[data-assignment-bulk]');
        if (!bulk || !assignmentCache) return;
        var group = bulk.closest('[data-assignment-group]');
        if (!group || group.dataset.assignmentBulkBusy === '1') return;
        var target = bulk.dataset.assignmentBulk === 'select';
        var visible = Array.prototype.slice.call(group.querySelectorAll('.roles-access__assignment-row:not(.is-hidden) [data-assignment-type]'));
        var changed = visible.filter(function (box) { return box.checked !== target && !box.disabled; });
        if (!changed.length) { announce('Закрепления сохранены.'); return; }
        setAssignmentBulkBusy(group, true);
        Promise.all(changed.map(function (box) { return saveAssignmentCheckbox(box, target, {silent:true}); }))
            .then(function (results) {
                var failed = results.filter(function (result) { return !result.ok; }).length;
                var saved = results.length - failed;
                announce(failed ? 'Сохранено: ' + saved + '. Не удалось: ' + failed + '.' : 'Закрепления сохранены.', failed ? 'error' : 'success');
            })
            .finally(function () { setAssignmentBulkBusy(group, false); refreshAssignmentGroupUi(group); });
    });
    root.querySelector('[data-assignment-content]').addEventListener('input', function (event) {
        var search = event.target.closest('[data-assignment-search]'); if (!search) return;
        var group = search.closest('[data-assignment-group]');
        var term = search.value.trim().toLocaleLowerCase('ru-RU');
        group.querySelectorAll('.roles-access__assignment-row').forEach(function (row) {
            row.classList.toggle('is-hidden', term !== '' && (row.dataset.assignmentLabel || '').indexOf(term) < 0);
        });
        refreshAssignmentGroupUi(group);
    });
    root.querySelector('[data-assignment-content]').addEventListener('change', function (event) {
        var checkbox = event.target.closest('[data-assignment-type]'); if (!checkbox || !assignmentCache) return;
        saveAssignmentCheckbox(checkbox, checkbox.checked);
    });

    root.querySelector('[data-role-create]').addEventListener('click', openNewRoleModal);
    root.querySelector('[data-membership-create]').addEventListener('click', openNewMembershipModal);
    renderAll();
}());
