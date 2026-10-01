<?php
$dailyBalances = $dailyBalances ?? [];
$summaryAccount = $accounts[0] ?? null;
$summaryBalance = $summaryAccount !== null
    ? ($summaryAccount['effective_balance'] ?? 0)
    : 0;
$summaryLastDate = $summaryAccount['last_balance_date']
    ?? $summaryAccount['last_transaction_date']
    ?? '—';
$summaryRows = count($transactions);

$fmtDate = function ($d) { return ($d && $d !== '—') ? date('d.m.Y', is_numeric(strtotime($d)) ? strtotime($d) : time()) : '—'; };
$fmtDateTime = function ($d) { return ($d && $d !== '—') ? date('d.m.Y H:i:s', is_numeric(strtotime($d)) ? strtotime($d) : time()) : '—'; };

$fmtMoney = function ($v, $allowZero = false) {
    if ($v === null || $v === '' || $v === false) return null;
    $v = (string)$v;
    $negative = $v[0] === '-';
    if ($negative) $v = substr($v, 1);
    $clean = ltrim(str_replace('.', '', $v), '0');
    if ($clean === '') {
        return $allowZero ? '0,00' : null;
    }
    $parts = explode('.', $v, 2);
    $intPart = $parts[0];
    $decPart = isset($parts[1]) ? str_pad(substr($parts[1], 0, 2), 2, '0') : '00';
    $len = strlen($intPart);
    $result = '';
    for ($i = 0; $i < $len; $i++) {
        if ($i > 0 && ($len - $i) % 3 === 0) {
            $result .= ' ';
        }
        $result .= $intPart[$i];
    }
    return ($negative ? '-' : '') . $result . ',' . $decPart;
};

$deleteImportUrl = app_url('/company/finance/bank-accounts/import/delete');
?>
<script>window._bankDeleteImportUrl = '<?= $deleteImportUrl ?>';</script>

<?php if ($company === null): ?>

<div class="notice warn">
    Компания не найдена. Укажите корректный company_id.
</div>

<?php elseif ($company['status'] !== 'active'): ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Выписки со счёта</h1>
        <div class="page-summary"><span>Загрузка, просмотр и автоматическое разнесение банковских операций</span></div>
    </div>
</div>

<div class="notice warn">
    Компания находится в статусе «<?= e($company['status']) ?>». Работа с банковскими счетами недоступна.
</div>

<?php elseif (isset($dbError)): ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Выписки со счёта</h1>
        <div class="page-summary"><span>Загрузка, просмотр и автоматическое разнесение банковских операций</span></div>
    </div>
</div>

<div class="notice warn">
    <?= e($dbError) ?>
</div>

<?php elseif (empty($accounts) && empty($transactions) && empty($dailyBalances)): ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Выписки со счёта</h1>
        <div class="page-summary"><span>Загрузка, просмотр и автоматическое разнесение банковских операций</span></div>
    </div>
    <div class="page-head-actions">
        <button type="button" class="btn btn-secondary" data-open-modal="bank-statement-upload-modal">Загрузить выписку XLSX</button>
        <button type="button" class="btn btn-secondary" data-open-modal="bank-statements-modal">Просмотр выписок</button>
        <form method="post" action="<?= app_url('/company/finance/bank-accounts/refresh-from-mail') ?>" class="inline-form"><?= csrfField() ?><button type="submit" class="btn btn-secondary">Обновить из почты</button></form>
        <a href="<?= app_url('/company/finance/settings/matching-rules') ?>" class="btn btn-secondary">Правила разнесения</a>
        <form method="post" action="<?= app_url('/company/finance/bank-accounts/apply-rules') ?>" class="inline-form" onsubmit="return confirm('Применить действующие правила ко всем неразнесённым банковским операциям? Ручные разнесения не изменятся.');"><?= csrfField() ?><button type="submit" class="btn btn-primary">Разнести по правилам</button></form>
    </div>
</div>

