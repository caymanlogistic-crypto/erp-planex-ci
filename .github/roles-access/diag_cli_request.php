<?php
declare(strict_types=1);
$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
$sid = (string)($argv[1] ?? '');
if ($root === '' || !preg_match('/^[a-f0-9]{48}$/D', $sid)) { fwrite(STDERR, "invalid input\n"); exit(2); }
require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$name = trim((string)getenv('SESSION_COOKIE_NAME'));
if ($name === '') { $name = session_name(); }
$_COOKIE[$name] = $sid;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/erpv2/company/clients';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$_SERVER['HTTP_HOST'] = 'plan-ex.ru';
$_SERVER['SERVER_NAME'] = 'plan-ex.ru';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
chdir($root);
require $root . '/public/index.php';
