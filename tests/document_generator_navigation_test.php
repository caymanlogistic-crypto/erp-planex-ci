<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$GLOBALS['documentGeneratorNavigationTestPath'] = '/company/document-generator';

if (!function_exists('current_app_path')) {
    function current_app_path(): string
    {
        return (string)($GLOBALS['documentGeneratorNavigationTestPath'] ?? '/');
    }
}
if (!function_exists('app_url')) {
    function app_url(string $path): string
    {
        return $path;
    }
}
if (!function_exists('app_base_path')) {
    function app_base_path(): string
    {
        return '';
    }
}
if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return dirname(__DIR__) . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}
if (!function_exists('csrfToken')) {
    function csrfToken(): string
    {
        return str_repeat('a', 64);
    }
}
if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $offset, ?int $length = null): string
    {
        return $length === null ? substr($value, $offset) : substr($value, $offset, $length);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $value): string
    {
        return strtoupper($value);
    }
}

if (!class_exists('App\\Service\\NavigationCounterService', false)) {
    eval('namespace App\\Service; final class NavigationCounterService { public static function forCompany(...$args): array { return ["bank_attention" => 0, "cash_attention" => 0, "closing_documents_problem" => 0]; } }');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$render = static function (string $role, string $path) use ($root): string {
    $_SESSION = [
        'role_code' => $role,
        'company_id' => 1,
        'user_id' => 1,
        'user_name' => 'Тест Пользователь',
    ];
    $GLOBALS['documentGeneratorNavigationTestPath'] = $path;
    $config = ['app' => ['app_name' => 'ERP PLANEX']];
    $db = new stdClass();
    $pageTitle = 'Тест';
    $pageContext = 'Тест';
    $topbarCrumbs = [['label' => 'Тест', 'url' => null]];
    $content = '';
    ob_start();
    require $root . '/app/View/layouts/main.php';
    return (string)ob_get_clean();
};

$sidebar = static function (string $html): string {
    $start = strpos($html, '<aside class="sidebar"');
    $end = strpos($html, '</aside>', $start === false ? 0 : $start);
    if ($start === false || $end === false) return '';
    return substr($html, $start, $end - $start + strlen('</aside>'));
};

$linkState = static function (string $sidebarHtml, string $href, string $label): ?bool {
    $pattern = '~<a class="nav-sub-item([^"]*)" href="' . preg_quote($href, '~') . '"><span>' . preg_quote($label, '~') . '</span></a>~s';
    if (!preg_match($pattern, $sidebarHtml, $match)) return null;
    return str_contains((string)$match[1], 'is-active');
};

$parentOpen = static function (string $sidebarHtml): bool {
    $labelPos = strpos($sidebarHtml, '<span class="nav-label">Генератор договоров</span>');
    if ($labelPos === false) return false;
    $before = substr($sidebarHtml, 0, $labelPos);
    $parentPos = strrpos($before, '<div class="nav-item is-parent');
    if ($parentPos === false) return false;
    $tagEnd = strpos($sidebarHtml, '>', $parentPos);
    if ($tagEnd === false) return false;
    $openingTag = substr($sidebarHtml, $parentPos, $tagEnd - $parentPos + 1);
    return str_contains($openingTag, 'is-open') && str_contains($openingTag, 'is-active');
};

$activeDocumentChildren = static function (string $sidebarHtml): int {
    preg_match_all('~<a class="nav-sub-item([^"]*is-active[^"]*)" href="/company/document-generator(?:/history|/templates)?">~', $sidebarHtml, $matches);
    return count($matches[0] ?? []);
};

$ownerRoot = $sidebar($render('company_owner', '/company/document-generator'));
$assert(str_contains($ownerRoot, '<span class="nav-label">Генератор договоров</span>'), 'company_owner sees parent group');
$assert($linkState($ownerRoot, '/company/document-generator', 'Создать документ') === true, 'root activates create child');
$assert($linkState($ownerRoot, '/company/document-generator/history', 'История документов') === false, 'root does not activate history');
$assert($linkState($ownerRoot, '/company/document-generator/templates', 'Шаблоны договоров') === false, 'root does not activate templates');
$assert($parentOpen($ownerRoot), 'parent group is open on root');
$assert($activeDocumentChildren($ownerRoot) === 1, 'root has exactly one active document-generator child');

$ownerHistory = $sidebar($render('company_owner', '/company/document-generator/history'));
$assert($linkState($ownerHistory, '/company/document-generator', 'Создать документ') === false, 'history does not activate create');
$assert($linkState($ownerHistory, '/company/document-generator/history', 'История документов') === true, 'history activates history child');
$assert($linkState($ownerHistory, '/company/document-generator/templates', 'Шаблоны договоров') === false, 'history does not activate templates');
$assert($parentOpen($ownerHistory), 'parent group is open on history');
$assert($activeDocumentChildren($ownerHistory) === 1, 'history has exactly one active child');

$ownerTemplates = $sidebar($render('company_owner', '/company/document-generator/templates'));
$assert($linkState($ownerTemplates, '/company/document-generator', 'Создать документ') === false, 'templates does not activate create');
$assert($linkState($ownerTemplates, '/company/document-generator/history', 'История документов') === false, 'templates does not activate history');
$assert($linkState($ownerTemplates, '/company/document-generator/templates', 'Шаблоны договоров') === true, 'templates activates templates child');
$assert($parentOpen($ownerTemplates), 'parent group is open on templates');
$assert($activeDocumentChildren($ownerTemplates) === 1, 'templates has exactly one active child');

$ownerTemplateChild = $sidebar($render('company_owner', '/company/document-generator/templates/17/versions/4/download'));
$assert($linkState($ownerTemplateChild, '/company/document-generator/templates', 'Шаблоны договоров') === true, 'template sub-route keeps templates child active');
$assert($parentOpen($ownerTemplateChild), 'parent group is open on template sub-route');
$assert($activeDocumentChildren($ownerTemplateChild) === 1, 'template sub-route has exactly one active child');

foreach (['senior_logist', 'logist'] as $role) {
    $roleRoot = $sidebar($render($role, '/company/document-generator'));
    $assert(str_contains($roleRoot, '<span class="nav-label">Генератор договоров</span>'), "{$role} sees parent group");
    $assert($linkState($roleRoot, '/company/document-generator', 'Создать документ') === true, "{$role} sees create child");
    $assert($linkState($roleRoot, '/company/document-generator/history', 'История документов') === false, "{$role} sees inactive history child on root");
    $assert(!str_contains($roleRoot, 'href="/company/document-generator/templates"'), "{$role} does not see template administration link");

    $roleHistory = $sidebar($render($role, '/company/document-generator/history'));
    $assert($linkState($roleHistory, '/company/document-generator/history', 'История документов') === true, "{$role} history child activates");
    $assert($activeDocumentChildren($roleHistory) === 1, "{$role} history has exactly one active child");
}

$superadmin = $sidebar($render('superadmin', '/company/document-generator/templates'));
$assert(!str_contains($superadmin, '<span class="nav-label">Генератор договоров</span>'), 'superadmin navigation contract remains unchanged');

$controller = (string)file_get_contents($root . '/app/Http/Controllers/Company/DocumentGeneratorController.php');
$extractMethod = static function (string $source, string $method): string {
    $start = strpos($source, 'public function ' . $method . '(');
    if ($start === false) return '';
    $brace = strpos($source, '{', $start);
    if ($brace === false) return '';
    $depth = 0;
    $len = strlen($source);
    for ($i = $brace; $i < $len; $i++) {
        if ($source[$i] === '{') $depth++;
        if ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) return substr($source, $start, $i - $start + 1);
        }
    }
    return '';
};
$routes = (string)file_get_contents($root . '/app/Http/Routes/company_document_generator.php');
$assert(str_contains($routes, "'/company/document-generator/templates'"), 'templates GET route remains registered');
$assert(str_contains($routes, "'/company/document-generator/templates/upload'"), 'template upload route remains registered');
$assert(
    !str_contains($extractMethod($controller, 'templates'), 'requireRole(')
    && !str_contains($extractMethod($controller, 'uploadTemplate'), 'requireRole('),
    'template controller delegates authorization to the Page Registry without a legacy role gate'
);
$assert(
    str_contains($routes, "\$documentTemplatesPage = 'company.documents.templates';")
    && str_contains($routes, "[\$documentGeneratorController, 'templates'], \$documentTemplatesPage")
    && str_contains($routes, "[\$documentGeneratorController, 'uploadTemplate'], \$documentTemplatesPage")
    && str_contains($routes, "[\$documentGeneratorController, 'toggleTemplate'], \$documentTemplatesPage")
    && str_contains($routes, "[\$documentGeneratorController, 'downloadTemplateVersion'], \$documentTemplatesPage"),
    'all template read and mutation endpoints use the protected templates page key'
);

$templateView = (string)file_get_contents($root . '/app/View/pages/company_document_generator_templates.php');
$assert(str_contains($templateView, "\$pageTitle = 'Шаблоны договоров';"), 'template page title is user-facing Шаблоны договоров');
$assert(str_contains($templateView, '<h1 class="page-title">Шаблоны договоров</h1>'), 'template H1 is Шаблоны договоров');
$assert(str_contains($templateView, 'Загрузить шаблон договора / новую версию'), 'upload panel has clear contract-template wording');
$assert(str_contains($templateView, "['label' => 'Генератор договоров', 'url' => app_url('/company/document-generator')]"), 'template breadcrumbs include generator parent');
$assert(str_contains($templateView, "['label' => 'Шаблоны договоров', 'url' => null]"), 'template breadcrumbs end at templates page');
$assert(str_contains($templateView, 'enctype="multipart/form-data"'), 'template upload remains multipart/form-data');
$assert(str_contains($templateView, 'name="_csrf"'), 'template upload keeps CSRF field');

fwrite(STDOUT, "OK document generator navigation contract ({$assertions} assertions)\n");
