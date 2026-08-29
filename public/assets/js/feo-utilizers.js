(function () {
    'use strict';

    function init() {
        var page = document.querySelector('[data-feo-utilizer-page-ready]');
        if (!page || !window.ModalShell || typeof window.ModalShell.create !== 'function') return;

        var controller = window.ModalShell.create({
            modalId: 'feo-utilizer-view-modal',
            deleteConfirmId: 'feo-utilizer-delete-confirm-modal',
            overlayClass: 'driver-view-overlay',
            modalInnerClass: 'modal-lg driver-view-modal-inner',
            title: 'Утилизатор',
            loadingClass: 'driver-modal-loading',
            nameSelector: '.feo-utilizer-view-name',
            editFormSelector: '[data-feo-utilizer-edit-form]',
            gridSelector: '[data-feo-utilizer-grid]',
            viewBtnSelectors: {
                edit: '[data-feo-utilizer-edit-btn]',
                close: '[data-feo-utilizer-close-btn]',
                delete: '[data-feo-utilizer-delete-btn]'
            },
            editBtnSelectors: {
                cancel: '[data-feo-utilizer-cancel-edit-btn]'
            },
            endpoints: {
                view: function (id) { return window.getErpBasePath() + '/company/feo/utilizers/' + id + '/modal-view'; },
                edit: function (id) { return window.getErpBasePath() + '/company/feo/utilizers/' + id + '/modal-edit'; },
                delete: function (id) { return window.getErpBasePath() + '/company/feo/utilizers/' + id + '/modal-delete'; }
            },
            errorMessages: {
                loadFailed: 'Не удалось загрузить данные утилизатора.',
                saveFailed: 'Не удалось сохранить изменения.',
                deleteFailed: 'Не удалось удалить утилизатора.'
            },
            deleteConfirm: {
                title: 'Удалить утилизатора?',
                warning: 'Удаление возможно только при отсутствии правил и ручных назначений.',
                instruction: 'Для подтверждения введите <b>УДАЛИТЬ</b>.',
                placeholder: 'Введите УДАЛИТЬ',
                confirmWord: 'УДАЛИТЬ',
                cancelBtn: 'Отмена',
                confirmBtn: 'Удалить'
            }
        });

        var tbody = page.querySelector('[data-feo-utilizer-grid] tbody');
        if (tbody) {
            tbody.addEventListener('dblclick', function (event) {
                if (event.target.closest('a, button, input, select, textarea, label')) return;
                var row = event.target.closest('tr[data-feo-utilizer-id]');
                if (!row || !tbody.contains(row)) return;
                var id = row.getAttribute('data-feo-utilizer-id');
                if (id) controller.loadView(id);
            });
        }

        document.addEventListener('input', function (event) {
            var input = event.target.closest('[data-feo-color-input]');
            if (!input) return;
            var wrap = input.closest('.feo-utilizer-color-input');
            var value = wrap ? wrap.querySelector('[data-feo-color-value]') : null;
            if (value) value.textContent = String(input.value || '').toUpperCase();
        });
    }

    if (document.readyState === 'loading') {
        window.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
