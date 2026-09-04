<?php
/** @var ContractorService $service */
use App\Service\ContractorContactService;

requireRole(['company_owner', 'senior_logist', 'logist']);

$renderMessage = static function (string $message): void {
    echo '<div class="modal-body"><div class="notice warn modal-notice">' . e($message) . '</div></div>';
    echo '<div class="modal-foot is-spaced"><div class="modal-foot-actions"><button type="button" class="btn btn-ghost" data-contractor-cancel-edit-btn>Закрыть</button></div></div>';
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
    $errors = [];
    $old = $contractor;
    $old['contacts'] = $contacts !== [] ? $contacts : contractorFormDefaultContacts();
    $formError = null;
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
    $leContactValues = $old['contacts'];
    $leContactErrors = [];

    require base_path('app/View/partials/company_contractor_modal_edit.php');
} catch (\Exception $e) {
    $renderMessage('Не удалось загрузить форму: ' . $e->getMessage());
}
