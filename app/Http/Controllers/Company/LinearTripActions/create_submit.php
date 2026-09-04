<?php

use App\Service\DocumentService;
use App\Service\LinearRouteService;
use App\Service\LocalMigrationService;

requireRole(['company_owner', 'senior_logist', 'logist']);

$companyId = (int) (getSessionCompanyId() ?? 0);
$redirect = app_url('/company/trips/linear');
$old = $_POST;
$errors = [];

if ($companyId <= 0) {
    $_SESSION['linear_trip_form_error'] = 'Компания не найдена.';
    header('Location: ' . $redirect);
    exit;
}

$sessionUser = [
    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
    'role_code' => (string) ($_SESSION['role_code'] ?? ''),
];

$routeType = trim((string) ($_POST['route_type'] ?? ''));
$clientId = (int) ($_POST['client_id'] ?? 0);
$carrierId = (int) ($_POST['carrier_contractor_id'] ?? 0);
$routeExecutorId = (int) ($_POST['route_executor_id'] ?? 0);
$cargoTypeName = LinearRouteService::normalizeCargoTypeName((string) ($_POST['cargo_type_name'] ?? ''));
$startDateKind = trim((string) ($_POST['start_date_kind'] ?? 'plan'));
$endDateKind = trim((string) ($_POST['end_date_kind'] ?? ''));
$plannedLoadingDate = LinearRouteService::normalizeDate($_POST['planned_loading_date'] ?? '');
$plannedUnloadingDate = LinearRouteService::normalizeDate($_POST['planned_unloading_date'] ?? '');
$actualLoadingDate = LinearRouteService::normalizeDate($_POST['actual_loading_date'] ?? '');
$actualUnloadingDate = LinearRouteService::normalizeDate($_POST['actual_unloading_date'] ?? '');
if ($endDateKind === '') {
    $plannedUnloadingDate = null;
    $actualUnloadingDate = null;
}
$comments = trim((string) ($_POST['comments'] ?? ''));
$routePoints = \App\Service\LinearRoutePointService::normalizeSubmitted($_POST);
\App\Service\LinearRoutePointService::validate($routePoints, $errors);

$isFinanceRealm = ($sessionUser['role_code'] ?? '') === 'company_owner';