<div class="panel">
    <div class="empty-state">
        <p class="empty-title">Выписки со счёта ещё не добавлены.</p>
        <p class="empty-desc">Загрузите выписку из банка в формате XLSX, чтобы начать работу с финансовым модулем.</p>
    </div>
</div>

<?php else: ?>

<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Выписки со счёта</h1>
        <div class="page-summary"><span>Загрузка, просмотр и автоматическое разнесение банковских операций</span></div>
    </div>
    <div class="page-head-actions">
        <button type="button" class="btn btn-secondary" data-open-modal="bank-statement-upload-modal">Загрузить выписку XLSX</button>
        <button type="button" class="btn btn-secondary" data-open-modal="bank-statements-modal">Просмотр выписок</button>
        <form method="post" action="<?= app_url('/company/finance/bank-accounts/refresh-from-mail') ?>" class="inline-form"><?= csrfField() ?><button type="submit" class="btn btn-secondary">Обновить из почты</button></form>
        <a href="<?= app_url('/company/finance/settings/matching-rules') ?>" class="btn btn-secondary">Правила разнесения</a>
        <form method="post" action="<?= app_url('/company/finance/bank-accounts/apply-rules') ?>" class="inline-form" onsubmit="return confirm('Применить действующие правила ко всем неразнесённым банковским операциям? Ручные разнесения не изменятся.');"><?= csrfField() ?><button type="submit" class="btn btn-primary">Разнести по правилам</button></form>
    </div>
</div>

<div class="table-card table-card--standard bank-finance-card bank-transactions-card">
    <div class="bank-controls">
        <div class="bank-controls-summary">
            <?php if (!empty($accounts)): ?>
                <span class="bank-summary-label">Счёт</span>
                <span class="bank-summary-account col-mono"><?= e($summaryAccount['account_number'] ?? '') ?></span>
                <span class="bank-summary-separator">·</span>
                <span class="bank-summary-label">Баланс</span>
                <span class="bank-summary-balance"><?= ($fmtMoney($summaryBalance, true) ?? '—') ?> ₽</span>
                <span class="bank-summary-separator">·</span>
                <span><?= (int)($summaryAccount['transaction_count'] ?? 0) ?> операций</span>
                <span class="bank-summary-separator">·</span>
                <span><?= count($dailyBalances) ?> выписок с остатками</span>
                <span class="bank-summary-separator">·</span>
                <span>последняя: <?= $fmtDate($summaryLastDate) ?></span>
                <?php if (count($accounts) > 1): ?>
                <span class="bank-summary-separator">·</span>
                <span>+<?= count($accounts) - 1 ?> счета</span>
<?php endif; ?>
        <?php endif; ?>
        </div>
        <form method="get" class="bank-controls-filter" action="">
            <label class="bank-date-field">
                <span class="bank-control-label">Дата с</span>
                <input type="date" name="date_from" class="bank-date-input" value="<?= e($_GET['date_from'] ?? '') ?>">
            </label>
            <label class="bank-date-field">
                <span class="bank-control-label">Дата по</span>
                <input type="date" name="date_to" class="bank-date-input" value="<?= e($_GET['date_to'] ?? '') ?>">
            </label>
            <button type="submit" class="btn btn-secondary btn-toolbar">Применить</button>
            <?php if (!empty($_GET['date_from']) || !empty($_GET['date_to'])): ?>
            <a href="<?= app_url('/company/finance/bank-accounts') ?>" class="btn btn-ghost btn-toolbar">Сброс</a>
