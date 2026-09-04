<?php

    verifyCsrfRequest();

    $companyId = (int)(getSessionCompanyId() ?? 0);
    if ($companyId <= 0) {
        $_SESSION['finance_error'] = 'Компания не найдена.';
        redirect_to('/company/finance/cash');
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND status = \'active\'');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            $_SESSION['finance_error'] = 'Компания не найдена или неактивна.';
            redirect_to('/company/finance/cash');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $user = [
            'id' => $_SESSION['user_id'] ?? null,
            'role' => $_SESSION['role_code'] ?? null,
            'user_id' => $_SESSION['user_id'] ?? null,
            'role_code' => $_SESSION['role_code'] ?? null,
        ];
        $scenario = strtoupper(trim((string)($_POST['scenario'] ?? 'CASH_OPERATION')));
        $payload = $_POST;

        if ($scenario === 'CLIENT_CASH_INVOICE') {
            $result = \App\Service\FinanceCashInvoiceEventService::createClientCashReceipt($localPdo, $payload, $user);
            $_SESSION['finance_success'] = 'Получено от клиента ' . \App\Service\FinanceCashService::formatAmount($result['amount']) . ' ₽. '
                . 'На счёт ' . $result['invoice_number'] . ' зачтено ' . \App\Service\FinanceCashService::formatAmount($result['allocated_amount']) . ' ₽.';
        } elseif ($scenario === 'MAIN_CASH_INVOICE_PAYMENT') {
            $result = \App\Service\FinanceCashInvoiceEventService::payCarrierInvoiceFromMainCash($localPdo, $payload, $user);
            $_SESSION['finance_success'] = 'Из Основной кассы оплачено ' . \App\Service\FinanceCashService::formatAmount($result['amount'])
                . ' ₽ по входящему счёту ' . $result['invoice_number'] . ' · ' . $result['carrier_name'] . '.';
        } elseif ($scenario === 'MAIN_CASH_EMPLOYEE_TRANSFER') {
            $employee = \App\Service\FinanceEmployeePaymentService::resolveActiveEmployee(
                $localPdo,
                $pdo,
                $companyId,
                trim((string)($payload['employee_ref'] ?? ''))
            );
            $result = \App\Service\FinanceCashInvoiceEventService::transferMainCashToEmployee($localPdo, $payload, $user, $employee);
            $_SESSION['finance_success'] = 'Передано сотруднику ' . $result['employee']['full_name'] . ': '
                . \App\Service\FinanceCashService::formatAmount($result['amount']) . ' ₽.';
        } elseif ($scenario === 'CLIENT_CASH_RECEIPT') {
            throw new \InvalidArgumentException('Старая форма оплаты клиента отключена. Обновите страницу и выберите «Получено от клиента по счёту».');
        } elseif ($scenario === 'EMPLOYEE_PERSONAL_EXPENSE') {
            throw new \InvalidArgumentException('Расход компании из личных средств сотрудника оформляется в разделе «Выплаты сотрудникам».');
        } elseif ($scenario === 'CASH_OPERATION') {
            \App\Service\FinanceCashService::createCashOperation($localPdo, $payload, $user);
            $typeLabel = ($payload['operation_type'] ?? '') === 'INCOME' ? 'Приход' : 'Расход';
            $_SESSION['finance_success'] = $typeLabel . ' успешно проведён.';
        } else {
            throw new \InvalidArgumentException('Неизвестный вид операции.');
        }
    } catch (\InvalidArgumentException $e) {
        $_SESSION['finance_error'] = $e->getMessage();
    } catch (\RuntimeException $e) {
        $_SESSION['finance_error'] = $e->getMessage();
    } catch (\Throwable $e) {
        $_SESSION['finance_error'] = 'Ошибка при проведении операции: ' . $e->getMessage();
    }

    redirect_to('/company/finance/cash');