$parsePaymentRows = static function (array $rows, string $scopeLabel, string $scopeKey, array &$validationErrors): array {
    $result = [];

    foreach (array_values($rows) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $amountRaw = trim((string) ($row['amount'] ?? ''));
        $paymentMethod = trim((string) ($row['payment_method'] ?? ''));
        $vatRateRaw = trim((string) ($row['vat_rate'] ?? ''));
        $conditionType = trim((string) ($row['condition_type'] ?? ''));
        $daysCountRaw = trim((string) ($row['days_count'] ?? ''));
        $daysKind = trim((string) ($row['days_kind'] ?? ''));
        $specificDueDateRaw = trim((string) ($row['specific_due_date'] ?? ''));
        $conditionComment = trim((string) ($row['condition_comment'] ?? ''));

        $isEmpty = $amountRaw === '' && $paymentMethod === '' && $conditionType === '' && $daysCountRaw === '' && $daysKind === '' && $specificDueDateRaw === '' && $conditionComment === '';
        if ($isEmpty) {
            continue;
        }

        $amount = LinearRouteService::parseAmount($amountRaw);
        if ($amount === null) {
            $validationErrors[$scopeKey . '.' . $index . '.amount'] = 'Укажите сумму целым числом для блока «' . $scopeLabel . '».';
        }

        if (!in_array($paymentMethod, ['cashless', 'cash', ''], true)) {
            $validationErrors[$scopeKey . '.' . $index . '.payment_method'] = 'Выберите способ оплаты для блока «' . $scopeLabel . '».';
        }

        $vatRate = null;
        if ($vatRateRaw !== '' && $vatRateRaw !== null) {
            if (in_array($vatRateRaw, ['0', '5', '7', '20', '22'], true)) {
                $vatRate = $vatRateRaw;
            } else {
                $validationErrors[$scopeKey . '.' . $index . '.vat_rate'] = 'Выберите ставку НДС для блока «' . $scopeLabel . '».';
            }
        }

        $paymentType = LinearRouteService::legacyPaymentTypeFromMethodAndVat(
            $paymentMethod ?: 'cashless',
            $vatRate !== null ? (string) $vatRate : null
        );

        if (!in_array($conditionType, LinearRouteService::CONDITION_TYPES, true)) {
            $validationErrors[$scopeKey . '.' . $index . '.condition_type'] = 'Выберите корректный срок оплаты для блока «' . $scopeLabel . '».';
        }

        $daysCount = null;
        if (LinearRouteService::conditionTypeRequiresDays($conditionType)) {
            if ($daysCountRaw === '' || !ctype_digit($daysCountRaw) || (int) $daysCountRaw <= 0) {
                $validationErrors[$scopeKey . '.' . $index . '.days_count'] = 'Укажите количество дней целым положительным числом для блока «' . $scopeLabel . '».';
            } else {
                $daysCount = (int) $daysCountRaw;
            }

            if (!array_key_exists($daysKind, LinearRouteService::PAYMENT_DUE_DAYS_KINDS)) {
                $validationErrors[$scopeKey . '.' . $index . '.days_kind'] = 'Выберите тип дней для блока «' . $scopeLabel . '».';
            }
        } else {
            $daysCount = null;
            $daysKind = null;
        }

        $specificDueDate = null;
        if (LinearRouteService::conditionTypeRequiresSpecificDate($conditionType)) {
            $specificDueDate = LinearRouteService::normalizeDate($specificDueDateRaw);
            if ($specificDueDate === null) {
                $validationErrors[$scopeKey . '.' . $index . '.specific_due_date'] = 'Укажите конкретную дату для блока «' . $scopeLabel . '».';
            }
        }

        $result[] = [
            'amount' => $amount,
            'payment_type' => $paymentType,
            'payment_method' => $paymentMethod ?: 'cashless',
            'vat_rate' => $vatRate,
            'condition_type' => $conditionType,
            'days_count' => $daysCount,
            'days_kind' => $daysKind,
            'specific_due_date' => $specificDueDate,
            'condition_comment' => $conditionComment !== '' ? $conditionComment : null,
        ];
    }

    return $result;
};

$customerPayments = [];
$carrierPayments = [];
$principalRows = [];
$principalPaymentMap = [];

if ($isFinanceRealm) {
    $customerPayments = $parsePaymentRows((array) ($_POST['customer_payments'] ?? []), 'Заказчик', 'customer_payments', $errors);
    $carrierPayments = $parsePaymentRows((array) ($_POST['carrier_payments'] ?? []), 'Перевозчик', 'carrier_payments', $errors);

    if (empty($customerPayments)) {
        $errors['customer_payments'] = 'Добавьте хотя бы одну оплату для заказчика.';
    }
    if (empty($carrierPayments)) {
        $errors['carrier_payments'] = 'Добавьте хотя бы одну оплату для перевозчика.';
    }

    if ($routeType === LinearRouteService::ROUTE_TYPE_AGENCY) {
        foreach (array_values((array) ($_POST['principal_rows'] ?? [])) as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }

            $entityKey = trim((string) ($row['entity_key'] ?? ''));
            $payments = $parsePaymentRows((array) ($row['payments'] ?? []), 'Принципал', 'principal_rows.' . $rowIndex . '.payments', $errors);
            if ($entityKey === '' && empty($payments)) {
                continue;
            }

            $principal = LinearRouteService::parsePrincipalEntityKey($entityKey);
            if ($principal === null) {
                $errors['principal_rows.' . $rowIndex . '.entity_key'] = 'Выберите принципала.';
                continue;
            }

            $contractSide = LinearRouteService::principalContractSide(
                $principal['principal_type'],
                (int) $principal['principal_id'],
                $clientId,
                $carrierId
            );
            if ($contractSide === null) {
                $errors['principal_rows.' . $rowIndex . '.entity_key'] = 'Принципалом может быть только выбранный заказчик или выбранный перевозчик.';
                continue;
            }

            if (empty($payments)) {
                $errors['principal_rows.' . $rowIndex . '.payments'] = 'Добавьте хотя бы одну оплату для принципала.';
            }

            $normalizedKey = LinearRouteService::principalEntityKey($principal['principal_type'], (int) $principal['principal_id']);
            if (isset($principalPaymentMap[$normalizedKey])) {
                $errors['principal_rows.' . $rowIndex . '.entity_key'] = 'Такой принципал уже добавлен.';
                continue;
            }

            $principalRows[] = $principal;
            $principalPaymentMap[$normalizedKey] = $payments;
        }

        if (empty($principalRows)) {
            $errors['principal_rows'] = 'Добавьте хотя бы одного принципала.';
        }
    }
}

