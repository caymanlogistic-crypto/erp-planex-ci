<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Service\FeoRequestService;
use PDO;
use RuntimeException;

final class ServiceKeysController
{
    public function __construct(private readonly array $config, private readonly Database $db) {}

    public function index(): void
    {
        $keyConfigured=false; $dbError=null;
        try {
            $pdo=$this->context();
            $keyConfigured=(bool)$pdo->query('SELECT api_key_encrypted FROM geocoding_settings WHERE id=1')->fetchColumn()
                || trim((string)getenv('YANDEX_GEOCODER_API_KEY'))!=='';
        } catch (\Throwable) { $dbError='Не удалось загрузить настройки сервисов. Попробуйте обновить страницу.'; }
        $pageTitle='Ключи и сервисы'; $pageContext='Настройки › '.$pageTitle;
        $topbarCrumbs=[['label'=>'Настройки','url'=>null],['label'=>$pageTitle,'url'=>null]];
        $config=$this->config; $db=$this->db;
        header('Cache-Control: no-store');
        ob_start(); require base_path('app/View/pages/company_service_keys.php'); $content=ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function saveYandex(): void
    {
        try {
            $pdo=$this->context();
            $key=trim((string)($_POST['api_key']??''));
            if (!preg_match('/^[a-zA-Z0-9_-]{20,200}$/D',$key)) throw new RuntimeException('Укажите корректный API-ключ Яндекс Геокодера.');
            $q=$pdo->prepare('INSERT INTO geocoding_settings(id,api_key_encrypted) VALUES(1,?) ON DUPLICATE KEY UPDATE api_key_encrypted=VALUES(api_key_encrypted)');
            $q->execute([cryptoEncrypt($key)]);
            jsonResponse(['success'=>true]);
        } catch (RuntimeException $e) { jsonResponse(['success'=>false,'message'=>$e->getMessage()],422); }
        catch (\Throwable) { jsonResponse(['success'=>false,'message'=>'Не удалось сохранить ключ. Попробуйте ещё раз.'],422); }
    }

    private function context(): PDO
    {
        $access=AccessRuntime::current();
        if (!$access->isActive() || !$access->isSystemOwner() || !$access->companyId()) throw new RuntimeException('Настройка доступна только руководителю компании.');
        $requests=new FeoRequestService($this->config,$this->db);
        $company=$requests->loadCompany($access->companyId());
        if (!$company || $company['status']!=='active') throw new RuntimeException('Компания недоступна.');
        return $requests->localPdo($company);
    }
}
