<?php

use App\Core\Database;
use App\Service\FinanceInvoicePrepaymentService;
use App\Service\FinanceInvoiceService;
use App\Service\FinanceObligationService;

verifyCsrfRequest();

$errors = [];
$dbError = null;
$company = null;
$localPdo = null;
$invoices = [];
$invPage = 1;
$invPerPage = 100;
$invTotal = 0;
$invPages = 1;
$old = $_POST;

$isValidDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $dateErrors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && (!is_array($dateErrors) || (($dateErrors['warning_count'] ?? 0) === 0 && ($dateErrors['error_count'] ?? 0) === 0))
        && $date->format('Y-m-d') === $value;
};
$moneyCents = static function (string $value): int {
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '00');
    return ((int)$whole * 100) + (int)str_pad(substr($fraction, 0, 2), 2, '0');
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
    FinanceObligationService::syncAllOperationalObligations($localPdo);

    $user = $_SESSION['user'] ?? [];
    $userId = (int)($user['user_id'] ?? 0);
    $roleCode = (string)($user['role_code'] ?? '');
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
        $errors[] = 'Некорректное значение НДС. Допустимые значения: Без НДС, 0%, 5%, 7%, 20%, 22%.';
    }
    $normalizedVat = FinanceInvoiceService::normalizeVatRateInput($vatRate);

    $counterpartyType = $direction === FinanceInvoiceService::DIRECTION_OUTGOING
        ? 'client'
        : ($direction === FinanceInvoiceService::DIRECTION_INCOMING ? 'contractor' : null);
    $counterpartyNorm = ['errors' => [], 'type' => null, 'id' => null, 'name' => '', 'inn' => ''];
    if ($counterpartyType !== null) {
        $counterpartyNorm = FinanceInvoiceService::normalizeCounterpartyInput(
            $localPdo,
            $counterpartyType,
            $_POST['counterparty_entity_id'] ?? null,
            '',
            ''
        );
        $errors = array_merge($errors, $counterpartyNorm['errors']);
    }
    $counterpartyId = $counterpartyNorm['id'];
    $counterpartyName = $counterpartyNorm['name'];
    $counterpartyInn = $counterpartyNorm['inn'];

    $obligationIds = is_array($_POST['obligation_id'] ?? null) ? $_POST['obligation_id'] : [];
    $obligationAmounts = is_array($_POST['obligation_amount'] ?? null) ? $_POST['obligation_amount'] : [];
    $obligationRows = [];
    $selectedObligations = 0;
    $obligationTotalCents = 0;
    foreach ($obligationIds as $idx => $obligationIdRaw) {
        $obligationId = (int)$obligationIdRaw;
        if ($obligationId <= 0) {
            continue;
        }
        $selectedObligations++;
        $linkAmount = FinanceInvoiceService::normalizeMoneyInput((string)($obligationAmounts[$idx] ?? ''));
        if ($linkAmount === null) {
            $errors[] = 'Для каждого выбранного обязательства укажите сумму больше нуля.';
            continue;
        }
        $obligationRows[] = ['obligation_id' => $obligationId, 'amount' => $linkAmount];
        $obligationTotalCents += $moneyCents($linkAmount);
    }

    if ($selectedObligations > 0) {
        $normalizedAmount = count($obligationRows) === $selectedObligations
            ? $centsToMoney($obligationTotalCents)
            : null;
        if ($normalizedAmount !== null) {
            $old['amount'] = $normalizedAmount;
        }
    } else {
        $normalizedAmount = FinanceInvoiceService::normalizeMoneyInput($rawAmount);
        if ($normalizedAmount === null) {
            $errors[] = 'Сумма должна быть больше нуля.';
        }
    }

    if ($errors === [] && $normalizedAmount !== null) {
        $duplicateStmt = $localPdo->prepare(
            "SELECT id FROM finance_invoices
              WHERE direction = :direction
                AND number = :number
                AND invoice_date = :invoice_date
                AND amount = :amount
                AND COALESCE(counterparty_entity_type,'') = :counterparty_type
                AND COALESCE(counterparty_entity_id,0) = :counterparty_id
                AND COALESCE(counterparty_name,'') = :counterparty_name
                AND cancelled_at IS NULL
              LIMIT 1"
        );
        $duplicateStmt->execute([
            ':direction' => $direction,
            ':number' => $number,
            ':invoice_date' => $invoiceDate,
            ':amount' => $normalizedAmount,
            ':counterparty_type' => $counterpartyType ?? '',
            ':counterparty_id' => $counterpartyId ?? 0,
            ':counterparty_name' => $counterpartyName,
        ]);
        if ($duplicateStmt->fetchColumn() !== false) {
            $errors[] = 'Идентичный счёт уже существует.';
        }
    }

    if ($errors === [] && $normalizedAmount !== null && $counterpartyType !== null) {
        $systemStatus = $direction === FinanceInvoiceService::DIRECTION_OUTGOING ? 'issued' : 'received';
        $localPdo->beginTransaction();
        try {
            $invoiceId = FinanceInvoiceService::createInvoice($localPdo, [
                'direction' => $direction,
                'number' => $number,
                'invoice_date' => $invoiceDate,
                'counterparty_entity_type' => $counterpartyType,
                'counterparty_entity_id' => $counterpartyId,
                'counterparty_name' => $counterpartyName,
                'counterparty_inn' => $counterpartyInn,
                'amount' => $normalizedAmount,
                'vat_rate' => $normalizedVat,
                'basis' => $basis,
                'planned_payment_date' => null,
                'comment' => $comment,
                'status' => $systemStatus,
            ], $userId, $roleCode);
            if ($obligationRows !== []) {
                FinanceObligationService::replaceInvoiceLinks($localPdo, $invoiceId, $obligationRows, $user);
                FinanceInvoicePrepaymentService::reconcileInvoice($localPdo, $invoiceId, $user);
            }
            $localPdo->commit();
        } catch (Throwable $e) {
            if ($localPdo->inTransaction()) {
                $localPdo->rollBack();
            }
            throw $e;
        }
        $_SESSION['invoice_success'] = 'Счёт №' . $number . ' создан.';
        redirect_to(($_POST['return_to'] ?? '') === '/company/finance/received-paid'
            ? '/company/finance/received-paid' : '/company/finance/invoices');
    }

    $formError = implode(' ', array_values(array_unique($errors)));
    $invResult = FinanceInvoiceService::fetchInvoices($localPdo, $user);
    $invoices = $invResult['data'];
    $invTotal = $invResult['total'];
    $invPages = $invResult['pages'];
} catch (Throwable $e) {
    $formError = 'Ошибка: ' . $e->getMessage();
}

$showCreateModal = true;
ob_start();
require base_path('app/View/pages/company_finance_invoices.php');
$content = ob_get_clean();
require base_path('app/View/layouts/main.php');