<?php endif; ?>
        </form>
        <div class="bank-controls-right">
            <input type="text" class="toolbar-search" placeholder="Поиск по таблице">
        </div>
    </div>
    <?php if (!empty($transactions)): ?>
    <div class="bank-tx-scroll">
        <table class="table bank-transactions-table">
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Счёт</th>
                    <th class="col-cparty-narrow">Контрагент</th>
                    <th class="col-mono">ИНН</th>
                    <th class="col-purpose-wide">Назначение</th>
                    <th class="col-tight">Дебет</th>
                    <th class="col-tight">Кредит</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $tx): ?>
                <tr data-tx-id="<?= (int)($tx['id'] ?? 0) ?>"
                    data-tx-date="<?= e($tx['operation_date'] ?? '') ?>"
                    data-tx-account="<?= e($tx['account_number'] ?? '') ?>"
                    data-tx-cparty="<?= e($tx['counterparty_name'] ?? '') ?>"
                    data-tx-inn="<?= e($tx['counterparty_inn'] ?? '') ?>"
                    data-tx-cparty-account="<?= e($tx['counterparty_account'] ?? '') ?>"
                    data-tx-bik="<?= e($tx['counterparty_bank_bik'] ?? '') ?>"
                    data-tx-docnum="<?= e($tx['document_number'] ?? '') ?>"
                    data-tx-type="<?= e($tx['operation_type'] ?? '') ?>"
                    data-tx-debit="<?= e($tx['debit_amount'] ?? '0') ?>"
                    data-tx-credit="<?= e($tx['credit_amount'] ?? '0') ?>"
                    data-tx-purpose="<?= e($tx['purpose'] ?? '') ?>">
                    <td class="col-mono"><?= $fmtDate($tx['operation_date'] ?? '') ?></td>
                    <td class="col-mono"><?= e($tx['account_number'] ?? '—') ?></td>
                    <td class="col-cparty-narrow" title="<?= e($tx['counterparty_name'] ?? '') ?>"><?= e($tx['counterparty_name'] ?: '—') ?></td>
                    <td class="col-mono"><?= e($tx['counterparty_inn'] ?: '—') ?></td>
                    <td class="col-purpose-wide" title="<?= e($tx['purpose'] ?? '') ?>"><?= e($tx['purpose'] ?: '—') ?></td>
                    <td class="col-mono text-danger col-tight<?= $fmtMoney($tx['debit_amount']) !== null ? '' : ' is-dash' ?>"><?= $fmtMoney($tx['debit_amount']) ?? '—' ?></td>
                    <td class="col-mono text-success col-tight<?= $fmtMoney($tx['credit_amount']) !== null ? '' : ' is-dash' ?>"><?= $fmtMoney($tx['credit_amount']) ?? '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state compact">
        <p>Операций за выбранный период не найдено.</p>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($accounts) && isset($reconciliation)): ?>
