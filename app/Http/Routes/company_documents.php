<?php

require_once base_path('app/Http/Controllers/Company/DocumentController.php');

$c = new \App\Http\Controllers\Company\DocumentController($config, $db);

$router->get('/company/documents', [$c, 'index']);
$router->get('/company/documents/upload', [$c, 'uploadForm']);
$router->post('/company/documents/upload', [$c, 'uploadSubmit']);
$router->get('/company/documents/download', [$c, 'download']);
$router->get('/company/documents/view', [$c, 'view']);
$router->post('/company/documents/delete', [$c, 'delete']);
$router->post('/company/documents/replace', [$c, 'replace']);
$router->get('/company/document-types', [$c, 'docTypesIndex']);
$router->get('/company/document-types/create', [$c, 'docTypesCreateForm']);
$router->post('/company/document-types/create', [$c, 'docTypesCreateSubmit']);
$router->get('/company/document-types/{id}/edit', [$c, 'docTypesEditForm']);
$router->post('/company/document-types/{id}/edit', [$c, 'docTypesEditSubmit']);
$router->post('/company/document-types/{id}/delete', [$c, 'docTypesDelete']);

require_once base_path('app/Http/Routes/company_document_generator.php');
