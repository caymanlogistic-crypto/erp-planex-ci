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
$assert(str_contains($css, 'position:sticky') && str_contains($css, 'overflow-y:auto'), 'sticky modal controls and body-only vertical scroll');
$assert(str_contains($css, 'roles-access-modal-open') && str_contains($js, "classList.add('roles-access-modal-open')"), 'underlying page scroll lock');
$assert(str_contains($js, "event.key !== 'Tab'") && str_contains($js, "event.key === 'Escape'"), 'focus trap and escape close');
$assert(str_contains($js, "body:{ name:name, pages:pages, data_scopes:dataScopes }") || str_contains($js, 'pages:pages,data_scopes:scopes'), 'role one-save payload');
$assert(str_contains($js, 'page_overrides:collectPageValues') && str_contains($js, 'data_scope_overrides:collectScopeValues'), 'membership atomic save payload');
$assert(str_contains($js, 'var readonlySystem = system && !copyMode') && str_contains($js, 'data-role-copy'), 'protected owner role remains read-only but can be copied as a template');
$assert(str_contains($js, 'data-page-choice="inherit"') && str_contains($js, '>По роли</button>') && str_contains($js, '>Разрешить</button>') && str_contains($js, '>Запретить</button>'), 'compact tri-state member page controls');
$assert(str_contains($js, 'data-assignment-search') && str_contains($js, 'data-assignment-count') && str_contains($js, 'data-assignment-bulk="select"') && str_contains($js, 'data-assignment-bulk="clear"'), 'assignment local search counts and UI-only bulk helpers');
$assert(str_contains($js, "'Выбрать найденные'") && str_contains($js, "'Снять найденные'") && str_contains($js, "search.value.trim()"), 'bulk labels follow active local search');
$assert(str_contains($js, ".roles-access__assignment-row:not(.is-hidden) [data-assignment-type]"), 'filtered bulk affects only visible rows');
$assert(str_contains($js, 'setAssignmentBulkBusy(group, true)') && str_contains($js, "group.querySelectorAll('[data-assignment-bulk]')") && str_contains($js, "group.dataset.assignmentBulkBusy === '1'"), 'both bulk controls are locked against parallel operations');
$assert(str_contains($js, "{silent:true}") && str_contains($js, "return {ok:false, error:error}") && str_contains($js, "'Сохранено: ' + saved + '. Не удалось: ' + failed + '.'") && str_contains($js, "'Закрепления сохранены.'"), 'bulk partial failure is counted while failed checkboxes roll back');
$assert(str_contains($js, 'assignmentCache.sets[key] = before') && str_contains($js, 'checkbox.checked ='), 'assignment error rollback');
$assert(str_contains($js, 'Promise.all(state.memberships.map') && str_contains($js, 'membership_ids:after'), 'full many-to-many assignment set preserved');
$assert(str_contains($js, "status === 419") && str_contains($js, "status === 403") && str_contains($js, "status === 422") && str_contains($js, "status >= 500"), 'roles-access request errors distinguish 419/403/422/500');
$assert(str_contains($inline, "status === 419") && str_contains($inline, "status === 403") && str_contains($inline, "status === 422") && str_contains($inline, "status >= 500"), 'inline assignment errors distinguish 419/403/422/500');
$assert(str_contains($js, 'ошибки сервера') && str_contains($inline, 'ошибки сервера'), '500 errors must use safe user-facing wording');
$assert(str_contains($component, 'isSystemOwner()') && str_contains($inline, 'data-inline-membership'), 'owner-only inline object assignments');
$assert(str_contains($layout, 'PageRegistry') && str_contains($layout, 'canAccessPage'), 'menu consumes canonical registry and effective permissions');
$assert(str_contains($layout, '/company/switch') && str_contains($layout, 'count($switchCompanies) > 1'), 'company selector appears only for multiple active companies');
$assert(str_contains($css, 'width:min(1060px,calc(100vw - 32px))') && str_contains($css, 'max-height:85vh'), 'owner modal desktop geometry');
$assert(str_contains($css, 'grid-template-columns:minmax(0,1.35fr) minmax(180px,.9fr) minmax(150px,.7fr)'), 'user identity row is User Role Status on desktop');
$assert(str_contains($css, 'width:16px') && str_contains($css, 'height:16px'), 'compact checkbox geometry');
$assert(str_contains($css, '@media(max-width:1439px)') && str_contains($css, '@media(max-width:900px)') && str_contains($css, '@media(max-width:620px)'), '1440 1366 800 narrow responsive contracts');
$assert(str_contains($css, 'body.roles-access-page .app') && str_contains($css, 'min-width:0!important'), 'roles page removes shell horizontal min-width around 800px');
$assert(str_contains($view, 'panel roles-access roles-access__workspace') && str_contains($css, '.roles-access__workspace{width:100%;margin:0 0 6px;background:transparent;border:0') && str_contains($css, '.roles-access__workspace>.roles-access__panel{margin:0;padding:0}') && str_contains($css, '.roles-access__workspace>.roles-access__panel>.roles-access__table-card{width:100%;border:0;background:transparent}'), 'roles and users tables fill the workspace without an outer white frame');
$assert(!str_contains($view, 'users × pages') && !str_contains($view, 'users x pages'), 'no users-by-pages matrix');
$assert(!str_contains($js, 'window.open('), 'no popup-over-popup path');

echo "roles access UI contract: PASS\n";
