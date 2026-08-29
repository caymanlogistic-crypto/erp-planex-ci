<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$fail=static function(string $message):never{fwrite(STDERR,"FAIL: {$message}\n");exit(1);};
$must=static function(bool $ok,string $message)use($fail):void{if(!$ok)$fail($message);};
$read=static function(string $rel)use($root,$fail):string{$v=@file_get_contents($root.'/'.$rel);if(!is_string($v))$fail('cannot read '.$rel);return$v;};
require_once $root.'/app/Http/Controllers/Company/FeoUtilizerController.php';

$method=new ReflectionMethod(\App\Http\Controllers\Company\FeoUtilizerController::class,'validateCoordinatePair');
$method->setAccessible(true);
$invoke=static function(array $payload)use($method):?string{try{$method->invoke(null,$payload);return null;}catch(Throwable $e){return$e->getMessage();}};
$must($invoke([])===null,'empty utilizer coordinates must remain allowed');
$must($invoke(['latitude'=>'55,7558','longitude'=>'37,6176'])===null,'valid comma decimal coordinates must pass');
$must(str_contains((string)$invoke(['latitude'=>'0','longitude'=>'0']),'0,0 недопустимы'),'0,0 must be rejected');
$must(str_contains((string)$invoke(['latitude'=>'55','longitude'=>'']),'парой чисел'),'partial coordinate pair must be rejected');
$must(str_contains((string)$invoke(['latitude'=>'91','longitude'=>'37']),'допустимый диапазон'),'out-of-range latitude must be rejected');

$view=$read('app/View/pages/company_feo_utilizers.php');
$controller=$read('app/Http/Controllers/Company/FeoUtilizerController.php');
$routes=$read('app/Http/Routes/company_feo_map.php');
$service=$read('app/Service/FeoUtilizerService.php');
$nav=$read('app/Support/feo_freshness_navigation.php');
$js=$read('public/assets/js/feo-utilizers.js');
$css=$read('public/assets/css/feo-utilizers.css');
$modalView=$read('app/View/partials/company_feo_utilizer_modal_view.php');
$modalEdit=$read('app/View/partials/company_feo_utilizer_modal_edit.php');
$productionQa=$read('.github/feo-map-stage1/production_visual.js');

$must(str_contains($view,'data-feo-utilizer-page-ready'),'browser-ready marker missing');
$must(str_contains($view,'table-card table-card--standard')&&str_contains($view,'data-erp-grid data-feo-utilizer-grid'),'canonical ERP registry missing');
$must(str_contains($view,'table-toolbar')&&str_contains($view,'found-label')&&str_contains($view,'toolbar-search')&&str_contains($view,'table-footer'),'canonical registry toolbar/footer missing');
$must(str_contains($view,'class="workspace-shell-1690 feo-utilizer-page"'),'canonical workspace-shell-1690 missing');
$must(!str_contains($view,'feo-domain-tabs'),'redundant FEO body navigation must stay removed');
$must(str_contains($css,'.feo-utilizer-page [data-feo-utilizer-grid] > .table-scroll > table{width:100%;max-width:100%;min-width:0;table-layout:fixed}'),'utilizer table must fit canonical content width');
$must(str_contains($css,'td:nth-child(3)')&&str_contains($css,'td:nth-child(4)')&&str_contains($css,'white-space:normal')&&str_contains($css,'overflow-wrap:anywhere'),'address/FKKO wrapping contract missing');
$must(str_contains($css,'#feo-utilizer-view-modal .modal-body{padding:16px}'),'canonical view/edit modal inset missing');
$must(!str_contains($css,'100vw'),'utilizer CSS must not force viewport width');
foreach(['ID','Утилизатор','Адрес','ФККО / классификация','ADR','Цвет','Координаты','Статус','Порядок']as$label)$must(str_contains($view,$label),'main registry column missing: '.$label);

