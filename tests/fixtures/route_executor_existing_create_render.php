<?php
require dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/core_runtime.php';
require_once BASE_PATH . '/app/View/components/view_formatters.php';

$routeExecutorCreateFormMode = 'page';
$routeExecutorFormId = 'route-executor-create-form';
$routeExecutorFormAction = app_url('/company/route-executors/create');
$formError = null;
$errors = [];
$old = [];
$contractors = [['id' => 1, 'name' => 'ООО Готовый подрядчик', 'inn' => '7701000000']];
$drivers = [['id' => 2, 'full_name' => 'Иванов Иван Иванович', 'phone' => '+7 900 000-00-00']];
$vehicleSets = [['id' => 3, 'set_type' => 'single', 'primary_plate' => 'А001АА77', 'secondary_plate' => null]];
$responsibleLogists = [['membership_id' => 11, 'full_name' => 'Анна Белова', 'login' => 'anna']];
?><!doctype html><html lang="ru"><body><main class="content">
<?php require BASE_PATH . '/app/View/partials/company_route_executor_create_form.php'; ?>
</main></body></html>