<div class="panel-section">
    <div class="section-title">Независимая сверка выписок</div>
    <?php $reconSummary = $reconciliation['summary'] ?? []; ?>
    <div class="bank-controls">
        <div class="bank-controls-summary">
            <span class="bank-summary-label">Статус:</span>
            <span class="bank-summary-balance">
                <?php if ($reconSummary['service_error'] ?? false): ?>
                <span class="badge badge-danger"><span class="dot"></span> Ошибка проверки</span>
                <?php elseif ($reconSummary['all_ok'] ?? false): ?>
                <span class="badge badge-ok"><span class="dot"></span> OK</span>
                <?php else: ?>
                <span class="badge badge-danger"><span class="dot"></span> Есть расхождения</span>
                <?php endif; ?>
            </span>
            <span class="bank-summary-separator">·</span>
            <span>OK: <?= (int)($reconSummary['ok'] ?? 0) ?></span>
            <?php if (($reconSummary['invalid_arithmetic'] ?? 0) > 0): ?>
            <span class="bank-summary-separator">·</span>
            <span class="text-danger">Ошибок арифметики: <?= (int)$reconSummary['invalid_arithmetic'] ?></span>
            <?php endif; ?>
            <?php if (($reconSummary['gap'] ?? 0) > 0): ?>
            <span class="bank-summary-separator">·</span>
            <span class="text-danger">Разрывов: <?= (int)$reconSummary['gap'] ?></span>
            <?php endif; ?>
            <?php if (($reconSummary['duplicate'] ?? 0) > 0): ?>
            <span class="bank-summary-separator">·</span>
            <span class="text-danger">Дубликатов: <?= (int)$reconSummary['duplicate'] ?></span>
            <?php endif; ?>
            <?php if (($reconSummary['mismatch'] ?? 0) > 0): ?>
            <span class="bank-summary-separator">·</span>
            <span class="text-danger">Несовпадений оборотов: <?= (int)$reconSummary['mismatch'] ?></span>
            <?php endif; ?>
            <span class="bank-summary-separator">·</span>
            <span>Без выписок: <?= (int)($reconSummary['no_statement'] ?? 0) ?></span>
        </div>
        <div class="bank-controls-right">
            <button type="button" class="btn btn-secondary btn-toolbar" onclick="window.openModal('bank-reconciliation-modal')">Детали сверки</button>
        </div>
    </div>
    <?php foreach ($reconciliation['accounts'] as $rAcc): ?>
    <?php $bal = $rAcc['current_balance'] ?? []; ?>
    <div class="mt-section">
        <div class="field bank-recon-row">
            <span class="col-mono bank-recon-account-num"><?= e($rAcc['account_number']) ?></span>
            <span class="badge <?= $rAcc['status'] === 'OK' ? 'badge-ok' : ($rAcc['status'] === 'NO_STATEMENT' ? 'badge-neutral' : 'badge-danger') ?>">
                <span class="dot"></span> <?= $rAcc['status'] === 'OK' ? 'OK' : ($rAcc['status'] === 'NO_STATEMENT' ? 'Нет выписок' : $rAcc['status']) ?>
            </span>
            <span class="bank-recon-spacer">
                <span class="bank-summary-label">Баланс (расчётный):</span>
                <span class="bank-summary-balance"><?= $fmtMoney($bal['current_calculated_balance'] ?? '0.00', true) ?> ₽</span>
            </span>
        </div>
        <?php if ($bal['snapshot_date'] ?? null): ?>
        <div class="bank-recon-detail">
            <span>Выписка: <?= $fmtDate($bal['snapshot_date']) ?></span>
            <span>Остаток по выписке: <?= $fmtMoney($bal['statement_confirmed_balance'], true) ?> ₽</span>
            <span>Поступления после: +<?= $fmtMoney($bal['post_snapshot_confirmed_inflows'], true) ?> ₽</span>
            <span>Списания после: −<?= $fmtMoney($bal['post_snapshot_confirmed_outflows'], true) ?> ₽</span>
            <span>Операций после выписки: <?= (int)($bal['post_snapshot_operation_count'] ?? 0) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div id="bank-statement-upload-modal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal modal-sm">
        <div class="modal-head">
            <span class="modal-title">Загрузить банковскую выписку</span>
            <button type="button" class="modal-close" data-close-modal="bank-statement-upload-modal">&times;</button>
        </div>
        <form action="<?= app_url('/company/finance/bank-accounts/import') ?>" method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <div class="modal-body">
                <div class="field">
                    <label class="field-label">Файл выписки XLSX</label>
                    <input type="file" name="bank_statement" accept=".xlsx" required class="input">
                    <p class="field-msg">Принимаются только файлы в формате .xlsx (банковская выписка).</p>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" data-close-modal="bank-statement-upload-modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Импортировать</button>
            </div>
        </form>
    </div>
</div>

