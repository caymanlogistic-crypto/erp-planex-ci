document.documentElement.classList.add('js-ready');

window.getErpBasePath = function () {
    if (window.ERP_BASE_PATH) return window.ERP_BASE_PATH;
    var meta = document.querySelector('meta[name="erp-base-path"]');
    if (meta) return meta.getAttribute('content');
    var m = window.location.pathname.match(/^(\/[^/]+)/);
    if (m && m[1] === '/erp') return '/erp';
    return '';
};

// ============================================================
// Driver form init — shared between create & edit modes
// ============================================================
(function () {
    var allowedExtensions = ['pdf','doc','docx','rtf','odt','xls','xlsx','csv','ods','jpg','jpeg','png','webp','gif','bmp','tif','tiff','heic','heif','txt'];
    var maxFileSize = 20 * 1024 * 1024;

    function getExt(name) {
        var p = String(name||'').split('.');
        return p.length > 1 ? p.pop().toLowerCase() : '';
    }
    function getKind(name) {
        var e = getExt(name);
        if (e === 'pdf') return 'pdf';
        if (['doc','docx','rtf','odt'].indexOf(e) !== -1) return 'word';
        if (['xls','xlsx','csv','ods'].indexOf(e) !== -1) return 'excel';
        if (['jpg','jpeg','png','webp','gif','bmp','tif','tiff','heic','heif'].indexOf(e) !== -1) return 'photo';
        return 'other';
    }
    function badgeMeta(kind) {
        if (kind === 'pdf') return {t:'PDF',c:'is-pdf'};
        if (kind === 'word') return {t:'DOC',c:'is-doc'};
        if (kind === 'excel') return {t:'XLS',c:'is-xls'};
        if (kind === 'photo') return {t:'IMG',c:'is-img'};
        return {t:'FILE',c:'is-other'};
    }

    function neutralSummary() {
        return {text:'Файл не выбран',badge:{text:'—',cls:'file-type-badge-empty'},filled:false};
    }

    function summarize(files) {
        if (!files||!files.length) return neutralSummary();
        if (files.length === 1) {
            var k = getKind(files[0].name);
            var b = badgeMeta(k);
            return {text:files[0].name,badge:{text:b.t,cls:b.c},filled:true};
        }
        return {text:'Выбрано файлов: '+files.length,badge:{text:'FILE',cls:'is-other'},filled:true};
    }

    function paintRow(row, summary) {
        var badge = row.querySelector('.file-type-badge');
        var meta  = row.querySelector('.file-meta');
        var btnLabel = row.querySelector('.file-action-btn span');
        var clearBtn = row.querySelector('.predef-file-clear') || row.querySelector('.file-remove');
        if (badge) { badge.className = 'file-type-badge ' + (summary.filled ? summary.badge.cls : 'file-type-badge-empty'); badge.textContent = summary.badge.text; }
        if (meta)  { meta.textContent = summary.text; meta.classList.toggle('is-hidden', !summary.text); }
        if (btnLabel) btnLabel.textContent = summary.filled ? 'Заменить' : 'Выбрать';
        if (clearBtn) clearBtn.classList.toggle('is-hidden', !summary.filled);
        row.classList.toggle('is-empty', !summary.filled);
        row.classList.toggle('has-file', summary.filled);
        row.classList.remove('is-loading');
    }

    function existingSummary(row) {
        var exist = row.dataset.hasExisting === '1';
        if (!exist) return neutralSummary();
        var cls = row.getAttribute('data-existing-badge-class') || 'file-type-badge';
        var txt = row.getAttribute('data-existing-badge-text') || '—';
        var metaTxt = row.getAttribute('data-existing-meta') || 'Файл';
        return {
            text: metaTxt,
            badge: {text: txt, cls: cls.replace('file-type-badge ','')},
            filled: true
        };
    }

    window.initDriverForm = function (form) {
        if (!form || form.dataset.driverFormReady === '1') return;
        form.dataset.driverFormReady = '1';
        if (window.initDriverInputValidation) {
            window.initDriverInputValidation(form);
        }

        // ── Extra phones ──
        var phoneBtn = form.querySelector('[data-add-extra-phone-btn]');
        var phoneContainer = form.querySelector('[data-extra-phones-container]');
        if (phoneBtn && phoneContainer) {
            phoneBtn.addEventListener('click', function (e) {
                e.preventDefault();
                var row = document.createElement('div');
                row.className = 'driver-extra-phone-row';
                row.innerHTML = '<div class="field driver-extra-phone-field"><input type="text" name="extra_phones[]" class="field-input" placeholder="+7 900 000-00-00"><div class="field-msg"></div></div><div class="driver-extra-phone-comment-wrap"><input type="text" name="extra_phone_comments[]" class="field-input" placeholder="Комментарий к телефону"><button type="button" class="driver-extra-phone-remove" title="Удалить">×</button></div>';
                row.querySelector('.driver-extra-phone-remove').addEventListener('click', function () {
                    phoneContainer.removeChild(row);
                });
                phoneContainer.appendChild(row);
            });
            phoneContainer.querySelectorAll('.driver-extra-phone-remove').forEach(function (btn) {
                if (btn.dataset.phoneRemoveReady === '1') return;
                btn.dataset.phoneRemoveReady = '1';
                btn.addEventListener('click', function () {
                    var r = btn.closest('.driver-extra-phone-row');
                    if (r) phoneContainer.removeChild(r);
                });
            });
        }

        // ── Custom documents ──
        var addDocBtn = form.querySelector('[data-add-custom-doc-btn]');
        var docContainer = form.querySelector('[data-custom-docs-container]');
        if (addDocBtn && docContainer) {
            var docTypes = [];
            try { docTypes = JSON.parse(form.querySelector('[data-doc-types]') ? form.querySelector('[data-doc-types]').textContent : '[]'); } catch(e){}

            addDocBtn.addEventListener('click', function (e) {
                e.preventDefault();
                var idx = docContainer.children.length;
                var row = document.createElement('div');
                row.className = 'file-item custom-doc-row document-file-row is-empty';
                row.setAttribute('data-file-row','custom');
                row.innerHTML = '<div class="file-type-badge file-type-badge-empty">—</div><div class="file-info"><div class="field custom-doc-title-field"><input type="text" name="custom_doc_type[]" class="field-input custom-doc-type-input" placeholder="Введите название" autocomplete="off"><div class="custom-doc-suggestions"></div><div class="field-msg"></div></div><div class="file-meta is-hidden"></div></div><button type="button" class="btn btn-secondary file-action-btn js-custom-file-pick-btn"><span>Выбрать</span></button><input type="file" class="file-input-hidden js-custom-file-input" name="custom_doc_file[]"><button type="button" class="file-remove" title="Удалить документ">×</button>';
                row.querySelector('.file-remove').addEventListener('click', function () {
                    docContainer.removeChild(row);
                });
                // Doc type suggestions
                var typeInput = row.querySelector('.custom-doc-type-input');
                var suggest = row.querySelector('.custom-doc-suggestions');
                if (typeInput && suggest && docTypes.length) {
                    typeInput.addEventListener('input', function () {
                        var q = typeInput.value.trim().toLowerCase();
                        if (!q) { suggest.innerHTML = ''; suggest.classList.remove('is-open'); return; }
                        var filtered = docTypes.filter(function(d){ return d.name.toLowerCase().indexOf(q) !== -1; }).slice(0,5);
                        if (!filtered.length) { suggest.innerHTML = ''; suggest.classList.remove('is-open'); return; }
                        suggest.innerHTML = filtered.map(function(d){ return '<div class="custom-doc-suggestion">'+d.name.replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</div>'; }).join('');
                        suggest.classList.add('is-open');
                    });
                    suggest.addEventListener('mousedown', function (ev) {
                        if (ev.target.classList.contains('custom-doc-suggestion')) {
                            typeInput.value = ev.target.textContent;
                            suggest.innerHTML = '';
                            suggest.classList.remove('is-open');
                        }
                    });
                    typeInput.addEventListener('blur', function () { setTimeout(function(){ suggest.classList.remove('is-open'); }, 120); });
                }
                // File pick button
                var pickBtn = row.querySelector('.js-custom-file-pick-btn');
                var fileInput = row.querySelector('.js-custom-file-input');
                if (pickBtn && fileInput) {
                    pickBtn.addEventListener('click', function () { fileInput.click(); });
                    fileInput.addEventListener('change', function () {
                        var s = summarize(fileInput.files);
                        paintRow(row, s);
                    });
                }
                docContainer.appendChild(row);
            });
        }

        // ── Predef document rows ──
        var preClickState = {};
        form.querySelectorAll('[data-file-row="predef"]').forEach(function (row) {
            var code = row.getAttribute('data-file-code');
            if (!code) return;
            var fileInput = row.querySelector('input[type="file"]');
            var pickBtn = row.querySelector('.file-action-btn');
            var clearBtn = row.querySelector('.predef-file-clear');
            if (!fileInput) return;

            // Pick button
            if (pickBtn) {
                pickBtn.addEventListener('click', function () {
                    preClickState[code] = {
                        badgeCls: (row.querySelector('.file-type-badge')||{}).className || '',
                        badgeTxt: (row.querySelector('.file-type-badge')||{}).textContent || '',
                        metaTxt: (row.querySelector('.file-meta')||{}).textContent || '',
                        btnTxt: (pickBtn.querySelector('span')||{}).textContent || 'Выбрать',
                        empty: row.classList.contains('is-empty')
                    };
                    var badge = row.querySelector('.file-type-badge');
                    if (badge) { badge.className = 'file-type-badge is-loading'; badge.textContent = ''; }
                    fileInput.click();
                });
            }

            // File input change
            fileInput.addEventListener('change', function () {
                if (fileInput.files && fileInput.files.length > 0) {
                    var delInput = row.querySelector('[data-delete-predef-doc]');
                    if (delInput) delInput.value = '0';
                    paintRow(row, summarize(fileInput.files));
                    delete preClickState[code];
                } else if (preClickState[code]) {
                    var s = preClickState[code];
                    var badge = row.querySelector('.file-type-badge');
                    var meta = row.querySelector('.file-meta');
                    var btnLabel = pickBtn ? pickBtn.querySelector('span') : null;
                    if (badge) { badge.className = s.badgeCls; badge.textContent = s.badgeTxt; }
                    if (meta) meta.textContent = s.metaTxt;
                    if (btnLabel) btnLabel.textContent = s.btnTxt;
                    if (s.empty) row.classList.add('is-empty'); else row.classList.remove('is-empty');
                    delete preClickState[code];
                }
            });

            // Clear button
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    fileInput.value = '';
                    var delInput = row.querySelector('[data-delete-predef-doc]');
                    if (row.dataset.hasExisting === '1' && delInput) {
                        delInput.value = '1';
                        paintRow(row, neutralSummary());
                        row.classList.remove('is-error','has-error');
                        return;
                    }
                    var exist = row.dataset.hasExisting === '1';
                    if (exist) {
                        paintRow(row, existingSummary(row));
                    } else {
                        paintRow(row, neutralSummary());
                    }
                    row.classList.remove('is-error', 'has-error');
                });
            }
        });

        // ── Upload size check ──
        form.addEventListener('submit', function (e) {
            var files = form.querySelectorAll('input[type="file"]');
            var total = 0;
            for (var j = 0; j < files.length; j++) {
                var f = files[j];
                if (!f.files) continue;
                for (var i = 0; i < f.files.length; i++) {
                    total += f.files[i].size;
                    if (f.files[i].size > maxFileSize) {
                        e.preventDefault();
                        showDriverClientError(form, 'Файл «' + f.files[i].name + '» больше 20 МБ.');
                        return;
                    }
                }
            }
            var maxTotal = 80 * 1024 * 1024;
            if (total > maxTotal) {
                e.preventDefault();
                showDriverClientError(form, 'Общий размер файлов превышает 80 МБ.');
            }
        });

        function showDriverClientError(form, message) {
            var errEl = form.querySelector('.driver-form-client-error');
            if (!errEl) {
                errEl = document.createElement('div');
                errEl.className = 'form-alert alert-error driver-form-client-error';
                errEl.innerHTML = '<div class="alert-mark"><svg width="11" height="11" viewBox="0 0 18 18" fill="none"><path d="M9 2L16.5 15H1.5L9 2Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M9 7V11M9 13V13.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></div><div class="alert-body"><div class="alert-body-title">Ошибка загрузки</div><div class="alert-body-sub"></div></div>';
                var target = form.querySelector('.driver-fields');
                if (target) {
                    target.before(errEl);
                } else {
                    form.prepend(errEl);
                }
            }
            errEl.querySelector('.alert-body-sub').textContent = message;
            errEl.hidden = false;
            setTimeout(function () { errEl.hidden = true; }, 5000);
        }
    };
})();

// ============================================================
// Подменю: раскрытие / закрытие группы «Подрядчики»
// ============================================================
(function () {
    // Клик по родительскому элементу — переключение is-open
    document.querySelectorAll('.nav-item.is-parent').forEach(function (parent) {
        parent.addEventListener('click', function () {
            parent.classList.toggle('is-open');
            var sub = parent.nextElementSibling;
            if (sub && sub.classList.contains('nav-sub')) {
                sub.classList.toggle('is-open');
            }
        });
    });

    // При загрузке: если любой подпункт активен — раскрыть родителя
    document.querySelectorAll('.nav-sub-item.is-active').forEach(function (activeItem) {
        var sub = activeItem.closest('.nav-sub');
        if (sub) {
            sub.classList.add('is-open');
            var parentEl = sub.previousElementSibling;
            if (parentEl && parentEl.classList.contains('is-parent')) {
                parentEl.classList.add('is-open');
            }
        }
    });
})();

