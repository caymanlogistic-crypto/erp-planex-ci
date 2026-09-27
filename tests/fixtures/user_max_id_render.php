<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
function base_path(string $path=''): string { global $root; return $root . ($path!==''?'/'.ltrim($path,'/'):''); }
function app_url(string $path): string { return $path; }
require_once $root.'/app/Security/PageRegistry.php';
$registry=new App\Security\PageRegistry();
$pageValues=array_fill_keys($registry->grantablePageKeys(),true);
$rolesAccessInitialStateJson=json_encode([
 'roles'=>[['id'=>9,'name'=>'Логист','is_system'=>false,'system_key'=>'','membership_count'=>1,'available_page_count'=>count($pageValues),'pages'=>$pageValues,'data_scopes'=>[],'default_page_key'=>'company.dashboard']],
 'memberships'=>[['id'=>12,'account_id'=>101,'full_name'=>'Иван Иванов','login'=>'ivan','email'=>'ivan@example.test','phone'=>'+79990000000','max_user_id'=>'123456789','status'=>'active','role_id'=>9,'role_name'=>'Логист','role_system_key'=>null,'page_overrides'=>[],'data_scope_overrides'=>[],'effective_pages'=>$pageValues,'effective_data_scopes'=>[],'is_system_owner'=>false,'personal_settings_count'=>0,'effective_page_count'=>count($pageValues)]],
 'page_tree'=>$registry->grantableTree(),'pages'=>$registry->grantablePages(),'regulated_entities'=>[],
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="erp-base-path" content=""><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/erp-ui.css"><link rel="stylesheet" href="/assets/css/workspace-benchmark.css"><link rel="stylesheet" href="/assets/css/roles-access.css"></head><body>
<main class="content content--wide-workspace"><div class="workspace-shell-1690"><?php require $root.'/app/View/pages/company/roles_access.php'; ?></div></main>
</body></html>
