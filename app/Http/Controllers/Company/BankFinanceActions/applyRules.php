<?php

verifyCsrfRequest();

require_once base_path('app/Service/FinanceCarrierBankAutoSettlementService.php');

$companyId = (int) (getSessionCompanyId() ?? 0);
if ($companyId <= 0) {
    $_SESSION['bank_finance_error'] = 'Компания не найдена.';
    redirect_to('/company/finance/bank-accounts');
}

try {
    $centralPdo = $db->connection();
    $stmt = $centralPdo->prepare("SELECT * FROM companies WHERE id = ? AND status = 'active'");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        throw new RuntimeException('Компания не найдена или неактивна.');
    }

    $localPdo = (new \App\Core\Database(companyDatabaseConfig($config, $company)))->connection();
    $summary = \App\Service\FinanceMatchingRuleService::applyRulesToUnallocatedBankOperations($localPdo);
    $carrierInvoiceSummary = \App\Service\FinanceCarrierBankAutoSettlementService::autoAllocateOutgoingCarrierPayments(
        $localPdo,
        $_SESSION['user'] ?? []
    );
    $settlementSync = \App\Service\FinanceSettlementStateService::syncPersistedStatuses($localPdo);

    $_SESSION['bank_finance_success'] = sprintf(
        'Разнесение завершено: проверено — %d, автоматически разнесено — %d, на проверку — %d, конфликтов — %d, без подходящего правила — %d%s. Поступления клиентов распределяются по счетам вручную. По счетам перевозчиков: сопоставлено оплат — %d, распределений — %d, сумма — %s ₽. Состояние расчётов обновлено: операций — %d, счетов — %d.',
        (int) $summary['scanned'],
        (int) $summary['auto_applied'],
        (int) ($summary['suggested'] + $summary['needs_review']),
        (int) $summary['conflicts'],
        (int) $summary['unmatched'],
        (int) $summary['errors'] > 0 ? ', ошибок — ' . (int) $summary['errors'] : '',
        (int) $carrierInvoiceSummary['operations'],
        (int) $carrierInvoiceSummary['allocations'],
        number_format((float)$carrierInvoiceSummary['amount'], 2, ',', ' '),
        (int) $settlementSync['transactions'],
        (int) $settlementSync['invoices']
    );
} catch (Throwable $e) {
    error_log('Batch bank matching error: ' . $e->getMessage());
    $_SESSION['bank_finance_error'] = 'Не удалось выполнить правила разнесения: ' . $e->getMessage();
}

redirect_to('/company/finance/bank-accounts');
