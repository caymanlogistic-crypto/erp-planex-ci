<?php
declare(strict_types=1);
namespace App\Service;

use PDO;

final class EmployeeMailSettingsService
{
    public const DEFAULTS = ['imap_host'=>'imap.spaceweb.ru','imap_port'=>993,'smtp_host'=>'smtp.spaceweb.ru','smtp_port'=>465,'encryption'=>'SSL'];

    public function __construct(private readonly PDO $pdo, private readonly int $companyId) {}

    public static function validate(array $input): array
    {
        $result=[];
        foreach (['imap','smtp'] as $protocol) {
            $host=strtolower(trim((string)($input[$protocol.'_host']??'')));
            if (strlen($host)>253 || !filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) || !str_contains($host,'.') || filter_var($host,FILTER_VALIDATE_IP)) {
                throw new \InvalidArgumentException('Укажите корректное имя сервера '.strtoupper($protocol).'.');
            }
            $port=filter_var($input[$protocol.'_port']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
            if (!$port) throw new \InvalidArgumentException('Укажите порт '.strtoupper($protocol).' от 1 до 65535.');
            $result[$protocol.'_host']=$host;
            $result[$protocol.'_port']=(int)$port;
        }
        if (($input['encryption']??'')!=='SSL') throw new \InvalidArgumentException('Для рабочей почты требуется SSL.');
        $result['encryption']='SSL';
        return $result;
    }

    public function read(): ?array
    {
        $q=$this->pdo->prepare('SELECT imap_host,imap_port,smtp_host,smtp_port,encryption FROM employee_mail_settings WHERE company_id=?');
        $q->execute([$this->companyId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return $row ? self::validate($row) : null;
    }

    public function save(int $actorAccountId,array $input): array
    {
        (new EmployeeMailProfileService($this->pdo,$this->companyId))->assertOwner($actorAccountId,$this->companyId);
        $settings=self::validate($input);
        $this->pdo->beginTransaction();
        try {
            // Serialize settings saves and invalidate checks performed with older configuration.
            $lock=$this->pdo->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE');
            $lock->execute([$this->companyId]);
            if ($this->read()!==$settings) {
                $q=$this->pdo->prepare('INSERT INTO employee_mail_settings(company_id,imap_host,imap_port,smtp_host,smtp_port,encryption) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE imap_host=VALUES(imap_host),imap_port=VALUES(imap_port),smtp_host=VALUES(smtp_host),smtp_port=VALUES(smtp_port),encryption=VALUES(encryption)');
                $q->execute([$this->companyId,...array_values($settings)]);
                $q=$this->pdo->prepare("UPDATE employee_mail_profiles SET connection_status=IF(password_encrypted='','incomplete','unverified'),checked_at=NULL,version=version+1 WHERE company_id=?");
                $q->execute([$this->companyId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return $settings;
    }
}