// ============================================================
// Страница создания водителя: ERP date-picker и нормализация
// ============================================================
(function () {
    var monthNames = [
        'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
        'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'
    ];
    var weekdays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    var activeDateInput = null;
    var viewYear = 0;
    var viewMonth = 0;

    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function collapseSpaces(value) {
        return String(value || '').trim().replace(/\s+/g, ' ');
    }

    function stripDateTail(value) {
        return collapseSpaces(value).replace(/\s*(г(?:\.|ода?|од)?)\s*$/i, '').trim();
    }

    function titleWord(word) {
        return word ? word.charAt(0).toLocaleUpperCase('ru-RU') + word.slice(1).toLocaleLowerCase('ru-RU') : word;
    }

    function fieldFor(input) {
        return input ? input.closest('.field') : null;
    }

    function setFieldError(input, message) {
        var field = fieldFor(input);
        if (!field) return;
        var msg = field.querySelector('.field-msg');
        field.classList.add('is-error');
        if (msg) msg.textContent = message;
    }

    function clearFieldError(input) {
        var field = fieldFor(input);
        if (!field) return;
        var msg = field.querySelector('.field-msg');
        field.classList.remove('is-error');
        if (msg) msg.textContent = '';
    }

    function datePartsToObject(day, month, year) {
        var date = new Date(year, month - 1, day);
        if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return null;
        return {
            day: day,
            month: month,
            year: year,
            date: date,
            display: pad2(day) + '.' + pad2(month) + '.' + year,
            iso: year + '-' + pad2(month) + '-' + pad2(day)
        };
    }

    function expandYear(year) {
        if (year < 100) return year <= 49 ? 2000 + year : 1900 + year;
        return year;
    }

    function parseDateValue(value) {
        var raw = stripDateTail(value);
        var match;
        if (!raw) return null;

        var leadingDate = raw.match(/^(\d{8}|\d{4}[./-]\d{1,2}[./-]\d{1,2}|\d{1,2}[\s./-]\d{1,2}[\s./-](?:\d{4}|\d{2}))/);
        if (leadingDate) {
            raw = leadingDate[1];
        }

        match = /^(\d{8})$/.exec(raw);
        if (match) {
            return datePartsToObject(
                Number(raw.slice(0, 2)),
                Number(raw.slice(2, 4)),
                Number(raw.slice(4, 8))
            );
        }

        match = /^(\d{4})[./-](\d{1,2})[./-](\d{1,2})$/.exec(raw);
        if (match) {
            return datePartsToObject(Number(match[3]), Number(match[2]), Number(match[1]));
        }

        match = /^(\d{1,2})[\s./-](\d{1,2})[\s./-](\d{2}|\d{4})$/.exec(raw);
        if (match) {
            return datePartsToObject(Number(match[1]), Number(match[2]), expandYear(Number(match[3])));
        }

        return null;
    }

    function normalizeDateInput(input, silent) {
        var value = collapseSpaces(input.value);
        if (!value) {
            clearFieldError(input);
            return true;
        }

        var parsed = parseDateValue(value);
        if (!parsed) {
            if (!silent) setFieldError(input, 'Укажите дату в формате дд.мм.гггг');
            return false;
        }

        input.value = parsed.display;
        input.dataset.isoValue = parsed.iso;
        clearFieldError(input);
        return true;
    }

    function formatPhone(value) {
        var digits = String(value || '').replace(/\D/g, '');
        if (digits.length === 11 && digits.charAt(0) === '8') digits = '7' + digits.slice(1);
        if (digits.length === 10) digits = '7' + digits;
        if (!(digits.length === 11 && digits.charAt(0) === '7')) return null;
        return '+7 ' + digits.slice(1, 4) + ' ' + digits.slice(4, 7) + '-' + digits.slice(7, 9) + '-' + digits.slice(9, 11);
    }

    function formatTenDigitDocument(value) {
        var digits = String(value || '').replace(/\D/g, '');
        return digits.length === 10 ? digits.slice(0, 4) + ' ' + digits.slice(4) : null;
    }

    function normalizeByName(input) {
        var name = input.name;
        var value = input.value;
        var formatted;

        if (name === 'full_name') {
            if (!value.trim()) {
                setFieldError(input, 'Укажите ФИО');
                return false;
            }
            var parts = collapseSpaces(value).split(' ');
            if (parts.length < 3) {
                setFieldError(input, 'ФИО: 3 слова');
                return false;
            }
            if (parts.length > 3) {
                setFieldError(input, 'ФИО: 3 слова');
                return false;
            }
            input.value = parts.map(titleWord).join(' ');
            clearFieldError(input);
            return true;
        }

        if (name === 'phone' || name === 'extra_phones[]') {
            if (!value.trim()) {
                if (name === 'phone') {
                    setFieldError(input, 'Укажите телефон');
                    return false;
                }
                clearFieldError(input);
                return true;
            }
            formatted = formatPhone(value);
            if (!formatted) {
                setFieldError(input, 'Неверный формат');
                return false;
            }
            input.value = formatted;
            clearFieldError(input);
            return true;
        }

        if (!value.trim()) {
            clearFieldError(input);
            return true;
        }

        if (name === 'passport_number' || name === 'license_number') {
            formatted = formatTenDigitDocument(value);
            if (!formatted) {
                setFieldError(input, 'Нужно 10 цифр');
                return false;
            }
            input.value = formatted;
            clearFieldError(input);
            return true;
        }

        if (name === 'passport_department_code') {
            var deptDigits = value.replace(/\D/g, '');
            if (deptDigits.length !== 6) {
                setFieldError(input, 'Формат 000-000');
                return false;
            }
            input.value = deptDigits.slice(0, 3) + '-' + deptDigits.slice(3);
            clearFieldError(input);
            return true;
        }

        if (name === 'snils') {
            var snilsDigits = value.replace(/\D/g, '');
            if (snilsDigits.length !== 11) {
                setFieldError(input, 'Нужно 11 цифр');
                return false;
            }
            input.value = snilsDigits.slice(0, 3) + '-' + snilsDigits.slice(3, 6) + '-' + snilsDigits.slice(6, 9) + ' ' + snilsDigits.slice(9);
            clearFieldError(input);
            return true;
        }

        if (name === 'email') {
            var email = collapseSpaces(value).toLowerCase();
            if (email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                setFieldError(input, 'Неверный email');
                return false;
            }
            input.value = email;
            clearFieldError(input);
            return true;
        }

        if (name === 'passport_issued_by' || name === 'comments') {
            input.value = collapseSpaces(value);
            clearFieldError(input);
            return true;
        }

        if (input.classList.contains('js-erp-date-picker')) {
            return normalizeDateInput(input, false);
        }

        return true;
    }

    function normalizeAfterPaste(input) {
        window.setTimeout(function () {
            normalizeByName(input);
        }, 0);
    }

    function getPopup() {
        var popup = document.querySelector('.erp-date-popover');
        if (popup) return popup;

        popup = document.createElement('div');
        popup.className = 'erp-date-popover';
        popup.innerHTML =
            '<div class="erp-date-head">' +
                '<button type="button" class="erp-date-nav" data-date-action="prev" aria-label="Предыдущий месяц">‹</button>' +
                '<div class="erp-date-title"></div>' +
                '<button type="button" class="erp-date-nav" data-date-action="next" aria-label="Следующий месяц">›</button>' +
            '</div>' +
            '<div class="erp-date-weekdays"></div>' +
            '<div class="erp-date-grid"></div>' +
            '<div class="erp-date-foot">' +
                '<button type="button" class="btn btn-ghost btn-sm" data-date-action="clear">Очистить</button>' +
                '<button type="button" class="btn btn-secondary btn-sm" data-date-action="today">Сегодня</button>' +
            '</div>';
        popup.querySelector('.erp-date-weekdays').innerHTML = weekdays.map(function (day) {
            return '<span>' + day + '</span>';
        }).join('');
        document.body.appendChild(popup);

        popup.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        popup.addEventListener('click', function (event) {
            var action = event.target.closest('[data-date-action]');
            var day = event.target.closest('[data-date-day]');
            if (!activeDateInput) return;

            if (action) {
                var kind = action.getAttribute('data-date-action');
                if (kind === 'prev') {
                    viewMonth -= 1;
                    if (viewMonth < 0) {
                        viewMonth = 11;
                        viewYear -= 1;
                    }
                    renderPopup();
                } else if (kind === 'next') {
                    viewMonth += 1;
                    if (viewMonth > 11) {
                        viewMonth = 0;
                        viewYear += 1;
                    }
                    renderPopup();
                } else if (kind === 'today') {
                    setDateInput(activeDateInput, new Date());
                    closePopup();
                } else if (kind === 'clear') {
                    activeDateInput.value = '';
                    delete activeDateInput.dataset.isoValue;
                    clearFieldError(activeDateInput);
                    closePopup();
                }
                return;
            }

            if (day) {
                setDateInput(activeDateInput, new Date(Number(day.dataset.year), Number(day.dataset.month), Number(day.dataset.day)));
                closePopup();
            }
        });

        return popup;
    }

    function setDateInput(input, date) {
        input.value = pad2(date.getDate()) + '.' + pad2(date.getMonth() + 1) + '.' + date.getFullYear();
        input.dataset.isoValue = date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
        clearFieldError(input);
    }

    function renderPopup() {
        var popup = getPopup();
        var grid = popup.querySelector('.erp-date-grid');
        var title = popup.querySelector('.erp-date-title');
        var first = new Date(viewYear, viewMonth, 1);
        var daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
        var leading = (first.getDay() + 6) % 7;
        var selected = activeDateInput ? parseDateValue(activeDateInput.value) : null;
        var today = new Date();
        var html = '';

        title.textContent = monthNames[viewMonth] + ' ' + viewYear;

        for (var i = 0; i < leading; i++) {
            html += '<span class="erp-date-empty"></span>';
        }

        for (var day = 1; day <= daysInMonth; day++) {
            var isSelected = selected && selected.year === viewYear && selected.month === viewMonth + 1 && selected.day === day;
            var isToday = today.getFullYear() === viewYear && today.getMonth() === viewMonth && today.getDate() === day;
            html += '<button type="button" class="erp-date-day' +
                (isSelected ? ' is-selected' : '') +
                (isToday ? ' is-today' : '') +
                '" data-date-day="' + day + '" data-year="' + viewYear + '" data-month="' + viewMonth + '" data-day="' + day + '">' + day + '</button>';
        }

        grid.innerHTML = html;
    }

    function openPopup(input) {
        activeDateInput = input;
        var parsed = parseDateValue(input.value);
        var base = parsed ? parsed.date : new Date();
        viewYear = base.getFullYear();
        viewMonth = base.getMonth();
        renderPopup();

        var popup = getPopup();
        var rect = input.getBoundingClientRect();
        var popupWidth = popup.offsetWidth || 228;
        var popupHeight = popup.offsetHeight || 290;
        var left = rect.left;
        var top = rect.bottom + 4;

        if (left + popupWidth > window.innerWidth - 8) {
            left = Math.max(8, window.innerWidth - popupWidth - 8);
        }
        if (top + popupHeight > window.innerHeight - 8) {
            top = Math.max(8, rect.top - popupHeight - 4);
        }

        popup.style.left = Math.round(left) + 'px';
        popup.style.top = Math.round(top) + 'px';
        popup.classList.add('is-open');
    }

    function closePopup() {
        var popup = document.querySelector('.erp-date-popover');
        if (popup) popup.classList.remove('is-open');
        activeDateInput = null;
    }

    function installDatePicker(input) {
        if (input.dataset.erpDateReady === '1') return;
        input.dataset.erpDateReady = '1';

        var wrap = document.createElement('div');
        wrap.className = 'erp-date-field';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'erp-date-trigger';
        button.setAttribute('aria-label', 'Открыть календарь');
        button.innerHTML = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><rect x="2" y="3" width="10" height="9" stroke="currentColor" stroke-width="1.2"/><path d="M4.5 1.8V4.2M9.5 1.8V4.2M2 5.2H12" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>';
        wrap.appendChild(button);

        if (input.value) normalizeDateInput(input, true);

        input.addEventListener('focus', function () {
            openPopup(input);
        });
        button.addEventListener('click', function () {
            openPopup(input);
            input.focus();
        });
    }

    function bindDriverInputValidation(form) {
        if (!form || form.dataset.driverInputValidationReady === '1') return;
        form.dataset.driverInputValidationReady = '1';

        Array.prototype.forEach.call(form.querySelectorAll('.js-erp-date-picker'), installDatePicker);

        form.addEventListener('focusin', function (event) {
            if (event.target && event.target.classList.contains('js-erp-date-picker')) {
                openPopup(event.target);
            }
        });

        form.addEventListener('click', function (event) {
            var trigger = event.target.closest('.erp-date-trigger');
            if (trigger) {
                var wrappedInput = trigger.closest('.erp-date-field') ? trigger.closest('.erp-date-field').querySelector('.js-erp-date-picker') : null;
                if (wrappedInput) {
                    openPopup(wrappedInput);
                    wrappedInput.focus();
                }
                return;
            }
            if (event.target && event.target.classList.contains('js-erp-date-picker')) {
                openPopup(event.target);
            }
        });

        form.addEventListener('focusout', function (event) {
            if (event.target && event.target.matches('input:not([type="file"]), textarea')) {
                normalizeByName(event.target);
            }
        });

        form.addEventListener('paste', function (event) {
            if (event.target && event.target.classList.contains('js-erp-date-picker')) {
                event.preventDefault();
                var pastedText = '';
                if (event.clipboardData && typeof event.clipboardData.getData === 'function') {
                    pastedText = event.clipboardData.getData('text');
                } else if (window.clipboardData && typeof window.clipboardData.getData === 'function') {
                    pastedText = window.clipboardData.getData('Text');
                }

                var parsedDate = parseDateValue(pastedText);
                event.target.value = parsedDate ? parsedDate.display : collapseSpaces(pastedText);
                normalizeDateInput(event.target, false);
                return;
            }

            if (event.target && event.target.matches('input:not([type="file"]), textarea')) {
                normalizeAfterPaste(event.target);
            }
        });

        form.addEventListener('submit', function (event) {
            var uploadCheck = window.erpCheckUploadSize ? window.erpCheckUploadSize(form) : { ok: true };
            if (!uploadCheck.ok) {
                event.preventDefault();
                return;
            }
            var ok = true;
            var textInputs = form.querySelectorAll('input[name]:not([type="file"]), textarea[name]');
            Array.prototype.forEach.call(textInputs, function (input) {
                if (!normalizeByName(input)) ok = false;
            });
            if (typeof window.erpDriverCreateValidateFiles === 'function') {
                if (!window.erpDriverCreateValidateFiles(form)) ok = false;
            }
            if (!ok) {
                event.preventDefault();
                var firstError = form.querySelector('.field.is-error input, .field.is-error textarea');
                if (firstError) firstError.focus();
                return;
            }
            Array.prototype.forEach.call(form.querySelectorAll('.js-erp-date-picker'), function (input) {
                if (input.value.trim()) {
                    var parsed = parseDateValue(input.value);
                    if (parsed) input.value = parsed.iso;
                }
            });
            if (typeof window.erpDriverCreateBeforeSubmit === 'function') {
                window.erpDriverCreateBeforeSubmit(form);
            }
        });
    }

    window.initDriverInputValidation = bindDriverInputValidation;

    var standaloneCreateForm = document.querySelector('form[action="/company/drivers/create"][method="post"]');
    if (standaloneCreateForm) {
        bindDriverInputValidation(standaloneCreateForm);
    }

    var modalCreateForm = document.querySelector('#driver-create-modal #driver-create-form');
    if (modalCreateForm) {
        bindDriverInputValidation(modalCreateForm);
    }

    document.addEventListener('mousedown', function (event) {
        var popup = document.querySelector('.erp-date-popover');
        if (!popup || !popup.classList.contains('is-open')) return;
        if (popup.contains(event.target)) return;
        if (event.target.closest('.erp-date-field')) return;
        closePopup();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closePopup();
    });
})();

// ============================================================
// ERP Grid: общий frontend-поиск, сортировка и footer
// Атрибуты: data-erp-grid (на .table-card),
//           data-erp-grid-sort (на select внутри .toolbar-right),
//           data-erp-sort-date (на tr, опционально)
// Работает автоматически для всех .table-card[data-erp-grid]
// ============================================================
(function () {
    var DRIVER_GRID_STATE_KEY = 'companyDriversGridState';
    var VEHICLE_SET_GRID_STATE_KEY = 'companyVehicleSetsGridState';

    document.querySelectorAll('.table-card[data-erp-grid]').forEach(function (card) {
        var searchInput = card.querySelector('.toolbar-search, [data-erp-grid-search]');
        if (!searchInput) return;

        var countEl = card.querySelector('.found-label b');
        var table = card.querySelector('table.table');
        if (!table) return;
        var tbody = table.querySelector('tbody');
        if (!tbody) return;

        // Footer elements
        var footerRange = card.querySelector('.footer-range');
        var footerTotal = card.querySelector('.footer-total');
        var totalRows = tbody.querySelectorAll('tr').length;
        if (footerTotal) footerTotal.textContent = totalRows;

        // Sort state (default: date — newest first)
        var sortSelect = card.querySelector('select[data-erp-grid-sort]');
        var sortMode = sortSelect ? sortSelect.value : 'date';
        var stateKey = null;
        if (window.location.pathname === '/company/drivers') {
            stateKey = DRIVER_GRID_STATE_KEY;
        } else if (window.location.pathname === '/company/vehicle-sets') {
            stateKey = VEHICLE_SET_GRID_STATE_KEY;
        }

        function saveGridState() {
            if (!stateKey || !window.sessionStorage) return;
            sessionStorage.setItem(stateKey, JSON.stringify({
                search: searchInput.value || '',
                sort: sortSelect ? sortSelect.value : sortMode
            }));
        }

        function restoreGridState() {
            if (!stateKey || !window.sessionStorage) return;
            var raw = sessionStorage.getItem(stateKey);
            if (!raw) return;

            try {
                var state = JSON.parse(raw);
                if (state && typeof state.search === 'string') {
                    searchInput.value = state.search;
                }
                if (sortSelect && state && typeof state.sort === 'string') {
                    sortSelect.value = state.sort;
                    sortMode = sortSelect.value || 'date';
                }
            } catch (e) {
                sessionStorage.removeItem(stateKey);
            }
        }

        // Создать no-results элемент, если отсутствует
        var noResults = card.querySelector('.table-no-results');
        if (!noResults) {
            noResults = document.createElement('div');
            noResults.className = 'table-no-results is-hidden';
            noResults.innerHTML =
                '<div class="empty-state is-compact">' +
                    '<p class="empty-title">Ничего не найдено</p>' +
                    '<p class="empty-desc">Попробуйте изменить поисковый запрос</p>' +
                '</div>';
            var scroll = card.querySelector('.table-scroll');
            if (scroll) scroll.appendChild(noResults);
        }

        // --- Sort helpers ---

        function getSortValue(tr, mode) {
            if (mode === 'alpha') {
                var main = tr.querySelector('.cell-main');
                if (main) return main.textContent.trim().toLowerCase();
                var firstTd = tr.querySelector('td');
                return (firstTd ? firstTd.textContent : '').trim().toLowerCase();
            }
            if (mode === 'date') {
                var dateVal = tr.getAttribute('data-erp-sort-date');
                if (dateVal !== null) {
                    var num = parseInt(dateVal, 10);
                    return isNaN(num) ? 0 : num;
                }
                // Fallback: extract ID from the first action link href
                var link = tr.querySelector('a[href]');
                if (link) {
                    var match = link.getAttribute('href').match(/\/(\d+)(?:\/|$|\?)/);
                    if (match) {
                        var idNum = parseInt(match[1], 10);
                        return isNaN(idNum) ? 0 : idNum;
                    }
                }
                return 0;
            }
            return '';
        }

        function sortRows() {
            var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
            rows.sort(function (a, b) {
                var pinA = a.hasAttribute('data-erp-pin') ? 1 : 0;
                var pinB = b.hasAttribute('data-erp-pin') ? 1 : 0;
                if (pinA !== pinB) return pinB - pinA;
                var va = getSortValue(a, sortMode);
                var vb = getSortValue(b, sortMode);
                if (sortMode === 'date') {
                    return vb - va; // newer first (descending)
                }
                // alpha: ascending
                if (va < vb) return -1;
                if (va > vb) return 1;
                return 0;
            });
            rows.forEach(function (row) { tbody.appendChild(row); });
        }

        // --- Footer update ---

        function updateFooter() {
            var visibleRows = tbody.querySelectorAll('tr:not(.is-hidden)').length;
            if (footerRange) {
                footerRange.textContent = visibleRows === 0 ? '0' : '1–' + visibleRows;
            }
            if (footerTotal) footerTotal.textContent = totalRows;
        }

        // --- Filter + combined update ---

        function filterRows() {
            var query = searchInput.value.trim().toLowerCase();
            var rows = tbody.querySelectorAll('tr');
            var visible = 0;

            rows.forEach(function (tr) {
                var text = tr.textContent.toLowerCase();
                if (query === '' || text.indexOf(query) !== -1) {
                    tr.classList.remove('is-hidden');
                    visible++;
                } else {
                    tr.classList.add('is-hidden');
                }
            });

            if (countEl) {
                countEl.textContent = visible;
            }

            if (query !== '' && visible === 0) {
                table.style.display = 'none';
                noResults.classList.remove('is-hidden');
            } else {
                table.style.display = '';
                noResults.classList.add('is-hidden');
            }

            updateFooter();
        }

        // --- Sort select change ---

        if (sortSelect) {
            sortSelect.addEventListener('change', function () {
                sortMode = sortSelect.value;
                sortRows();
                filterRows();
                saveGridState();
            });
        }

        // --- Search input events ---

        searchInput.addEventListener('input', function () {
            filterRows();
            saveGridState();
        });

        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                searchInput.value = '';
                filterRows();
                saveGridState();
            }
        });

        card.addEventListener('erp-grid-refresh', function () {
            totalRows = tbody.querySelectorAll('tr').length;
            sortRows();
            filterRows();
        });

        // --- Initial sort + footer ---
        restoreGridState();
        sortRows();
        filterRows();
        updateFooter();
    });
})();

