<?php
$documentReturn=$_SESSION['document_collection_return']??null;
$documentAccess=class_exists(\App\Security\AccessRuntime::class)?\App\Security\AccessRuntime::current():null;
$documentEditorPath=current_app_path();
if ($documentAccess && is_array($documentReturn) && (int)($documentReturn['request_id']??0)>0
    && (int)($documentReturn['company_id']??0)===$documentAccess->companyId()
    && (int)($documentReturn['membership_id']??0)===$documentAccess->membershipId()
    && $documentAccess->canAccessPage('company.documents.collection')
    && preg_match('~^/company/(?:contractors|drivers|vehicle-sets|route-executors)(?:/|$)~',$documentEditorPath)):
?>
<div class="notice"><a href="<?= e(app_url('/company/documents/collection/'.(int)$documentReturn['request_id'])) ?>">← Вернуться к сбору документов на рейс</a></div>
<?php endif; unset($documentReturn,$documentAccess,$documentEditorPath); ?>
