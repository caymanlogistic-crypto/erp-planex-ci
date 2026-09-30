<?php
declare(strict_types=1);

namespace App\Service;

use DomainException;
use RuntimeException;
use ZipArchive;

/** Unpredictable, 30-day read-only archive links for closing-document review mail. */
final class WasteTripClosingPackageStore
{
    private const LIFETIME = 30 * 86400;

    public function __construct(private readonly int $companyId) {}

    public function publish(int $tripId, int|string $businessId, array $documents): string
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Хранилище архивов недоступно.');
        $this->cleanExpired($directory);
        $token = bin2hex(random_bytes(32));
        $path = $this->path($token,'zip');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Не удалось создать архив.');
        try {
            $used = []; $total = 0;
            foreach ($documents as $document) {
                $relative = (string)($document['relative_path']??'');
                $prefix = 'companies/'.$this->companyId.'/documents/waste_trip/'.$tripId.'/';
                if (!str_starts_with($relative,$prefix)) throw new DomainException('Документ находится вне рейса.');
                $source = storage_path($relative);
                $root=realpath(storage_path('companies/'.$this->companyId.'/documents/waste_trip/'.$tripId));
                $resolved=realpath($source);
                if ($root===false || $resolved===false || !str_starts_with($resolved,$root.DIRECTORY_SEPARATOR) || !is_file($resolved)) {
                    throw new DomainException('Файл документа недоступен.');
                }
                $total += filesize($source) ?: 0;
                if ($total > 200 * 1024 * 1024) throw new DomainException('Комплект превышает 200 МБ.');
                $safeName = preg_replace('/[^\p{L}\p{N}._ -]+/u','_',basename((string)$document['original_name'])) ?: 'document';
                $name = (string)$document['slot_code'].'_'.$safeName;
                $sequence = 2;
                while (isset($used[$name])) $name = (string)$document['slot_code'].'_'.$sequence++.'_'.$safeName;
                $used[$name] = true;
                if (!$zip->addFile($resolved,$name)) throw new RuntimeException('Не удалось добавить файл в архив.');
            }
        } catch (\Throwable $error) {
            $zip->close(); @unlink($path); throw $error;
        }
        if (!$zip->close()) { @unlink($path); throw new RuntimeException('Не удалось завершить архив.'); }
        $meta = ['company_id'=>$this->companyId,'trip_id'=>$tripId,
            'name'=>'Рейс_'.$businessId.'_закрывающие_документы.zip',
            'size'=>filesize($path),'expires_at'=>time()+self::LIFETIME];
        if (file_put_contents($this->path($token,'json'),json_encode($meta,JSON_THROW_ON_ERROR),LOCK_EX) === false) {
            @unlink($path);throw new RuntimeException('Не удалось сохранить ссылку на архив.');
        }
        return $token;
    }

    /** @return array{path:string,name:string,size:int} */
    public function download(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new DomainException('Ссылка недействительна.');
        $metaPath=$this->path($token,'json');$path=$this->path($token,'zip');
        $meta=is_file($metaPath)?json_decode((string)file_get_contents($metaPath),true):null;
        if (!is_array($meta) || (int)($meta['company_id']??0)!==$this->companyId
            || (int)($meta['expires_at']??0)<time() || !is_file($path)
            || filesize($path)!==(int)($meta['size']??-1)) throw new DomainException('Ссылка недействительна или срок её действия истёк.');
        return ['path'=>$path,'name'=>(string)$meta['name'],'size'=>(int)$meta['size']];
    }

    public function discard(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) return;
        @unlink($this->path($token,'json'));@unlink($this->path($token,'zip'));
    }

    private function directory(): string { return storage_path('companies/'.$this->companyId.'/trip-closing-packages'); }
    private function path(string $token,string $ext): string { return $this->directory().DIRECTORY_SEPARATOR.$token.'.'.$ext; }
    private function cleanExpired(string $directory): void
    {
        $count=0;
        foreach (glob($directory.'/*.json')?:[] as $metaPath) {
            if ($count++>=20) break;
            $meta=json_decode((string)@file_get_contents($metaPath),true);
            if (is_array($meta) && (int)($meta['expires_at']??0)>=time()) continue;
            $token=basename($metaPath,'.json');$this->discard($token);
        }
    }
}
