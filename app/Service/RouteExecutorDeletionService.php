<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

final class RouteExecutorDeletionService
{
    /** @return array{linear_count:int,waste_count:int,linear_ids:list<int>,waste_ids:list<int>} */
    public static function tripUsage(PDO $pdo, int $crewId): array
    {
        $linearCount = $pdo->prepare('SELECT COUNT(*) FROM linear_routes WHERE route_executor_id=?');
        $linearCount->execute([$crewId]);
        $linearIds = $pdo->prepare('SELECT id FROM linear_routes WHERE route_executor_id=? ORDER BY id LIMIT 5');
        $linearIds->execute([$crewId]);
        $wasteCount = $pdo->prepare('SELECT COUNT(*) FROM waste_trips WHERE route_executor_id=?');
        $wasteCount->execute([$crewId]);
        $wasteIds = $pdo->prepare('SELECT id FROM waste_trips WHERE route_executor_id=? ORDER BY id LIMIT 5');
        $wasteIds->execute([$crewId]);
        return [
            'linear_count' => (int) $linearCount->fetchColumn(),
            'waste_count' => (int) $wasteCount->fetchColumn(),
            'linear_ids' => array_map('intval', $linearIds->fetchAll(PDO::FETCH_COLUMN)),
            'waste_ids' => array_map('intval', $wasteIds->fetchAll(PDO::FETCH_COLUMN)),
        ];
    }

    public static function archive(PDO $pdo, int $crewId, int $userId, string $role): array
    {
        if ($crewId <= 0 || $userId <= 0) throw new RuntimeException('Некорректный исполнитель рейса.');
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT * FROM crews WHERE id=? AND deleted_at IS NULL FOR UPDATE');
            $lock->execute([$crewId]);
            $crew = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$crew) throw new RuntimeException('Исполнитель рейса не найден или уже удалён.');
            $usage = self::tripUsage($pdo, $crewId);
            if ($usage['linear_count'] > 0 || $usage['waste_count'] > 0) {
                throw new RuntimeException('Удаление недоступно: исполнитель уже участвует в рейсе.');
            }
            $update = $pdo->prepare("UPDATE crews SET status='archived',deleted_at=NOW(),deleted_by_user_id=?,deleted_by_role=? WHERE id=? AND deleted_at IS NULL");
            $update->execute([$userId, $role, $crewId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('Исполнитель рейса уже изменён. Обновите страницу.');
            $pdo->commit();
            return $crew;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}
