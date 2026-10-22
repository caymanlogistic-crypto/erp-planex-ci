<?php
declare(strict_types=1);
$root=dirname(__DIR__);$files=[
'app/Service/WasteTripLegacySourceService.php','app/Service/WasteTripLegacySyncService.php','app/Service/WasteTripSynchronizationService.php','app/Service/WasteTripLegacyMappingService.php','app/Service/WasteTripService.php','app/Http/Controllers/Company/WasteTripController.php','app/Http/Routes/company_linear_trips.php','app/View/pages/company_waste_trips.php','app/View/partials/company_waste_trip_modal_edit.php'];
$fail=static function(string$m):never{fwrite(STDERR,"FAIL: {$m}\n");exit(1);};foreach($files as$f)if(!is_file($root.'/'.$f))$fail('missing '.$f);
$all='';foreach($files as$f)$all.="\n".(string)file_get_contents($root.'/'.$f);
$routes=(string)file_get_contents($root.'/app/Http/Routes/company_linear_trips.php');
$routeBootstrapEnd=strpos($routes,'$controller =');if($routeBootstrapEnd===false)$fail('route bootstrap boundary missing');
$routeBootstrap=substr($routes,0,$routeBootstrapEnd);
if(!function_exists('base_path')){function base_path(string $path=''):string{return dirname(__DIR__).($path!==''?'/'.$path:'');}}
try{eval('?>'.$routeBootstrap);}catch(Throwable $e){$fail('route bootstrap execution failed: '.$e->getMessage());}
if(!class_exists(\App\Service\WasteTripSynchronizationService::class,false))$fail('route bootstrap must load WasteTripSynchronizationService before controller dispatch');
foreach(['WasteTripLegacyImportService','importIndex','importSubmit','mapLegacyStatus','company_waste_trip_import.php','CRITICAL LEGACY NAMESPACE VIOLATION','assertNamespaceSafe','assertBusinessIdWithinHighWater']as$needle)if(str_contains($all,$needle))$fail('obsolete legacy namespace/import architecture remains: '.$needle);
$service=(string)file_get_contents($root.'/app/Service/WasteTripService.php');foreach(['FOR UPDATE','legacy_high_water_at_cutover','assertLegacyOwnedMutationAbsent','syncLegacyOwnedState','removed_at=NOW()','last_issued_business_id','legacy_last_checked_at IS NULL','ERP_BUSINESS_ID_START = 400','LEGACY_RANGE_WARNING_THRESHOLD = 390','Legacy ID range approaching ERP range start (400)','hasFlightId($legacyPdo,$next)',"source_system IN ('legacy_flights','legacy_sync')"]as$n)if(!str_contains($service,$n))$fail('service contract missing '.$n);
if(str_contains($service,'max((int)$state[\'legacy_high_water_at_cutover\']'))$fail('allocator must not use cutover high-water as runtime floor/ceiling');
$controller=(string)file_get_contents($root.'/app/Http/Controllers/Company/WasteTripController.php');foreach(['EntityResponsibilityService::eligibleRecipients','resolveMembershipTenantUserId','responsible_membership_id']as$n)if(!str_contains($controller,$n))$fail('manager membership controller wiring missing '.$n);if(!str_contains($controller,'проверка ID-коллизий недоступна')||!str_contains($controller,'createIndependent($pdo,$legacy'))$fail('independent collision/warning wiring missing');
$sync=(string)file_get_contents($root.'/app/Service/WasteTripLegacySyncService.php');foreach(['GET_LOCK','MAX_RUNTIME_SECONDS','linkedTripsForSync','markLegacySyncError','legacyRangeDiagnostic($legacyPdo)']as$n)if(!str_contains($sync,$n))$fail('sync contract missing '.$n);
$source=(string)file_get_contents($root.'/app/Service/WasteTripLegacySourceService.php');foreach(['SET SESSION TRANSACTION READ ONLY','дубликат заявки','planned_finish_date','hasFlightId']as$n)if(!str_contains($source,$n))$fail('source contract missing '.$n);
$buttonSync=(string)file_get_contents($root.'/app/Service/WasteTripSynchronizationService.php');
foreach(['WHERE f.id > 225','fetchAll(PDO::FETCH_ASSOC)','deleteMissingLegacyTrip','reconcileLegacyTrip',"'company_owner'",'GET_LOCK','RELEASE_LOCK'] as $n)if(!str_contains($buttonSync,$n))$fail('synchronization contract missing '.$n);
if(str_contains($routes,'departures/legacy')||str_contains($controller,'materializeSubmit')||str_contains($service,'createLegacyPrefill'))$fail('manual import must be removed');
if(!str_contains($routes,"post('/company/trips/departures/synchronize'")||!str_contains($controller,'WasteTripSynchronizationService::run'))$fail('sync POST wiring missing');

$edit=(string)file_get_contents($root.'/app/View/partials/company_waste_trip_modal_edit.php');if(!str_contains($edit,"==='legacy_flights'"))$fail('historical linked readonly UI missing');
$page=(string)file_get_contents($root.'/app/View/pages/company_waste_trips.php');if(!str_contains($page,'name="responsible_membership_id"')||!str_contains($page,'$oldResponsibleMembership'))$fail('main create manager membership/restoration semantics missing');$head=preg_match('/<thead><tr>(.*?)<\/tr><\/thead>/s',$page,$m)?$m[1]:'';if(preg_match_all('/<th\b[^>]*>/',$head)!==14)$fail('main registry must have exactly 14 columns');
foreach(['waste-count', '$openTripId', "loadModal('view'"] as $n)if(!str_contains($page,$n))$fail('registry/open-trip contract missing '.$n);
if(str_contains($page,"closeModal('waste-trip-view-modal');loadModal('edit'"))$fail('edit must not close the shared Waste Trip modal before switching modes');
if(!str_contains($page,"loadModal('edit',b.dataset.wasteOpenEdit)")||str_contains($page,'id="waste-trip-edit-modal"'))$fail('shared modal view/edit handoff missing');
echo "WASTE_TRIP_LEGACY_LINKED=PASS\n";
