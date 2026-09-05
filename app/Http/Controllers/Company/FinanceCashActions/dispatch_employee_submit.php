<?php

    verifyCsrfRequest();

    $companyId = (int)(getSessionCompanyId() ?? 0);
    if ($companyId <= 0) {
        $_SESSION['finance_error'] = 'Компания не найдена.';
        redirect_to('/company/finance/cash');
        return;
    }

    try {
        $centralPdo = $db->connection();
        $stmt = $centralPdo->prepare("SELECT * FROM companies WHERE id = ? AND status = 'active'");
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) {
            throw new \RuntimeException('Компания не найдена или неактивна.');
        }

        $localPdo = (new \App\Core\Database(companyDatabaseConfig($config, $company)))->connection();
        applyLocalMigrations($localPdo);

        $sourceIds = $_POST['source_operation_ids'] ?? [];
        if (!is_array($sourceIds)) $sourceIds = [$sourceIds];
        $employeeRef = trim((string)($_POST['employee_ref'] ?? ''));
        if ($employeeRef === '') {
            throw new \InvalidArgumentException('Выберите сотрудника.');
        }

        $result = \App\Service\FinanceCashResolutionService::dispatchToEmployee(
            $localPdo,
            $centralPdo,
            $companyId,
            $sourceIds,
            $employeeRef,
            [
                'id' => $_SESSION['user_id'] ?? null,
                'role' => $_SESSION['role_code'] ?? 'company_owner',
            ]
        );

        $_SESSION['finance_success'] = sprintf(
            'Передано сотруднику %s: %d поз. на %s ₽.',
            $result['employee_name'],
            $result['count'],
            \App\Service\FinanceCashService::formatAmount($result['total'])
        );
    } catch (\InvalidArgumentException|\RuntimeException $e) {
        $_SESSION['finance_error'] = $e->getMessage();
    } catch (\Throwable $e) {
        $_SESSION['finance_error'] = 'Не удалось передать позиции сотруднику: ' . $e->getMessage();
    }

    redirect_to('/company/finance/cash');