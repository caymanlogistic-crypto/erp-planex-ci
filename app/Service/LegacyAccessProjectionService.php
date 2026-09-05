<?php

namespace App\Service;

use InvalidArgumentException;
use PDO;

/**
 * Read-only runtime compatibility for factual legacy grants projected during
 * the one-time Roles & Access migration. No application endpoint writes here.
 */
final class LegacyAccessProjectionService
{
    public const ENTITY_LINEAR_TRIP = 'linear_trip';
    public const ENTITY_VEHICLE_SET = 'vehicle_set';
    public const LEVEL_VIEW = 'view';
    public const LEVEL_EDIT = 'edit';

    public static function accessLevel(PDO $pdo, string $entityType, int $entityId, int $membershipId): ?string
    {
        self::assertIdentity($entityType, $entityId, $membershipId);
        $stmt = $pdo->prepare(
            'SELECT access_level FROM legacy_access_projections
              WHERE entity_type=? AND entity_id=? AND membership_id=?
              LIMIT 1'
        );
        $stmt->execute([$entityType, $entityId, $membershipId]);
        $level = $stmt->fetchColumn();
        return in_array($level, [self::LEVEL_VIEW, self::LEVEL_EDIT], true) ? (string) $level : null;
    }

    /** @param list<string> $levels @return array{sql:string,params:list<int|string>} */
    public static function condition(string $entityType, string $idExpression, int $membershipId, array $levels = [self::LEVEL_VIEW, self::LEVEL_EDIT]): array
    {
        if (!in_array($entityType, [self::ENTITY_LINEAR_TRIP, self::ENTITY_VEHICLE_SET], true)
            || $membershipId <= 0
            || preg_match('/^[a-z_][a-z0-9_]*\.id$/D', $idExpression) !== 1) {
            throw new InvalidArgumentException('Invalid legacy access projection condition.');
        }
        $levels = array_values(array_unique(array_filter(array_map('strval', $levels), static fn(string $v): bool => in_array($v, [self::LEVEL_VIEW, self::LEVEL_EDIT], true))));
        if ($levels === []) {
            return ['sql' => '1 = 0', 'params' => []];
        }
        $marks = implode(',', array_fill(0, count($levels), '?'));
        return [
            'sql' => "EXISTS (SELECT 1 FROM legacy_access_projections lap
                       WHERE lap.entity_type=? AND lap.entity_id={$idExpression}
                         AND lap.membership_id=? AND lap.access_level IN ({$marks}))",
            'params' => array_merge([$entityType, $membershipId], $levels),
        ];
    }

    private static function assertIdentity(string $entityType, int $entityId, int $membershipId): void
    {
        if (!in_array($entityType, [self::ENTITY_LINEAR_TRIP, self::ENTITY_VEHICLE_SET], true)
            || $entityId <= 0 || $membershipId <= 0) {
            throw new InvalidArgumentException('Invalid legacy access projection identity.');
        }
    }
}
