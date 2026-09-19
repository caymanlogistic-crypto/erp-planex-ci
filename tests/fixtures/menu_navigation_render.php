<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
function base_path(string $path = ''): string { global $root; return $root . ($path !== '' ? '/' . ltrim($path, '/') : ''); }
function app_url(string $path): string { return $path; }
function app_base_path(): string { return ''; }
function current_app_path(): string { return '/company/feo/requests/map-placement'; }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrfToken(): string { return 'fixture-csrf'; }

require_once $root . '/app/Security/EffectiveAccess.php';
require_once $root . '/app/Security/AccessRuntime.php';
require_once $root . '/app/Security/PageRegistry.php';

$registry = new App\Security\PageRegistry();
App\Security\AccessRuntime::setCurrent(App\Security\EffectiveAccess::systemOwner(1, 7, $registry->pageKeys()));
$_SESSION = ['role_code' => 'company_owner', 'company_id' => 0, 'user_name' => 'Fixture Owner', 'user_id' => 1];
$config = ['app' => ['app_name' => 'ERP PLANEX']];
$pageTitle = 'Fixture';
$pageContext = '';
$topbarCrumbs = [['label' => 'Fixture', 'url' => null]];
$content = '<div id="fixture-content">Fixture</div>';

require $root . '/app/View/layouts/main.php';
