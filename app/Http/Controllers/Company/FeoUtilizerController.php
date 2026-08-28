<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\FeoRequestService;
use App\Service\FeoUtilizerService;

final class FeoUtilizerController
{
    private FeoRequestService $requests;
    private FeoUtilizerService $utilizers;

    public function __construct(private readonly array $config, private readonly Database $db)
    {
        $this->requests = new FeoRequestService($config, $db);
        $this->utilizers = new FeoUtilizerService();
    }

    public function index(): void
    {
        requireRole(['company_owner']);
        $pageTitle = 'Утилизаторы';
        $pageContext = 'Заявки ФЭО › Карта › Утилизаторы';
        $company = null;
        $dbError = null;
        $utilizers = [];
        $rules = [];
        $overrides = [];
        $successFlash = $_SESSION['flash_success'] ?? null;
        $errorFlash = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        try {
            [$company,$pdo] = $this->companyContext();
            $utilizers = $this->utilizers->listUtilizers($pdo, true);
            $rules = $this->utilizers->listRules($pdo);
            $overrides = $this->utilizers->listOverrides($pdo);
        } catch (\Throwable $e) {
            $dbError = $this->publicDatabaseError($e);
        }

        if (is_array($company)) {
            $topbarCrumbs = [
                ['label'=>mb_strtoupper((string)($company['name'] ?? 'Компания')),'url'=>app_url('/company/dashboard')],
                ['label'=>'Заявки ФЭО','url'=>app_url('/company/feo/requests')],
                ['label'=>'Карта','url'=>app_url('/company/feo/requests/map')],
                ['label'=>'Утилизаторы','url'=>null],
            ];
        }
        $config = $this->config;
        $db = $this->db;
        ob_start();
        require base_path('app/View/pages/company_feo_utilizers.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function create(): void
    {
        $this->mutate(function (\PDO $pdo): string {
            self::validateCoordinatePair($_POST);
            $id = $this->utilizers->createUtilizer($pdo, $_POST, $this->userId());
            return "Утилизатор #{$id} создан.";
        });
    }

    public function update(string $id): void
    {
        $this->mutate(function (\PDO $pdo) use ($id): string {
            self::validateCoordinatePair($_POST);
            $this->utilizers->updateUtilizer($pdo, (int)$id, $_POST, $this->userId());
            return 'Утилизатор обновлён.';
        });
    }

    public function delete(string $id): void
    {
        $this->mutate(function (\PDO $pdo) use ($id): string {
            $this->utilizers->deleteUtilizer($pdo, (int)$id);
            return 'Утилизатор удалён.';
        });
    }

    public function createRule(): void
    {
        $this->mutate(function (\PDO $pdo): string {
            $id = $this->utilizers->createRule($pdo, $_POST, $this->userId());
            return "Правило #{$id} создано.";
        });
    }

    public function deleteRule(string $id): void
    {
        $this->mutate(function (\PDO $pdo) use ($id): string {
            $this->utilizers->deleteRule($pdo, (int)$id);
            return 'Правило удалено.';
        });
    }

    public function setOverride(): void
    {
        $this->mutate(function (\PDO $pdo): string {
            $requestId = trim((string)($_POST['zayavka_id'] ?? ''));
            $this->utilizers->setOverride($pdo, $requestId, (int)($_POST['utilizer_id'] ?? 0), $this->userId());
            return "Для заявки {$requestId} сохранено ручное назначение.";
        });
    }

    public function deleteOverride(string $id): void
    {
        $this->mutate(function (\PDO $pdo) use ($id): string {
            $this->utilizers->deleteOverride($pdo, $id);
            return "Ручное назначение заявки {$id} удалено.";
        });
    }

    private function mutate(callable $callback): void
    {
        requireRole(['company_owner']);
        try {
            [, $pdo] = $this->companyContext();
            $_SESSION['flash_success'] = (string)$callback($pdo);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $this->publicDatabaseError($e);
        }
        redirect_to('/company/feo/utilizers');
    }

    private function companyContext(): array
    {
        $companyId = $this->requests->getCompanyId();
        if ($companyId <= 0) throw new \RuntimeException('Компания не выбрана.');
        $company = $this->requests->loadCompany($companyId);
        if (!$company || ($company['status'] ?? '') !== 'active') throw new \RuntimeException('Компания недоступна.');
        return [$company,$this->requests->localPdo($company)];
    }

    private function userId(): int
    {
        return max(0, (int)($_SESSION['user_id'] ?? 0));
    }

    private static function validateCoordinatePair(array $input): void
    {
        $latRaw = trim((string)($input['latitude'] ?? ''));
        $lngRaw = trim((string)($input['longitude'] ?? ''));
        if ($latRaw === '' && $lngRaw === '') return;

        $latNumeric = str_replace(',', '.', $latRaw);
        $lngNumeric = str_replace(',', '.', $lngRaw);
        if ($latRaw === '' || $lngRaw === '' || !is_numeric($latNumeric) || !is_numeric($lngNumeric)) {
            throw new \RuntimeException('Координаты утилизатора должны быть заполнены парой чисел.');
        }

        $lat = (float)$latNumeric;
        $lng = (float)$lngNumeric;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new \RuntimeException('Координаты утилизатора выходят за допустимый диапазон.');
        }
        if (abs($lat) < 0.0000001 && abs($lng) < 0.0000001) {
            throw new \RuntimeException('Координаты 0,0 недопустимы. Укажите фактическое местоположение или оставьте оба поля пустыми.');
        }
    }

    private function publicDatabaseError(\Throwable $e): string
    {
        if ($e instanceof \PDOException && in_array((string)$e->getCode(), ['42S02','42S22'], true)) {
            return 'Справочник утилизаторов ещё не инициализирован в базе компании. Требуется штатная локальная migration 125.';
        }
        return $e->getMessage();
    }
}
