<?php

require_once base_path('app/Http/Controllers/Company/FinanceDdsCategoryController.php');

$controller = new \App\Http\Controllers\Company\FinanceDdsCategoryController($config, $db);
$ddsCategoriesPage = 'company.finance.dds_categories';

// Kept at the existing URL for backward compatibility; UI label is now Financial Structure.
$router->get('/company/finance/settings/dds-categories', [$controller, 'index'], $ddsCategoriesPage);
$router->get('/company/finance/settings/dds-categories/create', [$controller, 'createForm'], $ddsCategoriesPage);
$router->post('/company/finance/settings/dds-categories/create', [$controller, 'createSubmit'], $ddsCategoriesPage);
$router->get('/company/finance/settings/dds-categories/edit', [$controller, 'editForm'], $ddsCategoriesPage);
$router->post('/company/finance/settings/dds-categories/edit', [$controller, 'editSubmit'], $ddsCategoriesPage);
$router->post('/company/finance/settings/dds-categories/active-toggle', [$controller, 'activeToggle'], $ddsCategoriesPage);
$router->post('/company/finance/settings/dds-categories/delete', [$controller, 'deleteSubmit'], $ddsCategoriesPage);
$router->post('/company/finance/settings/dds-categories/cfu/save', [$controller, 'cfuSave'], $ddsCategoriesPage);
$router->post('/company/finance/settings/dds-categories/cfu/toggle', [$controller, 'cfuToggle'], $ddsCategoriesPage);
$router->post('/company/finance/settings/dds-categories/cfu/delete', [$controller, 'cfuDelete'], $ddsCategoriesPage);
$router->post('/company/finance/settings/dds-categories/links/save', [$controller, 'linksSave'], $ddsCategoriesPage);