// ============================================================
// Общий валидатор загрузки документов (20 МБ/файл, 80 МБ/форма)
// ============================================================
(function () {
    var MAX_FILE_SIZE = 20 * 1024 * 1024;   // 20 MB per file
    var MAX_TOTAL_SIZE = 80 * 1024 * 1024;  // 80 MB per form

    var overlay = null;

    function getOverlay() {
        if (overlay) return overlay;
        overlay = document.createElement('div');
        overlay.className = 'upload-error-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Ошибка загрузки документов');
        overlay.innerHTML =
            '<div class="upload-error-panel">' +
                '<div class="upload-error-head">' +
                    '<span class="upload-error-mark"></span>' +
                    '<div class="upload-error-head-text">' +
                        '<div class="upload-error-title" data-ue-title></div>' +
                        '<div class="upload-error-subtitle" data-ue-subtitle></div>' +
                    '</div>' +
                '</div>' +
                '<div class="upload-error-body" data-ue-body></div>' +
                '<div class="upload-error-actions">' +
                    '<button type="button" class="btn btn-secondary" data-upload-error-close>Понятно</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        overlay.querySelector('[data-upload-error-close]').addEventListener('click', closeOverlay);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeOverlay();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeOverlay();
        });

        return overlay;
    }

    function closeOverlay() {
        var el = getOverlay();
        el.classList.remove('is-open');
    }

    function showError(title, subtitle, bodyHtml) {
        var el = getOverlay();
        el.querySelector('[data-ue-title]').textContent = title;
        el.querySelector('[data-ue-subtitle]').textContent = subtitle;
        el.querySelector('[data-ue-body]').innerHTML = bodyHtml;
        el.classList.add('is-open');
        el.querySelector('[data-upload-error-close]').focus();
    }

    function formatMB(bytes) {
        return (bytes / (1024 * 1024)).toFixed(1).replace('.0', '') + ' МБ';
    }

    function escapeHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /**
     * Проверить размеры файлов формы перед отправкой.
     * @param {HTMLFormElement} form
     * @returns {{ok: boolean, errorType: string|null}}
     */
    window.erpCheckUploadSize = function (form) {
        if (!form) return { ok: true, errorType: null };

        var fileInputs = form.querySelectorAll('input[type="file"]');
        var totalSize = 0;
        var tooBigFiles = [];

        Array.prototype.forEach.call(fileInputs, function (input) {
            if (!input.files || !input.files.length) return;
            for (var i = 0; i < input.files.length; i++) {
                var file = input.files[i];
                totalSize += file.size;
                if (file.size > MAX_FILE_SIZE) {
                    tooBigFiles.push(escapeHtml(file.name) + ' (' + formatMB(file.size) + ')');
                }
            }
        });

        // Check single file limit
        if (tooBigFiles.length > 0) {
            var singleBody = '<p>Файл слишком большой.</p>' +
                '<p>Максимальный размер одного файла — 20 МБ.</p>';
            if (tooBigFiles.length <= 3) {
                singleBody += '<p>Проблемные файлы: ' + tooBigFiles.join(', ') + '</p>';
            }
            singleBody += '<p>Уменьшите файл или загрузите другой документ.</p>';
            showError('Ошибка загрузки документов', 'Файлы не были отправлены', singleBody);
            return { ok: false, errorType: 'single' };
        }

        // Check total size limit
        if (totalSize > MAX_TOTAL_SIZE) {
            var totalBody = '<p>Общий размер выбранных файлов слишком большой.</p>' +
                '<p>Максимум за одну отправку — 80 МБ.</p>' +
                '<p>Текущий размер: ' + formatMB(totalSize) + '.</p>' +
                '<p>Уменьшите количество файлов или загрузите документы позже из карточки.</p>';
            showError('Ошибка загрузки документов', 'Файлы не были отправлены', totalBody);
            return { ok: false, errorType: 'total' };
        }

        return { ok: true, errorType: null };
    };
})();

// ============================================================
// FINAL3 Modal system: openModal / closeModal / closeOnOverlay
// ============================================================
window.erpExecuteInlineScripts = function (root) {
    if (!root) return;
    root.querySelectorAll('script').forEach(function (oldScript) {
        var newScript = document.createElement('script');
        Array.prototype.slice.call(oldScript.attributes).forEach(function (attr) {
            newScript.setAttribute(attr.name, attr.value);
        });
        newScript.textContent = oldScript.textContent;
        oldScript.parentNode.replaceChild(newScript, oldScript);
    });
};

window.openModal = function (id) {
    var modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('is-open');
    if (id === 'driver-create-modal' && window.bindDriverCreateModalForm) {
        window.bindDriverCreateModalForm();
    }
    if (id === 'vehicle-set-create-modal' && window.bindVehicleSetCreateModalForm) {
        window.bindVehicleSetCreateModalForm();
    }
    if (id === 'company-user-create-modal' && window.bindCompanyUserCreateModalForm) {
        window.bindCompanyUserCreateModalForm();
    }
};

function resetModalContent(modal) {
    if (!modal || modal.dataset.resetOnClose !== '1') return;
    var body = modal.querySelector('.modal-body');
    if (!body) return;
    if (!modal.dataset.initialBodyHtml) return;

    body.innerHTML = modal.dataset.initialBodyHtml;

    if (modal.id === 'vehicle-set-create-modal') {
        if (typeof window.erpExecuteInlineScripts === 'function') {
            window.erpExecuteInlineScripts(body);
        }
        if (window.bindVehicleSetCreateModalForm) {
            window.bindVehicleSetCreateModalForm();
        }
        return;
    }

    var form = body.querySelector('form');
    if (form) {
        form.dataset.driverFormReady = '0';
        form.dataset.modalSubmitReady = '0';
        form.dataset.modalCreateSubmitReady = '0';
        if (window.initDriverForm) {
            window.initDriverForm(form);
        }
    }
}

window.closeModal = function (id) {
    var modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('is-open');
    resetModalContent(modal);
};

window.closeOnOverlay = function (event, overlay) {
    if (overlay && overlay.dataset.closeOnOverlay === '0') {
        return;
    }
    if (event.target === overlay) {
        overlay.classList.remove('is-open');
        resetModalContent(overlay);
    }
};

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var openModals = document.querySelectorAll('.modal-overlay.is-open');
        openModals.forEach(function (m) {
            if (m.dataset.closeOnEscape === '0') return;
            m.classList.remove('is-open');
            resetModalContent(m);
        });
    }
});

// Declarative modal open/close via data attributes
document.addEventListener('click', function (e) {
    var openBtn = e.target.closest('[data-open-modal]');
    if (openBtn) {
        window.openModal(openBtn.getAttribute('data-open-modal'));
        return;
    }
    var closeBtn = e.target.closest('[data-close-modal]');
    if (closeBtn) {
        window.closeModal(closeBtn.getAttribute('data-close-modal'));
    }
});

// ============================================================
// Driver modal — ModalShell adapter
// ============================================================
(function () {
    var clientCtrl = ModalShell.create({
        modalId: 'client-view-modal',
        deleteConfirmId: 'client-archive-confirm-modal',
        overlayClass: 'driver-view-overlay',
        modalInnerClass: 'modal-lg driver-view-modal-inner',
        title: '\u041a\u043b\u0438\u0435\u043d\u0442',
        loadingClass: 'driver-modal-loading',
        nameSelector: '.driver-view-name',
        editFormSelector: '#client-edit-form',
        gridSelector: '.table-card[data-erp-grid]',
        gridStateKey: 'companyClientsGridState',
        viewBtnSelectors: {
            edit: '[data-client-edit-btn]',
            close: '[data-client-view-close-btn]',
            delete: '[data-client-archive-btn]'
        },
        editBtnSelectors: {
            cancel: '[data-client-cancel-edit-btn]'
        },
        endpoints: {
            view: function (id) { var base = window.getErpBasePath(); return base + '/company/clients/' + id + '/modal-view'; },
            edit: function (id) { var base = window.getErpBasePath(); return base + '/company/clients/' + id + '/modal-edit'; },
            delete: function (id) { var base = window.getErpBasePath(); return base + '/company/clients/' + id + '/modal-archive'; }
        },
        errorMessages: {
            loadFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u043a\u0430\u0440\u0442\u043e\u0447\u043a\u0443 \u043a\u043b\u0438\u0435\u043d\u0442\u0430.',
            saveFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u0438\u0437\u043c\u0435\u043d\u0435\u043d\u0438\u044f.',
            deleteFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0443\u0434\u0430\u043b\u0438\u0442\u044c \u043a\u043b\u0438\u0435\u043d\u0442\u0430.'
        },
        deleteConfirm: {
            title: '\u0423\u0434\u0430\u043b\u0438\u0442\u044c \u043a\u043b\u0438\u0435\u043d\u0442\u0430?',
            warning: '\u0417\u0430\u043f\u0438\u0441\u044c \u0431\u0443\u0434\u0435\u0442 \u0443\u0434\u0430\u043b\u0435\u043d\u0430 \u0438\u0437 \u0441\u043f\u0438\u0441\u043a\u0430.',
            instruction: '\u0414\u043b\u044f \u043f\u043e\u0434\u0442\u0432\u0435\u0440\u0436\u0434\u0435\u043d\u0438\u044f \u0432\u0432\u0435\u0434\u0438\u0442\u0435 <b>\u0423\u0414\u0410\u041b\u0418\u0422\u042c</b>.',
            placeholder: '\u0412\u0432\u0435\u0434\u0438\u0442\u0435 \u0423\u0414\u0410\u041b\u0418\u0422\u042c',
            confirmWord: '\u0423\u0414\u0410\u041b\u0418\u0422\u042c',
            cancelBtn: '\u041e\u0442\u043c\u0435\u043d\u0430',
            confirmBtn: '\u0423\u0434\u0430\u043b\u0438\u0442\u044c'
        },
        onContentLoaded: function (shell, mode) {
            if (mode === 'edit') {
                var form = shell.querySelector('#client-edit-form');
                if (form && window.initContactFields) {
                    window.initContactFields(form, { fieldPrefix: 'contacts' });
                }
                if (form && window.initLegalEntityDocuments) {
                    window.initLegalEntityDocuments(form);
                }
                if (form) {
                    var innBtn = form.querySelector('[data-inn-autofill-btn]');
                    if (innBtn && window.runLegalEntityInnLookup && !innBtn.dataset.leInnReady) {
                        innBtn.dataset.leInnReady = '1';
                        innBtn.addEventListener('click', function (event) {
                            event.preventDefault();
                            window.runLegalEntityInnLookup(form, {
                                button: innBtn,
                                typeFieldName: 'entity_type',
                                idleButtonText: '\u0417\u0430\u043f\u043e\u043b\u043d\u0438\u0442\u044c \u043f\u043e \u0418\u041d\u041d',
                                loadingButtonText: '\u041f\u043e\u0438\u0441\u043a...'
                            });
                        });
                    }
                }
            }
        }
    });
    ModalShell.register('client', clientCtrl);

    var clientTable = document.querySelector('.table-card[data-erp-grid] tbody');
    if (clientTable && document.querySelector('tr[data-client-id]')) {
        clientTable.addEventListener('click', function (event) {
            var row = event.target.closest('tr[data-client-id]');
            if (!row) {
                return;
            }

            var viewLink = event.target.closest('a[href^="/company/clients/"]');
            var viewHref = viewLink ? (viewLink.getAttribute('href') || '') : '';
            if (viewLink && !viewHref.endsWith('/edit') && /^\/company\/clients\/\d+$/.test(viewHref)) {
                event.preventDefault();
                var viewId = row.getAttribute('data-client-id');
                if (viewId) clientCtrl.loadView(viewId);
                return;
            }

            var editLink = event.target.closest('a[href*="/company/clients/"][href$="/edit"]');
            if (editLink) {
                event.preventDefault();
                var editId = row.getAttribute('data-client-id');
                if (editId) clientCtrl.loadEdit(editId);
            }
        });

        clientTable.addEventListener('dblclick', function (event) {
            if (event.target.closest('a, button, input, select, textarea, label')) return;
            var row = event.target.closest('tr[data-client-id]');
            if (!row) return;
            var id = row.getAttribute('data-client-id');
            if (id) clientCtrl.loadView(id);
        });
    }
})();

