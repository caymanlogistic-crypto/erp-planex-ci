(function () {
    function normalizeInn(value) {
        return String(value || '').replace(/[^0-9]/g, '');
    }

    function setFieldMessage(form, fieldName, message) {
        var field = form.querySelector('[data-field="' + fieldName + '"]');
        var msg = field ? field.querySelector('.field-msg') : null;
        if (field) {
            field.classList.toggle('is-error', !!message);
        }
        if (msg) {
            msg.textContent = message || '';
        }
    }

    function setAutofillMessage(form, tone, title, message) {
        var box = form.querySelector('[data-inn-autofill-message]');
        var titleNode = form.querySelector('[data-inn-autofill-title]');
        var subNode = form.querySelector('[data-inn-autofill-sub]');
        if (!box) {
            return;
        }

        box.classList.remove('is-hidden', 'alert-warning', 'alert-error', 'alert-success');
        box.classList.add(tone === 'error' ? 'alert-error' : (tone === 'success' ? 'alert-success' : 'alert-warning'));
        if (titleNode) {
            titleNode.textContent = title || '';
        }
        if (subNode) {
            subNode.textContent = message || '';
        }
    }

    function clearAutofillMessage(form) {
        var box = form.querySelector('[data-inn-autofill-message]');
        var titleNode = form.querySelector('[data-inn-autofill-title]');
        var subNode = form.querySelector('[data-inn-autofill-sub]');
        if (!box) {
            return;
        }

        box.classList.add('is-hidden');
        box.classList.remove('alert-warning', 'alert-error', 'alert-success');
        if (titleNode) {
            titleNode.textContent = '';
        }
        if (subNode) {
            subNode.textContent = '';
        }
    }

    function markAutofilled(input, value) {
        if (!input || value === null || value === undefined || value === '') {
            return;
        }
        input.value = String(value);
        input.dataset.autofilled = '1';
        input.dataset.autofillValue = String(value);
        input.classList.add('is-autofilled');
    }

    function applyLookupPayload(form, payload, options) {
        var data = payload && payload.data ? payload.data : {};
        var typeFieldName = options.typeFieldName || 'contractor_type';
        var map = {
            name: data.name || '',
            kpp: data.kpp || '',
            ogrn: data.ogrn || '',
            legal_address: data.legal_address || '',
            director_full_name: data.director_full_name || '',
            director_position: data.director_position || ''
        };
        map[typeFieldName] = data.contractor_type || '';

        Object.keys(map).forEach(function (fieldName) {
            var input = form.querySelector('[name="' + fieldName + '"]');
            if (!input || !map[fieldName]) {
                return;
            }
            markAutofilled(input, map[fieldName]);
            setFieldMessage(form, fieldName, '');
        });
    }

    window.runLegalEntityInnLookup = function (form, options) {
        if (!form) {
            return Promise.resolve({ ok: false, skipped: true });
        }

        var config = options || {};
        var innField = form.querySelector('[name="inn"]');
        var button = config.button || form.querySelector('[data-inn-autofill-btn]');
        var idleText = config.idleButtonText || 'Заполнить по ИНН';
        var loadingText = config.loadingButtonText || 'Поиск...';
        var inn = normalizeInn(innField ? innField.value : '');

        clearAutofillMessage(form);
        setFieldMessage(form, 'inn', '');

        if (innField) {
            innField.value = inn;
        }

        if (inn === '') {
            setFieldMessage(form, 'inn', 'Укажите ИНН');
            setAutofillMessage(form, 'warning', 'Автозаполнение недоступно', 'Сначала введите ИНН.');
            if (innField && typeof innField.focus === 'function') {
                innField.focus();
            }
            return Promise.resolve({ ok: false, skipped: true });
        }

        if (!/^\d+$/.test(inn) || (inn.length !== 10 && inn.length !== 12)) {
            setFieldMessage(form, 'inn', 'ИНН: 10 или 12 цифр');
            setAutofillMessage(form, 'warning', 'Некорректный ИНН', 'Укажите ИНН длиной 10 или 12 цифр.');
            if (innField && typeof innField.focus === 'function') {
                innField.focus();
            }
            return Promise.resolve({ ok: false, skipped: true });
        }

        if (button) {
            button.disabled = true;
            button.textContent = loadingText;
        }

        var lookupUrl = form.dataset.innLookupUrl || '/company/requisites/lookup-by-inn';

        return fetch(lookupUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ inn: inn })
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return {
                        ok: false,
                        message: 'Не удалось получить данные. Заполните реквизиты вручную.'
                    };
                });
            })
            .then(function (payload) {
                if (!payload || payload.ok !== true) {
                    setAutofillMessage(
                        form,
                        'warning',
                        'Автозаполнение не выполнено',
                        payload && payload.message ? payload.message : 'Не удалось получить данные. Заполните реквизиты вручную.'
                    );
                    return { ok: false, payload: payload };
                }

                applyLookupPayload(form, payload, config);
                setAutofillMessage(form, 'success', 'Реквизиты заполнены', payload.message || 'Данные по ИНН получены.');
                return { ok: true, payload: payload };
            })
            .catch(function () {
                setAutofillMessage(form, 'error', 'Сервис временно недоступен', 'Не удалось получить данные. Заполните реквизиты вручную.');
                return { ok: false };
            })
            .finally(function () {
                if (button) {
                    button.disabled = false;
                    button.textContent = idleText;
                }
            });
    };
})();
