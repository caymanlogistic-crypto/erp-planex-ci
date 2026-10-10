<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Core/Database.php';
require_once BASE_PATH.'/app/Service/WasteTripClosingService.php';
require_once BASE_PATH.'/app/Http/Controllers/Company/WasteTripClosingController.php';
require_once BASE_PATH.'/app/Support/http_runtime.php';

$_SESSION['_csrf_token'] = 'closing-email-block-test';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['_csrf_token'] = $_SESSION['_csrf_token'];

$database = new \App\Core\Database([]);
$controller = new \App\Http\Controllers\Company\WasteTripClosingController([], $database);
ob_start();
$controller->submit('42');
$response = (string)ob_get_clean();

if (http_response_code() !== 403
    || !str_contains($response, 'Отправка закрывающих документов на почту временно отключена.')
    || $database->isConnected()) {
    throw new RuntimeException('Review POST must stop before database access or mail delivery.');
}

echo "waste_trip_closing_email_block: PASS\n";