(function () {
    var contractorCtrl = ModalShell.create({
        modalId: 'contractor-view-modal',
        deleteConfirmId: 'contractor-archive-confirm-modal',
        overlayClass: 'driver-view-overlay',
        modalInnerClass: 'modal-lg driver-view-modal-inner',
        title: '\u041f\u0435\u0440\u0435\u0432\u043e\u0437\u0447\u0438\u043a',
        loadingClass: 'driver-modal-loading',
        nameSelector: '.driver-view-name',
        editFormSelector: '#contractor-edit-form',
        gridSelector: '.table-card[data-erp-grid]',
        gridStateKey: 'companyContractorsGridState',
        viewBtnSelectors: {
            edit: '[data-contractor-edit-btn]',
            close: '[data-contractor-view-close-btn]',
            delete: '[data-contractor-archive-btn]'
        },
        editBtnSelectors: {
            cancel: '[data-contractor-cancel-edit-btn]'
        },
        endpoints: {
            view: function (id) { var base = window.getErpBasePath(); return base + '/company/contractors/' + id + '/modal-view'; },
            edit: function (id) { var base = window.getErpBasePath(); return base + '/company/contractors/' + id + '/modal-edit'; },
            delete: function (id) { var base = window.getErpBasePath(); return base + '/company/contractors/' + id + '/modal-archive'; }
        },
        errorMessages: {
            loadFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u043a\u0430\u0440\u0442\u043e\u0447\u043a\u0443 \u043f\u0435\u0440\u0435\u0432\u043e\u0437\u0447\u0438\u043a\u0430.',
            saveFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u0438\u0437\u043c\u0435\u043d\u0435\u043d\u0438\u044f.',
            deleteFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0443\u0434\u0430\u043b\u0438\u0442\u044c \u043f\u0435\u0440\u0435\u0432\u043e\u0437\u0447\u0438\u043a\u0430.'
        },
        deleteConfirm: {
            title: '\u0423\u0434\u0430\u043b\u0438\u0442\u044c \u043f\u0435\u0440\u0435\u0432\u043e\u0437\u0447\u0438\u043a\u0430?',
            warning: '\u0417\u0430\u043f\u0438\u0441\u044c \u0431\u0443\u0434\u0435\u0442 \u0443\u0434\u0430\u043b\u0435\u043d\u0430 \u0438\u0437 \u0441\u043f\u0438\u0441\u043a\u0430.',
            instruction: '\u0414\u043b\u044f \u043f\u043e\u0434\u0442\u0432\u0435\u0440\u0436\u0434\u0435\u043d\u0438\u044f \u0432\u0432\u0435\u0434\u0438\u0442\u0435 <b>\u0423\u0414\u0410\u041b\u0418\u0422\u042c</b>.',
            placeholder: '\u0412\u0432\u0435\u0434\u0438\u0442\u0435 \u0423\u0414\u0410\u041b\u0418\u0422\u042c',
            confirmWord: '\u0423\u0414\u0410\u041b\u0418\u0422\u042c',
            cancelBtn: '\u041e\u0442\u043c\u0435\u043d\u0430',
            confirmBtn: '\u0423\u0434\u0430\u043b\u0438\u0442\u044c'
        },
        onContentLoaded: function (shell, mode) {
            if (mode === 'edit') {
                var form = shell.querySelector('#contractor-edit-form');
                if (form && window.initContactFields) {
                    window.initContactFields(form, { fieldPrefix: 'contacts' });
                }
                if (form && window.initLegalEntityDocuments) {
                    window.initLegalEntityDocuments(form);
                }
                if (form) {
                    var innBtn = form.querySelector('[data-inn-autofill-btn]');
                    if (innBtn && window.runLegalEntityInnLookup && !innBtn.dataset.leInnReady) {
                        innBtn.dataset.leInnReady = '1';
                        innBtn.addEventListener('click', function (event) {
                            event.preventDefault();
                            window.runLegalEntityInnLookup(form, {
                                button: innBtn,
                                typeFieldName: 'contractor_type',
                                idleButtonText: '\u0417\u0430\u043f\u043e\u043b\u043d\u0438\u0442\u044c \u043f\u043e \u0418\u041d\u041d',
                                loadingButtonText: '\u041f\u043e\u0438\u0441\u043a...'
                            });
                        });
                    }
                }
            }
        }
    });
    ModalShell.register('contractor', contractorCtrl);

    var contractorTable = document.querySelector('.table-card[data-erp-grid] tbody');
    if (contractorTable && document.querySelector('tr[data-contractor-id]')) {
        contractorTable.addEventListener('click', function (event) {
            var row = event.target.closest('tr[data-contractor-id]');
            if (!row) {
                return;
            }

            var viewLink = event.target.closest('a[href^="/company/contractors/"]');
            var viewHref = viewLink ? (viewLink.getAttribute('href') || '') : '';
            if (viewLink && !viewHref.endsWith('/edit') && /^\/company\/contractors\/\d+$/.test(viewHref)) {
                event.preventDefault();
                var viewId = row.getAttribute('data-contractor-id');
                if (viewId) contractorCtrl.loadView(viewId);
                return;
            }

            var editLink = event.target.closest('a[href*="/company/contractors/"][href$="/edit"]');
            if (editLink) {
                event.preventDefault();
                var editId = row.getAttribute('data-contractor-id');
                if (editId) contractorCtrl.loadEdit(editId);
            }
        });

        contractorTable.addEventListener('dblclick', function (event) {
            if (event.target.closest('a, button, input, select, textarea, label')) return;
            var row = event.target.closest('tr[data-contractor-id]');
            if (!row) return;
            var id = row.getAttribute('data-contractor-id');
            if (id) contractorCtrl.loadView(id);
        });
    }
})();

(function () {
    var driverCtrl = ModalShell.create({
        modalId: 'driver-view-modal',
        deleteConfirmId: 'driver-delete-confirm-modal',
        overlayClass: 'driver-view-overlay',
        modalInnerClass: 'modal-lg driver-view-modal-inner',
        title: '\u0412\u043e\u0434\u0438\u0442\u0435\u043b\u044c',
        loadingClass: 'driver-modal-loading',
        nameSelector: '.driver-view-name',
        editFormSelector: '#driver-edit-form',
        gridSelector: '.table-card[data-erp-grid]',
        gridStateKey: 'companyDriversGridState',
        viewBtnSelectors: {
            edit:   '[data-driver-edit-btn]',
            close:  '[data-driver-view-cancel]',
            delete: '[data-driver-delete-btn]'
        },
        editBtnSelectors: {
            cancel: '[data-driver-cancel-edit-btn]'
        },
        endpoints: {
            view:   function (id) { var base = window.getErpBasePath(); return base + '/company/drivers/' + id + '/modal-view'; },
            edit:   function (id) { var base = window.getErpBasePath(); return base + '/company/drivers/' + id + '/modal-edit'; },
            delete: function (id) { var base = window.getErpBasePath(); return base + '/company/drivers/' + id + '/modal-delete'; }
        },
        errorMessages: {
            loadFailed:    '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u0434\u0430\u043d\u043d\u044b\u0435.',
            saveFailed:    '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c.',
            deleteFailed:  '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0443\u0434\u0430\u043b\u0438\u0442\u044c \u0432\u043e\u0434\u0438\u0442\u0435\u043b\u044f.'
        },
        deleteConfirm: {
            title:       '\u0423\u0434\u0430\u043b\u0438\u0442\u044c \u0432\u043e\u0434\u0438\u0442\u0435\u043b\u044f?',
            warning:     '\u0417\u0430\u043f\u0438\u0441\u044c \u0431\u0443\u0434\u0435\u0442 \u0443\u0434\u0430\u043b\u0435\u043d\u0430 \u0438\u0437 \u0441\u043f\u0438\u0441\u043a\u0430.',
            instruction: '\u0414\u043b\u044f \u0437\u0430\u0449\u0438\u0442\u044b \u043e\u0442 \u0441\u043b\u0443\u0447\u0430\u0439\u043d\u043e\u0433\u043e \u0443\u0434\u0430\u043b\u0435\u043d\u0438\u044f \u0432\u0432\u0435\u0434\u0438\u0442\u0435 <b>\u0423\u0414\u0410\u041b\u0418\u0422\u042c</b>.',
            placeholder: '\u0412\u0432\u0435\u0434\u0438\u0442\u0435 \u0423\u0414\u0410\u041b\u0418\u0422\u042c',
            confirmWord: '\u0423\u0414\u0410\u041b\u0418\u0422\u042c',
            cancelBtn:   '\u041e\u0442\u043c\u0435\u043d\u0430',
            confirmBtn:  '\u0423\u0434\u0430\u043b\u0438\u0442\u044c'
        },
        onContentLoaded: function (shell, mode) {
            if (mode === 'edit') {
                var form = shell.querySelector('#driver-edit-form');
                if (form && window.initDriverForm) {
                    form.dataset.driverFormReady = '0';
                    form.dataset.modalSubmitReady = '0';
                    window.initDriverForm(form);
                }
            }
        }
    });
    ModalShell.register('driver', driverCtrl);

    var driverTable = document.querySelector('.table-card[data-erp-grid] tbody');
    if (driverTable) {
        driverTable.addEventListener('dblclick', function (e) {
            if (e.target.closest('a, button, input, select, textarea, label')) return;
            var row = e.target.closest('tr');
            if (!row) return;
            var id = row.getAttribute('data-driver-id');
            if (id) driverCtrl.loadView(id);
        });
    }
})();

// ============================================================
// Init driver forms on page load (create modal + standalone page)
// ============================================================
(function () {
    window.bindDriverCreateModalForm = function () {
        var createModal = document.getElementById('driver-create-modal');
        if (!createModal) return;
        var form = createModal.querySelector('#driver-create-form');
        if (!form || form.dataset.modalCreateSubmitReady === '1') return;

        form.dataset.modalCreateSubmitReady = '1';
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var formData = new FormData(form);
            fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function (r) { if (!r.ok) throw Error(r.status); return r.text(); })
            .then(function (html) {
                var tmp = document.createElement('div');
                tmp.innerHTML = html;

                var returnedForm = tmp.querySelector('#driver-create-form');
                if (returnedForm) {
                    var modalBody = createModal.querySelector('.modal-body');
                    if (!modalBody) return;

                    var returnedPageContent = tmp.querySelector('.page-content');
                    modalBody.innerHTML = returnedPageContent ? returnedPageContent.innerHTML : returnedForm.outerHTML;

                    var newForm = createModal.querySelector('#driver-create-form');
                    if (newForm) {
                        newForm.dataset.driverFormReady = '0';
                        newForm.dataset.modalCreateSubmitReady = '0';
                        if (window.initDriverForm) {
                            window.initDriverForm(newForm);
                        }
                    }
                    window.bindDriverCreateModalForm();
                    return;
                }

                window.location.reload();
            })
            .catch(function () {
                window.location.reload();
            });
        });
    };

    var createModal = document.getElementById('driver-create-modal');
    if (createModal && createModal.dataset.resetOnClose === '1') {
        var createModalBody = createModal.querySelector('.modal-body');
        if (createModalBody && !createModal.dataset.initialBodyHtml) {
            createModal.dataset.initialBodyHtml = createModalBody.innerHTML;
        }
    }

    // Create modal form
    var createModalForm = document.querySelector('#driver-create-modal #driver-create-form');
    if (createModalForm && window.initDriverForm) {
        window.initDriverForm(createModalForm);
    }
    window.bindDriverCreateModalForm();

    // Standalone create page form
    var standaloneForm = document.querySelector('form[action="/company/drivers/create"][method="post"]');
    if (standaloneForm && !standaloneForm.closest('#driver-create-modal') && window.initDriverForm) {
        window.initDriverForm(standaloneForm);
    }
})();

(function () {
    window.bindVehicleSetCreateModalForm = function () {
        var createModal = document.getElementById('vehicle-set-create-modal');
        if (!createModal) return;

        var form = createModal.querySelector('#vehicle-set-create-form');
        if (!form || form.dataset.modalCreateSubmitReady === '1') return;

        form.dataset.modalCreateSubmitReady = '1';
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) {
                return;
            }

            event.preventDefault();

            var formData = new FormData(form);
            fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
                .then(function (response) {
                    if (!response.ok) throw new Error(String(response.status));
                    return response.text();
                })
                .then(function (html) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = html;

                    var returnedForm = tmp.querySelector('#vehicle-set-create-form');
                    if (returnedForm) {
                        var modalBody = createModal.querySelector('.modal-body');
                        if (!modalBody) return;

                        var returnedPageContent = tmp.querySelector('.page-content');
                        modalBody.innerHTML = returnedPageContent ? returnedPageContent.innerHTML : returnedForm.outerHTML;

                        if (typeof window.erpExecuteInlineScripts === 'function') {
                            window.erpExecuteInlineScripts(modalBody);
                        }

                        var newForm = createModal.querySelector('#vehicle-set-create-form');
                        if (newForm) {
                            newForm.dataset.modalCreateSubmitReady = '0';
                        }

                        window.bindVehicleSetCreateModalForm();
                        return;
                    }

                    window.location.reload();
                })
                .catch(function () {
                    window.location.reload();
                });
        });
    };

    var createModal = document.getElementById('vehicle-set-create-modal');
    if (createModal && createModal.dataset.resetOnClose === '1') {
        var createModalBody = createModal.querySelector('.modal-body');
        if (createModalBody && !createModal.dataset.initialBodyHtml) {
            createModal.dataset.initialBodyHtml = createModalBody.innerHTML;
        }
    }

    window.bindVehicleSetCreateModalForm();
})();

// ============================================================
// VehicleSet modal — ModalShell adapter
// ============================================================
(function () {
    var vehicleSetCtrl = ModalShell.create({
        modalId: 'vehicle-set-view-modal',
        deleteConfirmId: 'vehicle-set-delete-confirm-modal',
        overlayClass: 'driver-view-overlay',
        modalInnerClass: 'modal-lg driver-view-modal-inner',
        title: '\u0422\u0440\u0430\u043d\u0441\u043f\u043e\u0440\u0442',
        loadingClass: 'driver-modal-loading',
        nameSelector: '.driver-view-name',
        editFormSelector: '#vehicle-set-edit-form',
        gridSelector: '.table-card[data-vehicle-sets-grid]',
        gridStateKey: 'companyVehicleSetsGridState',
        viewBtnSelectors: {
            edit:   '[data-vehicle-set-edit-btn]',
            close:  '[data-vehicle-set-close-btn]',
            delete: '[data-vehicle-set-delete-btn]'
        },
        editBtnSelectors: {
            cancel: '[data-vehicle-set-cancel-edit-btn]'
        },
        endpoints: {
            view:   function (id) { var base = window.getErpBasePath(); return base + '/company/vehicle-sets/' + id + '/modal-view'; },
            edit:   function (id) { var base = window.getErpBasePath(); return base + '/company/vehicle-sets/' + id + '/modal-edit'; },
            delete: function (id) { var base = window.getErpBasePath(); return base + '/company/vehicle-sets/' + id + '/modal-archive'; }
        },
        errorMessages: {
            loadFailed:    '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u0434\u0430\u043d\u043d\u044b\u0435 \u0442\u0440\u0430\u043d\u0441\u043f\u043e\u0440\u0442\u0430.',
            saveFailed:    '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c.',
            deleteFailed:  '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0443\u0434\u0430\u043b\u0438\u0442\u044c \u0442\u0440\u0430\u043d\u0441\u043f\u043e\u0440\u0442.'
        },
        deleteConfirm: {
            title:       '\u0423\u0434\u0430\u043b\u0438\u0442\u044c \u0442\u0440\u0430\u043d\u0441\u043f\u043e\u0440\u0442?',
            warning:     '\u0417\u0430\u043f\u0438\u0441\u044c \u0431\u0443\u0434\u0435\u0442 \u0443\u0434\u0430\u043b\u0435\u043d\u0430 \u0438\u0437 \u0441\u043f\u0438\u0441\u043a\u0430.',
            instruction: '\u0414\u043b\u044f \u0437\u0430\u0449\u0438\u0442\u044b \u043e\u0442 \u0441\u043b\u0443\u0447\u0430\u0439\u043d\u043e\u0433\u043e \u0443\u0434\u0430\u043b\u0435\u043d\u0438\u044f \u0432\u0432\u0435\u0434\u0438\u0442\u0435 <b>\u0423\u0414\u0410\u041b\u0418\u0422\u042c</b>.',
            placeholder: '\u0412\u0432\u0435\u0434\u0438\u0442\u0435 \u0423\u0414\u0410\u041b\u0418\u0422\u042c',
            confirmWord: '\u0423\u0414\u0410\u041b\u0418\u0422\u042c',
            cancelBtn:   '\u041e\u0442\u043c\u0435\u043d\u0430',
            confirmBtn:  '\u0423\u0434\u0430\u043b\u0438\u0442\u044c'
        },
        onContentLoaded: function (shell, mode) {
        }
    });
    ModalShell.register('vehicleSet', vehicleSetCtrl);

    function resolveVehicleSetId(target) {
        var direct = target.closest('[data-vehicle-set-id]');
        if (direct && direct.getAttribute('data-vehicle-set-id')) {
            return direct.getAttribute('data-vehicle-set-id');
        }
        var row = target.closest('tr[data-vehicle-set-id]');
        return row ? row.getAttribute('data-vehicle-set-id') : '';
    }

    var vehicleGrid = document.querySelector('.table-card[data-vehicle-sets-grid]');
    if (vehicleGrid) {
        var tableBody = vehicleGrid.querySelector('tbody');
        if (tableBody) {
            tableBody.addEventListener('click', function (event) {
                var viewBtn = event.target.closest('[data-vehicle-view-btn]');
                if (viewBtn) {
                    var viewId = resolveVehicleSetId(viewBtn);
                    if (viewId) vehicleSetCtrl.loadView(viewId);
                    return;
                }
                var editBtn = event.target.closest('[data-vehicle-edit-btn]');
                if (editBtn) {
                    var editId = resolveVehicleSetId(editBtn);
                    if (editId) vehicleSetCtrl.loadEdit(editId);
                }
            });

            tableBody.addEventListener('dblclick', function (event) {
                if (event.target.closest('a, button, input, select, textarea, label')) return;
                var row = event.target.closest('tr[data-vehicle-set-id]');
                if (!row) return;
                var id = row.getAttribute('data-vehicle-set-id');
                if (id) vehicleSetCtrl.loadView(id);
            });
        }
    }
})();

