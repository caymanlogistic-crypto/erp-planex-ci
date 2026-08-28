<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\FeoMapService;
use App\Service\FeoRequestService;

final class FeoMapController
{
    private FeoRequestService $requests;
    private FeoMapService $map;

    public function __construct(private readonly array $config, private readonly Database $db)
    {
        $this->requests = new FeoRequestService($config, $db);
        $this->map = new FeoMapService();
    }

    public function index(): void
    {
        requireRole(['company_owner','senior_logist','logist']);
        $pageTitle = 'Карта';
        $pageContext = 'Заявки ФЭО › Карта';
        $company = null;
        $dbError = null;
        $payload = ['markers'=>[],'layers'=>[],'diagnostics'=>[]];

        try {
            [$company,$pdo] = $this->companyContext();
            $payload = $this->map->payload($pdo);
        } catch (\Throwable $e) {
            $dbError = $this->publicDatabaseError($e);
        }

        if (is_array($company)) {
            $topbarCrumbs = [
                ['label'=>mb_strtoupper((string)($company['name'] ?? 'Компания')),'url'=>app_url('/company/dashboard')],
                ['label'=>'Заявки ФЭО','url'=>app_url('/company/feo/requests')],
                ['label'=>'Карта','url'=>null],
            ];
        }
        $mapPayloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{"markers":[],"layers":[],"diagnostics":{}}';
        $config = $this->config;
        $db = $this->db;
        ob_start();
        require base_path('app/View/pages/company_feo_map.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function data(): void
    {
        requireRole(['company_owner','senior_logist','logist']);
        try {
            [, $pdo] = $this->companyContext();
            jsonResponse(['success'=>true] + $this->map->payload($pdo));
        } catch (\Throwable $e) {
            jsonResponse(['success'=>false,'message'=>$this->publicDatabaseError($e)], 422);
        }
    }

    private function companyContext(): array
    {
        $companyId = $this->requests->getCompanyId();
        if ($companyId <= 0) throw new \RuntimeException('Компания не выбрана.');
        $company = $this->requests->loadCompany($companyId);
        if (!$company) throw new \RuntimeException('Компания не найдена.');
        if (($company['status'] ?? '') !== 'active') throw new \RuntimeException('Компания недоступна.');
        return [$company,$this->requests->localPdo($company)];
    }

    private function publicDatabaseError(\Throwable $e): string
    {
        if ($e instanceof \PDOException && in_array((string)$e->getCode(), ['42S02','42S22'], true)) {
            return 'Модуль карты ещё не инициализирован в базе компании. Требуется штатная локальная migration 125.';
        }
        return $e->getMessage();
    }
}
