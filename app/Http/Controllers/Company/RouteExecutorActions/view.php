<?php

require_once base_path('app/Support/crew_driver_helpers.php');
$crewId = (int)$crewId;
$pageTitle = 'Исполнитель рейса';
$pageScripts[] = '/assets/js/route-executor-delete.js';
$pageContext = 'Исполнители рейса › Компания';
$entityNotFound = false;
$companyId = (int)(getSessionCompanyId() ?? 0);

if ($companyId <= 0) {
    $company=null;$crew=null;$dbError=null;
    ob_start();require base_path('app/View/pages/company_route_executor_view.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;
}

try {
    $pdo=$db->connection();$stmt=$pdo->prepare('SELECT * FROM companies WHERE id=?');$stmt->execute([$companyId]);$company=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$company){$company=null;$crew=null;$dbError=null;ob_start();require base_path('app/View/pages/company_route_executor_view.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
    $pageContext='Исполнители рейса › Компания: '.$company['name'];
    if(($company['status']??'')!=='active'){$crew=null;$dbError=null;ob_start();require base_path('app/View/pages/company_route_executor_view.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
    $localPdo=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();
    $cStmt=$localPdo->prepare("SELECT c.*,ct.id contractor_id,ct.created_by_user_id contractor_created_by_user_id,ct.name contractor_name,ct.inn contractor_inn,d.id driver_id,d.created_by_user_id driver_created_by_user_id,d.full_name driver_name,d.phone driver_phone,dvb.id driver_vehicle_block_id,dvb.created_by_user_id dvb_created_by_user_id,vs.id vehicle_set_id,vs.created_by_user_id vehicle_set_created_by_user_id,vs.set_type,vu1.plate_number primary_plate,vu1.brand primary_brand,vu1.model primary_model,vu2.plate_number secondary_plate,vu2.brand secondary_brand,vu2.model secondary_model,u.full_name created_by_name FROM crews c JOIN contractors ct ON c.contractor_id=ct.id JOIN driver_vehicle_blocks dvb ON c.driver_vehicle_block_id=dvb.id JOIN drivers d ON dvb.driver_id=d.id JOIN vehicle_sets vs ON dvb.vehicle_set_id=vs.id LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id=vu1.id LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id=vu2.id LEFT JOIN users u ON c.created_by_user_id=u.id WHERE c.id=? AND c.deleted_at IS NULL");
    $cStmt->execute([$crewId]);$crew=$cStmt->fetch(PDO::FETCH_ASSOC);
    if(!$crew){$entityNotFound=true;}
    $accessDenied=$crew&&!\App\Service\RouteExecutorService::canAccessCrew($localPdo,$crewId)
        ? 'Исполнитель рейса недоступен по текущим правам на подрядчика, водителей или транспорт.'
        : null;
    if($crew&&!$accessDenied){
        $responsibleMembershipId=\App\Service\EntityResponsibilityService::effectiveBaseMembershipId($localPdo,'route_executor',$crewId);
        $crew['responsible_name']=$crew['created_by_name']??'—';
        foreach(\App\Service\EntityResponsibilityService::eligibleRecipients($pdo,$localPdo,$companyId) as $logist){
            if((int)$logist['membership_id']===$responsibleMembershipId){$crew['responsible_name']=$logist['full_name'];break;}
        }
        $crew['driver_name']=crewDriverNames($localPdo,$crewId,(string)($crew['driver_name']??''));
        $ids=loadCrewDriverIds($localPdo,$crewId,(int)($crew['driver_id']??0));
        $crew['driver_count']=count($ids);
    }
    $pageTitle=($crew&&!$accessDenied)?'Исполнитель рейса #'.$crew['id']:'Исполнитель рейса';$dbError=null;
} catch(Throwable $e){$company=$company??null;$crew=null;$dbError='Не удалось подключиться к базе данных компании.';}

ob_start();require base_path('app/View/pages/company_route_executor_view.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');
