<?php

namespace App\Service;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Domain filtering only. Effective policy is resolved exclusively by Agent 02. */
final class RegulatedDataScopeService
{
    public const REFERENCE_ENTITIES = ['client', 'contractor', 'vehicle_unit', 'driver'];
    public const TRIP_ENTITIES = ['linear_trip', 'waste_trip'];
    public const SCOPES = ['all', 'own', 'none'];

    /** @return array{sql:string,params:list<int|string>} */
    public static function condition(string $entityType, string $idExpression, string $creatorExpression = ''): array
    {
        self::assertEntity($entityType);
        $scope = AccessControlService::effectiveDataScope($entityType);
        if (!in_array($scope, self::SCOPES, true)) {
            return ['sql' => '1 = 0', 'params' => []];
        }
        if ($scope === 'all') {
            return ['sql' => '1 = 1', 'params' => []];
        }
        if ($scope === 'none') {
            return ['sql' => '1 = 0', 'params' => []];
        }

        $membershipId = AccessControlService::effectiveMembershipId();
        if ($membershipId === null || $membershipId <= 0) {
            return ['sql' => '1 = 0', 'params' => []];
        }

        if (in_array($entityType, self::REFERENCE_ENTITIES, true)) {
            return [
                'sql' => "EXISTS (SELECT 1 FROM entity_assignments ea
                          WHERE ea.entity_type = ? AND ea.entity_id = {$idExpression}
                            AND ea.membership_id = ?)",
                'params' => [$entityType, $membershipId],
            ];
        }
        if ($creatorExpression === '') {
            throw new InvalidArgumentException('Trip creator expression is required.');
        }

        return ['sql' => "{$creatorExpression} = ?", 'params' => [$membershipId]];
    }

    public static function canAccess(PDO $pdo, string $entityType, string $table, int $entityId): bool
    {
        if ($entityId <= 0 || preg_match('/^[a-z_]+$/D', $table) !== 1) {
            return false;
        }
        $creator = in_array($entityType, self::TRIP_ENTITIES, true) ? 't.created_by_membership_id' : '';
        $condition = self::condition($entityType, 't.id', $creator);
        $stmt = $pdo->prepare("SELECT 1 FROM `{$table}` t WHERE t.id = ? AND {$condition['sql']} LIMIT 1");
        $stmt->execute(array_merge([$entityId], $condition['params']));

        return (bool) $stmt->fetchColumn();
    }

    public static function assertCanCreate(string $entityType): int
    {
        self::assertEntity($entityType);
        if (AccessControlService::effectiveDataScope($entityType) === 'none') {
            throw new RuntimeException('Нет доступа к данным этого типа.');
        }
        $membershipId = AccessControlService::effectiveMembershipId();
        if ($membershipId === null || $membershipId <= 0) {
            throw new RuntimeException('Активное членство компании не определено.');
        }

        return $membershipId;
    }

    private static function assertEntity(string $entityType): void
    {
        if (!in_array($entityType, array_merge(self::REFERENCE_ENTITIES, self::TRIP_ENTITIES), true)) {
            throw new InvalidArgumentException('Unknown regulated entity type.');
        }
    }
}
