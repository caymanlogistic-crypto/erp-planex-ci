(function () {
    'use strict';

    var base = (document.querySelector('meta[name="erp-base-path"]') || {}).content || '';
    var url = function (path) { return base.replace(/\/$/, '') + path; };
    var initialized = new WeakSet();

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>'"]/g, function (char) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[char];
        });
    }

    function request(path, options) {
        options = options || {}; options.credentials = 'same-origin';
        options.headers = Object.assign({'Accept':'application/json'}, options.headers || {});
        if (options.body && typeof options.body !== 'string') {
            options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(options.body);
        }
        return fetch(url(path), options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (payload) {
                if (!response.ok || payload.ok === false || payload.success === false) throw new Error(payload.message || payload.error || 'Не удалось обновить закрепление.');
                return payload;
            });
        });
    }

    function toast(message) {
        if (window.ErpToast && typeof window.ErpToast.error === 'function') window.ErpToast.error(message);
        else window.alert(message);
    }

    function init(block) {
        if (initialized.has(block)) return;
        initialized.add(block);
        var content = block.querySelector('[data-entity-assignment-content]');
        var entityType = block.dataset.entityType, entityId = Number(block.dataset.entityId || 0);
        request('/company/roles-access/data').then(function (snapshot) {
            var memberships = snapshot.memberships || [];
            return Promise.all(memberships.map(function (membership) {
                return request('/company/roles-access/assignments?membership_id=' + Number(membership.id));
            })).then(function (payloads) { return { memberships:memberships, payloads:payloads }; });
        }).then(function (result) {
            var assigned = [];
            result.payloads.forEach(function (payload) {
                var entity = ((payload.entities || {})[entityType] || []).find(function (item) { return Number(item.id) === entityId; });
                if (entity && entity.assigned) assigned.push(Number(payload.selected_membership_id));
            });
            content.innerHTML = '<div class="roles-access__inline-members">' + result.memberships.map(function (membership) {
                return '<label><input type="checkbox" data-inline-membership="' + Number(membership.id) + '"' + (assigned.indexOf(Number(membership.id)) >= 0 ? ' checked' : '') + '><span>' + esc(membership.full_name) + (membership.status === 'active' ? '' : ' · Отключён') + '</span></label>';
            }).join('') + '</div>';
            content.addEventListener('change', function (event) {
                var checkbox = event.target.closest('[data-inline-membership]'); if (!checkbox) return;
                var before = assigned.slice(), membershipId = Number(checkbox.dataset.inlineMembership);
                assigned = assigned.filter(function (id) { return id !== membershipId; }); if (checkbox.checked) assigned.push(membershipId);
                checkbox.disabled = true;
                request('/company/responsible-assignments/reassign', {method:'POST',body:{entity_type:entityType,entity_id:entityId,membership_ids:assigned}})
                    .then(function (payload) { assigned = (payload.membership_ids || assigned).map(Number); })
                    .catch(function (error) { assigned=before;checkbox.checked=before.indexOf(membershipId)>=0;toast(error.message); })
                    .finally(function () { checkbox.disabled=false; });
            });
        }).catch(function (error) {
            content.innerHTML = '<div class="notice warn">' + esc(error.message) + '</div>';
        });
    }

    function scan(node) {
        if (node.nodeType !== 1) return;
        if (node.matches && node.matches('[data-entity-assignment-block]')) init(node);
        if (node.querySelectorAll) node.querySelectorAll('[data-entity-assignment-block]').forEach(init);
    }
    scan(document.body);
    new MutationObserver(function (changes) { changes.forEach(function (change) { change.addedNodes.forEach(scan); }); }).observe(document.body, {childList:true,subtree:true});
}());
