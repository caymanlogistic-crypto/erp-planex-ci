<?php
require_once base_path('app/Service/WasteTripClosingService.php');
require_once base_path('app/Service/FinanceInvoicePaymentTimelineService.php');
require_once base_path('app/Service/FinanceObligationService.php');
require_once base_path('app/Service/WasteTripClosingInvoiceService.php');
require_once base_path('app/Service/WasteTripClosingPackageStore.php');
require_once base_path('app/Http/Controllers/Company/WasteTripClosingController.php');
$tripClosingController=new \App\Http\Controllers\Company\WasteTripClosingController($config,$db);
$tripClosingPage='company.documents.trip_closing';
$router->get('/company/trips/closing-documents',[$tripClosingController,'index'],$tripClosingPage);
$router->get('/company/trips/closing-documents/{tripId}/invoices',[$tripClosingController,'invoiceCandidates'],$tripClosingPage);
$router->post('/company/trips/closing-documents/{tripId}/invoices/link',[$tripClosingController,'linkInvoice'],$tripClosingPage);
$router->post('/company/trips/closing-documents/{tripId}/documents',[$tripClosingController,'upload'],$tripClosingPage);
$router->post('/company/trips/closing-documents/{tripId}/diagnostic-card',[$tripClosingController,'uploadDiagnosticCard'],$tripClosingPage);
$router->post('/company/trips/closing-documents/{tripId}/documents/{documentId}/remove',[$tripClosingController,'remove'],$tripClosingPage);
$router->get('/company/trips/closing-documents/{tripId}/documents/{documentId}',[$tripClosingController,'download'],$tripClosingPage);
$router->post('/company/trips/closing-documents/{tripId}/review',[$tripClosingController,'submit'],$tripClosingPage);
$router->post('/company/trips/closing-documents/{tripId}/review/confirm-not-sent',[$tripClosingController,'confirmNotSent'],$tripClosingPage);
$router->get('/trip-closing-package/{companyId}/{token}',[$tripClosingController,'sharedPackage']);
