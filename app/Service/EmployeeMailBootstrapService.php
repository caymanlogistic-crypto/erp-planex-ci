<?php
declare(strict_types=1);
namespace App\Service;
use PDO;
require_once __DIR__.'/EmployeeMailProfileService.php';

/** Task-specific, explicit setup; never creates identities, memberships or companies. */
final class EmployeeMailBootstrapService
{
    public function __construct(private readonly PDO $central,private readonly int $companyId) {}

    public function mapping(): array
    {
        $q=$this->central->prepare("SELECT id,name,inn FROM companies WHERE id=? AND inn='5406854242' AND status='active'");$q->execute([$this->companyId]);
        $company=$q->fetch(PDO::FETCH_ASSOC);
        if (!$company) throw new \DomainException('Не найдено действующее ООО ПЛАНЭКС с ожидаемым ИНН.');
        $q=$this->central->prepare("SELECT a.id AS account_id,a.full_name,a.login,a.email AS original_email,m.id AS membership_id,r.system_key FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id WHERE m.company_id=? AND m.status='active' AND a.status='active'");$q->execute([$this->companyId]);
        $members=$q->fetchAll(PDO::FETCH_ASSOC);$employees=[];$owners=[];
        foreach ($members as $row) if ($row['system_key']==='company_owner') $owners[]=(int)$row['account_id'];
        if (count($owners)!==1) throw new \DomainException('Не найден единственный руководитель компании.');
        foreach (['gladskih','enshina','sidorov'] as $login) {
            $matches=array_values(array_filter($members,static fn(array $r):bool=>mb_strtolower(trim($r['login']))===$login || ($login==='gladskih' && preg_match('/^Гладских(?:\s|$)/ui',trim($r['full_name']))===1)));
            if (count($matches)!==1) throw new \DomainException('Не найден единственный действующий сотрудник: '.$login.'.');
            $employees[$login]=$matches[0];
        }
        if (count(array_unique(array_column($employees,'account_id')))!==3) throw new \DomainException('Сопоставление сотрудников неоднозначно.');
        return ['company'=>$company,'owner_account_id'=>$owners[0],'employees'=>$employees];
    }

    public function seed(#[\SensitiveParameter] array $passwords): array
    {
        $mapping=$this->mapping();
        $allowed=[];foreach(array_keys($mapping['employees']) as $login)foreach(['plan-ex.ru','mg-log.ru'] as $domain)$allowed[]=$login.'@'.$domain;
        foreach(array_keys($passwords) as $email) if(!in_array($email,$allowed,true))throw new \DomainException('В наборе есть неизвестный почтовый адрес.');
        if (!empty($passwords['gladskih@mg-log.ru'])) throw new \DomainException('Для gladskih@mg-log.ru в этой задаче пароль не предоставлен.');
        $s=new EmployeeMailProfileService($this->central,$this->companyId);
        $entities=[];
        $this->central->beginTransaction();
        try {
            $q=$this->central->prepare('INSERT IGNORE INTO employee_mail_settings(company_id) VALUES(?)');$q->execute([$this->companyId]);
            foreach (['plan-ex.ru'=>['planex','ПЛАНЭКС'],'mg-log.ru'=>['mg_log','МГ-ЛОГ']] as $domain=>[$code,$name]) {
                $q=$this->central->prepare('SELECT id,email_domain FROM employee_mail_legal_entities WHERE company_id=? AND code=? FOR UPDATE');$q->execute([$this->companyId,$code]);$row=$q->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['email_domain']!==$domain) throw new \DomainException('Существующая привязка юрлица имеет другой домен.');
                if (!$row) {$q=$this->central->prepare('INSERT INTO employee_mail_legal_entities(company_id,code,name,email_domain) VALUES(?,?,?,?)');$q->execute([$this->companyId,$code,$name,$domain]);$row=['id'=>(int)$this->central->lastInsertId()];}
                $entities[$domain]=(int)$row['id'];
            }
            $this->central->commit();
        } catch (\Throwable $e) {if($this->central->inTransaction())$this->central->rollBack();throw $e;}
        $results=[];
        foreach ($mapping['employees'] as $login=>$employee) foreach ($entities as $domain=>$entityId) {
            $email=$login.'@'.$domain;$account=(int)$employee['account_id'];
            $old=$s->publicProfile($account,$entityId);
            if ($old && $old['email']!==$email) throw new \DomainException('Существующий почтовый профиль отличается от согласованного адреса: '.$email);
            $profile=$s->save($mapping['owner_account_id'],$account,$entityId,['email'=>$email,'password'=>$passwords[$email]??'','version'=>$old['version']??0,'clear_password'=>$email==='gladskih@mg-log.ru']);
            $results[]=['account_id'=>$account,'legal_entity_id'=>$entityId]+$profile;
        }
        return $results;
    }

    public function checkAll(): array
    {
        $mapping=$this->mapping();$s=new EmployeeMailProfileService($this->central,$this->companyId);$results=[];
        foreach ($mapping['employees'] as $employee) foreach ($s->listForEmployee($mapping['owner_account_id'],(int)$employee['account_id']) as $profile) {
            if ($profile['has_password']) $result=$s->check($mapping['owner_account_id'],(int)$employee['account_id'],(int)$profile['legal_entity_id']);
            else $result=['profile'=>$profile,'imap'=>false,'smtp'=>false];
            $results[]=['email'=>$profile['email'],'status'=>$result['profile']['connection_status'],'imap'=>$result['imap'],'smtp'=>$result['smtp']];
        }
        return $results;
    }
}