<div id="bank-statements-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg">
        <div class="modal-head">
            <span class="modal-title">Загруженные выписки</span>
            <button type="button" class="modal-close" data-close-modal="bank-statements-modal">&times;</button>
        </div>
        <div class="modal-body">
            <?php if (!empty($imports)): ?>
            <table class="table bank-imports-table">
                <thead>
                    <tr>
                        <th class="col-date">Дата</th>
                        <th class="col-filename">Файл</th>
                        <th class="col-source">Источник</th>
                        <th class="col-account">Счёт</th>
                        <th class="col-period">Период</th>
                        <th class="col-ops">Операции</th>
                        <th class="col-balances">Остатки</th>
                        <th class="col-status">Статус</th>
                        <th class="col-action"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($imports as $imp): ?>
                    <tr>
                        <td class="col-mono"><?= $fmtDateTime($imp['created_at'] ?? '') ?></td>
                        <td class="col-filename" title="<?= e($imp['filename'] ?? '') ?>">
                            <?= e($imp['filename'] ?: '—') ?>
                            <?php if (!empty($imp['file_available'])): ?>
                                <div class="field-note"><a href="<?= e(app_url('/company/finance/bank-accounts/import/' . (int)$imp['id'] . '/download')) ?>">Скачать XLSX</a></div>
                            <?php else: ?>
                                <div class="field-note">Исходный файл не сохранён</div>
                            <?php endif; ?>
                        </td>
                        <td><?= $imp['source'] === 'imap' ? 'IMAP' : 'Вручную' ?></td>
                        <td class="col-mono"><?= e($imp['account_number'] ?? '—') ?></td>
                        <td class="col-mono"><?= e(($imp['period_from'] ? $fmtDate($imp['period_from']) : '—') . ' — ' . ($imp['period_to'] ? $fmtDate($imp['period_to']) : '—')) ?></td>
                        <td><?= (int)($imp['imported_transactions'] ?? 0) ?></td>
                        <td><?= (int)($imp['imported_balances'] ?? 0) ?></td>
                        <td>
                            <span class="badge<?= $imp['status'] === 'completed' ? ' badge-ok' : '' ?>">
                                <span class="dot"></span>
                                <?= $imp['status'] === 'completed' ? 'Завершён' : ($imp['status'] === 'error' ? 'Ошибка' : 'В обработке') ?>
                            </span>
                        </td>
                        <td class="col-action">
                            <button type="button" class="row-btn" title="Удалить выписку" data-delete-import="<?= (int)$imp['id'] ?>" data-delete-filename="<?= e(addslashes($imp['filename'] ?? 'Выписка')) ?>">&times;</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state compact">
                <p>Выписки ещё не загружены.</p>
            </div>
            <?php endif; ?>

            <?php if (!empty($dailyBalances)): ?>
            <div class="mt-section">
                <div class="found-label">Остатки по выпискам: <b><?= count($dailyBalances) ?></b></div>
            </div>
            <div class="table-scroll">
                <table class="table bank-balances-table">
                    <thead>
                        <tr>
                            <th>Дата выписки</th>
                            <th>Счёт</th>
                            <th>Валюта</th>
                            <th>Входящий остаток</th>
                            <th>Дебетовый оборот</th>
                            <th>Кредитовый оборот</th>
                            <th>Исходящий остаток</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dailyBalances as $balance): ?>
                        <tr>
                            <td class="col-mono"><?= $fmtDate($balance['statement_date'] ?? '') ?></td>
                            <td class="col-mono"><?= e($balance['account_number'] ?? '—') ?></td>
                            <td><?= e($balance['currency'] ?? 'RUR') ?></td>
                            <td class="col-mono"><?= $fmtMoney($balance['opening_balance'], true) ?? '—' ?></td>
                            <td class="col-mono text-danger"><?= $fmtMoney($balance['debit_turnover'], true) ?? '—' ?></td>
                            <td class="col-mono text-success"><?= $fmtMoney($balance['credit_turnover'], true) ?? '—' ?></td>
                            <td class="col-mono bank-balance-strong"><?= $fmtMoney($balance['closing_balance'], true) ?? '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer">
                <span class="footer-label">Показано <b class="footer-range">1–<?= count($dailyBalances) ?></b> из <b class="footer-total"><?= count($dailyBalances) ?></b></span>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-ghost" data-close-modal="bank-statements-modal">Закрыть</button>
        </div>
    </div>
</div>

