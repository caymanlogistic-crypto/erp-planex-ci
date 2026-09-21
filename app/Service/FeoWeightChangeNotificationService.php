<?php

namespace App\Service;

use PDO;
use RuntimeException;

require_once __DIR__ . '/MaxEventCatalog.php';
require_once __DIR__ . '/MaxMessagingService.php';
require_once __DIR__ . '/MaxEventNotificationService.php';

final class FeoWeightChangeNotificationService
{
    public const EVENT_KEY = 'feo.request.weight_changed';
    private const MAX_REQUESTS_PER_MESSAGE = 60;
    private const MAX_CHANGES_LENGTH = 2800;

    public static function change(array $before, array $after): ?array
    {
        $requestId = trim((string)($after['zayavka_id'] ?? $before['zayavka_id'] ?? ''));
        if ($requestId === '') return null;
        $beforeKg = self::kilograms($before['mass_netto'] ?? null);
        $afterKg = self::kilograms($after['mass_netto'] ?? null);
        if ($beforeKg === $afterKg) return null;
        return [
            'request_id' => $requestId,
            'before_kg' => $beforeKg,
            'after_kg' => $afterKg,
            'difference_kg' => round($afterKg - $beforeKg, 3),
        ];
    }

    public function enqueue(
        PDO $pdo,
        array $changes,
        string $entityPrefix,
        string $source,
        array $company,
        array $actor
    ): array {
        if (!$pdo->inTransaction()) throw new RuntimeException('Уведомление об изменении веса должно сохраняться вместе с операцией.');
        if (!preg_match('/^[a-z0-9:._-]{1,70}$/D', $entityPrefix)) throw new RuntimeException('Некорректный ключ уведомления об изменении веса.');

        $indexed = [];
        foreach ($changes as $change) {
            if (!is_array($change) || trim((string)($change['request_id'] ?? '')) === '') continue;
            $indexed[(string)$change['request_id']] = $change;
        }
        if ($indexed === []) return [];
        ksort($indexed, SORT_NATURAL);

        $events = new MaxEventNotificationService($pdo);
        if (!$events->ready()) return [];
        $chunks = [];
        $chunk = [];
        $chunkLength = 0;
        foreach (array_values($indexed) as $change) {
            $change['message_line'] = self::changeLine($change);
            $lineLength = mb_strlen($change['message_line']);
            if ($chunk !== [] && (count($chunk) >= self::MAX_REQUESTS_PER_MESSAGE || $chunkLength + 1 + $lineLength > self::MAX_CHANGES_LENGTH)) {
                $chunks[] = $chunk;
                $chunk = [];
                $chunkLength = 0;
            }
            $chunk[] = $change;
            $chunkLength += ($chunkLength > 0 ? 1 : 0) + $lineLength;
        }
        if ($chunk !== []) $chunks[] = $chunk;
        $deliveryIds = [];
        foreach ($chunks as $index => $chunk) {
            $beforeKg = array_sum(array_column($chunk, 'before_kg'));
            $afterKg = array_sum(array_column($chunk, 'after_kg'));
            $lines = array_column($chunk, 'message_line');
            $part = count($chunks) > 1 ? 'Часть ' . ($index + 1) . ' из ' . count($chunks) : '';
            $deliveryId = $events->enqueue(
                self::EVENT_KEY,
                'weight:' . $entityPrefix . ':p' . ($index + 1),
                [
                    'source' => mb_substr($source, 0, 300),
                    'part' => $part,
                    'request_count' => (string)count($chunk),
                    'old_mass_kg' => self::formatKg((float)$beforeKg),
                    'new_mass_kg' => self::formatKg((float)$afterKg),
                    'difference_kg' => self::formatSignedKg((float)($afterKg - $beforeKg)),
                    'changes' => implode("\n", $lines),
                    'author' => (string)($actor['created_by_display_name'] ?? '—'),
                    'company' => (string)($company['name'] ?? '—'),
                    'created_at' => date('d.m.Y H:i'),
                ],
                (int)($actor['created_by_user_id'] ?? 0) ?: null
            );
            if ($deliveryId !== null) $deliveryIds[] = $deliveryId;
        }
        return $deliveryIds;
    }

    public function dispatch(PDO $pdo, array $deliveryIds): string
    {
        $deliveryIds = array_values(array_unique(array_filter(array_map('intval', $deliveryIds), static fn(int $id): bool => $id > 0)));
        if ($deliveryIds === []) return 'disabled';
        $events = new MaxEventNotificationService($pdo);
        $statuses = [];
        foreach ($deliveryIds as $deliveryId) {
            try { $statuses[] = (string)$events->dispatch($deliveryId)['status']; }
            catch (\Throwable) { $statuses[] = 'failed'; }
        }
        if (in_array('failed', $statuses, true)) return 'failed';
        if ($statuses !== [] && count(array_filter($statuses, static fn(string $status): bool => $status === 'sent')) === count($statuses)) return 'sent';
        return $statuses[0] ?? 'disabled';
    }

    private static function kilograms(mixed $tonnes): float
    {
        if ($tonnes === null || trim((string)$tonnes) === '') return 0.0;
        return round((float)str_replace(',', '.', (string)$tonnes) * 1000, 3);
    }

    private static function formatKg(float $value): string
    {
        $formatted = number_format($value, 3, ',', ' ');
        return rtrim(rtrim($formatted, '0'), ',');
    }

    private static function formatSignedKg(float $value): string
    {
        return ($value > 0 ? '+' : '') . self::formatKg($value);
    }

    private static function changeLine(array $change): string
    {
        return '№' . $change['request_id'] . ': '
            . self::formatKg((float)$change['before_kg']) . ' → '
            . self::formatKg((float)$change['after_kg']) . ' кг ('
            . self::formatSignedKg((float)$change['difference_kg']) . ' кг)';
    }
}
