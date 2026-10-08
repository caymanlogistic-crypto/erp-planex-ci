<?php

require_once base_path('app/Service/FeoRequestInteractionService.php');
require_once base_path('app/Service/DocumentTemplateService.php');
require_once base_path('app/Service/DocumentGeneratorService.php');
require_once base_path('app/Http/Controllers/Company/DocumentGeneratorController.php');

$documentGeneratorController = new \App\Http\Controllers\Company\DocumentGeneratorController($config, $db);
$documentCreatePage = 'company.documents.create';
$documentHistoryPage = 'company.documents.history';
$documentTemplatesPage = 'company.documents.templates';
$router->get('/company/document-generator', [$documentGeneratorController, 'index'], $documentCreatePage);
$router->post('/company/document-generator/preview', [$documentGeneratorController, 'preview'], $documentCreatePage);
$router->post('/company/document-generator/generate', [$documentGeneratorController, 'generate'], $documentCreatePage);
$router->get('/company/document-generator/history', [$documentGeneratorController, 'history'], $documentHistoryPage);
$router->post('/company/document-generator/history/download', [$documentGeneratorController, 'downloadHistoryArchive'], $documentHistoryPage);
$router->get('/company/document-generator/generated/{id}/download', [$documentGeneratorController, 'downloadGenerated'], $documentHistoryPage);
$router->get('/company/document-generator/templates', [$documentGeneratorController, 'templates'], $documentTemplatesPage);
$router->post('/company/document-generator/templates/upload', [$documentGeneratorController, 'uploadTemplate'], $documentTemplatesPage);
$router->post('/company/document-generator/templates/{id}/status', [$documentGeneratorController, 'toggleTemplate'], $documentTemplatesPage);
$router->get('/company/document-generator/templates/{templateId}/versions/{versionId}/download', [$documentGeneratorController, 'downloadTemplateVersion'], $documentTemplatesPage);
