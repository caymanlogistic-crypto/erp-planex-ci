<?php

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$view = $read('app/View/pages/company/roles_access.php');
$layout = $read('app/View/layouts/main.php');
$js = $read('public/assets/js/roles-access.js');
$inline = $read('public/assets/js/entity-assignments.js');
$css = $read('public/assets/css/roles-access.css');
$component = $read('app/View/partials/company_entity_assignment_block.php');

$assert(str_contains($view, 'Роли') && str_contains($view, 'Пользователи') && str_contains($view, 'Закрепления'), 'single three-tab administration surface');
$assert(substr_count($view, 'roles-access-modal__dialog') === 1, 'one modal shell only');
$assert(str_contains($view, 'aria-modal="true"') && str_contains($view, 'role="tablist"'), 'dialog and tab accessibility semantics');
$assert(str_contains($css, 'position:sticky') && str_contains($css, 'overflow:auto'), 'sticky modal controls and internal scroll');
$assert(str_contains($css, 'roles-access-modal-open') && str_contains($js, "classList.add('roles-access-modal-open')"), 'underlying page scroll lock');
$assert(str_contains($js, "event.key !== 'Tab'") && str_contains($js, "event.key === 'Escape'"), 'focus trap and escape close');
$assert(str_contains($js, "body:{ name:name, pages:pages, data_scopes:dataScopes }") || str_contains($js, 'pages:pages,data_scopes:scopes'), 'role one-save payload');
$assert(str_contains($js, 'page_overrides:collectPageValues') && str_contains($js, 'data_scope_overrides:collectScopeValues'), 'membership atomic save payload');
$assert(str_contains($js, 'var readonlySystem = system && !copyMode') && str_contains($js, 'data-role-copy'), 'protected owner role remains read-only but can be copied as a template');
$assert(str_contains($js, 'По роли · ') && str_contains($js, 'Персонально'), 'inheritance and personal labels');
$assert(str_contains($js, 'assignmentCache.sets[key]=before') && str_contains($js, 'checkbox.checked='), 'assignment error rollback');
$assert(str_contains($js, 'Promise.all(state.memberships.map') && str_contains($js, 'membership_ids:after'), 'full many-to-many assignment set preserved');
$assert(str_contains($component, 'isSystemOwner()') && str_contains($inline, 'data-inline-membership'), 'owner-only inline object assignments');
$assert(str_contains($layout, 'PageRegistry') && str_contains($layout, 'canAccessPage'), 'menu consumes canonical registry and effective permissions');
$assert(str_contains($layout, '/company/switch') && str_contains($layout, 'count($switchCompanies) > 1'), 'company selector appears only for multiple active companies');
$assert(str_contains($css, '@media (max-width: 1439px)') && str_contains($css, '@media (max-width: 900px)'), '1440 and 800 responsive contracts');
$assert(!str_contains($view, 'users × pages') && !str_contains($view, 'users x pages'), 'no users-by-pages matrix');
$assert(!str_contains($js, 'window.open('), 'no popup-over-popup path');

echo "roles access UI contract: PASS\n";
