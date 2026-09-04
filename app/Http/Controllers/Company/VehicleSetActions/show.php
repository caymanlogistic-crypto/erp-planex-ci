<?php
/** @var VehicleSetService $service */
// Show page - redirect to list for now, or render view page if it exists
$companyId=$service->getCompanyId();
if($companyId<=0)header('Location: /company/vehicle-sets');
else{header('Location: /company/vehicle-sets');}
exit;