if (!in_array($routeType, [LinearRouteService::ROUTE_TYPE_LINEAR, LinearRouteService::ROUTE_TYPE_AGENCY], true)) {
    $errors['route_type'] = 'Выберите тип рейса.';
}
if ($clientId <= 0) {
    $errors['client_id'] = 'Выберите заказчика.';
}
if ($carrierId <= 0) {
    $errors['carrier_contractor_id'] = 'Выберите перевозчика.';
}
if ($routeExecutorId <= 0) {
    $errors['route_executor_id'] = 'Выберите исполнителя рейса.';
}
if ($cargoTypeName === '') {
    $errors['cargo_type_name'] = 'Укажите перевозимый груз.';
}
if (!in_array($startDateKind, ['plan', 'fact'], true)) {
    $errors['start_date_kind'] = 'Выберите плановую или фактическую дату начала рейса.';
} elseif ($startDateKind === 'plan' && $plannedLoadingDate === null) {
    $errors['planned_loading_date'] = 'Укажите плановую дату начала рейса.';
} elseif ($startDateKind === 'fact' && $actualLoadingDate === null) {
    $errors['actual_loading_date'] = 'Укажите фактическую дату начала рейса.';
}
if (!in_array($endDateKind, ['', 'plan', 'fact'], true)) {
    $errors['end_date_kind'] = 'Выберите плановую или фактическую дату окончания рейса.';
} elseif ($endDateKind === 'plan' && $plannedUnloadingDate === null) {
    $errors['planned_unloading_date'] = 'Укажите плановую дату окончания рейса.';
} elseif ($endDateKind === 'fact' && $actualUnloadingDate === null) {
    $errors['actual_unloading_date'] = 'Укажите фактическую дату окончания рейса.';
}
if ($plannedLoadingDate !== null && $plannedUnloadingDate !== null && $plannedUnloadingDate < $plannedLoadingDate) {
    $errors['planned_unloading_date'] = 'Плановое окончание рейса не может быть раньше планового начала.';
}
if ($actualLoadingDate !== null && $actualUnloadingDate !== null && $actualUnloadingDate < $actualLoadingDate) {
    $errors['actual_unloading_date'] = 'Фактическое окончание рейса не может быть раньше фактического начала.';
}

