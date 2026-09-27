<?php

require_once base_path('app/Support/crew_driver_helpers.php');
$pageTitle='Исполнители рейса';$pageContext='Исполнители рейса › Компания';$companyId=(int)(getSessionCompanyId()??0);
$pageStylesheets[] = '/assets/css/route-executor-combined-create.css';
$pageScripts[] = '/assets/js/route-executor-combined-create.js';
$pageScripts[] = '/assets/js/route-executor-delete.js';
if($companyId<=0){$company=null;$executors=[];$dbError=null;ob_start();require base_path('app/View/pages/company_route_executors.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
try{
    $pdo=$db->connection();$stmt=$pdo->prepare('SELECT * FROM companies WHERE id=?');$stmt->execute([$companyId]);$company=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$company){$company=null;$executors=[];$dbError=null;ob_start();require base_path('app/View/pages/company_route_executors.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
    $pageContext='Исполнители рейса › Компания: '.$company['name'];
    if(($company['status']??'')!=='active'){$executors=[];$dbError=null;ob_start();require base_path('app/View/pages/company_route_executors.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
    $localPdo=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();
    $responsibleLogists=\App\Service\EntityResponsibilityService::eligibleRecipients($db->connection(),$localPdo,$companyId);
    $responsibleLogistNames=[];
    foreach($responsibleLogists as $logist)$responsibleLogistNames[(int)$logist['membership_id']]=(string)$logist['full_name'];
    $hasGrantsButAllArchived=false;
    $baseSql="SELECT c.id crew_id,c.status crew_status,c.created_at crew_created_at,c.created_by_user_id,c.created_by_role,ct.id contractor_id,ct.created_by_user_id contractor_created_by_user_id,ct.name contractor_name,d.id driver_id,d.created_by_user_id driver_created_by_user_id,d.full_name driver_name,d.phone driver_phone,dvb.id driver_vehicle_block_id,dvb.created_by_user_id dvb_created_by_user_id,vs.id vehicle_set_id,vs.created_by_user_id vehicle_set_created_by_user_id,vs.set_type,CONCAT(vu1.plate_number,IFNULL(CONCAT(' + ',vu2.plate_number),'')) plates,vu1.plate_number primary_plate,u.full_name created_by_name,er.membership_id responsible_membership_id FROM crews c JOIN contractors ct ON c.contractor_id=ct.id JOIN driver_vehicle_blocks dvb ON c.driver_vehicle_block_id=dvb.id JOIN drivers d ON dvb.driver_id=d.id JOIN vehicle_sets vs ON dvb.vehicle_set_id=vs.id LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id=vu1.id LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id=vu2.id LEFT JOIN users u ON c.created_by_user_id=u.id LEFT JOIN entity_responsibilities er ON er.entity_type='route_executor' AND er.entity_id=c.id";
    $executors=$localPdo->query($baseSql.' WHERE c.deleted_at IS NULL ORDER BY c.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    $executors=\App\Service\RouteExecutorService::filterVisibleCrews($localPdo,$executors);
    foreach($executors as &$executor){
        $executor['responsible_name']=$responsibleLogistNames[(int)($executor['responsible_membership_id']??0)]??($executor['created_by_name']??'—');
        $executor['driver_name']=crewDriverNames($localPdo,(int)$executor['crew_id'],(string)($executor['driver_name']??''));
        $executor['driver_count']=count(loadCrewDriverIds($localPdo,(int)$executor['crew_id'],(int)($executor['driver_id']??0)));
    }unset($executor);
    $dbError=null;
}catch(Throwable $e){$company=$company??null;$executors=[];$hasGrantsButAllArchived=false;$dbError='Не удалось подключиться к базе данных компании.';}
ob_start();require base_path('app/View/pages/company_route_executors.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');
