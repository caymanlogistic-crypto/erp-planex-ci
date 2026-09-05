<?php

namespace App\Service;

use App\Security\AccessRuntime;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Domain filtering only. Effective policy is resolved exclusively by central ACL. */
final class RegulatedDataScopeService
{
    public const REFERENCE_ENTITIES = ['client', 'contractor', 'vehicle_unit', 'driver'];
    public const TRIP_ENTITIES = ['linear_trip', 'waste_trip'];
    public const SCOPES = ['all', 'own', 'none'];

    /** @return array{sql:string,params:list<int|string>} */
    public static function condition(string $entityType, string $idExpression, string $creatorExpression = ''): array
    {
        self::assertEntity($entityType);
        if (!AccessRuntime::current()->isActive()) {
            return self::legacyCondition($entityType, $idExpression, $creatorExpression);
        }
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

        $creatorExpression = $creatorExpression !== ''
            ? $creatorExpression
            : self::creatorExpression($idExpression);

        if (in_array($entityType, self::REFERENCE_ENTITIES, true)) {
            return [
                'sql' => "({$creatorExpression} = ? OR EXISTS (SELECT 1 FROM entity_assignments ea
                          WHERE ea.entity_type = ? AND ea.entity_id = {$idExpression}
                            AND ea.membership_id = ?))",
                'params' => [$membershipId, $entityType, $membershipId],
            ];
        }

        if ($entityType === 'linear_trip') {
            $legacy = LegacyAccessProjectionService::condition('linear_trip', $idExpression, $membershipId);
            return [
                'sql' => "({$creatorExpression} = ? OR {$legacy['sql']})",
                'params' => array_merge([$membershipId], $legacy['params']),
            ];
        }

        return ['sql' => "{$creatorExpression} = ?", 'params' => [$membershipId]];
    }

    public static function canAccess(PDO $pdo, string $entityType, string $table, int $entityId): bool
    {
        if ($entityId <= 0 || preg_match('/^[a-z_]+$/D', $table) !== 1) {
            return false;
        }
        $condition = self::condition($entityType, 't.id', 't.created_by_membership_id');
        $stmt = $pdo->prepare("SELECT 1 FROM `{$table}` t WHERE t.id = ? AND {$condition['sql']} LIMIT 1");
        $stmt->execute(array_merge([$entityId], $condition['params']));

        return (bool) $stmt->fetchColumn();
    }

    public static function canEditTrip(PDO $pdo, string $entityType, string $table, int $entityId): bool
    {
        if (!in_array($entityType, self::TRIP_ENTITIES, true)
            || $entityId <= 0
            || preg_match('/^[a-z_]+$/D', $table) !== 1) {
            return false;
        }
        if (!AccessRuntime::current()->isActive()) {
            return self::legacyCanEditTrip($pdo, $entityType, $table, $entityId);
        }
        $scope = AccessControlService::effectiveDataScope($entityType);
        if ($scope === 'all') {
            return true;
        }
        if ($scope !== 'own') {
            return false;
        }
        $membershipId = AccessControlService::effectiveMembershipId();
        if ($membershipId === null || $membershipId <= 0) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT created_by_membership_id FROM `{$table}` WHERE id=? LIMIT 1");
        $stmt->execute([$entityId]);
        $creator = $stmt->fetchColumn();
        if ($creator !== false && (int) $creator === $membershipId) {
            return true;
        }
        return $entityType === 'linear_trip'
            && LegacyAccessProjectionService::accessLevel($pdo, 'linear_trip', $entityId, $membershipId) === 'edit';
    }

    public static function assertCanCreate(string $entityType): int
    {
        self::assertEntity($entityType);
        if (!AccessRuntime::current()->isActive()) {
            $role = (string) ($_SESSION['role_code'] ?? '');
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            if (!in_array($role, ['superadmin', 'company_owner', 'senior_logist', 'logist'], true) || $userId <= 0) {
                throw new RuntimeException('Нет доступа к данным этого типа.');
            }
            // Legacy mode has no authoritative membership projection yet.
            // The legacy creator id is retained and the migration later projects it.
            return 0;
        }
        if (AccessControlService::effectiveDataScope($entityType) === 'none') {
            throw new RuntimeException('Нет доступа к данным этого типа.');
        }
        $membershipId = AccessControlService::effectiveMembershipId();
        if ($membershipId === null || $membershipId <= 0) {
            throw new RuntimeException('Активное членство компании не определено.');
        }

        return $membershipId;
    }


    /** @return array{sql:string,params:list<int|string>} */
    private static function legacyCondition(string $entityType, string $idExpression, string $creatorExpression): array
    {
        $role = (string) ($_SESSION['role_code'] ?? '');
        if (in_array($role, ['superadmin', 'company_owner', 'senior_logist'], true)) {
            return ['sql' => '1 = 1', 'params' => []];
        }
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($role !== 'logist' || $userId <= 0) {
            return ['sql' => '1 = 0', 'params' => []];
        }
        $creator = $creatorExpression !== ''
            ? preg_replace('/created_by_membership_id$/D', 'created_by_user_id', $creatorExpression)
            : self::legacyCreatorExpression($idExpression);
        if (!is_string($creator) || $creator === '') {
            throw new InvalidArgumentException('Legacy creator expression is required.');
        }
        if ($entityType === 'waste_trip') {
            return ['sql' => "{$creator} = ?", 'params' => [$userId]];
        }
        $legacyType = match ($entityType) {
            'linear_trip' => 'linear_route',
            'client', 'contractor', 'vehicle_unit', 'driver' => $entityType,
            default => throw new InvalidArgumentException('Unsupported legacy entity type.'),
        };
        return [
            'sql' => "({$creator} = ? OR EXISTS (SELECT 1 FROM entity_access_grants lag
                      WHERE lag.entity_type=? AND lag.entity_id={$idExpression}
                        AND lag.granted_to_user_id=? AND lag.access_level IN ('view','edit')
                        AND lag.revoked_at IS NULL))",
            'params' => [$userId, $legacyType, $userId],
        ];
    }

    private static function legacyCanEditTrip(PDO $pdo, string $entityType, string $table, int $entityId): bool
    {
        $role = (string) ($_SESSION['role_code'] ?? '');
        if (in_array($role, ['superadmin', 'company_owner', 'senior_logist'], true)) {
            return true;
        }
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($role !== 'logist' || $userId <= 0) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT created_by_user_id FROM `{$table}` WHERE id=? LIMIT 1");
        $stmt->execute([$entityId]);
        $creator = $stmt->fetchColumn();
        if ($creator !== false && (int) $creator === $userId) {
            return true;
        }
        if ($entityType !== 'linear_trip') {
            return false;
        }
        $grant = $pdo->prepare("SELECT access_level FROM entity_access_grants
            WHERE entity_type='linear_route' AND entity_id=? AND granted_to_user_id=?
              AND revoked_at IS NULL LIMIT 1");
        $grant->execute([$entityId, $userId]);
        return $grant->fetchColumn() === 'edit';
    }

    private static function legacyCreatorExpression(string $idExpression): string
    {
        if (preg_match('/^([a-z_][a-z0-9_]*)\.id$/D', $idExpression, $m) !== 1) {
            throw new InvalidArgumentException('Legacy creator expression is required.');
        }
        return $m[1] . '.created_by_user_id';
    }

    private static function creatorExpression(string $idExpression): string
    {
        if (preg_match('/^([a-z_][a-z0-9_]*)\.id$/D', $idExpression, $m) !== 1) {
            throw new InvalidArgumentException('Creator expression is required for this regulated entity.');
        }
        return $m[1] . '.created_by_membership_id';
    }

    private static function assertEntity(string $entityType): void
    {
        if (!in_array($entityType, array_merge(self::REFERENCE_ENTITIES, self::TRIP_ENTITIES), true)) {
            throw new InvalidArgumentException('Unknown regulated entity type.');
        }
    }
}
