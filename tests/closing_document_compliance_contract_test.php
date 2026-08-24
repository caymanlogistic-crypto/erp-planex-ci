<?php

declare(strict_types=1);

require_once __DIR__.'/../app/Service/ClosingDocumentService.php';
require_once __DIR__.'/../app/Service/ClosingDocumentComplianceService.php';

use App\Service\ClosingDocumentComplianceService as Compliance;
use App\Service\ClosingDocumentService as Closing;

$classificationCases = [
    'ООО "РОМАШКА"' => 'legal_entity',
    'АО "ПФ "СКБ КОНТУР"' => 'legal_entity',
    'ЗАО "ТЕСТ"' => 'legal_entity',
    'ИП Иванов Иван Иванович' => 'individual',
    'Индивидуальный предприниматель Иванов Иван Иванович' => 'individual',
    'Сидоров Сергей Сергеевич' => 'self_employed',
];
foreach ($classificationCases as $name => $expected) {
    $actual = Compliance::profileCodeForBankName($name);
    if ($actual !== $expected) throw new RuntimeException("classification failed for {$name}: {$actual} != {$expected}");
}

$profile = Closing::profileFor('legal_entity', 'outgoing');
if ($profile === null) throw new RuntimeException('legal entity profile missing');
$acceptedLegal = [];
foreach ($profile['requirements'] as $requirement) {
    foreach (($requirement['accepted'] ?? []) as $code) $acceptedLegal[$code] = true;
}
if (empty($acceptedLegal['upd'])) throw new RuntimeException('legal entity compliance profile must accept UPD');

$manual = Compliance::requirementStatuses($profile, [], ['agreement_or_application' => true], []);
if (($manual['agreement_or_application']['source'] ?? null) !== 'manual' || empty($manual['agreement_or_application']['satisfied'])) {
    throw new RuntimeException('manual collected status must satisfy only selected requirement');
}
if (!empty($manual['act_or_upd']['satisfied'])) throw new RuntimeException('manual collected must not satisfy sibling requirement');

$edoLink = [[
    'id' => 1,
    'requirement_code' => 'act',
    'document_deleted_at' => null,
    'is_edo' => 1,
]];
$edo = Compliance::requirementStatuses($profile, $edoLink, [], []);
if (($edo['act_or_upd']['source'] ?? null) !== 'edo' || empty($edo['act_or_upd']['satisfied'])) {
    throw new RuntimeException('EDO link must produce edo satisfied status');
}

$exempt = Compliance::requirementStatuses($profile, [], [], ['agreement_or_application' => true]);
if (empty($exempt['agreement_or_application']['exempt_for_inn']) || !empty($exempt['agreement_or_application']['applicable'])) {
    throw new RuntimeException('INN requirement exception must make only selected requirement non-applicable');
}
if (empty($exempt['act_or_upd']['applicable'])) throw new RuntimeException('INN exception must not disable sibling requirement');

$migration = (string)file_get_contents(__DIR__.'/../database/migrations-local/121_closing_document_evidence_overrides.sql');
foreach (['ADD COLUMN `is_edo`','finance_closing_document_manual_statuses','finance_closing_document_requirement_exceptions','PRIMARY KEY (`bank_transaction_id`, `requirement_key`)','PRIMARY KEY (`inn`, `requirement_key`)'] as $token) {
    if (!str_contains($migration, $token)) throw new RuntimeException('migration 121 missing '.$token);
}

$modal = (string)file_get_contents(__DIR__.'/../app/View/pages/company_finance_closing_document_modal.php');
foreach (['Считать собранным','Не требовать для этого ИНН','name="is_edo"','>ЭДО<','requirement-overrides','width:14px!important','height:14px!important','appearance:auto!important'] as $token) {
    if (!str_contains($modal, $token)) throw new RuntimeException('modal compliance UI missing '.$token);
}
if (str_contains($modal, 'Привязать существующий')) throw new RuntimeException('existing-document block must remain hidden');

$complianceSource = (string)file_get_contents(__DIR__.'/../app/Service/ClosingDocumentComplianceService.php');
if (str_contains($complianceSource, 'ClosingDocumentService::uploadAndLink(')) {
    throw new RuntimeException('compliance upload must not delegate validation to legacy profile logic');
}
foreach (['$row = self::findRow($pdo, $transactionId);','Этот тип документа не входит в обязательный профиль операции','finance_closing_document_links (bank_transaction_id, document_id, requirement_code, is_edo, created_by_user_id)'] as $token) {
    if (!str_contains($complianceSource, $token)) throw new RuntimeException('compliance upload source missing '.$token);
}

$main = (string)file_get_contents(__DIR__.'/../app/View/pages/company_finance_closing_documents.php');
foreach (['closing-doc-status--manual','closing-doc-edo','>ЭДО</span>'] as $token) {
    if (!str_contains($main, $token)) throw new RuntimeException('main compliance visual missing '.$token);
}

echo "closing_document_compliance_contract_test: PASS\nclassification=ooo_ao_zao_ip_full_ip\nmanual_status=per_requirement\ninn_exception=per_requirement_global_by_inn\nedo=per_document_link\nupload_validation=compliance_profile\ncheckboxes=14px_native\n";
