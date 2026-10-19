<?php
declare(strict_types=1);
// Visual supplement only: render a synthetic view fragment inside the real shell.
// This is deliberately not runtime route/controller/service evidence.
if (PHP_SAPI !== 'cli') throw new RuntimeException('Test CLI required');
$config=require dirname(__DIR__,2).'/bootstrap/app.php';
require BASE_PATH.'/app/Support/entrypoint_dependencies.php';
$_SERVER['REQUEST_URI']='/company/trips/departures';
$_SERVER['SCRIPT_NAME']='/index.php';
$_SESSION=['company_id'=>1,'role_code'=>'company_owner','account_id'=>3,'user_id'=>11,'membership_id'=>11];
\App\Security\AccessRuntime::setCurrent(\App\Security\EffectiveAccess::systemOwner(11,1,array_column(\App\Security\PageRegistry::companyPages(),'key')));
$db=new class([]) extends \App\Core\Database { public function connection(): PDO { throw new RuntimeException('Synthetic visual fixture has no database'); } };
$company=['name'=>'Синтетическая компания','status'=>'active'];
$pageTitle='Выезды';$pageContext='Выезды › Компания';
$content=stream_get_contents(STDIN);
if ($content==='') throw new RuntimeException('Fixture content required');
require BASE_PATH.'/app/View/layouts/main.php';
