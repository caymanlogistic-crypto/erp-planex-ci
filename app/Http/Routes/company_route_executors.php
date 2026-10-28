<?php

require_once base_path('app/Http/Controllers/Company/RouteExecutorController.php');
require_once base_path('app/Service/EmployeeMailProfileService.php');
require_once base_path('app/Service/DocumentMailTransport.php');
require_once base_path('app/Service/RouteExecutorReviewIndividualMail.php');
require_once base_path('app/Service/MaxEventCatalog.php');
require_once base_path('app/Service/MaxMessagingService.php');
require_once base_path('app/Service/MaxEventNotificationService.php');
require_once base_path('app/Service/RouteExecutorReviewRecipientService.php');
require_once base_path('app/Service/RouteExecutorReviewPackage.php');
require_once base_path('app/Service/RouteExecutorReviewCardService.php');
require_once base_path('app/Service/RouteExecutorExistingEntityService.php');
require_once base_path('app/Service/RouteExecutorReviewMaterializationService.php');
require_once base_path('app/Service/RouteExecutorReviewArchive.php');
require_once base_path('app/Service/RouteExecutorReviewEmail.php');
require_once base_path('app/Service/RouteExecutorReviewMaxMessage.php');
require_once base_path('app/Service/RouteExecutorContractMailService.php');
require_once base_path('app/Http/Controllers/Company/RouteExecutorReviewController.php');

$controller = new \App\Http\Controllers\Company\RouteExecutorController($config, $db);
$routeExecutorsPage = 'company.route_executors';
$reviewController = new \App\Http\Controllers\Company\RouteExecutorReviewController($config, $db);

$router->get('/company/route-executors', [$controller, 'index'], $routeExecutorsPage);
$router->get('/company/route-executors/create', [$controller, 'createForm'], $routeExecutorsPage);
$router->post('/company/route-executors/create', [$controller, 'createSubmit'], $routeExecutorsPage);
$router->get('/company/route-executors/review/config', [$reviewController, 'config'], $routeExecutorsPage);
$router->get('/company/route-executors/review/status', [$reviewController, 'status'], $routeExecutorsPage);
$router->get('/company/route-executors/review/existing', [$reviewController, 'existing'], $routeExecutorsPage);
$router->post('/company/route-executors/review/send', [$reviewController, 'send'], $routeExecutorsPage);
$router->get('/company/route-executors/{id}', [$controller, 'view'], $routeExecutorsPage);
$router->get('/company/route-executors/{id}/edit', [$controller, 'editForm'], $routeExecutorsPage);
$router->post('/company/route-executors/{id}/edit', [$controller, 'editSubmit'], $routeExecutorsPage);
$router->post('/company/route-executors/{id}/archive', [$controller, 'archive'], $routeExecutorsPage);
require_once base_path('app/Http/Routes/company_driver_ts_data.php');