try {
    $pdo = $db->connection();
    $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company || ($company['status'] ?? '') !== 'active') {
        $_SESSION['linear_trip_form_error'] = 'Компания недоступна для работы.';
        $_SESSION['linear_trip_old'] = $old;
        header('Location: ' . $redirect);
        exit;
    }

    $localDbConfig = companyDatabaseConfig($config, $company);
    $localDb = new \App\Core\Database($localDbConfig);
    $localPdo = $localDb->connection();
    applyLocalMigrations($localPdo);

    if ($clientId > 0 && !LinearRouteService::isVisibleEntity($localPdo, 'clients', $clientId, $sessionUser, 'client')) {
        $errors['client_id'] = 'Заказчик недоступен.';
    }
    if ($carrierId > 0 && !LinearRouteService::isVisibleEntity($localPdo, 'contractors', $carrierId, $sessionUser, 'contractor')) {
        $errors['carrier_contractor_id'] = 'Перевозчик недоступен.';
    }

    $visibleExecutors = LinearRouteService::fetchVisibleRouteExecutors($localPdo, $sessionUser);
    $visibleExecutorIds = array_map(static fn(array $row): int => (int) $row['id'], $visibleExecutors);
    if ($routeExecutorId > 0 && !in_array($routeExecutorId, $visibleExecutorIds, true)) {
        $errors['route_executor_id'] = 'Исполнитель рейса недоступен.';
    }

    if (!empty($errors)) {
        $_SESSION['linear_trip_form_error'] = 'Форма содержит ошибки. Проверьте обязательные поля.';
        $_SESSION['linear_trip_validation_errors'] = $errors;
        $_SESSION['linear_trip_old'] = $old;
        header('Location: ' . $redirect);
        exit;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $roleCode = (string) ($_SESSION['role_code'] ?? '');
    $cargoTypeId = LinearRouteService::createOrFindCargoType($localPdo, $cargoTypeName, $userId, $roleCode);

    $ensureDocumentSchema = static function (PDO $localPdo): void {
        try {
            $localPdo->query('SELECT 1 FROM documents LIMIT 1')->fetch();
        } catch (\Throwable $e) {
            $localPdo->exec(file_get_contents(base_path('database/migrations-local/007_create_company_documents.sql')));
        }
        try {
            $localPdo->query('SELECT 1 FROM document_types LIMIT 1')->fetch();
        } catch (\Throwable $e) {
            $localPdo->exec(file_get_contents(base_path('database/migrations-local/024_create_document_types.sql')));
            $localPdo->exec(file_get_contents(base_path('database/migrations-local/025_add_document_type_id.sql')));
        }
        try {
            $localPdo->query('SELECT created_by_user_id FROM documents LIMIT 1')->fetch();
        } catch (\Throwable $e) {
            $localPdo->exec("ALTER TABLE documents ADD COLUMN created_by_user_id INT UNSIGNED DEFAULT NULL, ADD COLUMN created_by_role VARCHAR(20) DEFAULT NULL");
        }
    };

    $saveUploadedDocument = static function (
        PDO $localPdo,
        int $companyId,
        int $routeId,
        string $documentName,
        string $documentCode,
        array $file,
        int $userId,
        string $roleCode,
        string $category = 'predefined'
    ): void {
        $originalName = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, DocumentService::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException('Недопустимое расширение файла: ' . $documentName);
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if (!DocumentService::isFileSizeValid($fileSize)) {
            throw new \RuntimeException('Размер файла превышает лимит: ' . $documentName);
        }

        $relativeDir = 'companies/' . $companyId . '/documents/linear_route/' . $routeId;
        $absoluteDir = storage_path($relativeDir);
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            throw new \RuntimeException('Не удалось создать директорию документов.');
        }

        $storedName = DocumentService::makeSafeFilename($originalName);
        $destination = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
            throw new \RuntimeException('Не удалось сохранить файл: ' . $documentName);
        }

        $documentTypeId = LocalMigrationService::ensureDocumentTypeRecord($localPdo, $documentName, $documentCode, 'linear_route', $category);
        $insertDocument = $localPdo->prepare(
            'INSERT INTO documents (
                entity_type,
                entity_id,
                document_type,
                document_type_id,
                original_name,
                stored_name,
                relative_path,
                mime_type,
                file_size,
                status,
                uploaded_by_user_id,
                uploaded_by_role,
                created_by_user_id,
                created_by_role
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertDocument->execute([
            'linear_route',
            $routeId,
            $documentName,
            $documentTypeId,
            $originalName,
            $storedName,
            $relativeDir . '/' . $storedName,
            (string) ($file['type'] ?? 'application/octet-stream'),
            $fileSize,
            'uploaded',
            $userId,
            $roleCode,
            $userId,
            $roleCode,
        ]);
    };

    $syncLegacyTerms = static function (
        PDO $localPdo,
        int $routeId,
        array $customerPayments,
        array $carrierPayments,
        array $principalPaymentMap,
        int $userId,
        string $roleCode
    ): void {
        $upsertTerm = $localPdo->prepare(
            "INSERT INTO linear_route_financial_terms (
                linear_route_id,
                party_role,
                amount,
                payment_type,
                payment_due_type,
                payment_due_days,
                payment_due_days_kind,
                created_by_user_id,
                created_by_role,
                updated_by_user_id,
                updated_by_role
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                amount = VALUES(amount),
                payment_type = VALUES(payment_type),
                payment_due_type = VALUES(payment_due_type),
                payment_due_days = VALUES(payment_due_days),
                payment_due_days_kind = VALUES(payment_due_days_kind),
                deleted_at = NULL,
                deleted_by_user_id = NULL,
                deleted_by_role = NULL,
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_by_role = VALUES(updated_by_role)"
        );

        $legacyRows = [
            'customer' => $customerPayments[0] ?? null,
            'carrier' => $carrierPayments[0] ?? null,
            'principal' => null,
        ];
        foreach ($principalPaymentMap as $payments) {
            if (!empty($payments[0])) {
                $legacyRows['principal'] = $payments[0];
                break;
            }
        }

        foreach ($legacyRows as $partyRole => $term) {
            if ($term === null) {
                $localPdo->prepare(
                    "UPDATE linear_route_financial_terms
                        SET deleted_at = NOW(),
                            deleted_by_user_id = ?,
                            deleted_by_role = ?
                      WHERE linear_route_id = ?
                        AND party_role = ?
                        AND deleted_at IS NULL"
                )->execute([$userId, $roleCode, $routeId, $partyRole]);
                continue;
            }

            $upsertTerm->execute([
                $routeId,
                $partyRole,
                $term['amount'],
                $term['payment_type'],
                $term['payment_due_type'],
                $term['payment_due_days'],
                $term['payment_due_days_kind'],
                $userId,
                $roleCode,
                $userId,
                $roleCode,
            ]);
        }
    };

    $membershipId = \App\Service\RegulatedDataScopeService::assertCanCreate('linear_trip');
    $localPdo->beginTransaction();

    $insertRoute = $localPdo->prepare(
        "INSERT INTO linear_routes (
            route_type,
            client_id,
            carrier_contractor_id,
            principal_type,
            principal_id,
            agency_contract_with,
            route_executor_id,
            cargo_type_id,
            planned_loading_date,
            planned_unloading_date,
            actual_loading_date,
            actual_unloading_date,
            status,
            comments,
            created_by_user_id,
            created_by_membership_id,
            created_by_role,
            updated_by_user_id,
            updated_by_role
        ) VALUES (
            :route_type,
            :client_id,
            :carrier_contractor_id,
            NULL,
            NULL,
            NULL,
            :route_executor_id,
            :cargo_type_id,
            :planned_loading_date,
            :planned_unloading_date,
            :actual_loading_date,
            :actual_unloading_date,
            'active',
            :comments,
            :created_by_user_id,
            :created_by_membership_id,
            :created_by_role,
            :updated_by_user_id,
            :updated_by_role
        )"
    );
    $insertRoute->execute([
        ':route_type' => $routeType,
        ':client_id' => $clientId,
        ':carrier_contractor_id' => $carrierId,
        ':route_executor_id' => $routeExecutorId,
        ':cargo_type_id' => $cargoTypeId,
        ':planned_loading_date' => $plannedLoadingDate,
        ':planned_unloading_date' => $plannedUnloadingDate,
        ':actual_loading_date' => $actualLoadingDate,
        ':actual_unloading_date' => $actualUnloadingDate,
        ':comments' => $comments !== '' ? $comments : null,
        ':created_by_user_id' => $userId,
        ':created_by_membership_id' => $membershipId,
        ':created_by_role' => $roleCode,
        ':updated_by_user_id' => $userId,
        ':updated_by_role' => $roleCode,
    ]);
    $linearRouteId = (int) $localPdo->lastInsertId();
    \App\Service\LinearRoutePointService::store($localPdo, $linearRouteId, $routePoints, $userId, $roleCode);

    $storedPrincipals = [];
    if ($isFinanceRealm) {
        if ($routeType === LinearRouteService::ROUTE_TYPE_AGENCY) {
            $storedPrincipals = LinearRouteService::storeRoutePrincipals(
                $localPdo,
                $linearRouteId,
                $principalRows,
                $clientId,
                $carrierId,
                $userId,
                $roleCode
            );
            LinearRouteService::syncLegacyRoutePrincipalFields(
                $localPdo,
                $linearRouteId,
                $storedPrincipals,
                $clientId,
                $carrierId,
                $userId,
                $roleCode
            );
        }

        LinearRouteService::storeRoutePayments(
            $localPdo,
            $linearRouteId,
            $customerPayments,
            $carrierPayments,
            $principalPaymentMap,
            $storedPrincipals,
            $userId,
            $roleCode,
            [
                'planned_loading_date' => $plannedLoadingDate,
                'planned_unloading_date' => $plannedUnloadingDate,
                'actual_loading_date' => $actualLoadingDate,
                'actual_unloading_date' => $actualUnloadingDate,
            ]
        );
        $syncLegacyTerms($localPdo, $linearRouteId, $customerPayments, $carrierPayments, $principalPaymentMap, $userId, $roleCode);
        LinearRouteService::reconcileLegacyPaymentRows($localPdo, $linearRouteId);
    }

    $ensureDocumentSchema($localPdo);

    foreach (LinearRouteService::routeDocumentDefinitions($routeType) as $inputName => $meta) {
        if (empty($_FILES[$inputName]) || (int) ($_FILES[$inputName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ((int) ($_FILES[$inputName]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Ошибка загрузки документа: ' . $meta['name']);
        }

        $saveUploadedDocument(
            $localPdo,
            $companyId,
            $linearRouteId,
            $meta['name'],
            $meta['code'],
            $_FILES[$inputName],
            $userId,
            $roleCode
        );
    }

    $customDocTypes = (array) ($_POST['custom_doc_type'] ?? []);
    $customDocFiles = $_FILES['custom_doc_file'] ?? null;
    if (is_array($customDocFiles) && isset($customDocFiles['name']) && is_array($customDocFiles['name'])) {
        foreach ($customDocFiles['name'] as $index => $name) {
            $docTypeName = trim((string) ($customDocTypes[$index] ?? ''));
            $fileError = (int) ($customDocFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($docTypeName === '' && $fileError === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($docTypeName === '') {
                throw new \RuntimeException('Укажите название пользовательского документа.');
            }
            if ($fileError !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Загрузите файл для документа «' . $docTypeName . '».');
            }

            $saveUploadedDocument(
                $localPdo,
                $companyId,
                $linearRouteId,
                $docTypeName,
                '',
                [
                    'name' => $customDocFiles['name'][$index] ?? '',
                    'type' => $customDocFiles['type'][$index] ?? 'application/octet-stream',
                    'tmp_name' => $customDocFiles['tmp_name'][$index] ?? '',
                    'error' => $fileError,
                    'size' => $customDocFiles['size'][$index] ?? 0,
                ],
                $userId,
                $roleCode,
                'custom'
            );
        }
    }

    if ($isFinanceRealm) {
        LinearRouteService::logFinanceAudit(
            $localPdo,
            'linear_route',
            $linearRouteId,
            'route_payments_create',
            null,
            ['payments' => LinearRouteService::fetchRoutePayments($localPdo, $linearRouteId)],
            $userId,
            $roleCode
        );
    }

    $localPdo->commit();

    $_SESSION['linear_trip_form_success'] = 'Рейс #' . $linearRouteId . ' успешно создан.';
    header('Location: ' . $redirect);
    exit;
} catch (\Throwable $e) {
    if (isset($localPdo) && $localPdo instanceof PDO && $localPdo->inTransaction()) {
        $localPdo->rollBack();
    }

    $_SESSION['linear_trip_form_error'] = 'Не удалось создать рейс: ' . $e->getMessage();
    $_SESSION['linear_trip_validation_errors'] = $errors;
    $_SESSION['linear_trip_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}
