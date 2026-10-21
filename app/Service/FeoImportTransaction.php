<?php
declare(strict_types=1);

namespace App\Service;

use PDO;
use PDOException;
use RuntimeException;

require_once __DIR__.'/FeoLegacyImportSync.php';

/** Two credential sets require XA plus a durable decision, never two ordinary commits. */
final class FeoImportTransaction
{
    private ?FeoLegacyImportSync $sync;
    private array $participants = [];
    private array $states = [];
    private array $record = [];
    private string $path = '';
    private $fileLock = null;
    private bool $decisionAttempted = false;
    private bool $finished = false;

    private function __construct(private readonly PDO $tenant)
    {
        $this->sync = FeoLegacyImportSync::forTenant($tenant);
    }

    public static function begin(PDO $tenant): self
    {
        $tx = new self($tenant);
        try {
            if (!$tx->sync || $tx->sync->connection() === $tenant) {
                if (self::pending($tenant)) throw new RuntimeException('ФЭО: сначала восстановите отдельное подключение для завершения операции из журнала.');
                $tenant->beginTransaction();
                return $tx;
            }
            $tx->participants = ['legacy'=>$tx->sync->connection(), 'tenant'=>$tenant];
            $tx->initializeJournal();
            $tx->recover();
            $tx->record = ['version'=>1, 'decision'=>'abort', 'xid'=>'feo_'.bin2hex(random_bytes(24)), 'databases'=>$tx->identities()];
            $tx->persist(); // Abort intent precedes XA START, including crashes between the two STARTs.
            foreach ($tx->participants as $name=>$pdo) {
                $pdo->exec('XA START '.$tx->xid($pdo,$name));
                $tx->states[$name] = 'active';
            }
            return $tx;
        } catch (\Throwable $e) {
            // A recovery failure must retain its previous decision and block the next operation.
            if ($tx->states !== []) $tx->rollback();
            $tx->releaseFileLock();
            throw $e;
        }
    }

    public function sync(): ?FeoLegacyImportSync { return $this->sync; }

    /** Caller holds the FEO database lock; the callback writes tenant and legacy rows in one XA decision. */
    public static function runWithLegacy(PDO $tenant, callable $action): mixed
    {
        self::recoverPending($tenant);
        $transaction = self::begin($tenant);
        try {
            $sync = $transaction->sync();
            if ($sync === null) throw new RuntimeException('Подключение к старой базе ФЭО не настроено.');
            $sync->validate();
            $result = $action($sync);
            $transaction->commit();
            return $result;
        } catch (\Throwable $e) {
            $transaction->rollback();
            throw $e;
        }
    }

    public static function pending(PDO $tenant): bool
    {
        $root=rtrim((string)env('FEO_LEGACY_SYNC_JOURNAL_DIR', dirname(BASE_PATH, 2).'/feo-import-sync'), '/');
        $key=hash('sha256',(string)$tenant->query('SELECT DATABASE()')->fetchColumn());
        return is_file($root.'/'.$key.'.json');
    }

    /** Caller holds the same FEO database lock as import/history mutations. */
    public static function recoverPending(PDO $tenant): void
    {
        if (!self::pending($tenant)) return;
        $tx=new self($tenant);
        if (!$tx->sync || $tx->sync->connection()===$tenant) throw new RuntimeException('ФЭО: для восстановления операции нужны прежние отдельные подключения.');
        $tx->participants=['legacy'=>$tx->sync->connection(),'tenant'=>$tenant];
        $tx->initializeJournal();
        $tx->recover();
    }

    public function commit(): void
    {
        if ($this->participants === []) { $this->tenant->commit(); $this->finished=true; return; }
        foreach ($this->participants as $name=>$pdo) {
            $pdo->exec('XA END '.$this->xid($pdo,$name));
            $this->states[$name]='idle';
            $pdo->exec('XA PREPARE '.$this->xid($pdo,$name));
            $this->states[$name]='prepared';
        }
        // Once a commit decision may be durable, rollback is forbidden even after a timeout.
        $this->decisionAttempted=true;
        $this->record['decision']='commit';
        try {
            $this->persist();
            $this->finishDecision();
        } catch (\Throwable $e) {
            throw new RuntimeException('ФЭО: подтверждение сохранения прервано. Журнал восстановления сохранён; повторите операцию после восстановления связи. Операция не должна считаться завершённой.');
        }
    }

    public function rollback(): void
    {
        if ($this->finished) return;
        if ($this->participants === []) {
            if ($this->tenant->inTransaction()) $this->tenant->rollBack();
            $this->finished=true;
            return;
        }
        if ($this->decisionAttempted) return; // Next attempt resolves the durable decision first.
        foreach ($this->participants as $name=>$pdo) {
            if (($this->states[$name] ?? '') === 'active') {
                $pdo->exec('XA END '.$this->xid($pdo,$name));
                $this->states[$name]='idle';
            }
        }
        $this->finishDecision();
    }

    public function __destruct() { $this->releaseFileLock(); }