// ============================================================
// Bank statement upload modal: close on overlay click
// ============================================================
(function () {
    var modal = document.getElementById('bank-statement-upload-modal');
    if (!modal) return;
    modal.addEventListener('click', function (e) {
        if (e.target === modal) {
            window.closeModal('bank-statement-upload-modal');
        }
    });
})();

// ============================================================
// Bank import delete confirmation (event delegation)
// ============================================================
(function () {
    var deleteImportId = null;

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-delete-import]');
        if (!btn) return;
        deleteImportId = parseInt(btn.getAttribute('data-delete-import'), 10);
        var input = document.getElementById('bank-delete-confirm-input');
        if (input) input.value = '';
        var confirmBtn = document.getElementById('bank-delete-confirm-btn');
        if (confirmBtn) confirmBtn.disabled = true;
        var errorBox = document.getElementById('bank-delete-confirm-error');
        if (errorBox) errorBox.classList.add('is-hidden');
        window.closeModal('bank-statements-modal');
        window.openModal('bank-delete-confirm-modal');
    });

    document.addEventListener('input', function (e) {
        var input = e.target.closest('[data-confirm-delete-input]');
        if (!input) return;
        var confirmBtn = document.getElementById('bank-delete-confirm-btn');
        if (confirmBtn) confirmBtn.disabled = input.value.trim() !== 'УДАЛИТЬ';
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-confirm-delete-btn]');
        if (!btn) return;
        if (!deleteImportId) return;
        var url = window._bankDeleteImportUrl || '/company/finance/bank-accounts/import/delete';
        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.getAttribute('content') : '';
        var form = document.createElement('form');
        form.method = 'post';
        form.action = url;
        var importInput = document.createElement('input');
        importInput.type = 'hidden';
        importInput.name = 'import_id';
        importInput.value = deleteImportId;
        form.appendChild(importInput);
        var csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_csrf_token';
        csrfInput.value = token;
        form.appendChild(csrfInput);
        document.body.appendChild(form);
        window.closeModal('bank-delete-confirm-modal');
        form.submit();
    });
})();

// ============================================================
// Linear trips: create form + ModalShell adapter
// ============================================================
(function () {
    var baseUrl = null;
    try { baseUrl = window.getErpBasePath(); } catch (e) { baseUrl = ''; }
    async function fetchLinearTripCargoSuggestions(query) {
        var response = await fetch((baseUrl || '') + '/company/trips/linear/cargo-types?q=' + encodeURIComponent(query), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) return [];
        var data = await response.json();
        return Array.isArray(data.items) ? data.items : [];
    }

    function syncDueFields(row) {
        var condSelect = row.querySelector('[data-condition-type]');
        if (!condSelect) return;
        var val = condSelect.value;
        var showDays = val === 'after_start' || val === 'after_end' || val === 'after_documents';
        var showSpecificDate = val === 'prepayment' || val === 'specific_date';
        var daysField = row.querySelector('[data-days-count-wrapper]');
        var kindField = row.querySelector('[data-days-kind-wrapper]');
        var specField = row.querySelector('[data-specific-date-wrapper]');
        var daysInput = daysField ? daysField.querySelector('input[name$="[days_count]"]') : null;
        var kindInput = kindField ? kindField.querySelector('select[name$="[days_kind]"]') : null;
        if (daysField) daysField.classList.toggle('is-hidden', !showDays);
        if (kindField) kindField.classList.toggle('is-hidden', !showDays);
        if (specField) specField.classList.toggle('is-hidden', !showSpecificDate);
        if (daysInput) {
            daysInput.required = showDays;
            if (!showDays) {
                daysInput.value = '';
            }
        }
        if (kindInput) {
            kindInput.required = showDays;
            if (!showDays) {
                kindInput.value = '';
            }
        }
    }

    function updateRemoveButtons(container, selector) {
        var rows = container.querySelectorAll(selector);
        rows.forEach(function (row, index) {
            var btn = row.querySelector('[data-remove-payment-row], [data-remove-principal-row]');
            if (!btn) return;
            btn.classList.toggle('is-hidden', rows.length <= 1);
        });
    }

    function renumberPaymentRows(container, baseName) {
        container.querySelectorAll('[data-payment-row]').forEach(function (row, index) {
            row.querySelectorAll('[name]').forEach(function (field) {
                var currentName = field.getAttribute('name') || '';
                field.setAttribute('name', currentName.replace(/^(.*)\[\d+\]\[(.+)\]$/, baseName + '[' + index + '][$2]'));
            });
        });
        updateRemoveButtons(container, '[data-payment-row]');
    }

    function buildPaymentRow(baseName) {
        var row = document.createElement('div');
        row.className = 'linear-trip-payment-row';
        row.setAttribute('data-payment-row', '');
        row.innerHTML = '' +
            '<div class="field">' +
                '<label class="field-label">Сумма <span class="req">*</span></label>' +
                '<input type="text" name="' + baseName + '[0][amount]" class="field-input" inputmode="numeric" placeholder="125000">' +
                '<div class="field-msg"></div>' +
            '</div>' +
            '<div class="field">' +
                '<label class="field-label">Способ оплаты <span class="req">*</span></label>' +
                '<select name="' + baseName + '[0][payment_method]" class="field-input">' +
                    '<option value="cashless">Безнал</option>' +
                    '<option value="cash">Нал</option>' +
                '</select>' +
                '<div class="field-msg"></div>' +
            '</div>' +
            '<div class="field">' +
                '<label class="field-label">НДС <span class="req">*</span></label>' +
                '<select name="' + baseName + '[0][vat_rate]" class="field-input">' +
                    '<option value="">Без НДС</option>' +
                    '<option value="0">НДС 0%</option>' +
                    '<option value="5">НДС 5%</option>' +
                    '<option value="7">НДС 7%</option>' +
                    '<option value="20">НДС 20%</option>' +
                    '<option value="22">НДС 22%</option>' +
                '</select>' +
                '<div class="field-msg"></div>' +
            '</div>' +
            '<div class="field">' +
                '<label class="field-label">Срок оплаты <span class="req">*</span></label>' +
                '<select name="' + baseName + '[0][condition_type]" class="field-input" data-condition-type>' +
                    '<option value="">— Выберите срок —</option>' +
                    '<option value="prepayment">Предоплата</option>' +
                    '<option value="start_day">В день начала рейса</option>' +
                    '<option value="after_start">После начала рейса</option>' +
                    '<option value="end_day">В день окончания рейса</option>' +
                    '<option value="after_end">После окончания рейса</option>' +
                    '<option value="after_documents">После получения документов</option>' +
                    '<option value="specific_date">Конкретная дата</option>' +
                '</select>' +
                '<div class="field-msg"></div>' +
            '</div>' +
            '<div class="field is-hidden" data-days-count-wrapper>' +
                '<label class="field-label">Дней <span class="req">*</span></label>' +
                '<input type="number" min="1" name="' + baseName + '[0][days_count]" class="field-input">' +
                '<div class="field-msg"></div>' +
            '</div>' +
            '<div class="field is-hidden" data-days-kind-wrapper>' +
                '<label class="field-label">Тип дней <span class="req">*</span></label>' +
                '<select name="' + baseName + '[0][days_kind]" class="field-input">' +
                    '<option value="">— Выберите тип —</option>' +
                    '<option value="working">Рабочие дни</option>' +
                    '<option value="calendar">Календарные дни</option>' +
                '</select>' +
                '<div class="field-msg"></div>' +
            '</div>' +
            '<div class="field is-hidden" data-specific-date-wrapper>' +
                '<label class="field-label">Конкретная дата <span class="req">*</span></label>' +
                '<input type="text" name="' + baseName + '[0][specific_due_date]" class="field-input js-erp-date-picker" placeholder="дд.мм.гггг" inputmode="numeric" autocomplete="off">' +
                '<div class="field-msg"></div>' +
            '</div>' +
            '<div class="field">' +
                '<label class="field-label">Комментарий к сроку</label>' +
                '<input type="text" name="' + baseName + '[0][condition_comment]" class="field-input" placeholder="Опциональный комментарий">' +
                '<div class="field-msg"></div>' +
            '</div>' +
            '<button type="button" class="linear-trip-row-remove" data-remove-payment-row aria-label="Удалить оплату">×</button>';
        return row;
    }

    function buildCustomDocRow() {
        var row = document.createElement('div');
        row.className = 'file-item custom-doc-row document-file-row is-empty';
        row.innerHTML = '' +
            '<div class="file-type-badge file-type-badge-empty">—</div>' +
            '<div class="file-info">' +
                '<div class="field custom-doc-title-field">' +
                    '<input type="text" name="custom_doc_type[]" class="field-input custom-doc-type-input" placeholder="Введите название">' +
                    '<div class="field-msg"></div>' +
                '</div>' +
                '<div class="file-meta is-hidden"></div>' +
            '</div>' +
            '<button type="button" class="btn btn-secondary file-action-btn js-custom-file-pick-btn"><span>Выбрать</span></button>' +
            '<input type="file" class="file-input-hidden js-custom-file-input" name="custom_doc_file[]">' +
            '<button type="button" class="file-remove" title="Удалить документ">×</button>';
        return row;
    }

    function initCustomDocRow(row) {
        var pickBtn = row.querySelector('.js-custom-file-pick-btn');
        var fileInput = row.querySelector('.js-custom-file-input');
        var removeBtn = row.querySelector('.file-remove');
        if (pickBtn && fileInput) {
            pickBtn.addEventListener('click', function () {
                fileInput.click();
            });
            fileInput.addEventListener('change', function () {
                var badge = row.querySelector('.file-type-badge');
                var meta = row.querySelector('.file-meta');
                if (!fileInput.files || !fileInput.files[0]) return;
                row.classList.remove('is-empty');
                row.classList.add('has-file');
                if (badge) {
                    badge.className = 'file-type-badge is-other';
                    badge.textContent = 'FILE';
                }
                if (meta) {
                    meta.textContent = fileInput.files[0].name;
                    meta.classList.remove('is-hidden');
                }
            });
        }
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                if (row.parentNode) row.parentNode.removeChild(row);
            });
        }
    }

    function buildPrincipalRow(form) {
        var clientSelect = form.querySelector('select[name="client_id"]');
        var carrierSelect = form.querySelector('select[name="carrier_contractor_id"]');

        var row = document.createElement('div');
        row.className = 'linear-trip-principal-card';
        row.setAttribute('data-principal-row', '');
        row.innerHTML = '' +
            '<div class="linear-trip-block-head">' +
                '<div>' +
                    '<div class="linear-trip-block-title">Принципал</div>' +
                    '<div class="field-msg"></div>' +
                '</div>' +
                '<button type="button" class="linear-trip-row-remove" data-remove-principal-row aria-label="Удалить принципала">×</button>' +
            '</div>' +
            '<div class="field">' +
                '<label class="field-label">Юрлицо принципала <span class="req">*</span></label>' +
                '<select class="field-input" data-principal-entity-select>' +
                    '<option value="">— Выберите юрлицо —</option>' +
                '</select>' +
                '<div class="field-msg"></div>' +
            '</div>' +
            '<div class="linear-trip-payment-card linear-trip-payment-card--nested">' +
                '<div class="linear-trip-block-head">' +
                    '<div class="linear-trip-block-title">Оплаты принципала</div>' +
                    '<button type="button" class="btn btn-ghost" data-add-principal-payment-row>+ Добавить оплату</button>' +
                '</div>' +
                '<div class="linear-trip-payment-rows" data-principal-payment-container></div>' +
            '</div>';

        var principalSelect = row.querySelector('[data-principal-entity-select]');
        if (principalSelect) {
            if (clientSelect && clientSelect.selectedOptions[0] && clientSelect.value) {
                var clientOption = document.createElement('option');
                clientOption.value = 'client:' + clientSelect.value;
                clientOption.textContent = 'Заказчик: ' + clientSelect.selectedOptions[0].textContent;
                principalSelect.appendChild(clientOption);
            }
            if (carrierSelect && carrierSelect.selectedOptions[0] && carrierSelect.value) {
                var carrierOption = document.createElement('option');
                carrierOption.value = 'contractor:' + carrierSelect.value;
                carrierOption.textContent = 'Перевозчик: ' + carrierSelect.selectedOptions[0].textContent;
                principalSelect.appendChild(carrierOption);
            }
        }

        var paymentsContainer = row.querySelector('[data-principal-payment-container]');
        paymentsContainer.appendChild(buildPaymentRow('principal_rows[0][payments]'));
        return row;
    }

    function renumberPrincipalRows(form) {
        var principalContainer = form.querySelector('[data-principal-container]');
        if (!principalContainer) return;
        principalContainer.querySelectorAll('[data-principal-row]').forEach(function (principalRow, principalIndex) {
            var select = principalRow.querySelector('[data-principal-entity-select]');
            if (select) {
                select.setAttribute('name', 'principal_rows[' + principalIndex + '][entity_key]');
            }
            var paymentContainer = principalRow.querySelector('[data-principal-payment-container]');
            if (paymentContainer) {
                renumberPaymentRows(paymentContainer, 'principal_rows[' + principalIndex + '][payments]');
            }
        });
        updateRemoveButtons(principalContainer, '[data-principal-row]');
    }

    window.initLinearTripForm = function (form) {
        if (!form || form.dataset.linearTripFormReady === '1') return form;
        form.dataset.linearTripFormReady = '1';

        if (typeof window.initDriverInputValidation === 'function') {
            window.initDriverInputValidation(form);
        }

        var routeTypeSelect = form.querySelector('[data-linear-trip-route-type]');
        var cargoInput = form.querySelector('[data-linear-trip-cargo-input]');
        var cargoSuggestions = form.querySelector('[data-linear-trip-cargo-suggestions]');

        function syncAgencyBlocks() {
            var isAgency = routeTypeSelect && routeTypeSelect.value === 'agency';
            form.querySelectorAll('.is-agency-only').forEach(function (block) {
                block.classList.toggle('is-hidden', !isAgency);
            });
        }

        function bindPaymentContainer(container, baseName) {
            if (!container || container.dataset.paymentContainerReady === '1') return;
            container.dataset.paymentContainerReady = '1';
            var resolveBaseName = typeof baseName === 'function'
                ? baseName
                : function () { return baseName; };
            container.querySelectorAll('[data-payment-row]').forEach(function (row) {
                syncDueFields(row);
            });
            container.addEventListener('change', function (event) {
                if (event.target.matches('[data-condition-type]')) {
                    syncDueFields(event.target.closest('[data-payment-row]'));
                }
            });
            container.addEventListener('click', function (event) {
                var removeBtn = event.target.closest('[data-remove-payment-row]');
                if (!removeBtn) return;
                var row = removeBtn.closest('[data-payment-row]');
                if (!row) return;
                if (container.querySelectorAll('[data-payment-row]').length <= 1) return;
                row.parentNode.removeChild(row);
                renumberPaymentRows(container, resolveBaseName());
            });
        }

        bindPaymentContainer(form.querySelector('[data-payment-container="customer_payments"]'), 'customer_payments');
        bindPaymentContainer(form.querySelector('[data-payment-container="general_payments"]'), 'general_payments');
        bindPaymentContainer(form.querySelector('[data-payment-container="carrier_payments"]'), 'carrier_payments');

        form.querySelectorAll('[data-add-payment-row]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var scope = btn.getAttribute('data-add-payment-row');
                var container = form.querySelector('[data-payment-container="' + scope + '"]');
                if (!container) return;
                var paymentRow = buildPaymentRow(scope);
                var defaultDays = container.getAttribute('data-default-days');
                if (defaultDays) {
                    paymentRow.querySelector('[name$="[condition_type]"]').value = 'after_end';
                    paymentRow.querySelector('[name$="[days_count]"]').value = defaultDays;
                    paymentRow.querySelector('[name$="[days_kind]"]').value = 'calendar';
                    syncDueFields(paymentRow);
                }
                container.appendChild(paymentRow);
                renumberPaymentRows(container, scope);
            });
        });

        var principalContainer = form.querySelector('[data-principal-container]');
        if (principalContainer) {
            principalContainer.querySelectorAll('[data-principal-payment-container]').forEach(function (container, principalIndex) {
                bindPaymentContainer(container, function () {
                    var principalRow = container.closest('[data-principal-row]');
                    if (!principalRow || !principalRow.parentNode) {
                        return 'principal_rows[' + principalIndex + '][payments]';
                    }
                    var rows = Array.prototype.slice.call(principalRow.parentNode.querySelectorAll('[data-principal-row]'));
                    var currentIndex = rows.indexOf(principalRow);
                    return 'principal_rows[' + Math.max(currentIndex, 0) + '][payments]';
                });
            });

            principalContainer.addEventListener('click', function (event) {
                var addBtn = event.target.closest('[data-add-principal-payment-row]');
                if (addBtn) {
                    var principalRow = addBtn.closest('[data-principal-row]');
                    var paymentContainer = principalRow ? principalRow.querySelector('[data-principal-payment-container]') : null;
                    if (!paymentContainer) return;
                    paymentContainer.appendChild(buildPaymentRow('principal_rows[0][payments]'));
                    renumberPrincipalRows(form);
                    return;
                }

                var removePrincipalBtn = event.target.closest('[data-remove-principal-row]');
                if (removePrincipalBtn) {
                    if (principalContainer.querySelectorAll('[data-principal-row]').length <= 1) return;
                    var principalRow = removePrincipalBtn.closest('[data-principal-row]');
                    if (principalRow && principalRow.parentNode) {
                        principalRow.parentNode.removeChild(principalRow);
                        renumberPrincipalRows(form);
                    }
                }
            });
        }

        var addPrincipalBtn = form.querySelector('[data-add-principal-row]');
        if (addPrincipalBtn && principalContainer) {
            addPrincipalBtn.addEventListener('click', function () {
                var principalRow = buildPrincipalRow(form);
                principalContainer.appendChild(principalRow);
                var paymentContainer = principalRow.querySelector('[data-principal-payment-container]');
                bindPaymentContainer(paymentContainer, function () {
                    var rows = Array.prototype.slice.call(principalContainer.querySelectorAll('[data-principal-row]'));
                    var currentIndex = rows.indexOf(principalRow);
                    return 'principal_rows[' + Math.max(currentIndex, 0) + '][payments]';
                });
                renumberPrincipalRows(form);
            });
        }

        var customDocContainer = form.querySelector('[data-custom-docs-container]');
        var addCustomDocBtn = form.querySelector('[data-add-custom-doc-btn]');
        if (customDocContainer && addCustomDocBtn) {
            addCustomDocBtn.addEventListener('click', function () {
                var row = buildCustomDocRow();
                customDocContainer.appendChild(row);
                initCustomDocRow(row);
            });
        }

        form.querySelectorAll('.document-file-row input[type="file"]').forEach(function (input) {
            if (input.classList.contains('js-custom-file-input')) return;
            input.addEventListener('change', function () {
                var row = input.closest('.document-file-row');
                if (!row || !input.files || !input.files[0]) return;
                var badge = row.querySelector('.file-type-badge');
                var meta = row.querySelector('.file-meta');
                row.classList.remove('is-empty');
                row.classList.add('has-file');
                if (badge) {
                    badge.className = 'file-type-badge is-other';
                    badge.textContent = 'FILE';
                }
                if (meta) {
                    meta.textContent = input.files[0].name;
                }
            });
        });

        if (cargoInput && cargoSuggestions) {
            cargoInput.addEventListener('input', async function () {
                var items = await fetchLinearTripCargoSuggestions(cargoInput.value.trim());
                cargoSuggestions.innerHTML = '';
                if (!items.length) {
                    cargoSuggestions.classList.remove('is-open');
                    return;
                }
                items.forEach(function (item) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'custom-doc-suggestion';
                    button.textContent = item.name || '';
                    button.addEventListener('click', function () {
                        cargoInput.value = item.name || '';
                        cargoSuggestions.innerHTML = '';
                        cargoSuggestions.classList.remove('is-open');
                    });
                    cargoSuggestions.appendChild(button);
                });
                cargoSuggestions.classList.add('is-open');
            });
            cargoInput.addEventListener('blur', function () {
                setTimeout(function () {
                    cargoSuggestions.classList.remove('is-open');
                }, 150);
            });
        }

        if (routeTypeSelect) {
            routeTypeSelect.addEventListener('change', syncAgencyBlocks);
        }

        syncAgencyBlocks();
        if (principalContainer) {
            renumberPrincipalRows(form);
        }
        return form;
    };

    window.bindLinearTripCreateModalForm = function () {
        var createModal = document.getElementById('linear-trip-create-modal');
        if (!createModal) return;

        var form = createModal.querySelector('#linear-trip-create-form');
        if (!form || form.dataset.modalCreateSubmitReady === '1') return;

        if (typeof window.initLinearTripForm === 'function') {
            window.initLinearTripForm(form);
        }

        form.dataset.modalCreateSubmitReady = '1';
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) return;

            event.preventDefault();

            var formData = new FormData(form);
            fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
                .then(function (response) {
                    if (!response.ok) throw new Error(String(response.status));
                    return response.text();
                })
                .then(function (html) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = html;

                    var returnedModal = tmp.querySelector('#linear-trip-create-modal');
                    var returnedForm = returnedModal ? returnedModal.querySelector('#linear-trip-create-form') : null;
                    if (returnedForm && returnedModal && returnedModal.classList.contains('is-open')) {
                        var returnedModalBody = returnedModal.querySelector('.modal-body');
                        var returnedModalFoot = returnedModal.querySelector('.modal-foot');
                        var modalBody = createModal.querySelector('.modal-body');
                        var modalFoot = createModal.querySelector('.modal-foot');
                        if (!modalBody || !modalFoot) {
                            window.location.reload();
                            return;
                        }

                        modalBody.innerHTML = returnedModalBody ? returnedModalBody.innerHTML : returnedForm.outerHTML;
                        if (returnedModalFoot) {
                            modalFoot.innerHTML = returnedModalFoot.innerHTML;
                        }

                        var newForm = createModal.querySelector('#linear-trip-create-form');
                        if (newForm) {
                            newForm.dataset.linearTripFormReady = '0';
                            newForm.dataset.modalCreateSubmitReady = '0';
                            window.initLinearTripForm(newForm);
                        }

                        window.bindLinearTripCreateModalForm();
                        return;
                    }

                    window.location.reload();
                })
                .catch(function () {
                    window.location.reload();
                });
        });
    };

    var createModal = document.getElementById('linear-trip-create-modal');
    if (createModal) {
        var createForm = createModal.querySelector('#linear-trip-create-form');
        if (createForm) {
            window.initLinearTripForm(createForm);
        }
        window.bindLinearTripCreateModalForm();
    }
})();

