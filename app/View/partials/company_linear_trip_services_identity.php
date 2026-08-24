<script>
(function () {
    'use strict';

    var endpoint = <?= json_encode(app_url('/company/trips/linear/service-lines'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
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
            new MutationObserver(function () {
                bindExistingIds(form, services);
            }).observe(form, {childList: true, subtree: true});
        });
    }

    function scan(root) {
        if (!root || !root.querySelectorAll) return;
        root.querySelectorAll('[data-linear-trip-form]').forEach(ensure);
        if (root.matches && root.matches('[data-linear-trip-form]')) ensure(root);
    }

    document.addEventListener('DOMContentLoaded', function () {
        scan(document);
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) scan(node);
                });
            });
        }).observe(document.body, {childList: true, subtree: true});
    });
})();
</script>
