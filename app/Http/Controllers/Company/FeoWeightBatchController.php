<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Service\FeoPlacementAuthorService;
use App\Service\FeoRequestService;
use App\Service\FeoWeightBatchService;
use App\Service\FeoWeightChangeNotificationService;
use PDO;
use RuntimeException;

final class FeoWeightBatchController
{
    private FeoWeightBatchService $service;

    public function __construct(private readonly array $config, private readonly Database $db)
    {
        $this->service = new FeoWeightBatchService();
    }

    public function index(): void
    {
        $pageTitle = 'Изменение веса заявок';
        $pageContext = 'Заявки ФЭО › '.$pageTitle;
        $page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
        $batches = []; $batchIndexItems = []; $dbError = null;
        try {
            [, $pdo] = $this->context();
            if (!$this->service->ready($pdo)) throw new RuntimeException('Инструмент ожидает обновления базы компании (162).');
            $batches = $this->service->batches($pdo, $page);
            $batchIndexItems = $this->service->batchIndexItems($pdo, $batches);
        } catch (\Throwable $e) { $dbError = $e->getMessage(); }
        $config = $this->config; $db = $this->db;
        $topbarCrumbs = [['label'=>'Заявки ФЭО','url'=>null], ['label'=>$pageTitle,'url'=>null]];
        ob_start(); require base_path('app/View/pages/company_feo_weight_batches.php'); $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function preview(): void
    {
        $this->respond(function (): array {
            [$company, $pdo] = $this->context();
            $requestId = FeoWeightBatchService::parseSingleId((string)($_POST['request_ids'] ?? ''));
            $preview = $this->service->preview($pdo, [$requestId]);
            $token = bin2hex(random_bytes(24));
            $records = $_SESSION['feo_weight_previews'] ?? [];
            foreach ($records as $key=>$record) if (($record['created_at'] ?? 0) < time()-1800) unset($records[$key]);
            while (count($records) >= 12) array_shift($records);
            $records[$token] = ['company_id'=>(int)$company['id'], 'account_id'=>(int)($_SESSION['account_id'] ?? 0), 'created_at'=>time(), 'preview'=>$preview];
            $_SESSION['feo_weight_previews'] = $records;
            return ['preview'=>$preview, 'preview_token'=>$token];
        });
    }

    public function create(): void
    {
        $this->respond(function (): array {
            [$company, $pdo] = $this->context();
            $token = (string)($_POST['preview_token'] ?? '');
            $preview = $this->previewRecord($token, (int)$company['id']);
            $rawTargets = (string)($_POST['targets'] ?? '');
            if (strlen($rawTargets) > 100000) throw new RuntimeException('Слишком большой блок изменений.');
            $targets = json_decode($rawTargets, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($targets)) throw new RuntimeException('Некорректный состав блока.');
            $actor = $this->actor($pdo, (int)$company['id']);
            $notifications = new FeoWeightChangeNotificationService(); $deliveryIds = [];
            $result = $this->service->create($pdo, $preview, $targets, $actor, hash('sha256', $token),
                (string)($_POST['name'] ?? ''), (string)($_POST['comment'] ?? ''),
                function (int $batchId, array $changes) use ($notifications, $pdo, $company, $actor, &$deliveryIds): void {
                    $deliveryIds = $notifications->enqueue($pdo, $changes, 'batch:'.$batchId.':apply', 'Изменение веса, блок #'.$batchId, $company, $actor);
                });
            return $result + ['notification'=>$notifications->dispatch($pdo, $deliveryIds)];
        });
    }

    public function revert(string $id): void
    {
        $this->respond(function () use ($id): array {
            [$company, $pdo] = $this->context(); $batchId = $this->id($id);
            $actor = $this->actor($pdo, (int)$company['id']);
            $notifications = new FeoWeightChangeNotificationService(); $deliveryIds = [];
            $result = $this->service->revert($pdo, $batchId, $actor,
                function (int $changedId, array $changes) use ($notifications, $pdo, $company, $actor, &$deliveryIds): void {
                    $deliveryIds = $notifications->enqueue($pdo, $changes, 'batch:'.$changedId.':revert', 'Возврат веса, блок #'.$changedId, $company, $actor);
                });
            return $result + ['notification'=>$notifications->dispatch($pdo, $deliveryIds)];
        });
    }

    public function detail(string $id): void
    {
        $this->respond(function () use ($id): array {
            [, $pdo] = $this->context(); $batchId = $this->id($id);
            $query = $pdo->prepare('SELECT * FROM feo_weight_batches WHERE id=?'); $query->execute([$batchId]);
            $batch = $query->fetch(PDO::FETCH_ASSOC);
            if (!$batch) throw new RuntimeException('Блок не найден.');
            $items = array_map(static function (array $item): array { unset($item['legacy_snapshot_json']); return $item; }, $this->service->items($pdo, $batchId));
            return ['batch'=>$batch, 'items'=>$items];
        });
    }

    private function previewRecord(string $token, int $companyId): array
    {
        $record = $_SESSION['feo_weight_previews'][$token] ?? null;
        if (!is_array($record) || $record['company_id'] !== $companyId || $record['account_id'] !== (int)($_SESSION['account_id'] ?? 0) || $record['created_at'] < time()-1800) {
            throw new RuntimeException('Проверка устарела. Загрузите заявки заново.');
        }
        return $record['preview'];
    }

    private function context(): array
    {
        $access = AccessRuntime::current();
        if (!$access->isActive() || !$access->companyId()) throw new RuntimeException('Не удалось подтвердить доступ к компании.');
        $requests = new FeoRequestService($this->config, $this->db);
        $company = $requests->loadCompany($access->companyId());
        if (!$company || $company['status'] !== 'active') throw new RuntimeException('Компания недоступна.');
        return [$company, $requests->localPdo($company)];
    }

    private function actor(PDO $pdo, int $companyId): array
    {
        $actor = FeoPlacementAuthorService::creatorFromSession($_SESSION);
        return (new FeoPlacementAuthorService($this->db->connection()))->decorate($pdo, $companyId, [$actor])[0];
    }

    private function id(string $id): int
    {
        if (!ctype_digit($id) || (int)$id <= 0) throw new RuntimeException('Некорректный номер блока.');
        return (int)$id;
    }

    private function respond(callable $action): void
    {
        try { jsonResponse(['success'=>true] + $action()); }
        catch (\Throwable $e) { jsonResponse(['success'=>false, 'message'=>$e->getMessage()], 422); }
    }
}
