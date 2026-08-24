<style>
.linear-trip-services-section [data-trip-services-list] .linear-trip-service-card{border:0;border-radius:0;padding:0;margin:14px 0;background:transparent}.linear-trip-services-section [data-trip-services-list] .linear-trip-service-fields{grid-template-columns:minmax(180px,1fr) minmax(220px,1.3fr) auto}.linear-trip-service-remove-inline{display:flex;align-items:flex-end;padding-bottom:1px}.linear-trip-service-remove-inline .linear-trip-row-remove{flex:0 0 auto}.trip-services-view>.section-title{text-transform:uppercase}.registry-date-attention{white-space:normal!important}.registry-date-attention-text{display:block;color:var(--danger,#b42318)!important;font-weight:600;white-space:normal;line-height:1.25}.registry-date-attention .registry-date-note{margin-top:2px}@media(max-width:900px){.linear-trip-services-section [data-trip-services-list] .linear-trip-service-fields{grid-template-columns:1fr}.linear-trip-service-remove-inline{justify-content:flex-end}}
</style>
<script>
(function () {
    'use strict';

    var endpoint = <?= json_encode(app_url('/company/trips/linear/service-lines'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var serverToday = <?= json_encode(date('Y-m-d')) ?>;
    var loaded = {};

    function routeIdFor(form) {
        var match = String(form.action || '').match(/\/linear\/(\d+)\/modal-edit/);
        return match ? match[1] : null;
    }

    function paymentRows(card) {
        return Array.from(card.querySelectorAll('[data-service-payment-row]'));
    }

    function paymentName(row, key) {
        var named = row.querySelector('[name*="[payments]"]');
        if (!named) return '';
        return named.name.replace(/\[[^\]]+\]$/, '[' + key + ']');
    }

    function plannedDateIso(value) {
        var match = String(value || '').trim().match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
        return match ? (match[3] + '-' + match[2] + '-' + match[1]) : '';
    }

    function applyPlannedUnloadingAttention() {
        document.querySelectorAll('.linear-trip-registry tbody tr[data-linear-route-id]').forEach(function (row) {
            var dateCells = row.querySelectorAll('td.registry-date');
            if (dateCells.length < 2) return;
            var cell = dateCells[1];
            var note = cell.querySelector('.registry-date-note');
            var value = cell.querySelector('.registry-date-value');
            if (!note || !value || note.textContent.trim() !== '(плановая)') return;

            var plannedIso = plannedDateIso(value.textContent);
            if (plannedIso === '' || plannedIso > serverToday) return;

            cell.classList.remove('registry-date-planned');
            cell.classList.add('registry-date-attention');
            value.classList.add('registry-date-attention-text');
            note.classList.add('registry-date-attention-text');
            if (note.textContent !== '(Проставьте дату выгрузки или замените плановую дату)') {
                note.textContent = '(Проставьте дату выгрузки или замените плановую дату)';
            }
        });
    }

    function normalizeServiceUi(form) {
        if (!form || !form.querySelector) return;
        var section = form.querySelector('.linear-trip-services-section');
        if (!section) return;

        var title = section.querySelector('.section-title');
        if (title && title.textContent !== 'ДОПОЛНИТЕЛЬНЫЕ УСЛУГИ') {
            title.textContent = 'ДОПОЛНИТЕЛЬНЫЕ УСЛУГИ';
        }

        var primary = section.querySelector(':scope > .linear-trip-service-card:not([data-trip-service-card])');
        if (primary) primary.remove();

        Array.from(section.querySelectorAll('[data-trip-service-card]')).forEach(function (card) {
            var head = card.querySelector('.linear-trip-service-card-head');
            var remove = head ? head.querySelector('[data-remove-service]') : card.querySelector('[data-remove-service]');
            var fields = card.querySelector('.linear-trip-service-fields');
            if (head) head.remove();
            if (!remove || !fields || remove.closest('.linear-trip-service-remove-inline')) return;

            var holder = document.createElement('div');
            holder.className = 'linear-trip-service-remove-inline';
            holder.appendChild(remove);
            var description = fields.querySelector('.service-description');
            fields.insertBefore(holder, description || null);
        });
    }

    function normalizeViewUi(root) {
        if (!root || !root.querySelectorAll) return;
        var views = [];
        if (root.matches && root.matches('.trip-services-view')) views.push(root);
        root.querySelectorAll('.trip-services-view').forEach(function (view) { views.push(view); });
        views.forEach(function (view) {
            var title = view.querySelector('.section-title');
            if (title && title.textContent !== 'ДОПОЛНИТЕЛЬНЫЕ УСЛУГИ') {
                title.textContent = 'ДОПОЛНИТЕЛЬНЫЕ УСЛУГИ';
            }
        });
    }

    function bindExistingIds(form, services) {
        Array.from(form.querySelectorAll('[data-trip-service-card]')).forEach(function (card) {
            var serviceIdInput = card.querySelector('input[type="hidden"][name$="[id]"]');
            var serviceId = serviceIdInput ? Number(serviceIdInput.value || 0) : 0;
            if (!serviceId) return;

            var service = (services || []).find(function (item) {
                return Number(item.id || 0) === serviceId;
            });
            if (!service) return;

            paymentRows(card).forEach(function (row, index) {
                if (row.querySelector('input[data-service-payment-id]')) return;
                var payment = (service.payments || [])[index];
                if (!payment || !payment.id) return;
                var name = paymentName(row, 'id');
                if (!name) return;
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = name;
                hidden.value = String(payment.id);
                hidden.setAttribute('data-service-payment-id', '');
                row.appendChild(hidden);
            });
        });
    }

    function ensure(form) {
        normalizeServiceUi(form);
        if (!form || form.dataset.servicePaymentIdentityReady === '1') return;
        var routeId = routeIdFor(form);
        if (!routeId) return;
        form.dataset.servicePaymentIdentityReady = '1';

        var promise = loaded[routeId];
        if (!promise) {
            promise = fetch(endpoint + '?route_ids=' + encodeURIComponent(routeId), {
                credentials: 'same-origin',
                headers: {Accept: 'application/json'}
            }).then(function (response) {
                return response.json();
            }).then(function (payload) {
                return payload && payload.success ? (payload.services_by_route[routeId] || []) : [];
            }).catch(function () {
                return [];
            });
            loaded[routeId] = promise;
        }

        promise.then(function (services) {
            bindExistingIds(form, services);
            normalizeServiceUi(form);
            new MutationObserver(function () {
                bindExistingIds(form, services);
                normalizeServiceUi(form);
            }).observe(form, {childList: true, subtree: true});
        });
    }

    function scan(root) {
        if (!root || !root.querySelectorAll) return;
        root.querySelectorAll('[data-linear-trip-form]').forEach(ensure);
        if (root.matches && root.matches('[data-linear-trip-form]')) ensure(root);
        var ownerForm = root.closest ? root.closest('[data-linear-trip-form]') : null;
        if (ownerForm) normalizeServiceUi(ownerForm);
        normalizeViewUi(root);
    }

    document.addEventListener('DOMContentLoaded', function () {
        applyPlannedUnloadingAttention();
        scan(document);
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) scan(node);
                });
            });
        }).observe(document.body, {childList:true,subtree:true});
    });
})();
</script>
