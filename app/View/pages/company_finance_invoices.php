<?php

use App\Service\FinanceInvoiceService;

$fmtDate = static function (mixed $value): string {
    $value = trim((string)$value);
    if ($value === '' || $value === '—') {
        return '—';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value ? $date->format('d.m.Y') : '—';
};
$fmtDue = static function (mixed $value) use ($fmtDate): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
        return $fmtDate($value);
    }
    return $value;
};
$fmtMoney = static fn(mixed $value): string => FinanceInvoiceService::formatAmount($value) . ' ₽';
$moneyToCents = static function (mixed $value): int {
    $normalized = trim(str_replace(',', '.', (string)$value));
    if (preg_match('/^-?\d+(?:\.\d+)?$/D', $normalized) !== 1) return 0;
    $negative = str_starts_with($normalized, '-');
    if ($negative) $normalized = substr($normalized, 1);
    [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
    $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
    $cents = ((int)$whole * 100) + (int)$fraction;
    return $negative ? -$cents : $cents;
};
$centsToMoney = static function (int $cents): string {
    $sign = $cents < 0 ? '-' : '';
    $cents = abs($cents);
    return $sign . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
};
$successFlash = $_SESSION['invoice_success'] ?? null;
unset($_SESSION['invoice_success']);
$currentDirection = $_GET['direction'] ?? '';
$selectedDirection = in_array($currentDirection, [FinanceInvoiceService::DIRECTION_OUTGOING, FinanceInvoiceService::DIRECTION_INCOMING], true) ? $currentDirection : '';
?>
<?php if ($company === null): ?>
<div class="notice warn">Компания не найдена.</div>
<?php elseif (($company['status'] ?? '') !== 'active'): ?>
<div class="page-head"><div class="page-head-left"><h1 class="page-title">Счета</h1><div class="page-summary"><span>Компания находится в неактивном статусе.</span></div></div></div>
<div class="notice warn">Работа со счетами недоступна.</div>
<?php elseif ($dbError !== null): ?>
<div class="page-head"><div class="page-head-left"><h1 class="page-title">Счета</h1><div class="page-summary"><span>Реестр счетов компании.</span></div></div></div>
<div class="notice warn"><?= e($dbError) ?></div>
<?php else: ?>
<style>
.invoice-stages-col{min-width:390px}
.invoice-stages{display:flex;flex-wrap:wrap;gap:6px;min-width:370px;max-width:520px;font-size:11px;line-height:1.35;color:var(--text)}
.invoice-stage-card{display:flex;flex-direction:column;flex:0 1 190px;min-width:170px;border:1px solid var(--border);border-radius:5px;padding:7px 8px;background:transparent}
.invoice-stage-card.is-paid{background:var(--success-bg);border-color:var(--success-border)}
.invoice-stage-head{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:5px}
.invoice-stage-title{font-weight:800;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.invoice-stage-route{color:var(--muted);font-size:10px;white-space:nowrap}
.invoice-stage-line{display:flex;justify-content:space-between;gap:8px;align-items:baseline;white-space:nowrap;padding-top:1px}
.invoice-stage-line span:first-child{color:var(--muted)}
.invoice-stage-line strong{font-weight:700}
.invoice-stage-line.is-overdue-remaining span:first-child,.invoice-stage-line.is-overdue-remaining strong{color:var(--danger-text)}
.invoice-stage-status{margin-top:auto;padding-top:6px;min-height:24px}
.invoice-stage-status .badge{white-space:nowrap}
.invoice-stage-empty{color:var(--muted);padding:3px 0}
@media (max-width:1280px){.invoice-stages-col{min-width:330px}.invoice-stages{min-width:310px}.invoice-stage-card{flex-basis:170px}}
</style>
<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Счета</h1>
        <div class="page-summary"><span>Реестр счетов компании: выставленные и полученные.</span></div>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-secondary" href="<?= e(app_url('/company/finance/receivables')) ?>">Дебиторка</a>
        <a class="btn btn-secondary" href="<?= e(app_url('/company/finance/payables')) ?>">Кредиторка</a>
        <button type="button" class="btn btn-primary" data-open-modal="invoice-create-modal">Создать счёт</button>
    </div>
</div>

<?php if ($successFlash): ?><div class="notice success"><?= e($successFlash) ?></div><?php endif; ?>
<?php if (!empty($formError)): ?><div class="notice warn"><?= e($formError) ?></div><?php endif; ?>

<div class="tabs-page">
    <a href="<?= app_url('/company/finance/invoices') ?>" class="tab-link<?= $selectedDirection === '' ? ' is-active' : '' ?>">Все счета</a>
    <a href="<?= app_url('/company/finance/invoices?direction=' . FinanceInvoiceService::DIRECTION_OUTGOING) ?>" class="tab-link<?= $selectedDirection === FinanceInvoiceService::DIRECTION_OUTGOING ? ' is-active' : '' ?>">Выставленные</a>
    <a href="<?= app_url('/company/finance/invoices?direction=' . FinanceInvoiceService::DIRECTION_INCOMING) ?>" class="tab-link<?= $selectedDirection === FinanceInvoiceService::DIRECTION_INCOMING ? ' is-active' : '' ?>">Полученные</a>
</div>

<?php if (empty($invoices)): ?>
<div class="panel"><div class="panel-body"><div class="empty-state"><p class="empty-title">Счета ещё не созданы.</p><p class="empty-desc">Создайте первый счёт через модальное окно.</p></div></div></div>
<?php else: ?>
<div class="table-card table-card--standard">
    <div class="table-toolbar">
        <div class="found-label">Найдено: <b><?= count($invoices) ?></b> из <b><?= $invTotal ?></b> счетов (страница <?= $invPage ?>/<?= $invPages ?>)</div>
        <div class="toolbar-right"><input type="text" class="toolbar-search" placeholder="Поиск по таблице"></div>
    </div>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>№</th><th>Дата</th><th>Направление</th><th>Контрагент</th><th>Сумма</th><th>НДС</th><th>Срок оплаты</th><th class="invoice-stages-col">Этапы оплаты</th></tr></thead>
            <tbody>
            <?php foreach ($invoices as $inv):
                $dueText = $fmtDue($inv['display_due_text'] ?? '—');
                $timeline = is_array($inv['payment_timeline'] ?? null) ? $inv['payment_timeline'] : null;
                $today = new DateTimeImmutable('today');
                $stageCards = [];
                $paidParts = $timeline !== null && is_array($timeline['settlement_parts'] ?? null) ? array_values($timeline['settlement_parts']) : [];
                $partIndex = 0;
                $partRemainingCents = isset($paidParts[0]) ? $moneyToCents($paidParts[0]['amount'] ?? '0') : 0;

                if ($timeline !== null) {
                    foreach (array_values($timeline['plans'] ?? []) as $stageIndex => $plan) {
                        $plannedCents = max(0, $moneyToCents($plan['amount'] ?? '0'));
                        $remainingCents = array_key_exists('remaining_amount', $plan)
                            ? max(0, min($plannedCents, $moneyToCents($plan['remaining_amount'] ?? '0')))
                            : $plannedCents;
                        $paidCents = max(0, $plannedCents - $remainingCents);
                        $needPaidCents = $paidCents;
                        $actualDates = [];

                        while ($needPaidCents > 0 && isset($paidParts[$partIndex])) {
                            if ($partRemainingCents <= 0) {
                                $partIndex++;
                                if (!isset($paidParts[$partIndex])) break;
                                $partRemainingCents = max(0, $moneyToCents($paidParts[$partIndex]['amount'] ?? '0'));
                                continue;
                            }
                            $take = min($needPaidCents, $partRemainingCents);
                            if ($take <= 0) break;
                            $actualDate = trim((string)($paidParts[$partIndex]['actual_date'] ?? ''));
                            if ($actualDate !== '') $actualDates[] = $actualDate;
                            $needPaidCents -= $take;
                            $partRemainingCents -= $take;
                            if ($partRemainingCents <= 0) {
                                $partIndex++;
                                if (isset($paidParts[$partIndex])) {
                                    $partRemainingCents = max(0, $moneyToCents($paidParts[$partIndex]['amount'] ?? '0'));
                                }
                            }
                        }

                        sort($actualDates);
                        $expectedDate = trim((string)($plan['expected_date'] ?? ''));
                        $dueDate = null;
                        if ($expectedDate !== '') {
                            $candidateDue = DateTimeImmutable::createFromFormat('!Y-m-d', $expectedDate);
                            if ($candidateDue !== false && $candidateDue->format('Y-m-d') === $expectedDate) $dueDate = $candidateDue;
                        }

                        $title = '';
                        foreach (['payment_event_label', 'condition_label', 'payment_condition_label', 'event_label', 'due_label'] as $labelKey) {
                            $candidate = trim((string)($plan[$labelKey] ?? ''));
                            if ($candidate !== '') { $title = $candidate; break; }
                        }
                        if ($title === '') $title = ($stageIndex + 1) . ' платёж';

                        $isPaid = $plannedCents > 0 && $remainingCents <= 0;
                        $isOverdueRemaining = !$isPaid && $remainingCents > 0 && $dueDate !== null && $today > $dueDate;
                        $statusText = '';
                        $statusClass = 'badge badge-neutral';
                        if ($isPaid) {
                            $statusText = 'Оплачен';
                            $statusClass = 'badge badge-ok';
                        } elseif ($paidCents > 0) {
                            if ($isOverdueRemaining) {
                                $days = (int)$dueDate->diff($today)->format('%a');
                                $statusText = 'Частично · просрочка ' . $days . ' дн.';
                                $statusClass = 'badge badge-warning';
                            } else {
                                $statusText = 'Частично';
                                $statusClass = 'badge badge-warning';
                            }
                        } elseif ($dueDate === null) {
                            $statusText = 'Ожидается событие';
                            $statusClass = 'badge badge-neutral';
                        } elseif ($today > $dueDate) {
                            $days = (int)$dueDate->diff($today)->format('%a');
                            $statusText = 'Просрочка ' . $days . ' дн.';
                            $statusClass = 'badge badge-danger';
                        } elseif ($today == $dueDate) {
                            $statusText = 'Ожидается сегодня';
                            $statusClass = 'badge badge-neutral';
                        } else {
                            $statusText = 'Ожидается до ' . $dueDate->format('d.m');
                            $statusClass = 'badge badge-neutral';
                        }

                        $routeId = (int)($plan['route_id'] ?? 0);
                        $stageCards[] = [
                            'title' => $title,
                            'route_id' => $routeId,
                            'planned' => $plannedCents,
                            'paid' => $paidCents,
                            'remaining' => $remainingCents,
                            'expected_date' => $dueDate?->format('Y-m-d'),
                            'status_text' => $statusText,
                            'status_class' => $statusClass,
                            'is_paid' => $isPaid,
                            'is_overdue_remaining' => $isOverdueRemaining,
                            'actual_dates' => $actualDates,
                        ];
                    }
                }
            ?>
                <tr data-invoice-id="<?= (int)($inv['id'] ?? 0) ?>">
                    <td class="col-mono"><?= e($inv['number'] ?? '—') ?></td>
                    <td class="col-mono"><?= e($fmtDate($inv['invoice_date'] ?? '')) ?></td>
                    <td><?= e(FinanceInvoiceService::directionLabel($inv['direction'] ?? null)) ?></td>
                    <td><?= e(($inv['counterparty_name'] ?? '') !== '' ? $inv['counterparty_name'] : '—') ?></td>
                    <td class="col-mono"><?= e(FinanceInvoiceService::formatAmount($inv['amount'] ?? null)) ?></td>
                    <td><?= $inv['vat_rate'] !== null ? e(((float)$inv['vat_rate'] == 0.0 ? '0' : rtrim(rtrim((string)$inv['vat_rate'], '0'), '.')) . '%') : 'Без НДС' ?></td>
                    <td class="col-mono"><?= e($dueText) ?></td>
                    <td>
                        <?php if (empty($stageCards)): ?>
                            <div class="invoice-stage-empty">Нет связанных платёжных обязательств.</div>
                        <?php else: ?>
                            <div class="invoice-stages">
                                <?php foreach ($stageCards as $stage):
                                    $tooltip = '';
                                    if (!empty($stage['actual_dates'])) {
                                        $formattedActuals = array_map($fmtDate, array_values(array_unique($stage['actual_dates'])));
                                        $tooltip = 'Фактические даты: ' . implode(', ', $formattedActuals);
                                    }
                                ?>
                                <div class="invoice-stage-card<?= $stage['is_paid'] ? ' is-paid' : '' ?>"<?= $tooltip !== '' ? ' title="' . e($tooltip) . '"' : '' ?>>
                                    <div class="invoice-stage-head">
                                        <span class="invoice-stage-title"><?= e($stage['title']) ?></span>
                                        <?php if ($stage['route_id'] > 0): ?><span class="invoice-stage-route">#<?= (int)$stage['route_id'] ?></span><?php endif; ?>
                                    </div>
                                    <div class="invoice-stage-line"><span>План:</span><strong><?= e($fmtMoney($centsToMoney($stage['planned']))) ?></strong></div>
                                    <div class="invoice-stage-line"><span><?= ($inv['direction'] ?? '') === FinanceInvoiceService::DIRECTION_OUTGOING ? 'Получено:' : 'Оплачено:' ?></span><strong><?= e($fmtMoney($centsToMoney($stage['paid']))) ?></strong></div>
                                    <?php if ($stage['remaining'] > 0 && ($stage['paid'] > 0 || $stage['is_overdue_remaining'])): ?><div class="invoice-stage-line<?= $stage['is_overdue_remaining'] ? ' is-overdue-remaining' : '' ?>"><span>Остаток:</span><strong><?= e($fmtMoney($centsToMoney($stage['remaining']))) ?></strong></div><?php endif; ?>
                                    <div class="invoice-stage-line"><span>Срок:</span><strong><?= e($stage['expected_date'] !== null ? $fmtDate($stage['expected_date']) : 'по событию') ?></strong></div>
                                    <div class="invoice-stage-status"><span class="<?= e($stage['status_class']) ?>"><span class="dot"></span><?= e($stage['status_text']) ?></span></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div id="invoice-create-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="0" data-close-on-escape="0">
    <div class="modal modal-lg">
        <div class="modal-head"><span class="modal-title">Создать счёт</span><button type="button" class="modal-close" data-close-modal="invoice-create-modal">&times;</button></div>
        <?php if ($localPdo !== null): ?>
        <?php
        $isEdit = false;
        $invoice = $old ?? null;
        $clients = FinanceInvoiceService::fetchClientsForSelect($localPdo);
        $contractors = FinanceInvoiceService::fetchContractorsForSelect($localPdo);
        require base_path('app/View/partials/company_invoice_create_form.php');
        ?>
        <?php else: ?>
        <div class="modal-body"><div class="form-alert alert-error">Данные компании недоступны.</div></div>
        <?php endif; ?>
    </div>
</div>

<div id="invoice-view-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg">
        <div class="modal-head"><span class="modal-title">Счёт</span><button type="button" class="modal-close" data-close-modal="invoice-view-modal">&times;</button></div>
        <div class="modal-body" id="invoice-view-modal-body"></div>
    </div>
</div>

<div id="invoice-edit-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="0" data-close-on-escape="0">
    <div class="modal modal-lg">
        <div class="modal-head"><span class="modal-title">Редактировать счёт</span><button type="button" class="modal-close" data-close-modal="invoice-edit-modal">&times;</button></div>
        <div class="modal-body" id="invoice-edit-modal-body"></div>
    </div>
</div>

<?php
$cancelModalId = 'invoice-cancel-modal';
$cancelActionUrl = app_url('/company/finance/invoices/0/modal-delete');
$cancelEntityLabel = 'счёта';
require base_path('app/View/partials/company_finance_cancel_modal.php');
?>

<script>
window.planexSetHtmlAndRunScripts = window.planexSetHtmlAndRunScripts || function(container, html) {
    container.innerHTML = html;
    Array.from(container.querySelectorAll('script')).forEach(function(oldScript) {
        var script = document.createElement('script');
        Array.from(oldScript.attributes).forEach(function(attr) { script.setAttribute(attr.name, attr.value); });
        script.textContent = oldScript.textContent;
        oldScript.replaceWith(script);
    });
};

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('table.table tbody tr[data-invoice-id]').forEach(function(row) {
        row.addEventListener('dblclick', function() {
            var id = parseInt(this.dataset.invoiceId || '0', 10);
            if (!Number.isInteger(id) || id <= 0) return;
            var body = document.getElementById('invoice-view-modal-body');
            body.innerHTML = '<div class="empty-state compact"><p>Загрузка...</p></div>';
            window.openModal('invoice-view-modal');
            fetch(window.getErpBasePath() + '/company/finance/invoices/' + id + '/modal-view')
                .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
                .then(function(html) { window.planexSetHtmlAndRunScripts(body, html); })
                .catch(function() { body.innerHTML = '<div class="form-alert alert-error">Не удалось загрузить данные счёта.</div>'; });
        });
    });
    var createBtn = document.querySelector('[data-open-modal="invoice-create-modal"]');
    if (createBtn) createBtn.addEventListener('click', function() { window.openModal('invoice-create-modal'); });
});
</script>
<?php endif; ?>