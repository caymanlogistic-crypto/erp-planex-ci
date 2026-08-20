<?php
$demoRows = [
    [
        'number' => '№ 1234',
        'client' => 'ООО «Пример»',
        'sub' => 'Регулярные перевозки · основной заказчик',
        'status' => 'Активен',
        'status_class' => 'badge-ok',
        'amount' => '125 000 ₽',
        'date' => '20.08.2026',
        'manager' => 'Иван Иванов',
    ],
    [
        'number' => '№ 1235',
        'client' => 'ООО «Горизонт»',
        'sub' => 'Новый договор · проверка условий',
        'status' => 'На проверке',
        'status_class' => 'badge-neutral',
        'amount' => '89 500 ₽',
        'date' => '19.08.2026',
        'manager' => 'Анна Смирнова',
    ],
    [
        'number' => '№ 1236',
        'client' => 'ООО «Вектор Сервис»',
        'sub' => 'Длинное название и вторичная строка для проверки плотности',
        'status' => 'Просрочка',
        'status_class' => 'badge-danger',
        'amount' => '240 000 ₽',
        'date' => '18.08.2026',
        'manager' => 'Пётр Соколов',
    ],
];
?>
<link rel="stylesheet" href="<?= app_url('/assets/css/ui-pattern-lab.css') ?>?v=<?= filemtime(base_path('public/assets/css/ui-pattern-lab.css')) ?>">

