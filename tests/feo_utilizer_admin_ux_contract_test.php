<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fail = static function (string $message): never { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); };
$must = static function (bool $ok, string $message) use ($fail): void { if (!$ok) $fail($message); };
$read = static function (string $rel) use ($root,$fail): string { $v=@file_get_contents($root.'/'.$rel); if(!is_string($v))$fail('cannot read '.$rel); return $v; };

require_once $root . '/app/Http/Controllers/Company/FeoUtilizerController.php';

$method = new ReflectionMethod(\App\Http\Controllers\Company\FeoUtilizerController::class, 'validateCoordinatePair');
$method->setAccessible(true);
$invoke = static function (array $payload) use ($method): ?string {
    try { $method->invoke(null, $payload); return null; }
    catch (ReflectionException $e) { return $e->getMessage(); }
    catch (Throwable $e) { return $e->getMessage(); }
};
$must($invoke([]) === null, 'empty utilizer coordinates must remain allowed');
$must($invoke(['latitude'=>'55,7558','longitude'=>'37,6176']) === null, 'valid comma decimal coordinates must pass');
$must(str_contains((string)$invoke(['latitude'=>'0','longitude'=>'0']), '0,0 недопустимы'), '0,0 must be rejected');
$must(str_contains((string)$invoke(['latitude'=>'55','longitude'=>'']), 'парой чисел'), 'partial coordinate pair must be rejected');
$must(str_contains((string)$invoke(['latitude'=>'91','longitude'=>'37']), 'допустимый диапазон'), 'out-of-range latitude must be rejected');

$view = $read('app/View/pages/company_feo_utilizers.php');
$controller = $read('app/Http/Controllers/Company/FeoUtilizerController.php');
$routes = $read('app/Http/Routes/company_feo_map.php');
$js = $read('public/assets/js/feo-utilizers.js');
$css = $read('public/assets/css/feo-utilizers.css');
$modalView = $read('app/View/partials/company_feo_utilizer_modal_view.php');
$modalEdit = $read('app/View/partials/company_feo_utilizer_modal_edit.php');
$mapView = $read('app/View/pages/company_feo_map.php');
$mapCss = $read('public/assets/css/feo-map.css');
$productionQa = $read('.github/feo-map-stage1/production_visual.js');

