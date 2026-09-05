<?php

use App\Core\Database;
use App\Service\FinanceEmployeeDirectTransferEditService;
use App\Service\FinanceEmployeeTransferService;

verifyCsrfRequest();
$returnRef=trim((string)($_POST['return_employee_ref']??$_POST['source_employee_ref']??''));
try{
    $companyId=(int)(getSessionCompanyId()??0);
    if($companyId<=0) throw new RuntimeException('Компания не найдена.');
    $central=$db->connection();
    $stmt=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");
    $stmt->execute([$companyId]);
    $company=$stmt->fetch(\PDO::FETCH_ASSOC);
    if(!$company) throw new RuntimeException('Компания не найдена или неактивна.');
    $pdo=(new Database(companyDatabaseConfig($config,$company)))->connection();
    $groupId=trim((string)($_POST['transfer_group_id']??''));
    $user=['id'=>(int)($_SESSION['user_id']??0),'role'=>(string)($_SESSION['role_code']??'company_owner')];
    if(str_starts_with($groupId,'EMPLOYEE-DIRECT-')){
        FinanceEmployeeDirectTransferEditService::update($pdo,$central,$companyId,$groupId,$_POST,$user);
    }else{
        FinanceEmployeeTransferService::updateTransfer($pdo,$central,$companyId,$groupId,$_POST,$user);
    }
    $_SESSION['employee_payments_success']='Передача между сотрудниками изменена.';
}catch(Throwable $e){$_SESSION['employee_payments_error']=$e->getMessage();}
$url='/company/finance/employee-payments';
if($returnRef!=='')$url.='?employee_ref='.rawurlencode($returnRef);
redirect_to($url);
