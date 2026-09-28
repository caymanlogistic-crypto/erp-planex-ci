<?php
declare(strict_types=1);

namespace App\Service;

use DomainException;
use InvalidArgumentException;
use PDO;
use Throwable;

final class RouteExecutorReviewRecipientService
{
    public function __construct(private readonly PDO $pdo, private readonly int $companyId) {}

    public function ready(): bool
    {
        $stmt=$this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('company_route_executor_review_email_settings','company_route_executor_review_email_recipients')");
        return (int)$stmt->fetchColumn()===2;
    }

    /** @return array{recipients:list<array<string,mixed>>,send_responsible_logist:bool} */
    public function read(): array
    {
        if (!$this->ready()) throw new DomainException('Список получателей проверки исполнителей ожидает обновления структуры базы данных.');
        $stmt=$this->pdo->prepare('SELECT send_responsible_logist FROM company_route_executor_review_email_settings WHERE company_id=?');
        $stmt->execute([$this->companyId]);
        $settings=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
        $stmt=$this->pdo->prepare('SELECT account_id,display_name,email FROM company_route_executor_review_email_recipients WHERE company_id=? ORDER BY position_no,id');
        $stmt->execute([$this->companyId]);
        return ['recipients'=>$stmt->fetchAll(PDO::FETCH_ASSOC),'send_responsible_logist'=>(bool)($settings['send_responsible_logist']??false)];
    }

    /** @return list<array{account_id:int,name:string,email:string}> */
    public function suggest(string $query): array
    {
        $query=trim($query);
        if (mb_strlen($query)<2 || mb_strlen($query)>100) return [];
        $stmt=$this->pdo->prepare("SELECT a.id account_id,a.full_name name,
            COALESCE((SELECT p.email FROM employee_mail_profiles p WHERE p.account_id=a.id AND p.company_id=m.company_id AND p.email<>'' ORDER BY p.id LIMIT 1),NULLIF(a.email,'')) email
            FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id
            WHERE m.company_id=? AND m.status='active' AND a.status='active' AND a.full_name LIKE ?
            ORDER BY a.full_name,a.id LIMIT 5");
        $stmt->execute([$this->companyId,'%'.$query.'%']);
        $rows=[];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!filter_var($row['email']??null,FILTER_VALIDATE_EMAIL)) continue;
            $rows[]=['account_id'=>(int)$row['account_id'],'name'=>(string)$row['name'],'email'=>(string)$row['email']];
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $recipients */
    public function save(int $actorAccountId,int $actorMembershipId,array $recipients,bool $sendLogist): void
    {
        (new EmployeeMailProfileService($this->pdo,$this->companyId))->assertOwner($actorAccountId,$this->companyId);
        if (!$this->ready()) throw new DomainException('Список получателей проверки исполнителей ожидает обновления структуры базы данных.');
        if (count($recipients)>50) throw new InvalidArgumentException('Можно указать не более 50 получателей.');
        $validated=[];$seen=[];
        $account=$this->pdo->prepare("SELECT a.full_name FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id WHERE m.company_id=? AND m.account_id=? AND m.status='active' AND a.status='active' LIMIT 1");
        foreach ($recipients as $recipient) {
            if (!is_array($recipient)) throw new InvalidArgumentException('Некорректный получатель проверки исполнителей.');
            $email=strtolower(trim((string)($recipient['email']??'')));
            if (strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Укажите корректный email получателя.');
            if (isset($seen[$email])) throw new InvalidArgumentException('В списке есть повторяющийся email.');
            $seen[$email]=true;
            $accountId=(int)($recipient['account_id']??0);
            if ($accountId>0) {
                $account->execute([$this->companyId,$accountId]);$name=$account->fetchColumn();
                if (!$name) throw new DomainException('Выбранный сотрудник недоступен в этой компании.');
            } else $name='Внешний получатель';
            $validated[]=['account_id'=>$accountId>0?$accountId:null,'name'=>(string)$name,'email'=>$email];
        }
        $this->pdo->beginTransaction();
        try {
            $lock=$this->pdo->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE');$lock->execute([$this->companyId]);
            $stmt=$this->pdo->prepare('INSERT INTO company_route_executor_review_email_settings(company_id,send_responsible_logist,updated_by_membership_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE send_responsible_logist=VALUES(send_responsible_logist),updated_by_membership_id=VALUES(updated_by_membership_id)');
            $stmt->execute([$this->companyId,$sendLogist?1:0,$actorMembershipId]);
            $this->pdo->prepare('DELETE FROM company_route_executor_review_email_recipients WHERE company_id=?')->execute([$this->companyId]);
            $insert=$this->pdo->prepare('INSERT INTO company_route_executor_review_email_recipients(company_id,account_id,display_name,email,position_no) VALUES(?,?,?,?,?)');
            foreach ($validated as $position=>$recipient) $insert->execute([$this->companyId,$recipient['account_id'],$recipient['name'],$recipient['email'],$position]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
