<?php
/** @var VehicleSetService $service */

$companyId = $service->getCompanyId();
if ($companyId <= 0) { http_response_code(404); echo '<div class="notice warn">Компания не найдена.</div>'; exit; }

try {
    $company = $service->loadCompany($companyId);
    if (!$company || ($company['status'] ?? '') !== 'active') { http_response_code(404); echo '<div class="notice warn">Компания не найдена или неактивна.</div>'; exit; }
    $localPdo = $service->getLocalPdo($company);
    $vs = $service->getVehicleSetById($localPdo, (int) $id);
    if (!$vs) { http_response_code(404); echo '<div class="notice warn">Транспорт не найден.</div>'; exit; }

    $roleCode = (string) ($_SESSION['role_code'] ?? '');
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $canEdit = vehicleSetAllUnitsAccessible($localPdo, $vs);
    if (!$canEdit) { http_response_code(403); echo '<div class="notice warn">У вас нет права редактировать эту запись.</div>'; exit; }

    $rules = vehicleSetTypeRules();
    $setType = (string) ($vs['set_type'] ?? 'single');
    $rule = $rules[$setType] ?? null;
    if (!$rule) throw new RuntimeException('Неизвестный тип комплекта.');

    $postedUnits = isset($_POST['units']) && is_array($_POST['units']) ? $_POST['units'] : [];
    $errors = [];
    $unitPayload = [];
    foreach (['primary', 'secondary'] as $role) {
        $roleRule = $rule['units'][$role] ?? null;
        if (!$roleRule) continue;
        $source = isset($postedUnits[$role]) && is_array($postedUnits[$role]) ? $postedUnits[$role] : [];
        $payload = [
            'brand' => trim((string) ($source['brand'] ?? '')),
            'model' => trim((string) ($source['model'] ?? '')),
            'plate_number' => trim((string) ($source['plate_number'] ?? '')),
            'vin' => strtoupper(trim((string) ($source['vin'] ?? ''))),
            'diagnostic_card_number' => trim((string) ($source['diagnostic_card_number'] ?? '')),
            'diagnostic_card_date' => trim((string) ($source['diagnostic_card_date'] ?? '')),
            'capacity_tons' => trim((string) ($source['capacity_tons'] ?? '')),
            'volume_m3' => trim((string) ($source['volume_m3'] ?? '')),
        ];

        // Keep edit validation aligned with create: only plate is required.
        // All technical attributes may legitimately be added later or cleared.
        if ($payload['plate_number'] === '') {
            $errors['units'][$role]['plate_number'] = 'Укажите госномер';
        }
        if ($payload['vin'] !== '' && !preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $payload['vin'])) {
            $errors['units'][$role]['vin'] = 'Формат VIN: 17 символов';
        }
        if ($payload['diagnostic_card_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['diagnostic_card_date'])) {
            $errors['units'][$role]['diagnostic_card_date'] = 'Укажите корректную дату';
        }
        if (!empty($roleRule['show_capacity']) && $payload['capacity_tons'] !== ''
            && (!is_numeric($payload['capacity_tons']) || (float) $payload['capacity_tons'] <= 0)) {
            $errors['units'][$role]['capacity_tons'] = 'Укажите корректную грузоподъёмность';
        }
        if (!empty($roleRule['show_volume']) && $payload['volume_m3'] !== ''
            && (!is_numeric($payload['volume_m3']) || (float) $payload['volume_m3'] <= 0)) {
            $errors['units'][$role]['volume_m3'] = 'Укажите корректный объём кузова';
        }
        $unitPayload[$role] = $payload;
    }

    $loadEditContext = static function () use ($service, $localPdo, $vs): array {
        $unitIds = array_values(array_filter([(int)($vs['primary_vehicle_unit_id'] ?? 0), (int)($vs['secondary_vehicle_unit_id'] ?? 0)]));
        $unitsByRole = ['primary' => [], 'secondary' => []];
        $docsByRole = ['primary' => [], 'secondary' => []];
        if ($unitIds) {
            foreach ($service->getUnitsByIds($localPdo, $unitIds) as $unit) {
                $uid = (int) ($unit['id'] ?? 0);
                if ($uid === (int) ($vs['primary_vehicle_unit_id'] ?? 0)) $unitsByRole['primary'] = $unit;
                elseif ($uid === (int) ($vs['secondary_vehicle_unit_id'] ?? 0)) $unitsByRole['secondary'] = $unit;
            }
            foreach ($service->getDocsForUnits($localPdo, $unitIds) as $doc) {
                $eid = (int) ($doc['entity_id'] ?? 0);
                if ($eid === (int) ($vs['primary_vehicle_unit_id'] ?? 0)) $docsByRole['primary'][] = $doc;
                elseif ($eid === (int) ($vs['secondary_vehicle_unit_id'] ?? 0)) $docsByRole['secondary'][] = $doc;
            }
        }
        return [$unitIds, $unitsByRole, $docsByRole];
    };

    if ($errors) {
        [, $unitsByRole, $docsByRole] = $loadEditContext();
        $old = ['set_type' => $setType, 'status' => $vs['status'] ?? 'active', 'comments' => $_POST['comments'] ?? '', 'units' => $postedUnits];
        $formError = null;
        $vehicleSet = $vs;
        header('Content-Type: text/html; charset=utf-8');
        require base_path('app/View/partials/company_vehicle_set_modal_edit.php');
        exit;
    }

    $localPdo->beginTransaction();
    try {
        $status = trim((string) ($_POST['status'] ?? $vs['status'] ?? 'active'));
        if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
        $comments = trim((string) ($_POST['comments'] ?? ''));
        $localPdo->prepare('UPDATE vehicle_sets SET status=?,comments=?,updated_by_user_id=?,updated_by_role=? WHERE id=?')
            ->execute([$status, $comments !== '' ? $comments : null, $userId, $roleCode, (int) $id]);

        $updateUnit = $localPdo->prepare('UPDATE vehicle_units SET brand=:brand,model=:model,plate_number=:plate_number,vin=:vin,diagnostic_card_number=:diagnostic_card_number,diagnostic_card_date=:diagnostic_card_date,capacity_tons=:capacity_tons,volume_m3=:volume_m3,status=:status,updated_by_user_id=:uid,updated_by_role=:role WHERE id=:id');
        foreach ($unitPayload as $role => $payload) {
            $unitId = $role === 'primary' ? (int) ($vs['primary_vehicle_unit_id'] ?? 0) : (int) ($vs['secondary_vehicle_unit_id'] ?? 0);
            if ($unitId <= 0) continue;
            $roleRule = $rule['units'][$role] ?? [];
            $updateUnit->execute([
                ':brand' => $payload['brand'],
                ':model' => $payload['model'],
                ':plate_number' => $payload['plate_number'],
                ':vin' => $payload['vin'],
                ':diagnostic_card_number' => $payload['diagnostic_card_number'] !== '' ? $payload['diagnostic_card_number'] : null,
                ':diagnostic_card_date' => $payload['diagnostic_card_date'] !== '' ? $payload['diagnostic_card_date'] : null,
                ':capacity_tons' => !empty($roleRule['show_capacity']) && $payload['capacity_tons'] !== '' ? (float) $payload['capacity_tons'] : null,
                ':volume_m3' => !empty($roleRule['show_volume']) && $payload['volume_m3'] !== '' ? (float) $payload['volume_m3'] : null,
                ':status' => $status,
                ':uid' => $userId,
                ':role' => $roleCode,
                ':id' => $unitId,
            ]);
        }
        $localPdo->commit();
    } catch (Throwable $e) {
        if ($localPdo->inTransaction()) $localPdo->rollBack();
        throw $e;
    }

    require_once base_path('app/Support/legal_entity_document_upload.php');
    $docErrors = [];
    $unitIdsByRole = [
        'primary' => (int) ($vs['primary_vehicle_unit_id'] ?? 0),
        'secondary' => (int) ($vs['secondary_vehicle_unit_id'] ?? 0),
    ];

    $sizeError = function_exists('validateTotalUploadSize') ? validateTotalUploadSize() : '';
    if ($sizeError !== '') $docErrors[] = $sizeError;

    if ($docErrors === []) {
        foreach (($_POST['delete_existing_doc'] ?? []) as $docId => $flag) {
            if ($flag !== '1') continue;
            foreach ($unitIdsByRole as $unitId) {
                if ($unitId <= 0) continue;
                $check = $localPdo->prepare("SELECT id FROM documents WHERE id=? AND entity_type='vehicle_unit' AND entity_id=? AND deleted_at IS NULL LIMIT 1");
                $check->execute([(int)$docId, $unitId]);
                if ($check->fetchColumn()) {
                    softDeleteEntityDocument($localPdo, (int)$docId, 'vehicle_unit', $unitId, $userId, 'Archived via vehicle modal edit');
                    break;
                }
            }
        }

        $existingFiles = $_FILES['existing_doc_file'] ?? [];
        if (!empty($existingFiles['name']) && is_array($existingFiles['name'])) {
            foreach ($existingFiles['name'] as $docId => $origName) {
                if (($existingFiles['error'][$docId] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || trim((string)$origName) === '') continue;
                foreach ($unitIdsByRole as $unitId) {
                    if ($unitId <= 0) continue;
                    $check = $localPdo->prepare("SELECT id FROM documents WHERE id=? AND entity_type='vehicle_unit' AND entity_id=? AND deleted_at IS NULL LIMIT 1");
                    $check->execute([(int)$docId, $unitId]);
                    if (!$check->fetchColumn()) continue;
                    try {
                        replaceEntityDocument($localPdo, (int)$docId, $unitId, 'vehicle_unit', $companyId, [
                            'name' => (string)$origName,
                            'tmp_name' => (string)($existingFiles['tmp_name'][$docId] ?? ''),
                            'type' => (string)($existingFiles['type'][$docId] ?? ''),
                            'size' => (int)($existingFiles['size'][$docId] ?? 0),
                        ], $userId, $roleCode);
                    } catch (Throwable $e) {
                        $docErrors[] = 'Не удалось заменить документ транспорта: ' . $e->getMessage();
                    }
                    break;
                }
            }
        }

        foreach ($unitIdsByRole as $unitRole => $unitId) {
            if ($unitId <= 0) continue;
            $customFiles = function_exists('vehicleSetNormalizeRoleFiles') ? vehicleSetNormalizeRoleFiles($_FILES['custom_doc_file'] ?? [], $unitRole) : [];
            $customTitles = $_POST['custom_doc_type'][$unitRole] ?? [];
            if (!is_array($customTitles)) $customTitles = [];
            foreach ($customFiles as $idx => $fileInfo) {
                if (($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || trim((string)($fileInfo['name'] ?? '')) === '') continue;
                $title = trim((string)($customTitles[$idx] ?? ''));
                if ($title === '') {
                    $docErrors[] = 'Введите название произвольного документа транспорта';
                    continue;
                }
                $extension = strtolower(pathinfo((string)$fileInfo['name'], PATHINFO_EXTENSION));
                if (!in_array($extension, legalEntityDocumentAllowedExtensions(), true)) {
                    $docErrors[] = 'Произвольный документ «' . $title . '»: недопустимый формат';
                    continue;
                }
                if ((int)($fileInfo['size'] ?? 0) > legalEntityDocumentMaxFileSize()) {
                    $docErrors[] = 'Произвольный документ «' . $title . '»: размер > 20 МБ';
                    continue;
                }
                try {
                    $typeId = createLegalEntityCustomDocumentType($localPdo, 'vehicle_unit', $title, $userId, $roleCode);
                    storeLegalEntityDocumentRecord($localPdo, $companyId, 'vehicle_unit', $unitId, $title, $typeId, $fileInfo, $userId, $roleCode);
                } catch (Throwable $e) {
                    $docErrors[] = 'Не удалось добавить документ «' . $title . '»: ' . $e->getMessage();
                }
            }
        }
    }

    $vs = $service->getVehicleSetById($localPdo, (int) $id);
    $unitIds = array_values(array_filter([(int)($vs['primary_vehicle_unit_id'] ?? 0), (int)($vs['secondary_vehicle_unit_id'] ?? 0)]));
    $unitsByRole = ['primary' => [], 'secondary' => []];
    $docsByRole = ['primary' => [], 'secondary' => []];
    foreach ($service->getUnitsByIds($localPdo, $unitIds) as $unit) {
        $uid = (int)($unit['id'] ?? 0);
        if ($uid === (int)($vs['primary_vehicle_unit_id'] ?? 0)) $unitsByRole['primary'] = $unit;
        elseif ($uid === (int)($vs['secondary_vehicle_unit_id'] ?? 0)) $unitsByRole['secondary'] = $unit;
    }
    foreach ($service->getDocsForUnits($localPdo, $unitIds) as $doc) {
        $eid = (int)($doc['entity_id'] ?? 0);
        if ($eid === (int)($vs['primary_vehicle_unit_id'] ?? 0)) $docsByRole['primary'][] = $doc;
        elseif ($eid === (int)($vs['secondary_vehicle_unit_id'] ?? 0)) $docsByRole['secondary'][] = $doc;
    }

    if ($docErrors !== []) {
        $old = ['set_type' => $setType, 'status' => $vs['status'] ?? 'active', 'comments' => $_POST['comments'] ?? '', 'units' => $postedUnits];
        $formError = 'Ошибка при обработке документов: ' . implode('; ', $docErrors);
        $vehicleSet = $vs;
        header('Content-Type: text/html; charset=utf-8');
        require base_path('app/View/partials/company_vehicle_set_modal_edit.php');
        exit;
    }

    $unitTitles = [
        'primary' => $rule['units']['primary']['label'] ?? 'Основная единица',
        'secondary' => $rule['units']['secondary']['label'] ?? 'Доп. единица',
    ];
    $vehicleSet = $vs;
    $canDelete = $canEdit;
    header('Content-Type: text/html; charset=utf-8');
    require base_path('app/View/partials/company_vehicle_set_modal_view.php');
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo '<div class="notice warn">Ошибка сохранения транспорта: ' . e($e->getMessage()) . '</div>';
    exit;
}
