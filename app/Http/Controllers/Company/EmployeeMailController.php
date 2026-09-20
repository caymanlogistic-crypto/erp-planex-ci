<?php
declare(strict_types=1);
namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Service\EmployeeMailProfileService;

final class EmployeeMailController
{
    public function __construct(private readonly Database $db) {}
    public function profiles(string $id): void { $this->execute($id,'list'); }
    public function save(string $id): void { $this->execute($id,'save'); }
    public function check(string $id): void { $this->execute($id,'check'); }

    private function execute(string $id,string $operation): void
    {
        header('Cache-Control: no-store');
        $access=AccessRuntime::current();
        if (!$access->isActive() || !$access->isSystemOwner()) {
            jsonResponse(['ok'=>false,'message'=>'РќР°СЃС‚СЂРѕР№РєР° РїРѕС‡С‚С‹ РґРѕСЃС‚СѓРїРЅР° С‚РѕР»СЊРєРѕ СЂСѓРєРѕРІРѕРґРёС‚РµР»СЋ.'],403); return;
        }
        try {
            $pdo=$this->db->connection();
            $q=$pdo->prepare('SELECT account_id FROM company_memberships WHERE id=? AND company_id=?');
            $q->execute([(int)$id,$access->companyId()]);
            $accountId=(int)$q->fetchColumn();
            if (!$accountId) throw new \DomainException('РЎРѕС‚СЂСѓРґРЅРёРє РЅРµ РЅР°Р№РґРµРЅ РІ С‚РµРєСѓС‰РµР№ РєРѕРјРїР°РЅРёРё.');
            $q->execute([$access->membershipId(),$access->companyId()]);
            $actor=(int)$q->fetchColumn();
            $service=new EmployeeMailProfileService($pdo,(int)$access->companyId());
            $service->assertOwner($actor,(int)$access->companyId());
            if ($operation==='list') {
                jsonResponse(['ok'=>true,'profiles'=>$service->listForEmployee($actor,$accountId)]); return;
            }
            $input=array_replace($_POST,requestJsonBody());
            $companyId=filter_var($input['legal_entity_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$companyId) throw new \InvalidArgumentException('Р’С‹Р±РµСЂРёС‚Рµ СЋСЂРёРґРёС‡РµСЃРєРѕРµ Р»РёС†Рѕ.');
            $result=$operation==='save' ? ['profile'=>$service->save($actor,$accountId,(int)$companyId,$input)] : $service->check($actor,$accountId,(int)$companyId);
            jsonResponse(['ok'=>true]+$result);
        } catch (\InvalidArgumentException|\DomainException $e) {
            jsonResponse(['ok'=>false,'message'=>$e->getMessage()],422);
        } catch (\Throwable) {
            // Never serialize exception arguments or connection details.
            jsonResponse(['ok'=>false,'message'=>'РќРµ СѓРґР°Р»РѕСЃСЊ РІС‹РїРѕР»РЅРёС‚СЊ РѕРїРµСЂР°С†РёСЋ СЃ СЂР°Р±РѕС‡РµР№ РїРѕС‡С‚РѕР№. РџСЂРѕРІРµСЂСЊС‚Рµ РґРѕСЃС‚СѓРїРЅРѕСЃС‚СЊ Р±Р°Р·С‹ Рё РєР»СЋС‡ С€РёС„СЂРѕРІР°РЅРёСЏ.'],500);
        }
    }
}