<div class="ui-lab" data-ui-pattern-lab>
    <header class="ui-lab-hero">
        <div class="ui-lab-hero-copy">
            <div class="ui-lab-kicker">Internal design review · synthetic data only</div>
            <h1>ERP PLANEX — UI Pattern Lab V0</h1>
            <p>Живая Candidate Gallery для сравнения существующих ERP patterns. V0 ничего не утверждает и не меняет рабочие business screens.</p>
        </div>
        <div class="ui-lab-owner-state">
            <strong>OWNER approvals: 0</strong>
            Все P0 patterns остаются кандидатами до отдельного решения OWNER.
        </div>
    </header>

    <nav class="ui-lab-nav" aria-label="Навигация по UI Pattern Lab">
        <a href="#foundation">Foundation</a>
        <a href="#page-header">01 Page Header</a>
        <a href="#table">02 Table</a>
        <a href="#search">03 Search</a>
        <a href="#filters">04 Filters</a>
        <a href="#buttons">05 Buttons</a>
        <a href="#fields">06 Fields</a>
    </nav>

    <section class="ui-lab-section" id="foundation" data-ui-lab-section="foundation">
        <div class="ui-lab-section-head">
            <div>
                <div class="ui-lab-section-title">Current Foundation</div>
                <div class="ui-lab-section-note">Read-only reference from live ERP tokens; no theme editor.</div>
            </div>
            <span class="badge badge-neutral">REFERENCE</span>
        </div>
        <div class="ui-lab-section-body">
            <div class="ui-lab-foundation">
                <div class="ui-lab-swatch"><span class="ui-lab-swatch-chip is-accent"></span><div><strong>Primary</strong><span>--accent</span></div></div>
                <div class="ui-lab-swatch"><span class="ui-lab-swatch-chip is-background"></span><div><strong>Background</strong><span>--app-bg</span></div></div>
                <div class="ui-lab-swatch"><span class="ui-lab-swatch-chip is-surface"></span><div><strong>Surface</strong><span>--surface-strong</span></div></div>
                <div class="ui-lab-swatch"><span class="ui-lab-swatch-chip is-text"></span><div><strong>Text</strong><span>--text-main</span></div></div>
                <div class="ui-lab-swatch"><span class="ui-lab-swatch-chip is-muted"></span><div><strong>Muted</strong><span>--text-muted</span></div></div>
                <div class="ui-lab-swatch"><span class="ui-lab-swatch-chip is-border-hair"></span><div><strong>Border hair</strong><span>--line-hair</span></div></div>
                <div class="ui-lab-swatch"><span class="ui-lab-swatch-chip is-border-soft"></span><div><strong>Border soft</strong><span>--line-soft</span></div></div>
                <div class="ui-lab-swatch"><span class="ui-lab-swatch-chip is-success"></span><div><strong>Success</strong><span>--success</span></div></div>
                <div class="ui-lab-swatch"><span class="ui-lab-swatch-chip is-warning"></span><div><strong>Warning</strong><span>--warning</span></div></div>
                <div class="ui-lab-swatch"><span class="ui-lab-swatch-chip is-danger"></span><div><strong>Danger</strong><span>--danger</span></div></div>
            </div>
            <div class="ui-lab-foundation-facts">
                <span class="ui-lab-fact">IBM Plex Sans</span>
                <span class="ui-lab-fact">Main text 13px</span>
                <span class="ui-lab-fact">Page title 19px</span>
                <span class="ui-lab-fact">Base radius 2px</span>
                <span class="ui-lab-fact">Toolbar 26px</span>
                <span class="ui-lab-fact">Field 28px</span>
                <span class="ui-lab-fact">Button 30px</span>
                <span class="ui-lab-fact">Table row 32px</span>
            </div>
        </div>
    </section>

    <section class="ui-lab-section" id="page-header" data-ui-lab-section="page-header">
        <div class="ui-lab-section-head"><div><div class="ui-lab-section-title">01 · Page Header</div><div class="ui-lab-section-note">Сравнение текущих meaningful compositions.</div></div></div>
        <div class="ui-lab-section-body">
            <div class="ui-lab-meta">
                <div class="ui-lab-meta-main"><span class="ui-lab-pattern-id">UI-PAGE-HEADER-01</span><span class="badge badge-ok">STRONG CANDIDATE</span></div>
                <div class="ui-lab-meta-copy">
                    <div><strong>Source</strong><span>Clients / Drivers</span></div>
                    <div><strong>Allowed extensions</strong><span>Back action, contextual primary/secondary action.</span></div>
                    <div><strong>Observed drift</strong><span>Legacy markup exists, but without separate visual value.</span></div>
                </div>
            </div>
            <div class="ui-lab-compare">
                <article class="ui-lab-variant">
                    <div class="ui-lab-variant-head"><div><div class="ui-lab-variant-title">Variant A · Recommended future candidate</div><div class="ui-lab-variant-source">Clients / Drivers-style</div></div><span class="badge badge-ok">STRONG CANDIDATE</span></div>
                    <div class="ui-lab-canvas">
                        <?= ui_page_header('Клиенты', 'Заказчики перевозок и их реквизиты', '<button type="button" class="btn btn-primary">Создать клиента</button>') ?>
                    </div>
                </article>
                <article class="ui-lab-variant">
                    <div class="ui-lab-variant-head"><div><div class="ui-lab-variant-title">Variant B · Contextual composition</div><div class="ui-lab-variant-source">Documents-style back action</div></div><span class="badge badge-neutral">CANDIDATE</span></div>
                    <div class="ui-lab-canvas">
                        <div class="page-head">
                            <div class="page-head-left">
                                <a href="#page-header" class="btn btn-ghost back-action">← Назад к списку</a>
                                <h1 class="page-title">Документы: ООО «Пример»</h1>
                                <div class="page-summary"><span>Управление загруженными файлами</span></div>
                            </div>
                            <div class="page-head-actions"><button type="button" class="btn btn-primary">Загрузить документ</button></div>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section class="ui-lab-section" id="table" data-ui-lab-section="table">
        <div class="ui-lab-section-head"><div><div class="ui-lab-section-title">02 · Table</div><div class="ui-lab-section-note">Главный V0 comparison: одинаковый synthetic dataset во всех variants.</div></div></div>
        <div class="ui-lab-section-body">
            <div class="ui-lab-meta">
                <div class="ui-lab-meta-main"><span class="ui-lab-pattern-id">UI-TABLE-01</span><span class="badge badge-ok">STRONG CANDIDATE</span></div>
                <div class="ui-lab-meta-copy">
                    <div><strong>Recommended source</strong><span>Clients / Drivers table-card.</span></div>
                    <div><strong>Allowed extensions</strong><span>Business columns, contextual filters/actions, semantic row states.</span></div>
                    <div><strong>Do not copy</strong><span>Module-local radius, search geometry or alternate shell chrome.</span></div>
                </div>
            </div>

            <div class="ui-lab-stack">
                <article class="ui-lab-variant">
                    <div class="ui-lab-variant-head"><div><div class="ui-lab-variant-title">Variant A · .table-card + .table</div><div class="ui-lab-variant-source">Clients / Drivers / Linear Trips</div></div><span class="badge badge-ok">STRONG CANDIDATE</span></div>
                    <div class="ui-lab-canvas">
                        <div class="table-card table-card--standard" data-erp-grid>
                            <div class="table-toolbar">
                                <div class="found-label">Найдено: <b><?= count($demoRows) ?></b> записей</div>
                                <div class="toolbar-right">
                                    <select class="toolbar-select" aria-label="Сортировка"><option>По дате добавления</option><option>По алфавиту</option></select>
                                    <input type="text" class="toolbar-search" value="" placeholder="Поиск по таблице" aria-label="Поиск по таблице">
                                </div>
                            </div>
                            <div class="table-scroll">
                                <table class="table">
                                    <thead><tr><th>№</th><th>Клиент</th><th>Статус</th><th>Сумма</th><th>Дата</th><th>Менеджер</th><th class="col-action"></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($demoRows as $index => $row): ?>
                                        <tr<?= $index === 0 ? ' data-ui-lab-hover-row' : '' ?><?= $index === 1 ? ' class="is-selected"' : ($index === 2 ? ' class="ux-row-overdue"' : '') ?>>
                                            <td class="col-mono"><?= e($row['number']) ?></td>
                                            <td class="cell-double"><span class="cell-main"><?= e($row['client']) ?></span><span class="cell-sub"><?= e($row['sub']) ?></span></td>
                                            <td><span class="badge <?= e($row['status_class']) ?>"><span class="dot"></span><?= e($row['status']) ?></span></td>
                                            <td class="col-num col-mono"><?= e($row['amount']) ?></td>
                                            <td class="col-mono"><?= e($row['date']) ?></td>
                                            <td><?= e($row['manager']) ?></td>
                                            <td class="col-action"><button type="button" class="row-btn" aria-label="Действия">···</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="table-footer"><span class="footer-label">Показано <b>1–3</b> из <b>3</b></span></div>
                        </div>
                        <div class="pagination-wrap mt-2">
                            <div class="pagination-left"><span class="pg-info">Страница <b>1</b> из <b>4</b></span></div>
                            <div class="pagination"><button type="button" class="pg-btn is-arrow" disabled>‹</button><button type="button" class="pg-btn is-active">1</button><button type="button" class="pg-btn">2</button><button type="button" class="pg-btn">3</button><span class="pg-dots">…</span><button type="button" class="pg-btn">4</button><button type="button" class="pg-btn is-arrow">›</button></div>
                        </div>
                        <div class="panel mt-2"><div class="empty-state is-compact"><p class="empty-title">Записей пока нет</p><p class="empty-desc">Empty state остаётся частью того же registry pattern.</p></div></div>
                    </div>
                </article>

                <article class="ui-lab-variant">
                    <div class="ui-lab-variant-head"><div><div class="ui-lab-variant-title">Variant B · .panel + .tbl</div><div class="ui-lab-variant-source">Superadmin / Documents</div></div><span class="badge badge-warning">OBSERVED LEGACY</span></div>
                    <div class="ui-lab-canvas">
                        <div class="panel">
                            <div class="panel-head"><h3 class="panel-head-title">Клиенты</h3><span class="badge badge-neutral">3 записи</span></div>
                            <div class="tbl-wrap"><table class="tbl"><thead><tr><th>№</th><th>Клиент</th><th>Статус</th><th class="col-num">Сумма</th><th>Дата</th><th>Менеджер</th></tr></thead><tbody>
                            <?php foreach ($demoRows as $row): ?><tr><td class="col-mono"><?= e($row['number']) ?></td><td class="cell-double"><span class="cell-main"><?= e($row['client']) ?></span><span class="cell-sub"><?= e($row['sub']) ?></span></td><td><span class="badge <?= e($row['status_class']) ?>"><span class="dot"></span><?= e($row['status']) ?></span></td><td class="col-num col-mono"><?= e($row['amount']) ?></td><td class="col-mono"><?= e($row['date']) ?></td><td><?= e($row['manager']) ?></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                    </div>
                </article>

                <article class="ui-lab-variant">
                    <div class="ui-lab-variant-head"><div><div class="ui-lab-variant-title">Variant C · .ux-table + .table</div><div class="ui-lab-variant-source">Finance report workspace</div></div><span class="badge badge-neutral">CANDIDATE</span></div>
                    <div class="ui-lab-canvas">
                        <div class="ux-table">
                            <div class="ux-table-head"><div><div class="ux-table-title">Клиенты</div><div class="ux-table-meta">Report/workspace composition around the same table rows.</div></div><div class="ux-table-meta">Показано: 3</div></div>
                            <div class="table-scroll"><table class="table"><thead><tr><th>№</th><th>Клиент</th><th>Статус</th><th>Сумма</th><th>Дата</th><th>Менеджер</th></tr></thead><tbody>
                            <?php foreach ($demoRows as $row): ?><tr><td class="col-mono"><?= e($row['number']) ?></td><td><div class="ux-primary-cell"><strong><?= e($row['client']) ?></strong><span><?= e($row['sub']) ?></span></div></td><td><span class="badge <?= e($row['status_class']) ?>"><span class="dot"></span><?= e($row['status']) ?></span></td><td class="col-num col-mono"><?= e($row['amount']) ?></td><td class="col-mono"><?= e($row['date']) ?></td><td><?= e($row['manager']) ?></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                    </div>
                </article>

                <article class="ui-lab-variant">
                    <div class="ui-lab-variant-head"><div><div class="ui-lab-variant-title">Base + business extension</div><div class="ui-lab-variant-source">Same UI-TABLE-01 shell, business-specific payment cell</div></div><span class="badge badge-neutral">CANDIDATE EXAMPLE</span></div>
                    <div class="ui-lab-canvas">
                        <div class="table-card table-card--standard">
                            <div class="table-toolbar"><div class="found-label">Найдено: <b>1</b> счёт</div><div class="toolbar-right"><input class="toolbar-search" type="text" placeholder="Поиск по таблице" aria-label="Поиск по таблице"></div></div>
                            <div class="table-scroll"><table class="table"><thead><tr><th>№</th><th>Клиент</th><th>Сумма</th><th>Этапы оплаты</th></tr></thead><tbody><tr><td class="col-mono">№ 1234</td><td>ООО «Пример»</td><td class="col-num col-mono">125 000 ₽</td><td class="cell-double"><span class="cell-main">1 платёж · 75 000 ₽ <span class="badge badge-ok">Оплачен</span></span><span class="cell-sub">2 платёж · 50 000 ₽ · ожидается до 25.08</span></td></tr></tbody></table></div>
                            <div class="table-footer"><span class="footer-label">Business extension не меняет базовый shell</span></div>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section class="ui-lab-section" id="search" data-ui-lab-section="search">
        <div class="ui-lab-section-head"><div><div class="ui-lab-section-title">03 · Search</div><div class="ui-lab-section-note">Registry density vs report density vs observed local drift.</div></div></div>
        <div class="ui-lab-section-body">
            <div class="ui-lab-meta">
                <div class="ui-lab-meta-main"><span class="ui-lab-pattern-id">UI-SEARCH-01</span><span class="badge badge-ok">STRONG CANDIDATE</span></div>
                <div class="ui-lab-meta-copy"><div><strong>Recommended</strong><span>26px toolbar search for registries.</span></div><div><strong>Legitimate extension</strong><span>Report search may live in a taller filter panel.</span></div><div><strong>Drift</strong><span>Finance Operations-like 34px local geometry is shown only for comparison.</span></div></div>
            </div>
            <div class="ui-lab-compare cols-3">
                <article class="ui-lab-variant"><div class="ui-lab-variant-head"><div><div class="ui-lab-variant-title">A · Registry toolbar</div><div class="ui-lab-variant-source">26px</div></div><span class="badge badge-ok">STRONG CANDIDATE</span></div><div class="ui-lab-canvas is-plain"><div class="table-toolbar"><div class="found-label">Найдено: <b>3</b></div><div class="toolbar-right"><input class="toolbar-search" type="text" placeholder="Поиск по таблице" aria-label="Registry search"></div></div></div></article>
                <article class="ui-lab-variant"><div class="ui-lab-variant-head"><div><div class="ui-lab-variant-title">B · Report search</div><div class="ui-lab-variant-source">Filter-panel density</div></div><span class="badge badge-neutral">CANDIDATE</span></div><div class="ui-lab-canvas is-plain"><div class="ux-filter-panel"><div class="ux-filter-body"><div class="ux-filter-field is-search"><label>Поиск</label><input class="field-input" type="search" placeholder="Название клиента"></div></div></div></div></article>
                <article class="ui-lab-variant"><div class="ui-lab-variant-head"><div><div class="ui-lab-variant-title">C · Local 34px variant</div><div class="ui-lab-variant-source">Finance Operations-like</div></div><span class="badge badge-danger">OBSERVED DRIFT</span></div><div class="ui-lab-canvas is-plain"><input class="toolbar-search toolbar-search--ops" type="text" placeholder="Контрагент или назначение" aria-label="Observed drift search"></div></article>
            </div>
        </div>
    </section>

    <section class="ui-lab-section" id="filters" data-ui-lab-section="filters">
        <div class="ui-lab-section-head"><div><div class="ui-lab-section-title">04 · Filters</div><div class="ui-lab-section-note">Два разных будущих pattern candidates, а не один universal filter.</div></div></div>
        <div class="ui-lab-section-body">
            <div class="ui-lab-compare">
                <article class="ui-lab-variant">
                    <div class="ui-lab-variant-head"><div><div class="ui-lab-variant-title">UI-FILTER-BAR-01</div><div class="ui-lab-variant-source">Compact registry filters</div></div><span class="badge badge-neutral">CANDIDATE</span></div>
                    <div class="ui-lab-canvas is-plain">
                        <div class="filters-bar">
                            <div class="filter-select-wrap"><select class="field-select" aria-label="Статус"><option>Статус: Все</option><option>Активен</option></select></div>
                            <div class="filter-date-wrap"><input type="date" value="2026-08-20" aria-label="Период"></div>
                            <div class="filter-select-wrap"><select class="field-select" aria-label="Менеджер"><option>Менеджер: Все</option><option>Иван Иванов</option></select></div>
                            <div class="filter-search"><input class="field-input" type="search" placeholder="Поиск" aria-label="Поиск"><span class="filter-search-icon">⌕</span></div>
                            <button type="button" class="btn btn-toolbar">Применить</button>
                        </div>
                    </div>
                </article>
                <article class="ui-lab-variant">
                    <div class="ui-lab-variant-head"><div><div class="ui-lab-variant-title">UI-FILTER-PANEL-01</div><div class="ui-lab-variant-source">Complex report filters</div></div><span class="badge badge-neutral">CANDIDATE</span></div>
                    <div class="ui-lab-canvas is-plain">
                        <div class="ux-filter-panel">
                            <div class="ux-filter-head"><div class="ux-filter-title">Параметры отчёта</div><button type="button" class="btn btn-ghost btn-sm">Сбросить</button></div>
                            <div class="ux-filter-body">
                                <div class="ux-filter-field"><label>Статус</label><select class="field-select"><option>Все</option><option>Активен</option></select></div>
                                <div class="ux-filter-field"><label>Период</label><input class="field-input" type="date" value="2026-08-20"></div>
                                <div class="ux-filter-field"><label>Менеджер</label><select class="field-select"><option>Все</option><option>Иван Иванов</option></select></div>
                                <div class="ux-filter-field is-search"><label>Поиск</label><input class="field-input" type="search" placeholder="Название клиента"></div>
                                <div class="ux-filter-actions"><button type="button" class="btn btn-primary">Сформировать</button></div>
                            </div>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section class="ui-lab-section" id="buttons" data-ui-lab-section="buttons">
        <div class="ui-lab-section-head"><div><div class="ui-lab-section-title">05 · Buttons</div><div class="ui-lab-section-note">Existing system family only; no new button design.</div></div></div>
        <div class="ui-lab-section-body">
            <div class="ui-lab-meta"><div class="ui-lab-meta-main"><span class="ui-lab-pattern-id">UI-BUTTON-01</span><span class="badge badge-ok">STRONG CANDIDATE</span></div><div class="ui-lab-meta-copy"><div><strong>Source</strong><span>erp-ui.css button family.</span></div><div><strong>States</strong><span>Normal, real hover/focus in browser QA, disabled.</span></div><div><strong>UI GAP</strong><span>Dedicated loading-button state is not standardised in current P0 system.</span></div></div></div>
            <div class="ui-lab-variant"><div class="ui-lab-variant-head"><div><div class="ui-lab-variant-title">Existing button family</div><div class="ui-lab-variant-source">Interactive demo</div></div><span class="badge badge-ok">STRONG CANDIDATE</span></div><div class="ui-lab-canvas is-plain"><div class="ui-lab-button-grid">
                <button type="button" class="btn btn-primary">Primary</button>
                <button type="button" class="btn btn-secondary">Secondary</button>
                <button type="button" class="btn btn-ghost">Ghost</button>
                <button type="button" class="btn btn-danger">Danger</button>
                <button type="button" class="btn btn-toolbar">Toolbar</button>
                <button type="button" class="btn btn-icon" aria-label="Icon action">⋯</button>
                <button type="button" class="btn btn-secondary btn-sm">Compact</button>
                <button type="button" class="btn btn-primary" disabled>Disabled</button>
                <button type="button" class="btn btn-primary" id="ui-lab-focus-button">Focus state</button>
                <button type="button" class="btn btn-primary" id="ui-lab-hover-button">Hover state</button>
            </div><div class="ui-lab-gap mt-2">UI GAP · Loading button не стандартизирован в текущем P0 contract, поэтому новый visual state здесь не придумывается.</div></div></div>
        </div>
    </section>

    <section class="ui-lab-section" id="fields" data-ui-lab-section="fields">
        <div class="ui-lab-section-head"><div><div class="ui-lab-section-title">06 · Fields</div><div class="ui-lab-section-note">Existing input/select/textarea states + intentional density hierarchy.</div></div></div>
        <div class="ui-lab-section-body">
            <div class="ui-lab-meta"><div class="ui-lab-meta-main"><span class="ui-lab-pattern-id">UI-FIELD-01</span><span class="badge badge-ok">STRONG CANDIDATE</span></div><div class="ui-lab-meta-copy"><div><strong>Source</strong><span>.field + .field-input/.field-select/.field-textarea.</span></div><div><strong>Validation</strong><span>Error, warning, success, readonly, disabled already exist.</span></div><div><strong>Density</strong><span>Toolbar 26 → field 28 → button 30 → row 32px.</span></div></div></div>
            <div class="ui-lab-field-grid">
                <div class="field"><label class="field-label">Текст <span class="req">*</span></label><input class="field-input" type="text" value="ООО «Пример»" required><div class="field-msg">Обязательное поле</div></div>
                <div class="field"><label class="field-label">Число</label><input class="field-input" type="number" value="125000"><div class="field-msg">Сумма без форматирования</div></div>
                <div class="field"><label class="field-label">Select</label><select class="field-select"><option>Активен</option><option>На проверке</option></select><div class="field-msg">Системный select</div></div>
                <div class="field"><label class="field-label">Дата</label><input class="field-input" type="date" value="2026-08-20"><div class="field-msg">Дата</div></div>
                <div class="field"><label class="field-label">Readonly</label><input class="field-input" type="text" value="Системное значение" readonly><div class="field-msg">Только чтение</div></div>
                <div class="field"><label class="field-label">Disabled</label><input class="field-input" type="text" value="Недоступно" disabled><div class="field-msg">Недоступно для изменения</div></div>
                <div class="field is-error"><label class="field-label">Error</label><input class="field-input" type="text" value="Некорректное значение"><div class="field-msg">Проверьте значение поля</div></div>
                <div class="field is-warning"><label class="field-label">Warning</label><input class="field-input" type="text" value="Требует проверки"><div class="field-msg">Нужна дополнительная проверка</div></div>
                <div class="field is-success"><label class="field-label">Success</label><input class="field-input" type="text" value="Проверено"><div class="field-msg">Значение принято</div></div>
                <div class="field"><label class="field-label">Textarea</label><textarea class="field-textarea" rows="3">Комментарий к демонстрационной записи.</textarea><div class="field-msg">Helper message под полем</div></div>
            </div>

            <div class="ui-lab-density-grid">
                <div class="ui-lab-density-item"><div class="ui-lab-density-label"><span>Toolbar control</span><strong>26px</strong></div><input class="toolbar-search" type="text" placeholder="Поиск"></div>
                <div class="ui-lab-density-item"><div class="ui-lab-density-label"><span>Standard field</span><strong>28px</strong></div><input class="field-input" type="text" value="Поле формы"></div>
                <div class="ui-lab-density-item"><div class="ui-lab-density-label"><span>Primary button</span><strong>30px</strong></div><button type="button" class="btn btn-primary">Действие</button></div>
                <div class="ui-lab-density-item"><div class="ui-lab-density-label"><span>Table row</span><strong>32px</strong></div><table class="table"><tbody><tr><td>Строка таблицы</td></tr></tbody></table></div>
            </div>
        </div>
    </section>
</div>
