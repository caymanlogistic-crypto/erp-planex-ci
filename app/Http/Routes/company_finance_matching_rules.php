<?php
require_once base_path('app/Http/Controllers/Company/FinanceMatchingRuleController.php');
$controller=new \App\Http\Controllers\Company\FinanceMatchingRuleController($config,$db);
$matchingRulesPage = 'company.finance.matching_rules';
$router->get('/company/finance/settings/matching-rules',[$controller,'index'], $matchingRulesPage);
$router->get('/company/finance/settings/matching-rules/create',[$controller,'createForm'], $matchingRulesPage);
$router->post('/company/finance/settings/matching-rules/create',[$controller,'createSubmit'], $matchingRulesPage);
$router->get('/company/finance/settings/matching-rules/edit',[$controller,'editForm'], $matchingRulesPage);
$router->post('/company/finance/settings/matching-rules/edit',[$controller,'editSubmit'], $matchingRulesPage);
$router->post('/company/finance/settings/matching-rules/toggle',[$controller,'toggle'], $matchingRulesPage);
$router->post('/company/finance/settings/matching-rules/reorder',[$controller,'reorder'], $matchingRulesPage);
$router->post('/company/finance/settings/matching-rules/remove',[$controller,'remove'], $matchingRulesPage);
$router->get('/company/finance/settings/matching-rules/preview',[$controller,'preview'], $matchingRulesPage);
$router->get('/company/finance/settings/matching-rules/test',[$controller,'testOnTransaction'], $matchingRulesPage);
$router->post('/company/finance/settings/matching-rules/cfu/save',[$controller,'cfuSave'], $matchingRulesPage);
$router->post('/company/finance/settings/matching-rules/cfu/toggle',[$controller,'cfuToggle'], $matchingRulesPage);
