<?php

$router->get('/favicon.ico', function () {
    http_response_code(204);
    exit;
});

require_once base_path('app/Http/Controllers/CoreLandingController.php');
$coreLandingController = new \App\Http\Controllers\CoreLandingController($config, $db);
$router->get('/', [$coreLandingController, 'index']);
$router->get('/access-unavailable', [$coreLandingController, 'unavailable']);

$router->get('/dev/ui-foundation', function () use ($config) {
    if (($config['app']['app_env'] ?? 'production') !== 'local') {
        http_response_code(404);
        exit;
    }
    requireRole('superadmin');
    $pageTitle = 'UI foundation';

    ob_start();
    require base_path('app/View/pages/ui_demo.php');
    $content = ob_get_clean();

    require base_path('app/View/layouts/main.php');
});

$router->get('/test', function () {
    if (env('APP_ENV', 'production') !== 'local') {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain');
    echo 'ERP PLANEX core is running';
});

$router->get('/test-db', function () use ($db) {
    if (env('APP_ENV', 'production') !== 'local') {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain');
    try {
        $db->connection();
        echo 'DB connection OK';
    } catch (\Exception $e) {
        echo 'DB connection FAILED';
    }
});
// ============================================================
// SUPERADMIN: Companies list
// ============================================================
