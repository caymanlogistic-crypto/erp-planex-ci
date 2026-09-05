<?php

require_once base_path('app/Http/Controllers/Company/ContractorController.php');

$controller = new \App\Http\Controllers\Company\ContractorController($config, $db);

// --- Contractor list ---
$router->get('/company/contractors', [$controller, 'index'], 'company.contractors');

// --- Contractor create (full page + modal) ---
$router->get('/company/contractors/create', [$controller, 'createForm'], 'company.contractors');
$router->post('/company/contractors/create', [$controller, 'createSubmit'], 'company.contractors');

// --- INN autofill ---
$router->get('/company/requisites/lookup-by-inn', [$controller, 'lookupInn'], 'company.contractors');
$router->post('/company/requisites/lookup-by-inn', [$controller, 'lookupInn'], 'company.contractors');

// --- Contractor + Driver + Vehicle (full create) ---
$router->get('/company/contractors/create-full', [$controller, 'createFullForm'], 'company.contractors');
$router->post('/company/contractors/create-full', [$controller, 'createFullSubmit'], 'company.contractors');

// --- Add crew to existing contractor ---
$router->get('/company/contractors/{id}/add-crew', [$controller, 'addCrewForm'], 'company.contractors');
$router->post('/company/contractors/{id}/add-crew', [$controller, 'addCrewSubmit'], 'company.contractors');

// --- Contractor view ---
$router->get('/company/contractors/{id}', [$controller, 'show'], 'company.contractors');

// --- Contractor edit (full page) ---
$router->get('/company/contractors/{id}/edit', [$controller, 'editForm'], 'company.contractors');
$router->post('/company/contractors/{id}/edit', [$controller, 'editSubmit'], 'company.contractors');

// --- Contractor archive (full page) ---
$router->post('/company/contractors/{id}/archive', [$controller, 'archive'], 'company.contractors');

// --- Contractor modal view/edit/archive ---
$router->get('/company/contractors/{id}/modal-view', [$controller, 'modalView'], 'company.contractors');
$router->get('/company/contractors/{id}/modal-edit', [$controller, 'modalEditForm'], 'company.contractors');
$router->post('/company/contractors/{id}/modal-edit', [$controller, 'modalEditSubmit'], 'company.contractors');
$router->post('/company/contractors/{id}/modal-archive', [$controller, 'modalArchive'], 'company.contractors');

// --- Contractor contacts CRUD ---
$router->post('/company/contractors/{contractor_id}/contacts/create', [$controller, 'contactCreate'], 'company.contractors');
$router->post('/company/contractors/{contractor_id}/contacts/{contact_id}/edit', [$controller, 'contactEdit'], 'company.contractors');
$router->post('/company/contractors/{contractor_id}/contacts/{contact_id}/delete', [$controller, 'contactDelete'], 'company.contractors');
$router->post('/company/contractors/{contractor_id}/contacts/{contact_id}/set-primary', [$controller, 'contactSetPrimary'], 'company.contractors');
$router->post('/company/contractors/{contractor_id}/contacts/{contact_id}/set-document-email', [$controller, 'contactSetDocumentEmail'], 'company.contractors');

// --- Contractor tax history ---
$router->post('/company/contractors/{contractor_id}/tax-history/create', [$controller, 'taxHistoryCreate'], 'company.contractors');
