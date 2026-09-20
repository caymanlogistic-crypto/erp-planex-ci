<?php
declare(strict_types=1);
namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Service\EmployeeMailProfileService;

final class EmployeeMailController
{
    public function __construct(private readonly Database $db) {}
    public function template(): void { $this->execute('0','list'); }
    public function profiles(string $id): void { $this->execute($id,'list'); }
    public function save(string $id): void { $this->execute($id,'save'); }
    public function check(string $id): void { $this->execute($id,'check'); }

    private function execute(string $id,string $operation): void
    {
        header('Cache-Control: no-store');
        $access=AccessRuntime::current();
        if (!$access->isActive() || !$access->isSystemOwner()) {
            jsonResponse(['ok'=>false,'message'=>'Настройка почты доступна только руководителю.'],403); return;
        }
        try {
            $pdo=$this->db->connection();
            $q=$pdo->prepare('SELECT account_id FROM company_memberships WHERE id=? AND company_id=?');
            $q->execute([(int)$id,$access->companyId()]);
            $accountId=(int)$q->fetchColumn();
            if (!$accountId && !($operation==='list' && $id==='0')) throw new \DomainException('Сотрудник не найден в текущей компании.');
            $q->execute([$access->membershipId(),$access->companyId()]);
            $actor=(int)$q->fetchColumn();
            $service=new EmployeeMailProfileService($pdo,(int)$access->companyId());
            $service->assertOwner($actor,(int)$access->companyId());
            if ($operation==='list') {
                jsonResponse(['ok'=>true,'profiles'=>$service->listForEmployee($actor,$accountId)]); return;
            }
            $input=array_replace($_POST,requestJsonBody());
            $companyId=filter_var($input['legal_entity_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$companyId) throw new \InvalidArgumentException('Выберите юридическое лицо.');
            $result=$operation==='save' ? ['profile'=>$service->save($actor,$accountId,(int)$companyId,$input)] : $service->check($actor,$accountId,(int)$companyId);
            jsonResponse(['ok'=>true]+$result);
        } catch (\InvalidArgumentException|\DomainException $e) {
            jsonResponse(['ok'=>false,'message'=>$e->getMessage()],422);
        } catch (\Throwable) {
            // Never serialize exception arguments or connection details.
            jsonResponse(['ok'=>false,'message'=>'Не удалось выполнить операцию с рабочей почтой. Проверьте доступность базы и ключ шифрования.'],500);
        }
    }
}
