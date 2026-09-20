<?php
declare(strict_types=1);

namespace App\Service;

use PDO;

require_once __DIR__ . '/../Support/crypto_helper.php';
require_once __DIR__ . '/EmployeeMailTransport.php';

/** Central account + existing legal company, independent of the identity email. */
final class EmployeeMailProfileService
{
    public function __construct(private readonly PDO $pdo, private readonly int $tenantCompanyId) {}

    public function ready(): bool
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('employee_mail_profiles','employee_mail_legal_entities','employee_mail_settings')")->fetchColumn()===3;
    }

    public function settings(): array
    {
        $q=$this->pdo->prepare('SELECT imap_host,imap_port,smtp_host,smtp_port,encryption FROM employee_mail_settings WHERE company_id=?');$q->execute([$this->tenantCompanyId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['imap_host']!==EmployeeMailTransport::IMAP_HOST || (int)$row['imap_port']!==993 || $row['smtp_host']!==EmployeeMailTransport::SMTP_HOST || (int)$row['smtp_port']!==465 || $row['encryption']!=='SSL') throw new \DomainException('Общие настройки рабочей почты Spaceweb не заполнены.');
        return $row;
    }

    public function assertMember(int $accountId, int $companyId): void
    {
        $q = $this->pdo->prepare("SELECT m.id FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id JOIN companies c ON c.id=m.company_id WHERE m.account_id=? AND m.company_id=? AND m.status='active' AND a.status='active' AND c.status='active'");
        $q->execute([$accountId, $companyId]);
        if (!$q->fetchColumn()) throw new \DomainException('Нет активного сотрудника в выбранном юридическом лице.');
    }

    public function assertOwner(int $actorAccountId, int $companyId): void
    {
        $this->assertMember($actorAccountId, $companyId);
        $q = $this->pdo->prepare("SELECT m.id FROM company_memberships m JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id WHERE m.account_id=? AND m.company_id=? AND r.is_system=1 AND r.system_key='company_owner'");
        $q->execute([$actorAccountId, $companyId]);
        if (!$q->fetchColumn()) throw new \DomainException('Настройка почты доступна только руководителю юридического лица.');
    }

    /** Mail company identity is independent of client and contractor directories. */
    public function legalEntity(int $entityId): array
    {
        $q=$this->pdo->prepare('SELECT id,company_id,code,name AS company_name,email_domain FROM employee_mail_legal_entities WHERE id=? AND company_id=?');
        $q->execute([$entityId,$this->tenantCompanyId]);
        $entity=$q->fetch(PDO::FETCH_ASSOC);
        if (!$entity) throw new \DomainException('Компания не настроена для рабочей почты этого пользователя.');
        return $entity;
    }

    public function listForEmployee(int $actorAccountId, int $accountId): array
    {
        $this->assertOwner($actorAccountId,$this->tenantCompanyId);
        $this->assertMember($accountId,$this->tenantCompanyId);
        if (!$this->ready()) return [];
        $q=$this->pdo->prepare('SELECT id FROM employee_mail_legal_entities WHERE company_id=? ORDER BY id');
        $q->execute([$this->tenantCompanyId]);
        $rows=[];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $entity=$this->legalEntity((int)$id);
            $profile=$this->publicProfile($accountId,(int)$id);
            $rows[]=['legal_entity_id'=>(int)$id,'company_id'=>$this->tenantCompanyId,'company_name'=>$entity['company_name'],'code'=>$entity['code'],'email_domain'=>$entity['email_domain'],'schema_ready'=>true,'imap_host'=>EmployeeMailTransport::IMAP_HOST,'imap_port'=>993,'smtp_host'=>EmployeeMailTransport::SMTP_HOST,'smtp_port'=>465,'encryption'=>'SSL']+($profile??['email'=>'','has_password'=>false,'connection_status'=>'incomplete','checked_at'=>null,'version'=>0]);
        }
        return $rows;
    }

    public function publicProfile(int $accountId, int $legalEntityId): ?array
    {
        $q = $this->pdo->prepare("SELECT id,email,(password_encrypted <> '') AS has_password,connection_status,checked_at,version FROM employee_mail_profiles WHERE account_id=? AND company_id=? AND legal_entity_id=?");
        $q->execute([$accountId,$this->tenantCompanyId,$legalEntityId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function save(int $actorAccountId, int $accountId, int $legalEntityId, #[\SensitiveParameter] array $input): array
    {
        $this->assertOwner($actorAccountId,$this->tenantCompanyId);
        $this->assertMember($accountId,$this->tenantCompanyId);
        $entity=$this->legalEntity($legalEntityId);
        if (!$this->ready()) throw new \DomainException('Рабочая почта ожидает обновления структуры базы данных.');
        $email = strtolower(trim((string)($input['email'] ?? '')));
        if (strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Укажите корректный адрес рабочей почты.');
        if (substr(strrchr($email,'@')?:'',1)!==$entity['email_domain']) throw new \InvalidArgumentException('Для выбранного юридического лица укажите адрес @'.$entity['email_domain'].'.');
        $password = (string)($input['password'] ?? '');
        if (strlen($password)>512 || preg_match('/[\r\n\x00]/',$password)) throw new \InvalidArgumentException('Некорректный пароль почты.');
        if (preg_match('/^[XХхx]{4,}$/u',$password)) throw new \InvalidArgumentException('Вместо заглушки оставьте пароль пустым: подключение будет незавершённым.');
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT * FROM employee_mail_profiles WHERE account_id=? AND company_id=? AND legal_entity_id=? FOR UPDATE');
            $q->execute([$accountId,$this->tenantCompanyId,$legalEntityId]);
            $old=$q->fetch(PDO::FETCH_ASSOC) ?: null;
            if ((int)($input['version']??0)!==(int)($old['version']??0)) throw new \DomainException('Профиль изменён другим пользователем. Обновите карточку.');
            $changed = !$old || $old['email']!==$email || $password!=='' || !empty($input['clear_password']);
            $encrypted = $old && $old['email']===$email ? $old['password_encrypted'] : '';
            if (!empty($input['clear_password'])) $encrypted='';
            elseif ($password!=='') $encrypted=cryptoEncrypt($password);
            $status = $changed ? ($encrypted===''?'incomplete':'unverified') : $old['connection_status'];
            $checked = $changed ? null : $old['checked_at'];
            // A duplicate address owned by another account must never redirect this update.
            $dup=$this->pdo->prepare('SELECT id FROM employee_mail_profiles WHERE email=? AND NOT(account_id=? AND company_id=? AND legal_entity_id=?)');
            $dup->execute([$email,$accountId,$this->tenantCompanyId,$legalEntityId]);
            if ($dup->fetchColumn()) throw new \DomainException('Этот почтовый адрес уже используется другим профилем.');
            if ($old) {
                $q=$this->pdo->prepare('UPDATE employee_mail_profiles SET email=?,password_encrypted=?,connection_status=?,checked_at=?,version=version+1 WHERE account_id=? AND company_id=? AND legal_entity_id=?');
                $q->execute([$email,$encrypted,$status,$checked,$accountId,$this->tenantCompanyId,$legalEntityId]);
            } else {
                $q=$this->pdo->prepare('INSERT INTO employee_mail_profiles(account_id,company_id,legal_entity_id,email,password_encrypted,connection_status,checked_at) VALUES(?,?,?,?,?,?,?)');
                $q->execute([$accountId,$this->tenantCompanyId,$legalEntityId,$email,$encrypted,$status,$checked]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($e instanceof \PDOException) throw new \DomainException('Не удалось сохранить профиль. Проверьте, что адрес не занят.');
            throw $e;
        }
        return $this->publicProfile($accountId,$legalEntityId);
    }

    /** Credentials are only consumed by transport; callers must never log this result. */
    public function credentials(int $accountId, int $legalEntityId): array
    {
        $this->settings();
        $this->assertMember($accountId,$this->tenantCompanyId);
        $entity=$this->legalEntity($legalEntityId);
        $q=$this->pdo->prepare('SELECT id,email,password_encrypted,version FROM employee_mail_profiles WHERE account_id=? AND company_id=? AND legal_entity_id=?');
        $q->execute([$accountId,$this->tenantCompanyId,$legalEntityId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['password_encrypted']==='') throw new \DomainException('Рабочий ящик сотрудника для выбранного юридического лица не настроен. Откройте «Рабочая почта» в карточке сотрудника.');
        try { $password=cryptoDecrypt($row['password_encrypted']); }
        catch (\Throwable) { throw new \DomainException('Не удалось расшифровать пароль рабочей почты. Проверьте ключ шифрования сервера.'); }
        unset($row['password_encrypted']);
        $row['password']=$password;
        return $row;
    }

    /** Future document requests must persist company_id, legal_entity_id, sender profile id and email
     * with the thread, then call this resolver with that same company for follow-ups.
     * No fallback to user_accounts.email or a different company is allowed.
     */
    public function resolve(int $accountId, int $legalEntityId): array
    {
        $this->settings();
        $this->assertMember($accountId,$this->tenantCompanyId);
        $entity=$this->legalEntity($legalEntityId);
        if (!$this->ready()) throw new \DomainException('Рабочая почта ожидает обновления структуры базы данных.');
        $profile=$this->publicProfile($accountId,$legalEntityId);
        if (!$profile || !$profile['has_password'] || $profile['connection_status']!=='connected') {
            throw new \DomainException('Рабочий ящик сотрудника для выбранного юридического лица не подключён. Настройте и проверьте его в карточке сотрудника.');
        }
        return ['profile_id'=>(int)$profile['id'],'account_id'=>$accountId,'company_id'=>$this->tenantCompanyId,'legal_entity_id'=>$legalEntityId,'email'=>$profile['email']];
    }

    public function resolveEnvelope(int $senderAccountId, int $legalEntityId, ?int $assistantAccountId=null, array $employeeAccountIds=[]): array
    {
        $sender=$this->resolve($senderAccountId,$legalEntityId);
        $cc=$assistantAccountId!==null ? [$this->resolve($assistantAccountId,$legalEntityId)['email']] : [];
        $recipients=[];
        foreach (array_unique($employeeAccountIds) as $accountId) $recipients[]=$this->resolve((int)$accountId,$legalEntityId)['email'];
        return ['sender'=>$sender,'cc'=>$cc,'employees'=>array_values(array_unique($recipients))];
    }

    public function check(int $actorAccountId, int $accountId, int $legalEntityId, ?callable $probe=null): array
    {
        $this->assertOwner($actorAccountId,$this->tenantCompanyId);
        $credentials=$this->credentials($accountId,$legalEntityId);
        $result=($probe ?? [new EmployeeMailTransport(),'check'])($credentials);
        $connected=($result['imap']??false)===true && ($result['smtp']??false)===true;
        $q=$this->pdo->prepare('UPDATE employee_mail_profiles SET connection_status=?,checked_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=? AND version=?');
        $q->execute([$connected?'connected':'failed',$credentials['id'],$credentials['version']]);
        if ($q->rowCount()!==1) throw new \DomainException('Настройки изменились во время проверки. Повторите проверку.');
        return ['profile'=>$this->publicProfile($accountId,$legalEntityId),'imap'=>(bool)($result['imap']??false),'smtp'=>(bool)($result['smtp']??false),'message'=>$connected?'IMAP и SMTP подключены. Письма не отправлялись.':'Не удалось подключить '.(!$result['imap']?'IMAP ':'').(!$result['smtp']?'SMTP':'').'. Проверьте пароль и доступность почтового сервера.'];
    }
}
