<?php

require_once base_path('app/Service/FeoXlsxParser.php');
require_once base_path('app/Service/FeoRequestService.php');
require_once base_path('app/Http/Controllers/Company/FeoRequestsController.php');

$feoController = new \App\Http\Controllers\Company\FeoRequestsController($config, $db);
$router->get('/company/feo/requests', [$feoController, 'index']);
$router->get('/company/feo/requests/{id}/json', [$feoController, 'detail']);
$router->post('/company/feo/requests/import/analyze', [$feoController, 'analyzeImport']);
$router->post('/company/feo/requests/import/commit', [$feoController, 'commitImport']);
$router->post('/company/feo/requests/import/rollback', [$feoController, 'rollbackImport']);

if (PHP_SAPI !== 'cli') {
    ob_start(static function (string $html): string {
        $role = (string)($_SESSION['role_code'] ?? '');
        if (!in_array($role, ['company_owner','senior_logist','logist'], true)) return $html;
        if (!str_contains($html, '</aside>') || str_contains($html, '<span class="nav-section-label">ЗАЯВКИ ФЭО</span>')) return $html;
        $contentType = '';
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) { $contentType = strtolower($header); break; }
        }
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) return $html;

        $active = str_starts_with(current_app_path(), '/company/feo/requests') ? ' is-active' : '';
        $href = e(app_url('/company/feo/requests'));
        $menu = '<div class="nav-group feo-nav-group">'
            . '<div class="nav-section-label">ЗАЯВКИ ФЭО</div>'
            . '<a class="nav-item' . $active . '" href="' . $href . '">'
            . '<svg class="nav-icon" viewBox="0 0 16 16" fill="none"><path d="M3 2.5h7l3 3v8H3v-11Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M10 2.5v3h3M5.5 8h5M5.5 10.5h5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>'
            . '<span class="nav-label">Все заявки</span></a></div>';
        return str_replace('</aside>', $menu . '</aside>', $html);
    });
}