(function () {
    var baseUrl = null;
    try { baseUrl = window.getErpBasePath(); } catch (e) { baseUrl = ''; }
    var linearTripGrid = document.querySelector('.table-card[data-erp-grid]');
    if (!linearTripGrid || !document.querySelector('tr[data-linear-route-id]')) {
        return;
    }

    var linearTripCtrl = ModalShell.create({
        modalId: 'linear-trip-view-modal',
        deleteConfirmId: 'linear-trip-delete-confirm-modal',
        overlayClass: 'driver-view-overlay',
        modalInnerClass: 'modal-lg driver-view-modal-inner',
        title: '\u0420\u0435\u0439\u0441',
        loadingClass: 'driver-modal-loading',
        nameSelector: '.driver-view-name',
        editFormSelector: '#linear-trip-edit-form',
        gridSelector: '.table-card[data-erp-grid]',
        gridStateKey: 'companyLinearTripsGridState',
        viewBtnSelectors: {
            edit: '[data-linear-trip-edit-btn]',
            close: '[data-linear-trip-view-close-btn]',
            delete: '[data-linear-trip-delete-btn]'
        },
        editBtnSelectors: {
            cancel: '[data-linear-trip-cancel-edit-btn]'
        },
        endpoints: {
            view: function (id) { return (baseUrl || '') + '/company/trips/linear/' + id + '/modal-view'; },
            edit: function (id) { return (baseUrl || '') + '/company/trips/linear/' + id + '/modal-edit'; },
            delete: function (id) { return (baseUrl || '') + '/company/trips/linear/' + id + '/modal-delete'; }
        },
        errorMessages: {
            loadFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u0434\u0430\u043d\u043d\u044b\u0435 \u0440\u0435\u0439\u0441\u0430.',
            saveFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u0440\u0435\u0439\u0441.',
            deleteFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0443\u0434\u0430\u043b\u0438\u0442\u044c \u0440\u0435\u0439\u0441.'
        },
        deleteConfirm: {
            title: '\u0423\u0434\u0430\u043b\u0438\u0442\u044c \u0440\u0435\u0439\u0441?',
            warning: '\u0417\u0430\u043f\u0438\u0441\u044c \u0431\u0443\u0434\u0435\u0442 \u0443\u0434\u0430\u043b\u0435\u043d\u0430 \u0438\u0437 \u0441\u043f\u0438\u0441\u043a\u0430.',
            instruction: '\u0414\u043b\u044f \u0437\u0430\u0449\u0438\u0442\u044b \u043e\u0442 \u0441\u043b\u0443\u0447\u0430\u0439\u043d\u043e\u0433\u043e \u0443\u0434\u0430\u043b\u0435\u043d\u0438\u044f \u0432\u0432\u0435\u0434\u0438\u0442\u0435 <b>\u0423\u0414\u0410\u041b\u0418\u0422\u042c</b>.',
            placeholder: '\u0412\u0432\u0435\u0434\u0438\u0442\u0435 \u0423\u0414\u0410\u041b\u0418\u0422\u042c',
            confirmWord: '\u0423\u0414\u0410\u041b\u0418\u0422\u042c',
            cancelBtn: '\u041e\u0442\u043c\u0435\u043d\u0430',
            confirmBtn: '\u0423\u0434\u0430\u043b\u0438\u0442\u044c'
        },
        onContentLoaded: function (shell, mode) {
            if (mode !== 'edit') return;
            var form = shell.querySelector('#linear-trip-edit-form');
            if (form && typeof window.initLinearTripForm === 'function') {
                form.dataset.linearTripFormReady = '0';
                form.dataset.modalSubmitReady = '0';
                window.initLinearTripForm(form);
            }
        }
    });
    ModalShell.register('linearTrip', linearTripCtrl);

    var tableBody = linearTripGrid.querySelector('tbody');
    if (!tableBody) return;

    tableBody.addEventListener('dblclick', function (event) {
        if (event.target.closest('a, button, input, select, textarea, label')) return;
        var row = event.target.closest('tr[data-linear-route-id]');
        if (!row) return;
        var routeId = row.getAttribute('data-linear-route-id');
        if (routeId) linearTripCtrl.loadView(routeId);
    });
})();

// ============================================================
// Superadmin company modal — ModalShell adapter
// ============================================================
(function () {
    var isSuperadminCompanies = window.location.pathname.indexOf('/superadmin/companies') !== -1;
    if (!isSuperadminCompanies) return;

    var companyCtrl = ModalShell.create({
        modalId: 'sa-company-view-modal',
        overlayClass: 'driver-view-overlay',
        modalInnerClass: 'modal-lg driver-view-modal-inner',
        title: '\u041a\u043e\u043c\u043f\u0430\u043d\u0438\u044f',
        loadingClass: 'driver-modal-loading',
        nameSelector: '.driver-view-name',
        editFormSelector: '#company-edit-form',
        viewBtnSelectors: {
            edit: '[data-company-edit-btn]',
            close: '[data-company-view-close-btn]'
        },
        editBtnSelectors: {
            cancel: '[data-company-cancel-edit-btn]'
        },
        endpoints: {
            view: function (id) {
                var base = window.getErpBasePath();
                return base + '/superadmin/companies/' + id + '/modal-view';
            },
            edit: function (id) {
                var base = window.getErpBasePath();
                return base + '/superadmin/companies/' + id + '/modal-edit';
            }
        },
        errorMessages: {
            loadFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u043a\u0430\u0440\u0442\u043e\u0447\u043a\u0443 \u043a\u043e\u043c\u043f\u0430\u043d\u0438\u0438.',
            saveFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u0438\u0437\u043c\u0435\u043d\u0435\u043d\u0438\u044f.'
        },
        onContentLoaded: function (shell, mode) {
            if (mode === 'edit') {
                var form = shell.querySelector('#company-edit-form');
                if (form && window.initLegalEntityDocuments) {
                    window.initLegalEntityDocuments(form);
                }
            }
        }
    });
    ModalShell.register('superadminCompany', companyCtrl);

    var tableBody = document.querySelector('.tbl-wrap tbody');
    if (tableBody) {
        tableBody.addEventListener('dblclick', function (event) {
            if (event.target.closest('a, button, input, select, textarea, label')) return;
            var row = event.target.closest('tr[data-company-id]');
            if (!row) return;
            var id = row.getAttribute('data-company-id');
            if (id) companyCtrl.loadView(id);
        });
    }
})();

// ============================================================
// Document preview popup windows
// ============================================================
document.addEventListener('click', function (event) {
    var link = event.target.closest('.js-doc-popup-window');
    if (!link) return;

    var href = link.getAttribute('href');
    if (!href) return;

    event.preventDefault();

    var screenWidth = window.screen && window.screen.availWidth ? window.screen.availWidth : window.innerWidth;
    var screenHeight = window.screen && window.screen.availHeight ? window.screen.availHeight : window.innerHeight;
    var popupWidth = Math.max(640, Math.round(screenWidth * 0.5));
    var popupHeight = Math.max(720, Math.round(screenHeight * 0.8));
    var popupLeft = Math.max(0, Math.round((screenWidth - popupWidth) / 2));
    var popupTop = Math.max(0, Math.round((screenHeight - popupHeight) / 2));
    var features = [
        'popup=yes',
        'toolbar=no',
        'location=yes',
        'status=no',
        'menubar=no',
        'scrollbars=yes',
        'resizable=yes',
        'width=' + popupWidth,
        'height=' + popupHeight,
        'left=' + popupLeft,
        'top=' + popupTop
    ].join(',');

    var popupWindow = window.open(href, '_blank', features);
    if (!popupWindow) {
        window.location.href = href;
    }
});

// ============================================================
// Detail page delete confirm modal helpers
// ============================================================
window.confirmDeleteForm = function (btn) {
    var form = btn.closest('form');
    if (!form) return;
    window._deleteForm = form;
    var input = document.getElementById('delete-confirm-input');
    if (input) input.value = '';
    var confirmBtn = document.getElementById('delete-confirm-btn');
    if (confirmBtn) confirmBtn.disabled = true;
    var errorBox = document.getElementById('delete-confirm-error');
    if (errorBox) errorBox.classList.add('is-hidden');
    window.openModal('delete-confirm-modal');
};

window.submitDeleteForm = function () {
    var form = window._deleteForm;
    if (!form) return;
    window.closeModal('delete-confirm-modal');
    form.submit();
};

// ============================================================
// Password controls: show/hide, copy, generate (reusable)
// ============================================================
window.initPasswordControls = function (root) {
    if (!root) return;
    var fields = root.querySelectorAll('[data-owner-password-field], [data-sa-user-password-field], [data-company-user-password-field]');
    if (!fields.length) {
        var fieldAlt = root.querySelector('[data-owner-password-field]') || root.querySelector('[data-sa-user-password-field]') || root.querySelector('[data-company-user-password-field]');
        if (fieldAlt) fields = [fieldAlt];
    }

    fields.forEach(function (passwordField) {
        if (!passwordField) return;
        if (passwordField.dataset.pwdReady === '1') return;
        passwordField.dataset.pwdReady = '1';

        var prefix = 'owner';
        if (passwordField.hasAttribute('data-sa-user-password-field')) prefix = 'sa-user';
        else if (passwordField.hasAttribute('data-company-user-password-field')) prefix = 'company-user';

        var rootParent = passwordField.closest('.field-inline-group') || passwordField.parentElement;
        var generateBtn = rootParent.querySelector('[data-' + prefix + '-password-generate]');
        var toggleBtn = rootParent.querySelector('[data-' + prefix + '-password-toggle]');
        var copyBtn = rootParent.querySelector('[data-' + prefix + '-password-copy]');

        if (!generateBtn && !toggleBtn && !copyBtn) {
            generateBtn = rootParent.querySelector('[data-owner-password-generate]') || rootParent.querySelector('[data-sa-user-password-generate]') || rootParent.querySelector('[data-company-user-password-generate]');
            toggleBtn = rootParent.querySelector('[data-owner-password-toggle]') || rootParent.querySelector('[data-sa-user-password-toggle]') || rootParent.querySelector('[data-company-user-password-toggle]');
            copyBtn = rootParent.querySelector('[data-owner-password-copy]') || rootParent.querySelector('[data-sa-user-password-copy]') || rootParent.querySelector('[data-company-user-password-copy]');
        }

        if (generateBtn) {
            generateBtn.addEventListener('click', function () {
                var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                var pwd = '';
                for (var i = 0; i < 12; i++) {
                    pwd += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                passwordField.value = pwd;
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    if (toggleBtn) toggleBtn.textContent = 'Скрыть';
                }
            });
        }

        if (toggleBtn) {
            toggleBtn.addEventListener('click', function () {
                var isPassword = passwordField.type === 'password';
                passwordField.type = isPassword ? 'text' : 'password';
                toggleBtn.textContent = isPassword ? 'Скрыть' : 'Показать';
            });
        }

        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                if (passwordField.value) {
                    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                        navigator.clipboard.writeText(passwordField.value).catch(function () {});
                    }
                    copyBtn.textContent = 'Скопировано';
                    setTimeout(function () { copyBtn.textContent = 'Копировать'; }, 2000);
                }
            });
        }
    });
};