// Main owner screen is a canonical ERP registry, not a wall of edit cards/forms.
$must(str_contains($view, 'data-feo-utilizer-page-ready'), 'browser-ready marker missing');
$must(str_contains($view, 'table-card table-card--standard') && str_contains($view, 'data-erp-grid data-feo-utilizer-grid'), 'canonical ERP table registry missing');
$must(str_contains($view, 'table-toolbar') && str_contains($view, 'found-label') && str_contains($view, 'toolbar-search') && str_contains($view, 'table-footer'), 'canonical registry toolbar/footer missing');
$must(str_contains($view, 'Поиск: название, адрес, ФККО'), 'utilizer search scope is not visible');
$must(str_contains($view, 'data-feo-utilizer-id='), 'stable utilizer row id missing');
$must(!str_contains($view, 'feo-util-item'), 'legacy utilizer cards must be removed');
$must(!str_contains($view, '/update'), 'inline per-row edit forms must be removed');
$must(!str_contains($view, '<style>'), 'page-specific inline style block must be removed');
$must(str_contains($view, '/assets/css/feo-utilizers.css'), 'small utilizer-specific styles must live in an asset');
$must(str_contains($view, 'class="workspace-shell-1690 feo-utilizer-page"'), 'utilizers must reuse canonical ERP workspace-shell-1690');
$must(!str_contains($view, 'feo-domain-tabs') && !str_contains($view, 'Разделы заявок ФЭО'), 'redundant FEO body navigation must be absent from utilizers');
$must(!str_contains($mapView, 'feo-domain-tabs') && !str_contains($mapView, 'Разделы заявок ФЭО'), 'redundant FEO body navigation must be absent from map');
$must(!str_contains($css, '.feo-domain-tabs') && !str_contains($mapCss, '.feo-domain-tabs'), 'dead FEO body-navigation CSS must be removed');
$must(!str_contains($view, 'feo-content-container') && !str_contains($css, '.feo-content-container'), 'page-specific FEO content container must not be introduced');
$must(str_contains($css, '.feo-utilizer-page{display:flex;flex-direction:column;gap:14px;min-width:0;max-width:100%}'), 'utilizer root must remain shrink-safe inside canonical workspace shell');
$must(str_contains($css, '.feo-utilizer-page .table-card{width:100%;min-width:0;max-width:100%;margin:0}'), 'utilizer table cards must stay inside canonical workspace width');
$must(str_contains($css, '.feo-utilizer-page .table-scroll{min-width:0;max-width:100%;overflow-x:auto}'), 'wide utilizer tables must scroll inside table-card');
$must(str_contains($css, '.feo-utilizer-page [data-feo-utilizer-grid] .table{width:100%;max-width:100%;min-width:0;table-layout:fixed}'), 'main utilizer table must fit the canonical content width');
$must(str_contains($css, 'td:nth-child(3)') && str_contains($css, 'td:nth-child(4)') && str_contains($css, 'white-space:normal') && str_contains($css, 'overflow-wrap:anywhere'), 'address and FKKO columns must wrap instead of forcing horizontal overflow');
$must(str_contains($css, '#feo-utilizer-view-modal .modal-body{padding:16px}'), 'utilizer view/edit modal must keep the canonical body inset');
$must(!str_contains($css, '100vw'), 'utilizer page-specific CSS must not force viewport-wide layout');
$must(str_contains($productionQa, "const requestsUrl='https://plan-ex.ru/erpv2/company/feo/requests';") && str_contains($productionQa, "const placementUrl='https://plan-ex.ru/erpv2/company/feo/requests/map-placement';"), 'production QA must cover requests and map-placement pages');
$must(str_contains($productionQa, "[requestsUrl,'all requests'],[placementUrl,'map placement'],[mapUrl,'map']"), 'production QA must execute FEO shell checks on all non-utilizer pages');
$must(str_contains($productionQa, "page.locator('.feo-domain-tabs').count()===0"), 'production QA must reject redundant FEO body navigation');
$must(str_contains($productionQa, "'.workspace-shell-1690.feo-utilizer-page'"), 'production QA must verify canonical utilizer workspace shell');
$must(str_contains($productionQa, "overflowX==='auto'||overflowX==='scroll'"), 'production QA must protect internal utilizer table scrolling');

foreach (['ID','Утилизатор','Адрес','ФККО / классификация','ADR','Цвет','Координаты','Статус','Порядок'] as $label) {
    $must(str_contains($view, $label), "main registry column missing: {$label}");
}

// Create is modal-based and retains the complete domain field set.
$must(str_contains($view, "openModal('feo-utilizer-create-modal')"), 'Add action must open standard create modal');
$must(str_contains($view, 'id="feo-utilizer-create-modal"') && str_contains($view, 'modal modal-lg driver-view-modal-inner'), 'create modal must use ERP modal conventions');
foreach (['full_name','short_name','address','latitude','longitude','fkko_classification','color_hex','adr','is_active','sort_order'] as $name) {
    $must(str_contains($view, 'name="'.$name.'"'), "create modal field missing: {$name}");
    $must(str_contains($modalEdit, 'name="'.$name.'"'), "edit modal field missing: {$name}");
}
$must(str_contains($view, 'type="color"') && str_contains($modalEdit, 'type="color"'), 'color must stay editable in create/edit');

