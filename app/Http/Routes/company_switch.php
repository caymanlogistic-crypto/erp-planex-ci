<?php

require_once base_path('app/Http/Controllers/Company/CompanySwitchController.php');
require_once base_path('app/Service/CompanySwitchService.php');

$companySwitchController = new \App\Http\Controllers\Company\CompanySwitchController($db);
$router->post('/company/switch', [$companySwitchController, 'switch']);
