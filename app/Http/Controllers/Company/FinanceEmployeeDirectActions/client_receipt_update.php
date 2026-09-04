<?php

use App\Core\Database;
use App\Service\FinanceEmployeeClientReceiptEditService;

verifyCsrfRequest();
$employeeRef=trim((string)($_POST['employee_ref']??''));
try{
    $companyId=(int)(getSessionCompanyId()??0);
    if($companyId<=0) throw new RuntimeException('Компания не найдена.');
    $central=$db->connection();
    $stmt=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");
    $stmt->execute([$companyId]);
    $company=$stmt->fetch(\PDO::FETCH_ASSOC);
    if(!$company) throw new RuntimeException('Компания не найдена или неактивна.');
    $pdo=(new Database(companyDatabaseConfig($config,$company)))->connection();
    FinanceEmployeeClientReceiptEditService::update($pdo,(int)($_POST['movement_id']??0),$_POST,[
        'id'=>(int)($_SESSION['user_id']??0),'role'=>(string)($_SESSION['role_code']??'company_owner')
    ]);
    $_SESSION['employee_payments_success']='Платёж клиента изменён. Счёт и взаиморасчёты пересчитаны.';
}catch(Throwable $e){$_SESSION['employee_payments_error']=$e->getMessage();}
$url='/company/finance/employee-payments';
if($employeeRef!=='')$url.='?employee_ref='.rawurlencode($employeeRef);
redirect_to($url);
