<?php
declare(strict_types=1);
$root=dirname(__DIR__);$files=[
'app/Service/WasteTripLegacySourceService.php','app/Service/WasteTripLegacySyncService.php','app/Service/WasteTripService.php','app/Http/Controllers/Company/WasteTripController.php','app/Http/Routes/company_linear_trips.php','app/View/pages/company_waste_trip_legacy.php','app/View/pages/company_waste_trips.php','app/View/partials/company_waste_trip_modal_edit.php'];
$fail=static function(string$m):never{fwrite(STDERR,"FAIL: {$m}\n");exit(1);};foreach($files as$f)if(!is_file($root.'/'.$f))$fail('missing '.$f);
$all='';foreach($files as$f)$all.="\n".(string)file_get_contents($root.'/'.$f);
foreach(['WasteTripLegacyImportService','importIndex','importSubmit','mapLegacyStatus','company_waste_trip_import.php']as$needle)if(str_contains($all,$needle))$fail('old import architecture remains: '.$needle);
$service=(string)file_get_contents($root.'/app/Service/WasteTripService.php');foreach(['FOR UPDATE','legacy_high_water_at_cutover','CRITICAL LEGACY NAMESPACE VIOLATION','assertLegacyOwnedMutationAbsent','syncLegacyOwnedState','DELETE FROM waste_trip_requests','last_issued_business_id','legacy_last_checked_at IS NULL']as$n)if(!str_contains($service,$n))$fail('service contract missing '.$n);
$controller=(string)file_get_contents($root.'/app/Http/Controllers/Company/WasteTripController.php');if(!str_contains($controller,'namespace guard недоступен')||!str_contains($controller,'createIndependent($pdo,$legacy'))$fail('independent create namespace guard missing');
$sync=(string)file_get_contents($root.'/app/Service/WasteTripLegacySyncService.php');foreach(['GET_LOCK','MAX_RUNTIME_SECONDS','linkedTripsForSync','markLegacySyncError']as$n)if(!str_contains($sync,$n))$fail('sync contract missing '.$n);
$source=(string)file_get_contents($root.'/app/Service/WasteTripLegacySourceService.php');foreach(['WHERE f.id > 243','SET SESSION TRANSACTION READ ONLY','дубликат заявки','неполный диапазон','planned_finish_date']as$n)if(!str_contains($source,$n))$fail('source contract missing '.$n);
$edit=(string)file_get_contents($root.'/app/View/partials/company_waste_trip_modal_edit.php');if(!str_contains($edit,'$linked'))$fail('linked readonly UI missing');
$page=(string)file_get_contents($root.'/app/View/pages/company_waste_trips.php');$head=preg_match('/<thead><tr>(.*?)<\/tr><\/thead>/s',$page,$m)?$m[1]:'';if(substr_count($head,'<th>')!==9)$fail('main registry must have exactly 9 columns');
if(!str_contains($page,"closeModal('waste-trip-view-modal');loadModal('edit'"))$fail('modal stack handoff missing');
echo "WASTE_TRIP_LEGACY_LINKED=PASS\n";