// Canonical double-click -> view -> edit ModalShell flow.
$must(str_contains($js, 'window.ModalShell.create'), 'canonical ModalShell must drive utilizer view/edit');
$must(str_contains($js, 'viewBtnSelectors:') && str_contains($js, 'editBtnSelectors:'), 'ModalShell must use canonical view/edit selector configuration');
$must(str_contains($js, 'view: function (id)') && str_contains($js, 'edit: function (id)') && str_contains($js, 'delete: function (id)'), 'ModalShell endpoints must use canonical endpoint callbacks');
$must(str_contains($js, 'deleteConfirm:') && str_contains($js, "confirmWord: 'УДАЛИТЬ'"), 'ModalShell typed destructive confirmation contract missing');
$must(!str_contains($js, 'selectors:'), 'non-canonical ModalShell selectors configuration must not be introduced');
$must(str_contains($js, "tbody.addEventListener('dblclick'"), 'double-click row handler missing');
$must(str_contains($js, "event.target.closest('a, button, input, select, textarea, label')"), 'interactive double-click exclusions changed');
$must(str_contains($js, 'controller.loadView(id)'), 'double-click must load view mode first');
foreach (['/modal-view','/modal-edit','/modal-delete'] as $suffix) $must(str_contains($js, $suffix), "ModalShell endpoint missing: {$suffix}");
$must(!preg_match('/\bconfirm\s*\(/', $js.$view), 'native confirm() must not be used');
$must(!str_contains($js, 'class Modal') && !str_contains($js, 'createElement(\'div\')'), 'utilizers must not implement a second modal engine');

$must(str_contains($modalView, 'data-feo-utilizer-edit-btn'), 'view modal edit action missing');
$must(str_contains($modalView, 'data-feo-utilizer-delete-btn'), 'view modal destructive action missing');
$must(str_contains($modalView, 'data-feo-utilizer-close-btn'), 'view modal close action missing');
foreach (['Полное название','Адрес','Координаты','ФККО / классификация','ADR','Цвет','Статус','Порядок','ID'] as $label) {
    $must(str_contains($modalView, $label), "view modal field missing: {$label}");
}
$must(str_contains($modalEdit, 'data-feo-utilizer-edit-form') && str_contains($modalEdit, 'data-feo-utilizer-cancel-edit-btn'), 'canonical edit/cancel modal contract missing');

// Read/edit/delete modal endpoints remain company_owner-only and reuse the existing safe-delete service.
foreach (['modalView','modalEditForm','modalEditSubmit','modalDelete'] as $methodName) $must(str_contains($controller, 'function '.$methodName.'('), "controller modal method missing: {$methodName}");
$must(substr_count($controller, "requireRole(['company_owner'])") >= 5, 'owner-only RBAC must cover modal endpoints');
$must(str_contains($controller, 'self::validateCoordinatePair($_POST);'), 'modal edit must retain coordinate validation');
$must(str_contains($controller, '$this->utilizers->deleteUtilizer($pdo, (int)$id);'), 'modal delete must reuse canonical safe-delete service');
foreach (['/{id}/modal-view','/{id}/modal-edit','/{id}/modal-delete'] as $route) $must(str_contains($routes, '/company/feo/utilizers'.$route), "modal route missing: {$route}");

// Rules and overrides remain separate compact secondary registries; creation is modal-based.
$must(str_contains($view, 'data-feo-utilizer-rules') && str_contains($view, 'data-feo-utilizer-overrides'), 'rules/overrides secondary registries missing');
$must(str_contains($view, "openModal('feo-utilizer-rule-modal')") && str_contains($view, "openModal('feo-utilizer-override-modal')"), 'rules/overrides add actions must open modals');
$must(str_contains($view, 'Ручное назначение используется первым'), 'manual override priority must stay visible to operators');
$must(str_contains($view, 'точное совпадение проверяется раньше'), 'same-priority exact-before-contains rule must stay visible');
$must(!str_contains($view, 'feo-rule-grid') && !str_contains($view, 'feo-override-grid'), 'legacy always-expanded rule/override forms must be removed');

// Small scoped CSS only: form layout/table helpers, no full second design system.
$must(str_contains($css, '.feo-utilizer-form-grid') && str_contains($css, '.feo-utilizer-color-dot'), 'scoped utilizer CSS helpers missing');
$must(!str_contains($css, '.btn{') && !str_contains($css, '.table{') && !str_contains($css, '.modal{'), 'utilizer CSS must not redefine core ERP components');

echo "FEO_UTILIZER_ADMIN_UX_CONTRACT=PASS\n";
