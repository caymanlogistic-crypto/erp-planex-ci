<?php

use App\Core\Database;
use App\Service\FinanceInvoicePrepaymentService;
use App\Service\FinanceInvoiceService;
use App\Service\FinanceObligationService;

requireRole(['company_owner']);
verifyCsrfRequest();
$errors = [];

$isValidDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $dateErrors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && (!is_array($dateErrors) || (($dateErrors['warning_count'] ?? 0) === 0 && ($dateErrors['error_count'] ?? 0) === 0))
        && $date->format('Y-m-d') === $value;
};
$moneyCents = static function (string $value): int {
    $normalized = str_replace(',', '.', trim($value));
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $normalized)) {
        return 0;
    }
    [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
    return ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
};
$centsToMoney = static fn(int $cents): string => intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);

try {
    $companyId = (int)(getSessionCompanyId() ?? 0);
    $company = $db->fetch('SELECT * FROM companies WHERE id = ?', [$companyId]);
    if (!$company || ($company['status'] ?? '') !== 'active') {
        throw new RuntimeException('Компания недоступна.');
    }
    $localPdo = (new Database(companyDatabaseConfig($config, $company)))->connection();
    applyLocalMigrations($localPdo);
    FinanceObligationService::syncAllLinearRoutes($localPdo);

    $user = $_SESSION['user'] ?? [];
    $userId = (int)($user['user_id'] ?? 0);
    $roleCode = (string)($user['role_code'] ?? '');
    $invoiceId = (int)$id;
    $existing = FinanceInvoiceService::fetchInvoiceById($localPdo, $invoiceId);
    if (!$existing) {
        throw new RuntimeException('Счёт не найден.');
    }
    if (($existing['cancelled_at'] ?? null) !== null || ($existing['status'] ?? '') === 'cancelled') {
        throw new RuntimeException('Аннулированный счёт нельзя редактировать.');
    }

    $direction = strtoupper(trim((string)($_POST['direction'] ?? '')));
    $number = trim((string)($_POST['number'] ?? ''));
    $invoiceDate = trim((string)($_POST['invoice_date'] ?? ''));
    $rawAmount = trim((string)($_POST['amount'] ?? ''));
    $vatRate = ($_POST['vat_rate'] ?? '') !== '' ? $_POST['vat_rate'] : null;
    $basis = trim((string)($_POST['basis'] ?? ''));
    $comment = trim((string)($_POST['comment'] ?? ''));

    if (!in_array($direction, [FinanceInvoiceService::DIRECTION_OUTGOING, FinanceInvoiceService::DIRECTION_INCOMING], true)) {
        $errors[] = 'Укажите направление счёта.';
    }
    if ($number === '') {
        $errors[] = 'Укажите номер счёта.';
    }
    if ($invoiceDate === '' || !$isValidDate($invoiceDate)) {
        $errors[] = 'Укажите корректную дату счёта.';
    }
    if (!FinanceInvoiceService::isVatRateInputValid($vatRate)) {
        $errors[] = 'Некорректное значение НДС.';
    }
    $normalizedVat = FinanceInvoiceService::normalizeVatRateInput($vatRate);

    $counterpartyType = $direction === FinanceInvoiceService::DIRECTION_OUTGOING
        ? 'client'
        : ($direction === FinanceInvoiceService::DIRECTION_INCOMING ? 'contractor' : null);
    $counterparty = ['errors' => [], 'type' => null, 'id' => null, 'name' => '', 'inn' => ''];
    if ($counterpartyType !== null) {
        $counterparty = FinanceInvoiceService::normalizeCounterpartyInput(
            $localPdo,
            $counterpartyType,
            $_POST['counterparty_entity_id'] ?? null,
            '',
            ''
        );
        $errors = array_merge($errors, $counterparty['errors']);
    }

    $ids = is_array($_POST['obligation_id'] ?? null) ? $_POST['obligation_id'] : [];
    $amounts = is_array($_POST['obligation_amount'] ?? null) ? $_POST['obligation_amount'] : [];
    $obligationRows = [];
    $newLinkMap = [];
    $selectedObligations = 0;
    $obligationTotalCents = 0;
    foreach ($ids as $idx => $obligationIdRaw) {
        $obligationId = (int)$obligationIdRaw;
        if ($obligationId <= 0) {
            continue;
        }
        $selectedObligations++;
        $linkAmount = FinanceInvoiceService::normalizeMoneyInput((string)($amounts[$idx] ?? ''));
        if ($linkAmount === null) {
            $errors[] = 'Для каждого выбранного обязательства укажите сумму больше нуля.';
            continue;
        }
        $obligationRows[] = ['obligation_id' => $obligationId, 'amount' => $linkAmount];
        $newLinkMap[$obligationId] = $linkAmount;
        $obligationTotalCents += $moneyCents($linkAmount);
    }
    ksort($newLinkMap);

    if ($selectedObligations > 0) {
        $normalizedAmount = count($obligationRows) === $selectedObligations
            ? $centsToMoney($obligationTotalCents)
            : null;
    } else {
        $normalizedAmount = FinanceInvoiceService::normalizeMoneyInput($rawAmount);
        if ($normalizedAmount === null) {
            $errors[] = 'Сумма должна быть больше нуля.';
        }
    }

    $paidCents = $moneyCents((string)($existing['paid_amount'] ?? '0.00'));
    if ($paidCents > 0) {
        if ($direction !== (string)$existing['direction']) {
            $errors[] = 'После поступления оплаты нельзя менять направление счёта.';
        }
        if ($counterpartyType !== (string)($existing['counterparty_entity_type'] ?? '')
            || (int)($counterparty['id'] ?? 0) !== (int)($existing['counterparty_entity_id'] ?? 0)) {
            $errors[] = 'После поступления оплаты нельзя менять контрагента счёта.';
        }
        if ($normalizedAmount !== null && $moneyCents($normalizedAmount) < $paidCents) {
            $errors[] = 'Сумма счёта не может быть меньше уже оплаченной суммы.';
        }
        $currentLinkMap = [];
        foreach (FinanceObligationService::invoiceLinks($localPdo, $invoiceId) as $link) {
            if ((int)($link['obligation_id'] ?? 0) > 0) {
                $currentLinkMap[(int)$link['obligation_id']] = (string)$link['amount'];
            }
        }
        ksort($currentLinkMap);
        if ($currentLinkMap !== $newLinkMap) {
            $errors[] = 'После поступления оплаты нельзя менять привязки счёта к платёжным обязательствам.';
        }
    }

    if ($errors === [] && $normalizedAmount !== null && $counterpartyType !== null) {
        $systemStatus = $direction === FinanceInvoiceService::DIRECTION_OUTGOING ? 'issued' : 'received';
        $localPdo->beginTransaction();
        try {
            FinanceInvoiceService::updateInvoice($localPdo, $invoiceId, [
                'direction' => $direction,
                'number' => $number,
                'invoice_date' => $invoiceDate,
                'counterparty_entity_type' => $counterpartyType,
                'counterparty_entity_id' => $counterparty['id'],
                'counterparty_name' => $counterparty['name'],
                'counterparty_inn' => $counterparty['inn'],
                'amount' => $normalizedAmount,
                'vat_rate' => $normalizedVat,
                'basis' => $basis,
                'planned_payment_date' => null,
                'comment' => $comment,
                'status' => $systemStatus,
            ], $userId, $roleCode);
            FinanceObligationService::replaceInvoiceLinks($localPdo, $invoiceId, $obligationRows, $user);
            FinanceInvoicePrepaymentService::reconcileInvoice($localPdo, $invoiceId, $user);
            $localPdo->commit();
        } catch (Throwable $e) {
            if ($localPdo->inTransaction()) {
                $localPdo->rollBack();
            }
            throw $e;
        }
        $_SESSION['invoice_success'] = 'Счёт №' . $number . ' сохранён.';
        redirect_to('/company/finance/invoices?direction=' . rawurlencode($direction));
    }

    $formError = implode(' ', array_values(array_unique($errors)));
    $invoice = array_merge($existing, [
        'direction' => $direction,
        'number' => $number,
        'invoice_date' => $invoiceDate,
        'counterparty_entity_id' => $counterparty['id'],
        'counterparty_inn' => $counterparty['inn'],
        'amount' => $normalizedAmount ?? $rawAmount,
        'vat_rate' => $vatRate,
        'basis' => $basis,
        'comment' => $comment,
    ]);
    $clients = FinanceInvoiceService::fetchClientsForSelect($localPdo);
    $contractors = FinanceInvoiceService::fetchContractorsForSelect($localPdo);
    $isEdit = true;
    require base_path('app/View/partials/company_invoice_create_form.php');
} catch (Throwable $e) {
    echo '<div class="form-alert alert-error">Ошибка: ' . e($e->getMessage()) . '</div>';
}
