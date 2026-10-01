<?php
declare(strict_types=1);

namespace App\Service;

use DomainException;
use PDO;
use Throwable;

/** One ordinary contract letter per review card. Unknown SMTP outcomes remain locked. */
final class RouteExecutorContractMailService
{
    public function __construct(private readonly PDO $tenant) {}

    public static function destination(string $tripLegalEntityCode): array
    {
        return match ($tripLegalEntityCode) {
            'mg_logistic'=>['login'=>'ashibanov','mail_entity'=>'mg_log'],
            'planex'=>['login'=>'spugov','mail_entity'=>'planex'],
            default=>throw new DomainException('У рейса не определено юридическое лицо для письма о договоре.'),
        };
    }

    public function assertReady(): void
    {
        $q=$this->tenant->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='route_executor_contract_mails'");
        if ((int)$q->fetchColumn()!==1) throw new DomainException('Отправка договора ожидает обновления структуры базы данных (155).');
    }

    public function status(string $cardId): ?string
    {
        $this->assertReady();
        $q=$this->tenant->prepare('SELECT mail_status FROM route_executor_contract_mails WHERE card_id=?');
        $q->execute([$cardId]);
        return $q->fetchColumn()?:null;
    }

    public function reserve(string $cardId,int $actorId,int $tripId,string $recipient,string $messageId): bool
    {
        $this->assertReady();
        $this->tenant->beginTransaction();
        try {
            $q=$this->tenant->prepare("INSERT IGNORE INTO route_executor_contract_mails(card_id,actor_account_id,trip_business_id,recipient_email,message_id,mail_status) VALUES(?,?,?,?,?,'sending')");
            $q->execute([$cardId,$actorId,$tripId,$recipient,$messageId]);
            if ($q->rowCount()===1) { $this->tenant->commit();return true; }
            $q=$this->tenant->prepare('SELECT mail_status FROM route_executor_contract_mails WHERE card_id=? FOR UPDATE');
            $q->execute([$cardId]);$status=$q->fetchColumn();
            if (in_array($status,['accepted','sending','unknown'],true)) { $this->tenant->commit();return false; }
            if ($status==='failed') {
                $q=$this->tenant->prepare("UPDATE route_executor_contract_mails SET actor_account_id=?,trip_business_id=?,recipient_email=?,message_id=?,mail_status='sending' WHERE card_id=? AND mail_status='failed'");
                $q->execute([$actorId,$tripId,$recipient,$messageId,$cardId]);
                if ($q->rowCount()!==1) throw new DomainException('Состояние письма договора изменилось.');
            } else throw new DomainException('Состояние письма договора недоступно.');
            $this->tenant->commit();return true;
        } catch (Throwable $error) {
            if ($this->tenant->inTransaction()) $this->tenant->rollBack();
            throw $error;
        }
    }

    public function finish(string $cardId,string $messageId,string $status): void
    {
        if (!in_array($status,['accepted','failed','unknown'],true)) throw new DomainException('Некорректный статус письма договора.');
        $q=$this->tenant->prepare("UPDATE route_executor_contract_mails SET mail_status=?,sent_at=IF(?='accepted',NOW(6),sent_at) WHERE card_id=? AND message_id=? AND mail_status='sending'");
        $q->execute([$status,$status,$cardId,$messageId]);
        if ($q->rowCount()!==1) throw new DomainException('Состояние письма договора изменилось.');
    }
}