$must(str_contains($view,'Название из заявки ФЭО сопоставляется с названием или синонимом утилизатора.'),'simple matching explanation missing');
$must(str_contains($view,'name="aliases"')&&str_contains($view,'Синонимы названия'),'create modal synonym field missing');
$must(str_contains($modalEdit,'name="aliases"')&&str_contains($modalEdit,'Синонимы названия'),'edit modal synonym field missing');
$must(str_contains($modalView,'Синонимы'),'view modal synonym row missing');
$must(str_contains($service,'feo_utilizer_aliases'),'service alias storage missing');
$must(str_contains($service,'currentPlacementProblemSummary'),'unmatched problem summary missing');
$must(str_contains($view,'data-feo-utilizer-problem')&&str_contains($view,'Не определён утилизатор:'),'red unresolved problem surface missing');
$must(str_contains($css,'.feo-utilizer-problem{')&&str_contains($css,'var(--alert-error'),'problem surface must use red ERP alert semantics');

foreach(['Автоматическое сопоставление','Ручные назначения','Добавить правило','Добавить назначение']as$text)$must(!str_contains($view,$text),'obsolete UI remains: '.$text);
foreach(['data-feo-utilizer-rules','data-feo-utilizer-overrides','feo-utilizer-rule-modal','feo-utilizer-override-modal']as$token)$must(!str_contains($view,$token),'obsolete registry/modal remains: '.$token);
foreach(['/company/feo/utilizers/rules','/company/feo/utilizers/overrides','/{id}/update','/{id}/delete']as$route)$must(!str_contains($routes,$route),'obsolete route remains: '.$route);
foreach(['createRule','deleteRule','setOverride','deleteOverride','public function update(','public function delete(']as$methodName)$must(!str_contains($controller,$methodName),'obsolete controller action remains: '.$methodName);
$must(!str_contains($service,'public function createRule(')&&!str_contains($service,'public function setOverride('),'obsolete matching mutation API remains');

$must(str_contains($nav,'data-feo-utilizer-badge')&&str_contains($nav,"className='nav-count is-alert'"),'utilizer red menu badge missing');
$must(str_contains($nav,'parentCount')&&str_contains($nav,'data-feo-freshness-badge'),'parent FEO menu badge must include utilizer problems');
$must(str_contains($nav,'currentPlacementProblemSummary($pdo)'),'menu problem count must derive from current placement matching');

$must(str_contains($js,'window.ModalShell.create'),'canonical ModalShell must drive utilizer view/edit');
$must(str_contains($js,"tbody.addEventListener('dblclick'"),'double-click row handler missing');
$must(str_contains($js,"event.target.closest('a, button, input, select, textarea, label')"),'interactive double-click exclusions changed');
$must(str_contains($js,"confirmWord: 'УДАЛИТЬ'"),'typed destructive confirmation missing');
$must(!preg_match('/\bconfirm\s*\(/',$js.$view),'native confirm() must not be used');
foreach(['/modal-view','/modal-edit','/modal-delete']as$suffix)$must(str_contains($js,$suffix),'ModalShell endpoint missing: '.$suffix);
foreach(['full_name','short_name','address','latitude','longitude','fkko_classification','color_hex','adr','is_active','sort_order','aliases']as$name){$must(str_contains($view,'name="'.$name.'"'),'create field missing: '.$name);$must(str_contains($modalEdit,'name="'.$name.'"'),'edit field missing: '.$name);}
$must(str_contains($modalView,'data-feo-utilizer-edit-btn')&&str_contains($modalView,'data-feo-utilizer-delete-btn')&&str_contains($modalView,'data-feo-utilizer-close-btn'),'view modal actions missing');
foreach(['modalView','modalEditForm','modalEditSubmit','modalDelete']as$methodName)$must(str_contains($controller,'function '.$methodName.'('),'controller modal method missing: '.$methodName);
$must(substr_count($controller,"requireRole(['company_owner'])")>=5,'owner-only RBAC must cover utilizer mutations/modal endpoints');

$must(str_contains($productionQa,'data-feo-utilizer-problem'),'production QA must inspect unresolved problem surface');
$must(str_contains($productionQa,'aliases'),'production QA must check synonym field');
$must(!str_contains($productionQa,'Добавить правило')&&!str_contains($productionQa,'Добавить назначение'),'production QA must stop expecting obsolete rule/override UI');

$must(str_contains($css,'.feo-utilizer-form-grid')&&str_contains($css,'.feo-utilizer-color-dot'),'scoped utilizer CSS helpers missing');
$must(!str_contains($css,'.btn{')&&!str_contains($css,'.table{')&&!str_contains($css,'.modal{'),'utilizer CSS must not redefine core ERP components');

echo "FEO_UTILIZER_ADMIN_UX_CONTRACT=PASS\n";
