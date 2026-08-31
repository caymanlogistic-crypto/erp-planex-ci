<?php

require_once base_path('app/Service/FeoRequestInteractionService.php');
require_once base_path('app/Service/DocumentTemplateService.php');
require_once base_path('app/Service/DocumentGeneratorService.php');
require_once base_path('app/Http/Controllers/Company/DocumentGeneratorController.php');

$documentGeneratorController = new \App\Http\Controllers\Company\DocumentGeneratorController($config, $db);
$router->get('/company/document-generator', [$documentGeneratorController, 'index']);
$router->post('/company/document-generator/preview', [$documentGeneratorController, 'preview']);
$router->post('/company/document-generator/generate', [$documentGeneratorController, 'generate']);
$router->get('/company/document-generator/history', [$documentGeneratorController, 'history']);
$router->get('/company/document-generator/generated/{id}/download', [$documentGeneratorController, 'downloadGenerated']);
$router->get('/company/document-generator/templates', [$documentGeneratorController, 'templates']);
$router->post('/company/document-generator/templates/upload', [$documentGeneratorController, 'uploadTemplate']);
$router->post('/company/document-generator/templates/{id}/status', [$documentGeneratorController, 'toggleTemplate']);
$router->get('/company/document-generator/templates/{templateId}/versions/{versionId}/download', [$documentGeneratorController, 'downloadTemplateVersion']);