// Backward compat alias
window.initOwnerCreateForm = window.initPasswordControls;

// ============================================================
// Owner create modal — superadmin company detail page
// Binds data-owner-create-modal links and modal controls
// ============================================================
(function () {
    var modalId = 'sa-owner-create-modal';
    var contentId = 'sa-owner-create-modal-content';
    var modalEl = document.getElementById(modalId);
    if (!modalEl) return;

    window.closeOwnerCreateModal = function () {
        if (window.closeModal) {
            window.closeModal(modalId);
        } else {
            modalEl.classList.remove('is-open');
        }
    };

    document.querySelectorAll('[data-owner-create-modal]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var companyId = link.getAttribute('data-company-id');
            if (!companyId) return;

            var contentTarget = document.getElementById(contentId);
            contentTarget.innerHTML = '<div class="modal-body"><div class="driver-modal-loading">Загрузка...</div></div>';
            if (window.openModal) {
                window.openModal(modalId);
            } else {
                modalEl.classList.add('is-open');
            }

            var base = window.getErpBasePath();
            fetch(base + '/superadmin/companies/' + companyId + '/create-owner/modal')
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    contentTarget.innerHTML = html;
                    if (window.initOwnerCreateForm) {
                        window.initOwnerCreateForm(contentTarget);
                    }
                })
                .catch(function () {
                    contentTarget.innerHTML = '<div class="modal-body"><div class="notice warn">Ошибка загрузки формы. <a href="' + link.getAttribute('href') + '">Открыть в полной версии</a></div></div>';
                });
        });
    });

    modalEl.addEventListener('submit', function (e) {
        if (e.target && e.target.id === 'owner-create-form') {
            e.preventDefault();
            var form = e.target;
            var formData = new FormData(form);
            var contentTarget = document.getElementById(contentId);

            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: formData
            })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                contentTarget.innerHTML = html;
                if (window.initOwnerCreateForm) {
                    window.initOwnerCreateForm(contentTarget);
                }
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                if (tmp.querySelector('[data-owner-create-success]')) {
                    modalEl.dataset.refreshOnClose = '1';
                }
            })
            .catch(function () {
                contentTarget.innerHTML = '<div class="modal-body"><div class="notice warn">Ошибка отправки формы.</div></div>';
            });
        }
    });

    modalEl.addEventListener('click', function (e) {
        if (e.target.closest('[data-owner-create-close]')) {
            window.closeOwnerCreateModal();
            if (modalEl.dataset.refreshOnClose === '1') {
                delete modalEl.dataset.refreshOnClose;
                window.location.reload();
            }
        }
    });
})();

// ============================================================
// Superadmin — Owner View/Edit Modal (ModalShell)
// ============================================================
(function () {
    var ownerCtrl = ModalShell.create({
        modalId: 'sa-owner-view-modal',
        overlayClass: 'driver-view-overlay',
        modalInnerClass: 'modal-lg driver-view-modal-inner',
        title: '\u0420\u0443\u043a\u043e\u0432\u043e\u0434\u0438\u0442\u0435\u043b\u044c',
        loadingClass: 'driver-modal-loading',
        viewBtnSelectors: {
            edit: '[data-user-edit-btn]',
            close: '[data-user-view-close-btn]'
        },
        editBtnSelectors: {
            cancel: '[data-user-cancel-edit-btn]'
        },
        endpoints: {
            view: function (companyId) { var base = window.getErpBasePath(); return base + '/superadmin/companies/' + companyId + '/owner/modal-view'; },
            edit: function (companyId) { var base = window.getErpBasePath(); return base + '/superadmin/companies/' + companyId + '/owner/modal-edit'; }
        },
        errorMessages: {
            loadFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u0434\u0430\u043d\u043d\u044b\u0435.',
            saveFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c.'
        },
        onContentLoaded: function () {}
    });
    ModalShell.register('saOwner', ownerCtrl);

    var ownerViewClose = document.querySelector('[data-owner-view-close]');
    if (ownerViewClose) {
        ownerViewClose.addEventListener('click', function () { ownerCtrl.close(); });
    }

    var ownerCard = document.querySelector('[data-erp-owner-card]');
    if (ownerCard) {
        ownerCard.addEventListener('dblclick', function (e) {
            if (e.target.closest('a, button, input, select, textarea, label')) return;
            var companyId = this.getAttribute('data-owner-id');
            if (companyId) ownerCtrl.loadView(companyId);
        });
    }
})();

// ============================================================
// Superadmin — Logist View/Edit Modal (ModalShell)
// ============================================================
(function () {
    var baseUrl = null;
    try { baseUrl = window.getErpBasePath(); } catch (e) { baseUrl = ''; }

    var logistCtrl = ModalShell.create({
        modalId: 'sa-logist-view-modal',
        overlayClass: 'driver-view-overlay',
        modalInnerClass: 'modal-lg driver-view-modal-inner',
        title: '\u041f\u043e\u043b\u044c\u0437\u043e\u0432\u0430\u0442\u0435\u043b\u044c',
        loadingClass: 'driver-modal-loading',
        viewBtnSelectors: {
            edit: '[data-user-edit-btn]',
            close: '[data-user-view-close-btn]'
        },
        editBtnSelectors: {
            cancel: '[data-user-cancel-edit-btn]'
        },
        editFormSelector: '#user-edit-form',
        endpoints: {
            view: function (uid) {
                var companyId = document.getElementById('sa-logist-view-modal').getAttribute('data-company-id');
                return (baseUrl || '') + '/superadmin/companies/' + companyId + '/users/logists/' + uid + '/modal-view';
            },
            edit: function (uid) {
                var companyId = document.getElementById('sa-logist-view-modal').getAttribute('data-company-id');
                return (baseUrl || '') + '/superadmin/companies/' + companyId + '/users/logists/' + uid + '/modal-edit';
            }
        },
        errorMessages: {
            loadFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u0434\u0430\u043d\u043d\u044b\u0435.',
            saveFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c.'
        },
        onContentLoaded: function () {}
    });
    ModalShell.register('saLogist', logistCtrl);

    var saLogistViewClose = document.querySelector('[data-sa-logist-view-close]');
    if (saLogistViewClose) {
        saLogistViewClose.addEventListener('click', function () { logistCtrl.close(); });
    }

    var logistRow = document.querySelector('tr[data-sa-user-id]');
    var logistTable = null;
    if (logistRow) {
        logistTable = logistRow.closest('tbody');
    }
    if (logistTable) {
        // dblclick on row -> view
        logistTable.addEventListener('dblclick', function (e) {
            if (e.target.closest('a, button, input, select, textarea, label')) return;
            var row = e.target.closest('tr[data-sa-user-id]');
            if (!row) return;
            var uid = row.getAttribute('data-sa-user-id');
            if (uid) logistCtrl.loadView(uid);
        });

        // click on action links -> modal view/edit
        logistTable.addEventListener('click', function (e) {
            var base = window.getErpBasePath ? window.getErpBasePath() : '';
            var link = e.target.closest('a[href*="/superadmin/companies/"][href*="/users/logists/"]');
            if (!link) return;
            var href = link.getAttribute('href') || '';
            var row = e.target.closest('tr[data-sa-user-id]');
            var uid = row ? row.getAttribute('data-sa-user-id') : '';

            var path;
            try {
                path = new URL(href, window.location.origin).pathname;
            } catch (_) {
                path = href;
            }

            var basePat = base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            var viewPat = new RegExp('^' + basePat + '/superadmin/companies/\\d+/users/logists/\\d+$');
            var editPat = new RegExp('^' + basePat + '/superadmin/companies/\\d+/users/logists/\\d+/edit$');

            if (viewPat.test(path)) {
                e.preventDefault();
                if (uid) logistCtrl.loadView(uid);
                return;
            }

            if (editPat.test(path)) {
                e.preventDefault();
                if (uid) logistCtrl.loadEdit(uid);
            }
        });
    }

    // Set data-company-id on view modal when showing
    document.querySelectorAll('[data-sa-logist-create-modal]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var companyId = this.getAttribute('data-company-id');
            if (!companyId) {
                var m = window.location.pathname.match(/\/superadmin\/companies\/(\d+)/);
                if (m) companyId = m[1];
                else return;
            }

            var modal = document.getElementById('sa-logist-create-modal');
            if (!modal) return;
            var contentTarget = document.getElementById('sa-logist-create-modal-content');
            contentTarget.innerHTML = '<div class="modal-body"><div class="driver-modal-loading">\u0417\u0430\u0433\u0440\u0443\u0437\u043a\u0430...</div></div>';
            window.openModal('sa-logist-create-modal');

            fetch((baseUrl || '') + '/superadmin/companies/' + companyId + '/users/logists/modal-create')
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    contentTarget.innerHTML = html;
                    if (window.initPasswordControls) {
                        window.initPasswordControls(contentTarget);
                    }
                })
                .catch(function () {
                    contentTarget.innerHTML = '<div class="modal-body"><div class="notice warn">\u041e\u0448\u0438\u0431\u043a\u0430 \u0437\u0430\u0433\u0440\u0443\u0437\u043a\u0438 \u0444\u043e\u0440\u043c\u044b.</div></div>';
                });
        });
    });

    // SA logist create modal AJAX submit
    var saLogistCreateModal = document.getElementById('sa-logist-create-modal');
    if (saLogistCreateModal) {
        saLogistCreateModal.addEventListener('submit', function (e) {
            if (e.target && e.target.id === 'sa-logist-create-form') {
                e.preventDefault();
                var form = e.target;
                var formData = new FormData(form);
                var contentTarget = document.getElementById('sa-logist-create-modal-content');

                fetch(form.getAttribute('action'), {
                    method: 'POST',
                    body: formData
                })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    contentTarget.innerHTML = html;
                    var tmp = document.createElement('div');
                    tmp.innerHTML = html;
                    if (tmp.querySelector('[data-sa-logist-create-success]')) {
                        saLogistCreateModal.dataset.refreshOnClose = '1';
                        var closeBtns = contentTarget.querySelectorAll('[data-sa-logist-create-close]');
                        closeBtns.forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                window.closeModal('sa-logist-create-modal');
                                if (saLogistCreateModal.dataset.refreshOnClose === '1') {
                                    delete saLogistCreateModal.dataset.refreshOnClose;
                                    window.location.reload();
                                }
                            });
                        });
                    }
                })
                .catch(function () {
                    contentTarget.innerHTML = '<div class="modal-body"><div class="notice warn">\u041e\u0448\u0438\u0431\u043a\u0430 \u043e\u0442\u043f\u0440\u0430\u0432\u043a\u0438 \u0444\u043e\u0440\u043c\u044b.</div></div>';
                });
            }
        });

        saLogistCreateModal.addEventListener('click', function (e) {
            if (e.target.closest('[data-sa-logist-create-close]')) {
                window.closeModal('sa-logist-create-modal');
                if (saLogistCreateModal.dataset.refreshOnClose === '1') {
                    delete saLogistCreateModal.dataset.refreshOnClose;
                    window.location.reload();
                }
            }
        });
    }
})();

// ============================================================
// Company owner — User View/Edit/Create Modal
// ============================================================
(function () {
    var baseUrl = null;
    try { baseUrl = window.getErpBasePath(); } catch (e) { baseUrl = ''; }

    var companyUserCtrl = ModalShell.create({
        modalId: 'company-user-view-modal',
        overlayClass: 'driver-view-overlay',
        modalInnerClass: 'modal-lg driver-view-modal-inner',
        title: '\u041f\u043e\u043b\u044c\u0437\u043e\u0432\u0430\u0442\u0435\u043b\u044c',
        loadingClass: 'driver-modal-loading',
        viewBtnSelectors: {
            edit: '[data-company-user-edit-btn]',
            close: '[data-company-user-view-close-btn]'
        },
        editFormSelector: '#company-user-edit-form',
        editBtnSelectors: {
            cancel: '[data-company-user-cancel-edit-btn]'
        },
        endpoints: {
            view: function (id) { return (baseUrl || '') + '/company/logists/' + id + '/modal-view'; },
            edit: function (id) { return (baseUrl || '') + '/company/logists/' + id + '/modal-edit'; }
        },
        errorMessages: {
            loadFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u0434\u0430\u043d\u043d\u044b\u0435.',
            saveFailed: '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c.'
        },
        onContentLoaded: function () {}
    });
    ModalShell.register('companyUser', companyUserCtrl);

    var cuViewClose = document.querySelector('[data-company-user-view-close]');
    if (cuViewClose) {
        cuViewClose.addEventListener('click', function () {
            companyUserCtrl.close();
        });
    }

    var companyUserTable = document.querySelector('table.tbl tbody');
    if (companyUserTable && companyUserTable.querySelector('[data-company-user-id]')) {
        companyUserTable.addEventListener('dblclick', function (e) {
            if (e.target.closest('a, button, input, select, textarea, label')) return;
            var row = e.target.closest('tr[data-company-user-id]');
            if (!row) return;
            var id = row.getAttribute('data-company-user-id');
            if (id) companyUserCtrl.loadView(id);
        });
    }

    // Table action link click — modal view/edit instead of navigation
    if (companyUserTable && companyUserTable.querySelector('[data-company-user-id]')) {
        companyUserTable.addEventListener('click', function (e) {
            var base = window.getErpBasePath ? window.getErpBasePath() : '';
            var link = e.target.closest('a[href*="/company/logists/"]');
            if (!link) return;
            var href = link.getAttribute('href') || '';
            var r = e.target.closest('tr[data-company-user-id]');
            var id = r ? r.getAttribute('data-company-user-id') : '';
            var path;
            try {
                path = new URL(href, window.location.origin).pathname;
            } catch (_) {
                path = href;
            }
            var viewPat = new RegExp('^' + base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/company/logists/\\d+$');
            var editPat = new RegExp('^' + base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/company/logists/\\d+/edit$');
            if (viewPat.test(path)) {
                e.preventDefault();
                if (id) companyUserCtrl.loadView(id);
                return;
            }
            if (editPat.test(path)) {
                e.preventDefault();
                if (id) companyUserCtrl.loadEdit(id);
            }
        });
    }

    // ── Create modal ──
    window.bindCompanyUserCreateModalForm = function () {
        var m = document.getElementById('company-user-create-modal');
        if (!m) return;
        var f = m.querySelector('#company-user-create-form');
        if (!f || f.dataset.modalCreateSubmitReady === '1') return;
        f.dataset.modalCreateSubmitReady = '1';
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(f);
            fetch(f.action, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { if (!r.ok) throw Error(r.status); return r.text(); })
                .then(function (html) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = html;
                    // Success: render details, keep modal open, refresh on close
                    if (tmp.querySelector('[data-company-user-create-success]')) {
                        var mb = m.querySelector('.modal-body');
                        var mf = m.querySelector('.modal-foot');
                        if (mb) { var nb = tmp.querySelector('.modal-body'); if (nb) mb.innerHTML = nb.innerHTML; }
                        if (mf) { var nf = tmp.querySelector('.modal-foot'); if (nf) mf.innerHTML = nf.innerHTML; }
                        m.dataset.refreshOnClose = '1';
                        return;
                    }
                    // Validation error: re-render form in modal, re-bind
                    if (tmp.querySelector('#company-user-create-form')) {
                        var mb = m.querySelector('.modal-body');
                        if (mb) { var nb = tmp.querySelector('.modal-body'); if (nb) mb.innerHTML = nb.innerHTML; }
                        var mf = m.querySelector('.modal-foot');
                        if (mf) { var nf = tmp.querySelector('.modal-foot'); if (nf) mf.innerHTML = nf.innerHTML; }
                        if (window.initPasswordControls) window.initPasswordControls(m);
                        window.bindCompanyUserCreateModalForm();
                        return;
                    }
                    // Unexpected response: show inline warning
                    var mb = m.querySelector('.modal-body');
                    if (mb) mb.innerHTML = '<div class="notice warn">Неожиданный ответ сервера. <a href="' + window.location.href + '">Обновите страницу</a>.</div>';
                })
                .catch(function () {
                    var mb = m.querySelector('.modal-body');
                    if (mb) mb.innerHTML = '<div class="notice warn">Ошибка соединения. Попробуйте ещё раз.</div>';
                });
        });
    };

    var cuCreateModal = document.getElementById('company-user-create-modal');
    if (cuCreateModal && cuCreateModal.dataset.resetOnClose === '1') {
        var cuBody = cuCreateModal.querySelector('.modal-body');
        if (cuBody && !cuCreateModal.dataset.initialBodyHtml) {
            cuCreateModal.dataset.initialBodyHtml = cuBody.innerHTML;
        }
    }
    window.bindCompanyUserCreateModalForm();
    if (cuCreateModal && window.initPasswordControls) window.initPasswordControls(cuCreateModal);

    document.querySelectorAll('[data-company-user-create-modal]').forEach(function (b) {
        b.addEventListener('click', function () { openModal('company-user-create-modal'); });
    });
    if (cuCreateModal) {
        cuCreateModal.addEventListener('click', function (e) {
            if (e.target.closest('[data-company-user-create-close]')) {
                closeModal('company-user-create-modal');
                if (cuCreateModal.dataset.refreshOnClose === '1') {
                    delete cuCreateModal.dataset.refreshOnClose;
                    window.location.reload();
                }
            }
        });
    }
})();

