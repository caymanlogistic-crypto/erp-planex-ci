<?php
declare(strict_types=1);

$config=require __DIR__.'/../bootstrap/app.php';
require_once base_path('app/Support/entrypoint_dependencies.php');

$db=new App\Core\Database($config['database']);
$router=new App\Http\Router(new App\Security\PageRegistry());
require_once base_path('app/Http/Routes/company_route_executors.php');

foreach ([
    App\Service\RouteExecutorReviewPackage::class,
    App\Service\RouteExecutorReviewEmail::class,
    App\Service\RouteExecutorReviewRecipientService::class,
    App\Service\DocumentMailTransport::class,
    App\Service\MaxEventNotificationService::class,
] as $dependency) {
    if (!class_exists($dependency, false)) {
        throw new RuntimeException("Route executor review dependency is not loaded: {$dependency}");
    }
}

echo "route_executor_review_route_wiring_test: PASS\n";