    private function initializeJournal(): void
    {
        if (PHP_OS_FAMILY === 'Windows') throw new RuntimeException('XA-журнал ФЭО требует локальную POSIX-файловую систему.');
        foreach ($this->participants as $pdo) {
            $version=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
            $minimum = stripos($version,'MariaDB') !== false ? '10.5.0' : '5.7.7';
            if (!preg_match('/^(\d+\.\d+\.\d+)/', $version,$m) || version_compare($m[1],$minimum,'<')) throw new RuntimeException('Версия MySQL/MariaDB не обеспечивает сохранение подготовленных XA-транзакций.');
        }
        $root=rtrim((string)env('FEO_LEGACY_SYNC_JOURNAL_DIR', dirname(BASE_PATH, 2).'/feo-import-sync'), '/');
        if ($root==='' || $root[0]!=='/' || is_link($root)) throw new RuntimeException('Некорректный каталог XA-журнала ФЭО.');
        if (!is_dir(dirname($root))) throw new RuntimeException('Родительский каталог XA-журнала отсутствует.');
        if (!is_dir($root) && !mkdir($root,0700) && !is_dir($root)) throw new RuntimeException('Не удалось создать каталог XA-журнала ФЭО.');
        if ((fileperms($root)&0077)!==0) throw new RuntimeException('Каталог XA-журнала должен быть закрыт правами 0700.');
        // Persist the directory entry itself as well as the later decision-file entry.
        $parent=fopen(dirname($root),'r');
        if (!$parent) throw new RuntimeException('Родительский каталог XA-журнала недоступен.');
        try { if (!fsync($parent)) throw new RuntimeException('Не удалось сохранить каталог XA-журнала.'); }
        finally { fclose($parent); }
        $key=hash('sha256',(string)$this->tenant->query('SELECT DATABASE()')->fetchColumn());
        $this->path=$root.'/'.$key.'.json';
        if (is_link($this->path) || is_link($this->path.'.lock')) throw new RuntimeException('Ссылка вместо XA-журнала запрещена.');
        $this->fileLock=fopen($this->path.'.lock','c+b');
        if (!$this->fileLock || !flock($this->fileLock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Журнал ФЭО занят незавершённой операцией.');
        chmod($this->path.'.lock',0600);
        $this->syncDirectory();
    }

    private function identities(): array
    {
        $out=[];
        foreach($this->participants as $name=>$pdo) {
            $out[$name]=$pdo->query('SELECT DATABASE() AS db, CURRENT_USER() AS account, @@hostname AS server_host, @@port AS server_port')->fetch(PDO::FETCH_ASSOC);
        }
        return $out;
    }

    private function recover(): void
    {
        if (!is_file($this->path)) return;
        $r=json_decode((string)file_get_contents($this->path),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($r) || ($r['version']??null)!==1 || !in_array($r['decision']??'', ['abort','commit'],true)
            || !preg_match('/^feo_[a-f0-9]{48}$/D',(string)($r['xid']??'')) || ($r['databases']??[])!==$this->identities()) {
            throw new RuntimeException('XA-журнал ФЭО повреждён или подключение изменилось. Требуется восстановление прежнего подключения.');
        }
        $this->record=$r;
        $this->decisionAttempted=$r['decision']==='commit';
        $this->finishDecision();
        $this->finished=false;
        $this->decisionAttempted=false;
    }

    private function finishDecision(): void
    {
        foreach($this->participants as $name=>$pdo) {
            try {
                $pdo->exec('XA '.($this->record['decision']==='commit'?'COMMIT':'ROLLBACK').' '.$this->xid($pdo,$name));
            } catch(PDOException $e) {
                // Known XID already resolved, or START never reached this participant.
                if ((int)($e->errorInfo[1]??0)!==1397) throw $e;
            }
        }
        if (!unlink($this->path)) throw new RuntimeException('Не удалось завершить XA-журнал ФЭО.');
        $this->syncDirectory();
        $this->states=[];
        $this->finished=true;
    }

    private function xid(PDO $pdo,string $name): string
    {
        return $pdo->quote($this->record['xid']).','.$pdo->quote($name).',1178947377';
    }

    private function persist(): void
    {
        $temporary=$this->path.'.tmp-'.bin2hex(random_bytes(8));
        $handle=fopen($temporary,'xb');
        if (!$handle) throw new RuntimeException('Не удалось создать XA-журнал.');
        try {
            chmod($temporary,0600);
            $body=json_encode($this->record,JSON_THROW_ON_ERROR);
            if (fwrite($handle,$body)!==strlen($body) || !fflush($handle) || !fsync($handle)) throw new RuntimeException('Не удалось надёжно сохранить XA-журнал.');
            if (!rename($temporary,$this->path)) throw new RuntimeException('Не удалось установить XA-журнал.');
            $this->syncDirectory();
        } finally { fclose($handle); if (is_file($temporary)) unlink($temporary); }
    }

    private function syncDirectory(): void
    {
        $dir=fopen(dirname($this->path),'r');
        if (!$dir) throw new RuntimeException('Каталог XA-журнала недоступен.');
        try { if(!fsync($dir)) throw new RuntimeException('Файловая система не поддерживает надёжный XA-журнал.'); }
        finally { fclose($dir); }
    }

    private function releaseFileLock(): void
    {
        if (is_resource($this->fileLock)) { flock($this->fileLock,LOCK_UN); fclose($this->fileLock); }
        $this->fileLock=null;
    }
}