// ============================================================
// Route executors — create/view/edit popups
// ============================================================
(function () {
    var createModal = document.getElementById('route-executor-create-modal');
    var viewModal = document.getElementById('route-executor-view-modal');
    if (!createModal && !viewModal) return;

    var baseUrl = '';
    try { baseUrl = window.getErpBasePath ? window.getErpBasePath() : ''; } catch (e) { baseUrl = ''; }

    function parseContent(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        return doc.querySelector('main.content') || doc.body;
    }

    function cleanRouteExecutorContent(container) {
        var clone = container.cloneNode(true);
        clone.querySelectorAll('.page-head, script').forEach(function (node) {
            node.remove();
        });
        clone.querySelectorAll('a[href^="/company/route-executors"], a[href^="' + baseUrl + '/company/route-executors"]').forEach(function (link) {
            var href = link.getAttribute('href') || '';
            try {
                href = new URL(href, window.location.origin).pathname;
            } catch (e) {}
            var viewMatch = href.match(/\/company\/route-executors\/(\d+)$/);
            var editMatch = href.match(/\/company\/route-executors\/(\d+)\/edit$/);
            if (editMatch) {
                link.setAttribute('href', baseUrl + '/company/route-executors/' + editMatch[1] + '/edit');
                link.setAttribute('data-route-executor-edit-link', editMatch[1]);
            } else if (viewMatch) {
                link.setAttribute('href', baseUrl + '/company/route-executors/' + viewMatch[1]);
                link.setAttribute('data-route-executor-view-link', viewMatch[1]);
            }
        });
        return clone;
    }

    function setModalLoading(modal, contentId) {
        var content = document.getElementById(contentId);
        if (content) {
            content.innerHTML = '<div class="driver-modal-loading">\u0417\u0430\u0433\u0440\u0443\u0437\u043a\u0430...</div>';
        }
        if (window.openModal) {
            window.openModal(modal.id);
        } else {
            modal.classList.add('is-open');
        }
    }

    function showModalError(contentId, message) {
        var content = document.getElementById(contentId);
        if (content) {
            content.innerHTML = '<div class="notice warn modal-notice">' + message + '</div>';
        }
    }

    function prepareCreateForm(content) {
        var form = content.querySelector('#route-executor-create-form') || content.querySelector('form[action*="/company/route-executors/create"]');
        if (!form) return null;
        form.id = 'route-executor-create-form';
        form.setAttribute('action', baseUrl + '/company/route-executors/create');
        form.querySelectorAll('.form-actions').forEach(function (node) { node.remove(); });
        if (!form.querySelector('input[name="_is_modal"]')) {
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = '_is_modal';
            hidden.value = '1';
            form.appendChild(hidden);
        }
        return form;
    }

    function bindCreateForm() {
        if (!createModal) return;
        var form = createModal.querySelector('#route-executor-create-form');
        if (!form || form.dataset.routeExecutorCreateReady === '1') return;
        form.dataset.routeExecutorCreateReady = '1';
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var body = new FormData(form);
            fetch(form.getAttribute('action'), {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                var contentTarget = document.getElementById('route-executor-create-modal-content');
                if (!contentTarget) return;
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                if (tmp.querySelector('[data-route-executor-create-success]')) {
                    window.closeModal('route-executor-create-modal');
                    window.location.reload();
                    return;
                }

                var parsed = parseContent(html);
                var nextForm = prepareCreateForm(parsed);
                if (nextForm) {
                    contentTarget.innerHTML = '';
                    contentTarget.appendChild(nextForm);
                    bindCreateForm();
                } else {
                    contentTarget.innerHTML = html;
                }
            })
            .catch(function () {
                showModalError('route-executor-create-modal-content', 'Ошибка отправки формы.');
            });
        });
    }

    function openCreateModal() {
        if (!createModal) return;
        if (typeof window.openCombinedRouteExecutorCreate === 'function') {
            window.openCombinedRouteExecutorCreate();
            return;
        }
        setModalLoading(createModal, 'route-executor-create-modal-content');
        var foot = document.getElementById('route-executor-create-modal-foot');
        if (foot) {
            foot.style.display = '';
            var note = foot.querySelector('.modal-required-note');
            var actions = foot.querySelector('.modal-foot-actions');
            if (note) note.style.display = '';
            if (actions) {
                actions.innerHTML = '<button type="button" class="btn btn-ghost" data-route-executor-create-close>\u041e\u0442\u043c\u0435\u043d\u0430</button><button type="submit" form="route-executor-create-form" class="btn btn-primary">\u0421\u043e\u0437\u0434\u0430\u0442\u044c \u0438\u0441\u043f\u043e\u043b\u043d\u0438\u0442\u0435\u043b\u044f \u0440\u0435\u0439\u0441\u0430</button>';
            }
        }
        fetch(baseUrl + '/company/route-executors/create', { credentials: 'same-origin' })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                var content = parseContent(html);
                var form = prepareCreateForm(content);
                var target = document.getElementById('route-executor-create-modal-content');
                if (!target) return;
                if (form) {
                    target.innerHTML = '';
                    target.appendChild(form);
                    bindCreateForm();
                } else {
                    target.innerHTML = '';
                    target.appendChild(cleanRouteExecutorContent(content));
                }
            })
            .catch(function () {
                showModalError('route-executor-create-modal-content', 'Не удалось загрузить форму создания.');
            });
    }

    function prepareEditForm(content, id) {
        var form = content.querySelector('#route-executor-edit-form') || content.querySelector('form[action*="/company/route-executors/"][action*="/edit"]');
        if (!form) return null;
        form.id = 'route-executor-edit-form';
        form.setAttribute('action', baseUrl + '/company/route-executors/' + id + '/edit');
        form.querySelectorAll('.form-actions').forEach(function (node) { node.remove(); });
        if (!form.querySelector('input[name="_is_modal"]')) {
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = '_is_modal';
            hidden.value = '1';
            form.appendChild(hidden);
        }
        return form;
    }

    function setViewFoot(html) {
        var modalInner = viewModal ? viewModal.querySelector('.modal') : null;
        if (!modalInner) return;
        var oldFoot = modalInner.querySelector('.modal-foot');
        if (oldFoot) oldFoot.remove();
        if (html) {
            modalInner.insertAdjacentHTML('beforeend', html);
        }
    }

    function openViewModal(id) {
        if (!viewModal || !id) return;
        viewModal.dataset.routeExecutorId = id;
        var inner = document.getElementById('route-executor-view-modal-inner');
        if (inner) inner.classList.add('is-view-mode');
        setModalLoading(viewModal, 'route-executor-view-modal-content');
        setViewFoot('');
        fetch(baseUrl + '/company/route-executors/' + id, { credentials: 'same-origin' })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                var content = cleanRouteExecutorContent(parseContent(html));
                var target = document.getElementById('route-executor-view-modal-content');
                if (!target) return;
                var viewCard = content.querySelector('[data-route-executor-view-card]');
                var fragment;
                if (viewCard) {
                    var panel = viewCard.closest('.panel');
                    fragment = panel || viewCard;
                } else {
                    fragment = content;
                }
                fragment.querySelectorAll('[data-re-section-actions]').forEach(function (section) {
                    section.remove();
                });
                target.innerHTML = '';
                target.appendChild(fragment);
                setViewFoot('<div class="modal-foot is-spaced"><div><button type="button" class="btn btn-danger" data-route-executor-delete-btn="' + id + '">Удалить</button></div><div class="modal-foot-actions"><button type="button" class="btn btn-ghost" data-route-executor-view-close>\u0417\u0430\u043a\u0440\u044b\u0442\u044c</button><button type="button" class="btn btn-primary" data-route-executor-edit-btn="' + id + '">\u0420\u0435\u0434\u0430\u043a\u0442\u0438\u0440\u043e\u0432\u0430\u0442\u044c</button></div></div>');
            })
            .catch(function () {
                showModalError('route-executor-view-modal-content', 'Не удалось загрузить карточку исполнителя рейса.');
            });
    }

    function openEditModal(id) {
        if (!viewModal || !id) return;
        viewModal.dataset.routeExecutorId = id;
        var inner = document.getElementById('route-executor-view-modal-inner');
        if (inner) inner.classList.remove('is-view-mode');
        setModalLoading(viewModal, 'route-executor-view-modal-content');
        setViewFoot('<div class="modal-foot is-spaced"><div class="modal-required-note"><span class="req">*</span> \u2014 \u043e\u0431\u044f\u0437\u0430\u0442\u0435\u043b\u044c\u043d\u044b\u0435 \u043f\u043e\u043b\u044f</div><div class="modal-foot-actions"><button type="button" class="btn btn-ghost" data-route-executor-view-close>\u041e\u0442\u043c\u0435\u043d\u0430</button><button type="submit" form="route-executor-edit-form" class="btn btn-primary">\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c</button></div></div>');
        fetch(baseUrl + '/company/route-executors/' + id + '/edit', { credentials: 'same-origin' })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                var content = parseContent(html);
                var form = prepareEditForm(content, id);
                var target = document.getElementById('route-executor-view-modal-content');
                if (!target) return;
                target.innerHTML = '';
                if (form) {
                    target.appendChild(form);
                    bindEditForm(id);
                } else {
                    var cleaned = cleanRouteExecutorContent(content);
                    var editForm = cleaned.querySelector('#route-executor-edit-form');
                    var panel = editForm || cleaned.querySelector('.panel');
                    target.appendChild(panel || cleaned);
                }
            })
            .catch(function () {
                showModalError('route-executor-view-modal-content', 'Не удалось загрузить форму редактирования.');
            });
    }

    function bindEditForm(id) {
        var form = viewModal ? viewModal.querySelector('#route-executor-edit-form') : null;
        if (!form || form.dataset.routeExecutorEditReady === '1') return;
        form.dataset.routeExecutorEditReady = '1';
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            fetch(form.getAttribute('action'), {
                method: 'POST',
                credentials: 'same-origin',
                body: new FormData(form)
            })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                if (html.indexOf('\u0418\u0441\u043f\u043e\u043b\u043d\u0438\u0442\u0435\u043b\u044c \u0440\u0435\u0439\u0441\u0430 \u0443\u0441\u043f\u0435\u0448\u043d\u043e \u043e\u0431\u043d\u043e\u0432\u043b\u0451\u043d') !== -1) {
                    viewModal.dataset.refreshOnClose = '1';
                    openViewModal(id);
                    return;
                }
                var content = parseContent(html);
                var nextForm = prepareEditForm(content, id);
                var target = document.getElementById('route-executor-view-modal-content');
                if (!target) return;
                target.innerHTML = '';
                if (nextForm) {
                    target.appendChild(nextForm);
                    bindEditForm(id);
                } else {
                    target.innerHTML = '';
                    target.appendChild(cleanRouteExecutorContent(content));
                }
            })
            .catch(function () {
                showModalError('route-executor-view-modal-content', 'Не удалось сохранить изменения.');
            });
        });
    }

    document.querySelectorAll('[data-route-executor-create-btn]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            openCreateModal();
        });
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-route-executor-create-close]')) {
            event.preventDefault();
            // Closing an unfinished form must not reload or rebuild the list.
            if (createModal) createModal.classList.remove('is-open');
            return;
        }
        if (event.target.closest('[data-route-executor-view-close]')) {
            event.preventDefault();
            if (viewModal && viewModal.dataset.refreshOnClose === '1') {
                window.location.reload();
                return;
            }
            if (viewModal) viewModal.classList.remove('is-open');
            return;
        }
        var editBtn = event.target.closest('[data-route-executor-edit-btn]');
        if (editBtn) {
            event.preventDefault();
            openEditModal(editBtn.getAttribute('data-route-executor-edit-btn'));
        }
        var editLink = event.target.closest('[data-route-executor-edit-link]');
        if (editLink) {
            event.preventDefault();
            openEditModal(editLink.getAttribute('data-route-executor-edit-link'));
        }
    });

    var tableBody = document.querySelector('[data-route-executors-grid] tbody');
    if (tableBody) {
        tableBody.addEventListener('dblclick', function (event) {
            if (event.target.closest('a, button, input, select, textarea, label')) return;
            var row = event.target.closest('tr[data-route-executor-id]');
            if (!row) return;
            openViewModal(row.getAttribute('data-route-executor-id'));
        });
    }
})();

// ============================================================
// Bank transaction detail modal (double-click on row)
// ============================================================
(function () {
    var txModal = document.getElementById('tx-detail-modal');
    if (!txModal) return;

    var closeBtn = txModal.querySelector('[data-tx-modal-close]');
    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            window.closeModal('tx-detail-modal');
        });
    }

    txModal.addEventListener('click', function (e) {
        if (e.target === txModal) {
            window.closeModal('tx-detail-modal');
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && txModal.classList.contains('is-open')) {
            window.closeModal('tx-detail-modal');
        }
    });

    function openTxDetail(row) {
        var get = function (attr) {
            return row.getAttribute(attr) || '';
        };

        var dateVal = get('data-tx-date');
        var accountVal = get('data-tx-account');
        var cpartyVal = get('data-tx-cparty');
        var innVal = get('data-tx-inn');
        var cpartyAccountVal = get('data-tx-cparty-account');
        var bikVal = get('data-tx-bik');
        var docnumVal = get('data-tx-docnum');
        var typeVal = get('data-tx-type');
        var debitVal = get('data-tx-debit');
        var creditVal = get('data-tx-credit');
        var purposeVal = get('data-tx-purpose');

        var fmtDate = function (val) {
            if (!val || val === '\u2014') return '\u2014';
            var m = val.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (m) return m[3] + '.' + m[2] + '.' + m[1];
            return val;
        };

        var fmtAmount = function (val) {
            var num = parseFloat(val);
            return num > 0 ? num.toFixed(2).replace('.', ',').replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1 ') + ' \u20bd' : '\u2014';
        };

        var setText = function (sel, val) {
            var el = txModal.querySelector(sel);
            if (el) el.textContent = val;
        };

        setText('[data-tx-detail-date]', fmtDate(dateVal) || '\u2014');
        setText('[data-tx-detail-account]', accountVal || '\u2014');
        setText('[data-tx-detail-cparty]', cpartyVal || '\u2014');
        setText('[data-tx-detail-inn]', innVal || '\u2014');
        setText('[data-tx-detail-cparty-account]', cpartyAccountVal || '\u2014');
        setText('[data-tx-detail-bik]', bikVal || '\u2014');
        setText('[data-tx-detail-docnum]', docnumVal || '\u2014');
        setText('[data-tx-detail-type]', typeVal || '\u2014');
        setText('[data-tx-detail-debit]', fmtAmount(debitVal));
        setText('[data-tx-detail-credit]', fmtAmount(creditVal));
        setText('[data-tx-detail-purpose]', purposeVal || '\u2014');

        window.openModal('tx-detail-modal');
    }

    var txTable = document.querySelector('.bank-transactions-table');

    if (txTable) {
        var tbody = txTable.querySelector('tbody');
        if (tbody) {
            tbody.addEventListener('dblclick', function (event) {
                if (event.target.closest('a, button, input, select, textarea, label')) return;
                var row = event.target.closest('tr[data-tx-id]');
                if (!row) return;
                openTxDetail(row);
            });
        }
    }
})();
