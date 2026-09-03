<?php

namespace App\Service;

use PDO;
use RuntimeException;

/**
 * Narrow data-scope enforcement consumed after EffectiveAccessResolver.
 *
 * This service does not decide roles/pages. It accepts the resolver's current
 * effective scope and enforces entity visibility inside the already-selected
 * tenant DB. Unknown state is denied.
 */
final class DataScopeService
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_ONLY_OWN = 'only_own';
    public const SCOPE_NONE = 'none';

    public const ASSIGNMENT_ENTITY_TYPES = [
        'client',
        'contractor',
        'vehicle_unit',
        'driver',
    ];

    public const CREATOR_ENTITY_TYPES = [
        'linear_trip',
        'waste_trip',
    ];

    public const REGULATED_ENTITY_TYPES = [
        'client',
        'contractor',
        'vehicle_unit',
        'driver',
        'linear_trip',
        'waste_trip',
    ];

    public static function normalizeScope(?string $scope): string
    {
        return in_array($scope, [self::SCOPE_ALL, self::SCOPE_ONLY_OWN, self::SCOPE_NONE], true)
            ? (string) $scope
            : self::SCOPE_NONE;
    }

    public static function isRegulated(string $entityType): bool
    {
        return in_array($entityType, self::REGULATED_ENTITY_TYPES, true);
    }

    /**
     * SQL fragment for list/search/autocomplete/counter/export queries.
     *
     * $idExpression and $creatorExpression are server-owned SQL identifiers or
     * expressions supplied by repository code, never client input.
     *
     * @return array{where:string,params:array}
     */
    public static function filter(
        string $scope,
        string $entityType,
        int $membershipId,
        int $tenantUserId,
        string $idExpression = 'id',
        string $creatorExpression = 'created_by_user_id'
    ): array {
        $scope = self::normalizeScope($scope);
        if (!self::isRegulated($entityType)) {
            return ['where' => '1=0', 'params' => []];
        }
        if ($scope === self::SCOPE_NONE) {
            return ['where' => '1=0', 'params' => []];
        }
        if ($scope === self::SCOPE_ALL) {
            return ['where' => '1=1', 'params' => []];
        }

        if (in_array($entityType, self::ASSIGNMENT_ENTITY_TYPES, true)) {
            if ($membershipId <= 0) {
                return ['where' => '1=0', 'params' => []];
            }
            return [
                'where' => "EXISTS (
                    SELECT 1
                      FROM company_user_assignments cua
                     WHERE cua.entity_type = ?
                       AND cua.entity_id = {$idExpression}
                       AND cua.membership_id = ?
                )",
                'params' => [$entityType, $membershipId],
            ];
        }

        if ($tenantUserId <= 0) {
            return ['where' => '1=0', 'params' => []];
        }
        return [
            'where' => "{$creatorExpression} = ?",
            'params' => [$tenantUserId],
        ];
    }

    /**
     * Direct-ID/card/mutation guard. The entity row is assumed to have already
     * been loaded from the current tenant DB, preserving company isolation.
     */
    public static function canAccessRow(
        PDO $pdo,
        string $scope,
        string $entityType,
        array $row,
        int $membershipId,
        int $tenantUserId
    ): bool {
        $scope = self::normalizeScope($scope);
        if (!self::isRegulated($entityType) || $scope === self::SCOPE_NONE) {
            return false;
        }
        if ($scope === self::SCOPE_ALL) {
            return true;
        }

        $entityId = (int) ($row['id'] ?? 0);
        if ($entityId <= 0) {
            return false;
        }

        if (in_array($entityType, self::ASSIGNMENT_ENTITY_TYPES, true)) {
            return CompanyAssignmentService::isAssigned($pdo, $entityType, $entityId, $membershipId);
        }

        return $tenantUserId > 0 && (int) ($row['created_by_user_id'] ?? 0) === $tenantUserId;
    }

    public static function assertCanAccessRow(
        PDO $pdo,
        string $scope,
        string $entityType,
        array $row,
        int $membershipId,
        int $tenantUserId
    ): void {
        if (!self::canAccessRow($pdo, $scope, $entityType, $row, $membershipId, $tenantUserId)) {
            throw new RuntimeException('Доступ к данным запрещён.');
        }
    }

    /**
     * Creation is impossible under NONE. ALL and ONLY_OWN may create; for
     * assignment-backed entities the successful create path must immediately
     * call CompanyAssignmentService::autoAssignCreator().
     */
    public static function canCreate(string $scope, string $entityType): bool
    {
        return self::isRegulated($entityType) && self::normalizeScope($scope) !== self::SCOPE_NONE;
    }

    /**
     * Whether ONLY_OWN is assignment-backed (directory objects) or creator-backed
     * (trip objects). Useful for repository/controller integration and tests.
     */
    public static function ownershipMode(string $entityType): string
    {
        if (in_array($entityType, self::ASSIGNMENT_ENTITY_TYPES, true)) {
            return 'assignment';
        }
        if (in_array($entityType, self::CREATOR_ENTITY_TYPES, true)) {
            return 'creator';
        }
        return 'none';
    }
}
