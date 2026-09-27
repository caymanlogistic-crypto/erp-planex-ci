<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Service\FeoRequestService;
use PDO;
use App\Service\EmployeeMailSettingsService;
use App\Service\EmployeeMailProfileService;
use App\Service\ReportEmailRecipientService;
require_once __DIR__.'/../../../Service/EmployeeMailProfileService.php';
require_once __DIR__.'/../../../Service/ReportEmailRecipientService.php';
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
        $mailSettings=EmployeeMailSettingsService::DEFAULTS; $mailConfigured=false; $mailError=null;
        try {
            $mail=$this->mailContext();
            $stored=$mail->read();
            $mailSettings=$stored??$mailSettings; $mailConfigured=$stored!==null;
        } catch (\Throwable) { $mailError='Настройки почты недоступны. Проверьте обновление структуры базы данных.'; }
        $reportEmails=['recipients'=>[],'send_responsible_logist'=>false,'sender_profile_id'=>0,'senders'=>[]];$reportEmailError=null;
        try { $reportEmails=$this->reportEmailContext()->read(); }
        catch (\Throwable) { $reportEmailError='Получатели отчётов недоступны. Проверьте обновление структуры базы данных.'; }
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

    public function saveMail(): void
    {
        header('Cache-Control: no-store');
        try {
            $service=$this->mailContext();
            $access=AccessRuntime::current();
            $q=$this->db->connection()->prepare('SELECT account_id FROM company_memberships WHERE id=? AND company_id=?');
            $q->execute([$access->membershipId(),$access->companyId()]);
            $service->save((int)$q->fetchColumn(),$_POST);
            jsonResponse(['success'=>true,'message'=>'Настройки почты сохранены. Если они изменились, проверьте подключения ящиков заново.']);
        } catch (\InvalidArgumentException|\DomainException $e) {
            jsonResponse(['success'=>false,'message'=>$e->getMessage()],422);
        } catch (\Throwable) {
            jsonResponse(['success'=>false,'message'=>'Не удалось сохранить настройки почты. Попробуйте ещё раз.'],422);
        }
    }

    public function suggestReportRecipients(): void
    {
        header('Cache-Control: no-store');
        try {
            $query=is_scalar($_GET['q']??null)?(string)$_GET['q']:'';
            $suggestions=$this->reportEmailContext()->suggest($query);
            jsonResponse(['success'=>true,'people'=>$suggestions]);
        } catch (\Throwable) { jsonResponse(['success'=>false,'message'=>'Не удалось найти сотрудников.'],422); }
    }

    public function saveReportRecipients(): void
    {
        verifyCsrfRequest();header('Cache-Control: no-store');
        try {
            $service=$this->reportEmailContext();$access=AccessRuntime::current();
            $stmt=$this->db->connection()->prepare('SELECT account_id FROM company_memberships WHERE id=? AND company_id=?');
            $stmt->execute([$access->membershipId(),$access->companyId()]);
            $actorAccountId=(int)$stmt->fetchColumn();
            $recipients=json_decode((string)($_POST['recipients']??'[]'),true,64,JSON_THROW_ON_ERROR);
            if (!is_array($recipients) || !array_is_list($recipients)) throw new \InvalidArgumentException('Некорректный список получателей.');
            $service->save($actorAccountId,(int)$access->membershipId(),$recipients,($_POST['send_responsible_logist']??'0')==='1',max(0,(int)($_POST['sender_profile_id']??0)));
            jsonResponse(['success'=>true,'message'=>'Получатели отчётов сохранены.']);
        } catch (\InvalidArgumentException|\DomainException|\JsonException $error) {
            jsonResponse(['success'=>false,'message'=>$error->getMessage()],422);
        } catch (\Throwable) { jsonResponse(['success'=>false,'message'=>'Не удалось сохранить получателей.'],422); }
    }

    private function reportEmailContext(): ReportEmailRecipientService
    {
        $access=AccessRuntime::current();
        if (!$access->isActive() || !$access->isSystemOwner() || !$access->companyId()) throw new \DomainException('Настройка доступна только руководителю компании.');
        return new ReportEmailRecipientService($this->db->connection(),(int)$access->companyId());
    }

    private function mailContext(): EmployeeMailSettingsService
    {
        $access=AccessRuntime::current();
        if (!$access->isActive() || !$access->isSystemOwner() || !$access->companyId()) throw new \DomainException('Настройка доступна только руководителю компании.');
        return new EmployeeMailSettingsService($this->db->connection(),(int)$access->companyId());
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
