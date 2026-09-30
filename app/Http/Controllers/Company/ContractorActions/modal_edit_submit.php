<?php
/** @var ContractorService $service */
use App\Service\ContractorContactService;

requireRole(['company_owner', 'senior_logist', 'logist']);

$renderMessage = static function (string $message): void {
    echo '<div class="modal-body"><div class="notice warn modal-notice">' . e($message) . '</div></div>';
    echo '<div class="modal-foot is-spaced"><div class="modal-foot-actions"><button type="button" class="btn btn-ghost" data-contractor-view-close-btn>Закрыть</button></div></div>';
};

$renderEditForm = static function (
    array $contractor,
    array $old,
    array $errors,
    ?string $formError,
    array $leExistingDocs,
    array $leDocTypes,
    array $leContactValues,
    array $leContactErrors
): void {
    require base_path('app/View/partials/company_contractor_modal_edit.php');
};

$companyId = $service->getCompanyId();
if ($companyId <= 0) {
    $renderMessage('Компания не найдена.');
    return;
}

try {
    $company = $service->loadCompany($companyId);
    if (!$company || ($company['status'] ?? '') !== 'active') {
        $renderMessage('Компания недоступна.');
        return;
    }

    $localPdo = $service->getLocalPdo($company);

    $contractor = $service->getContractorById($localPdo, (int) $id);
    if (!$contractor) {
        $renderMessage('Перевозчик не найден.');
        return;
    }

    $roleCode = (string) ($_SESSION['role_code'] ?? '');
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $contacts = ContractorContactService::loadByContractorId($localPdo, (int) $id);
    $old = array_merge($contractor, $_POST);
    $old['contacts'] = $_POST['contacts'] ?? ($contacts !== [] ? $contacts : contractorFormDefaultContacts());
    $formError = null;

    $contactPayload = ContractorContactService::normalizeSubmittedContacts($_POST['contacts'] ?? []);
    $submittedContacts = $contactPayload['contacts'];
    $errors = $service->validateContractor($_POST, $localPdo, (int) $id);
    if (!empty($contactPayload['errors'])) {
        $errors['contacts'] = $contactPayload['errors'];
    }

    $leDocTypes = [];
    $leExistingDocs = [];
    try {
        $service->ensureDocumentTables($localPdo);
        $leDocTypes = $service->getDocTypes($localPdo, 'contractor');
    } catch (\Exception $e) {
        $leDocTypes = [];
    }

    require_once base_path('app/Support/legal_entity_document_upload.php');
    try {
        $leExistingDocs = loadEntityDocuments($localPdo, (int) $id, 'contractor');
    } catch (\Exception $e) {
        $leExistingDocs = [];
    }

    if (!empty($errors)) {
        $leContactValues = $old['contacts'] ?? [];
        $leContactErrors = $errors['contacts'] ?? [];
        $renderEditForm($contractor, $old, $errors, $formError, $leExistingDocs, $leDocTypes, $leContactValues, $leContactErrors);
        return;
    }

    $totalSizeError = '';
    if (function_exists('validateTotalUploadSize')) {
        $totalSizeError = validateTotalUploadSize();
    }
    if ($totalSizeError !== '') {
        $formError = $totalSizeError;
        $leContactValues = $old['contacts'] ?? [];
        $leContactErrors = $errors['contacts'] ?? [];
        $renderEditForm($contractor, $old, $errors, $formError, $leExistingDocs, $leDocTypes, $leContactValues, $leContactErrors);
        return;
    }

    $customDocumentTitleError = null;
    if (function_exists('validateLegalEntityCustomDocumentTitles')) {
        $customDocumentTitleError = validateLegalEntityCustomDocumentTitles($_POST, $_FILES);
    }
    if ($customDocumentTitleError !== null) {
        $formError = $customDocumentTitleError;
        $leContactValues = $old['contacts'] ?? [];
        $leContactErrors = $errors['contacts'] ?? [];
        $renderEditForm($contractor, $old, $errors, $formError, $leExistingDocs, $leDocTypes, $leContactValues, $leContactErrors);
        return;
    }

    $docErrors = [];

    $deleteExistingDocs = $_POST['delete_existing_doc'] ?? [];
    foreach ($deleteExistingDocs as $docId => $val) {
        if ($val !== '1') {
            continue;
        }
        try {
            if (function_exists('softDeleteEntityDocument')) {
                softDeleteEntityDocument($localPdo, (int) $docId, 'contractor', (int) $id, $userId, 'Archived via contractor modal edit');
            }
        } catch (\Exception $e) {
            $docErrors[] = 'Не удалось удалить документ: ' . $e->getMessage();
        }
    }

    $existingFiles = $_FILES['existing_doc_file'] ?? [];
    if (!empty($existingFiles['name']) && is_array($existingFiles['name'])) {
        foreach ($existingFiles['name'] as $docId => $origName) {
            $uploadError = $existingFiles['error'][$docId] ?? UPLOAD_ERR_NO_FILE;
            if ($uploadError !== UPLOAD_ERR_OK || trim((string) $origName) === '') {
                continue;
            }
            try {
                if (function_exists('replaceEntityDocument')) {
                    replaceEntityDocument(
                        $localPdo,
                        (int) $docId,
                        (int) $id,
                        'contractor',
                        $companyId,
                        [
                            'name' => (string) $origName,
                            'tmp_name' => (string) ($existingFiles['tmp_name'][$docId] ?? ''),
                            'type' => (string) ($existingFiles['type'][$docId] ?? ''),
                            'size' => (int) ($existingFiles['size'][$docId] ?? 0),
                        ],
                        $userId,
                        $roleCode
                    );
                }
            } catch (\Exception $e) {
                $docErrors[] = 'Не удалось заменить документ: ' . $e->getMessage();
            }
        }
    }

    if (function_exists('processLegalEntityCreateDocuments')) {
        $docResult = processLegalEntityCreateDocuments(
            $localPdo,
            $companyId,
            'contractor',
            (int) $id,
            $_POST,
            $_FILES,
            $userId,
            $roleCode
        );
        if (!empty($docResult['docErrors'])) {
            $docErrors = array_merge($docErrors, $docResult['docErrors']);
        }
    }

    if (!empty($docErrors)) {
        $formError = 'Ошибка при обработке документов:<br>' . implode('<br>', array_map('htmlspecialchars', $docErrors));
        try {
            $service->ensureDocumentTables($localPdo);
            $leDocTypes = $service->getDocTypes($localPdo, 'contractor');
            $leExistingDocs = loadEntityDocuments($localPdo, (int) $id, 'contractor');
        } catch (\Exception $e) {
            $leExistingDocs = [];
        }
        $leContactValues = $old['contacts'] ?? [];
        $leContactErrors = $errors['contacts'] ?? [];
        $renderEditForm($contractor, $old, $errors, $formError, $leExistingDocs, $leDocTypes, $leContactValues, $leContactErrors);
        return;
    }

    $service->updateContractor($localPdo, (int) $id, $_POST, $userId, $roleCode);
    ContractorContactService::replaceForContractor($localPdo, (int) $id, $submittedContacts, $userId, $roleCode);

    $contractor = $service->getContractorById($localPdo, (int) $id) ?: $contractor;
    $contacts = ContractorContactService::loadByContractorId($localPdo, (int) $id);
    $canEdit = $roleCode !== 'logist' || (int) ($contractor['created_by_user_id'] ?? 0) === $userId || $canEditGrant;
    $canArchive = $roleCode === 'company_owner' || $roleCode === 'senior_logist' || ($roleCode === 'logist' && ((int) ($contractor['created_by_user_id'] ?? 0) === $userId || $canEditGrant));
    $archiveBlockedMessage = '';
    try {
        $localPdo->query("SELECT 1 FROM crews LIMIT 1")->fetch();
        $crewCheck = $localPdo->prepare("SELECT COUNT(*) FROM crews WHERE contractor_id = ? AND deleted_at IS NULL");
        $crewCheck->execute([(int) $id]);
        if ((int) $crewCheck->fetchColumn() > 0) {
            $archiveBlockedMessage = 'Перевозчик участвует в экипажах. Сначала удалите экипажи.';
            $canArchive = false;
        }
    } catch (\Exception $e) {
    }

    require base_path('app/View/partials/company_contractor_modal_view.php');
} catch (\Exception $e) {
    $errors = [];
    $old = $_POST;
    $old['contacts'] = $_POST['contacts'] ?? contractorFormDefaultContacts();
    $contacts = $old['contacts'];
    $contractor = $contractor ?? ['id' => (int) $id, 'status' => 'active'];
    $formError = 'Ошибка сохранения: ' . $e->getMessage();

    $leDocTypes = [];
    $leExistingDocs = [];
    try {
        if (isset($localPdo)) {
            $service->ensureDocumentTables($localPdo);
            $leDocTypes = $service->getDocTypes($localPdo, 'contractor');
            if (function_exists('loadEntityDocuments')) {
                $leExistingDocs = loadEntityDocuments($localPdo, (int) $id, 'contractor');
            }
        }
    } catch (\Exception $ex) {
        $leDocTypes = [];
        $leExistingDocs = [];
    }

    $leContactValues = $old['contacts'] ?? [];
    $leContactErrors = $errors['contacts'] ?? [];
    $renderEditForm($contractor, $old, $errors, $formError, $leExistingDocs, $leDocTypes, $leContactValues, $leContactErrors);
}
