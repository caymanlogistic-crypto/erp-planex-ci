<?php
require_once base_path('app/Service/WasteTripClosingService.php');
require_once base_path('app/Service/WasteTripClosingPackageStore.php');
require_once base_path('app/Http/Controllers/Company/WasteTripClosingController.php');
$tripClosingController=new \App\Http\Controllers\Company\WasteTripClosingController($config,$db);
$tripClosingPage='company.documents.trip_closing';
$router->get('/company/trips/closing-documents',[$tripClosingController,'index'],$tripClosingPage);
$router->post('/company/trips/closing-documents/{tripId}/documents',[$tripClosingController,'upload'],$tripClosingPage);
$router->post('/company/trips/closing-documents/{tripId}/documents/{documentId}/remove',[$tripClosingController,'remove'],$tripClosingPage);
$router->get('/company/trips/closing-documents/{tripId}/documents/{documentId}',[$tripClosingController,'download'],$tripClosingPage);
$router->post('/company/trips/closing-documents/{tripId}/review',[$tripClosingController,'submit'],$tripClosingPage);
$router->post('/company/trips/closing-documents/{tripId}/review/confirm-not-sent',[$tripClosingController,'confirmNotSent'],$tripClosingPage);
$router->get('/trip-closing-package/{companyId}/{token}',[$tripClosingController,'sharedPackage']);