<div id="bank-delete-confirm-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-sm">
        <div class="modal-head">
            <span class="modal-title">Удалить выписку</span>
            <button type="button" class="modal-close" data-close-modal="bank-delete-confirm-modal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="driver-delete-confirm-title">Выписка будет удалена.</div>
            <div class="driver-delete-confirm-text">Для подтверждения введите <b>УДАЛИТЬ</b>.</div>
            <div class="field mt-section">
                <input type="text" class="field-input" id="bank-delete-confirm-input" autocomplete="off" placeholder="Введите УДАЛИТЬ" data-confirm-delete-input>
            </div>
            <div class="is-hidden delete-confirm-error" id="bank-delete-confirm-error"></div>
        </div>
        <div class="modal-foot is-spaced">
            <div class="modal-foot-actions">
                <button type="button" class="btn btn-ghost" data-close-modal="bank-delete-confirm-modal">Отмена</button>
                <button type="button" class="btn btn-danger" id="bank-delete-confirm-btn" disabled data-confirm-delete-btn>Удалить</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-tx-id]').forEach(function(row) {
        row.addEventListener('click', function() {
            var modal = document.getElementById('tx-detail-modal');
            if (!modal) return;
            var fields = {
                date: this.getAttribute('data-tx-date'),
                account: this.getAttribute('data-tx-account'),
                cparty: this.getAttribute('data-tx-cparty'),
                inn: this.getAttribute('data-tx-inn'),
                'cparty-account': this.getAttribute('data-tx-cparty-account'),
                bik: this.getAttribute('data-tx-bik'),
                docnum: this.getAttribute('data-tx-docnum'),
                type: this.getAttribute('data-tx-type'),
                debit: this.getAttribute('data-tx-debit'),
                credit: this.getAttribute('data-tx-credit'),
                purpose: this.getAttribute('data-tx-purpose')
            };
            Object.keys(fields).forEach(function(key) {
                var el = modal.querySelector('[data-tx-detail-' + key + ']');
                if (el) el.textContent = fields[key] || '—';
            });
            window.openModal('tx-detail-modal');
        });
    });
});
</script>

<div id="bank-reconciliation-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg">
        <div class="modal-head">
            <span class="modal-title">Детали независимой сверки</span>
            <button type="button" class="modal-close" data-close-modal="bank-reconciliation-modal">&times;</button>
        </div>
        <div class="modal-body">
            <?php if (isset($reconciliation)): ?>
            <?php foreach ($reconciliation['accounts'] as $rAcc): ?>
            <?php $bal = $rAcc['current_balance'] ?? []; ?>
            <div class="mt-section">
                <div class="bank-recon-row">
                    <span class="col-mono bank-recon-account-num"><?= e($rAcc['account_number']) ?></span>
                    <span class="badge <?= $rAcc['status'] === 'OK' ? 'badge-ok' : ($rAcc['status'] === 'NO_STATEMENT' ? 'badge-neutral' : 'badge-danger') ?>">
                        <span class="dot"></span> <?= $rAcc['status'] ?>
                    </span>
                </div>
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Дата выписки</th>
                                <th>Входящий</th>
                                <th>Дебет</th>
                                <th>Кредит</th>
                                <th>Исходящий (выписка)</th>
                                <th>Исходящий (расчёт)</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rAcc['statement_arithmetic'] as $sa): ?>
                            <tr>
                                <td class="col-mono"><?= $fmtDate($sa['statement_date'] ?? '') ?></td>
                                <td class="col-mono"><?= $fmtMoney($sa['opening_balance'], true) ?? '—' ?></td>
                                <td class="col-mono text-danger"><?= $fmtMoney($sa['debit_turnover'], true) ?? '—' ?></td>
                                <td class="col-mono text-success"><?= $fmtMoney($sa['credit_turnover'], true) ?? '—' ?></td>
                                <td class="col-mono"><?= $fmtMoney($sa['stated_closing_balance'], true) ?? '—' ?></td>
                                <td class="col-mono"><?= $fmtMoney($sa['computed_closing_balance'], true) ?? '—' ?></td>
                                <td>
                                    <span class="badge <?= $sa['status'] === 'OK' ? 'badge-ok' : 'badge-danger' ?>">
                                        <span class="dot"></span> <?= $sa['status'] ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($rAcc['continuity'] && !empty($rAcc['continuity']['items'])): ?>
                <div class="mt-section">
                    <div class="bank-recon-section-title">Непрерывность выписок</div>
                    <div class="table-scroll">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Дата</th>
                                    <th>Предыдущий исходящий</th>
                                    <th>Текущий входящий</th>
                                    <th>Статус</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rAcc['continuity']['items'] as $ci): ?>
                                <tr>
                                    <td class="col-mono"><?= $fmtDate($ci['statement_date'] ?? '') ?></td>
                                    <td class="col-mono"><?= $ci['previous_closing_balance'] !== null ? ($fmtMoney($ci['previous_closing_balance'], true) ?? '—') : '—' ?></td>
                                    <td class="col-mono"><?= $fmtMoney($ci['opening_balance'], true) ?? '—' ?></td>
                                    <td>
                                        <span class="badge <?= $ci['status'] === 'OK' || $ci['status'] === 'FIRST' ? 'badge-ok' : 'badge-danger' ?>">
                                            <span class="dot"></span> <?= $ci['status'] ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                <div class="bank-recon-post-balance">
                    <span>Поступления после выписки: <b><?= $fmtMoney($bal['post_snapshot_confirmed_inflows'] ?? '0.00', true) ?> ₽</b></span>
                    <span>Списания после выписки: <b><?= $fmtMoney($bal['post_snapshot_confirmed_outflows'] ?? '0.00', true) ?> ₽</b></span>
                    <span>Текущий расчётный остаток: <b><?= $fmtMoney($bal['current_calculated_balance'] ?? '0.00', true) ?> ₽</b></span>
                </div>
            </div>
            <?php if ($rAcc !== end($reconciliation['accounts'])): ?>
            <hr class="bank-recon-hr">
            <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-ghost" data-close-modal="bank-reconciliation-modal">Закрыть</button>
            <a href="<?= app_url('/company/finance/bank-accounts/reconciliation/json') ?>" class="btn btn-secondary" target="_blank">JSON-отчёт</a>
        </div>
    </div>
