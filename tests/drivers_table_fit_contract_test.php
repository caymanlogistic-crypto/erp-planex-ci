<?php
declare(strict_types=1);
$root=dirname(__DIR__);$view=file_get_contents($root.'/app/View/pages/company_drivers.php');
$must=static function(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}};
$must(!str_contains($view,'<style>'),'no local presentation copy');
$must(str_contains($view,'registry_ui_head.php')&&str_contains($view,'registry_ui_state_scope.php'),'shared header and authorized scope');
$must(str_contains($view,'registry-ui-data')&&str_contains($view,'registry-ui-scroll'),'shared responsive table');
$must(substr_count($view,'<th>')===8,'all eight business columns');
$must(str_contains($view,'data-registry-search="<?= e($fullSearch) ?>"'),'full raw values remain searchable');
$must(str_contains($view,'data-driver-id=')&&str_contains($view,"'/company/drivers/modal-create'"),'existing modal targets preserved');
$must(!str_contains($view,'Сортировка по:')&&!str_contains($view,'table-footer'),'no redundant label/footer');
echo "DRIVERS_TABLE_FIT_CONTRACT=PASS\n";
