<?php

namespace App\Service;

use PDO;

/**
 * Fixed-query bridge for regulated entity surfaces.
 *
 * It deliberately owns only table/column metadata for the six regulated types.
 * Role/page decisions remain in EffectiveAccessResolver; this service receives
 * the already-resolved scope and actor identifiers.
 */
final class ScopedEntityQueryService
{
    private const DEFINITIONS = [
        'client' => [
            'table' => 'clients',
            'base_where' => 'e.deleted_at IS NULL',
            'search_columns' => ['e.name', 'e.inn'],
        ],
        'contractor' => [
            'table' => 'contractors',
            'base_where' => 'e.deleted_at IS NULL',
            'search_columns' => ['e.name', 'e.inn'],
        ],
        'vehicle_unit' => [
            'table' => 'vehicle_units',
            'base_where' => 'e.deleted_at IS NULL',
            'search_columns' => ['e.plate_number', 'e.brand', 'e.model'],
        ],
        'driver' => [
            'table' => 'drivers',
            'base_where' => 'e.deleted_at IS NULL',
            'search_columns' => ['e.full_name', 'e.phone', 'e.email'],
        ],
        'linear_trip' => [
            'table' => 'linear_routes',
            'base_where' => 'e.deleted_at IS NULL',
            'search_columns' => [],
        ],
        'waste_trip' => [
            'table' => 'waste_trips',
            'base_where' => '1=1',
            'search_columns' => [],
        ],
    ];

    /**
     * @return array{where:string,params:array}
     */
    public static function scopeClause(
        string $entityType,
        string $scope,
        int $membershipId,
        int $tenantUserId,
        string $alias = 'e'
    ): array {
        if (!isset(self::DEFINITIONS[$entityType]) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            return ['where' => '1=0', 'params' => []];
        }

        return DataScopeService::filter(
            $scope,
            $entityType,
            $membershipId,
            $tenantUserId,
            $alias . '.id',
            $alias . '.created_by_user_id'
        );
    }

    public static function findById(
        PDO $pdo,
        string $entityType,
        int $entityId,
        string $scope,
        int $membershipId,
        int $tenantUserId
    ): ?array {
        $definition = self::DEFINITIONS[$entityType] ?? null;
        if ($definition === null || $entityId <= 0) {
            return null;
        }

        $filter = self::scopeClause($entityType, $scope, $membershipId, $tenantUserId, 'e');
        $sql = sprintf(
            'SELECT e.* FROM `%s` e WHERE e.id = ? AND (%s) AND (%s) LIMIT 1',
            $definition['table'],
            $definition['base_where'],
            $filter['where']
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$entityId], $filter['params']));
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return int[] */
    public static function visibleIds(
        PDO $pdo,
        string $entityType,
        string $scope,
        int $membershipId,
        int $tenantUserId
    ): array {
        $definition = self::DEFINITIONS[$entityType] ?? null;
        if ($definition === null) {
            return [];
        }

        $filter = self::scopeClause($entityType, $scope, $membershipId, $tenantUserId, 'e');
        $sql = sprintf(
            'SELECT e.id FROM `%s` e WHERE (%s) AND (%s) ORDER BY e.id',
            $definition['table'],
            $definition['base_where'],
            $filter['where']
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute($filter['params']);
        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    public static function countVisible(
        PDO $pdo,
        string $entityType,
        string $scope,
        int $membershipId,
        int $tenantUserId
    ): int {
        $definition = self::DEFINITIONS[$entityType] ?? null;
        if ($definition === null) {
            return 0;
        }

        $filter = self::scopeClause($entityType, $scope, $membershipId, $tenantUserId, 'e');
        $sql = sprintf(
            'SELECT COUNT(*) FROM `%s` e WHERE (%s) AND (%s)',
            $definition['table'],
            $definition['base_where'],
            $filter['where']
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute($filter['params']);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Directory search/autocomplete using only fixed server-owned columns.
     * NONE returns no rows and ONLY_OWN never searches outside assignments.
     */
    public static function search(
        PDO $pdo,
        string $entityType,
        string $query,
        string $scope,
        int $membershipId,
        int $tenantUserId,
        int $limit = 20
    ): array {
        $definition = self::DEFINITIONS[$entityType] ?? null;
        if ($definition === null || $definition['search_columns'] === []) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $query = trim($query);
        $filter = self::scopeClause($entityType, $scope, $membershipId, $tenantUserId, 'e');

        $searchSql = '1=1';
        $searchParams = [];
        if ($query !== '') {
            $likes = [];
            foreach ($definition['search_columns'] as $column) {
                $likes[] = $column . ' LIKE ?';
                $searchParams[] = '%' . $query . '%';
            }
            $searchSql = '(' . implode(' OR ', $likes) . ')';
        }

        $sql = sprintf(
            'SELECT e.* FROM `%s` e WHERE (%s) AND (%s) AND (%s) ORDER BY e.id DESC LIMIT %d',
            $definition['table'],
            $definition['base_where'],
            $filter['where'],
            $searchSql,
            $limit
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($filter['params'], $searchParams));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