</div>

<div id="tx-detail-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="0" data-close-on-escape="0">
    <div class="modal modal-lg">
        <div class="modal-head">
            <span class="modal-title">Детали операции</span>
            <button type="button" class="modal-close" data-close-modal="tx-detail-modal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="tx-detail-grid">
                <div class="tx-detail-row">
                    <span class="tx-detail-label">Дата</span>
                    <span class="tx-detail-value" data-tx-detail-date></span>
                </div>
                <div class="tx-detail-row">
                    <span class="tx-detail-label">Счёт</span>
                    <span class="tx-detail-value col-mono" data-tx-detail-account></span>
                </div>
                <div class="tx-detail-row">
                    <span class="tx-detail-label">Контрагент</span>
                    <span class="tx-detail-value" data-tx-detail-cparty></span>
                </div>
                <div class="tx-detail-row">
                    <span class="tx-detail-label">ИНН</span>
                    <span class="tx-detail-value col-mono" data-tx-detail-inn></span>
                </div>
                <div class="tx-detail-row">
                    <span class="tx-detail-label">Счёт контрагента</span>
                    <span class="tx-detail-value col-mono" data-tx-detail-cparty-account></span>
                </div>
                <div class="tx-detail-row">
                    <span class="tx-detail-label">БИК</span>
                    <span class="tx-detail-value col-mono" data-tx-detail-bik></span>
                </div>
                <div class="tx-detail-row">
                    <span class="tx-detail-label">Номер документа</span>
                    <span class="tx-detail-value" data-tx-detail-docnum></span>
                </div>
                <div class="tx-detail-row">
                    <span class="tx-detail-label">Тип операции</span>
                    <span class="tx-detail-value" data-tx-detail-type></span>
                </div>
                <div class="tx-detail-row">
                    <span class="tx-detail-label">Дебет</span>
                    <span class="tx-detail-value text-danger" data-tx-detail-debit></span>
                </div>
                <div class="tx-detail-row">
                    <span class="tx-detail-label">Кредит</span>
                    <span class="tx-detail-value text-success" data-tx-detail-credit></span>
                </div>
                <div class="tx-detail-row tx-detail-row-wide">
                    <span class="tx-detail-label">Назначение</span>
                    <span class="tx-detail-value tx-detail-purpose" data-tx-detail-purpose></span>
                </div>
            </div>
        </div>
    </div>
</div>
