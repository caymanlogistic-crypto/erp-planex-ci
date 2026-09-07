<?php

namespace App\Service;

use App\Security\AccessRuntime;
use PDO;

final class RouteExecutorService
{
    public static function findOrCreateDriverVehicleBlock(
        PDO $localPdo,
        int $driverId,
        int $vehicleSetId,
        int $userId,
        string $roleCode
    ): int {
        $stmt = $localPdo->prepare(
            'SELECT id FROM driver_vehicle_blocks WHERE driver_id = ? AND vehicle_set_id = ? LIMIT 1'
        );
        $stmt->execute([$driverId, $vehicleSetId]);
        $existingId = (int) $stmt->fetchColumn();

        if ($existingId > 0) {
            return $existingId;
        }

        $insert = $localPdo->prepare(
            "INSERT INTO driver_vehicle_blocks (
                driver_id,
                vehicle_set_id,
                status,
                created_by_user_id,
                created_by_role
            ) VALUES (?, ?, 'active', ?, ?)"
        );
        $insert->execute([$driverId, $vehicleSetId, $userId, $roleCode]);

        return (int) $localPdo->lastInsertId();
    }

    public static function hasDuplicateCrew(
        PDO $localPdo,
        int $contractorId,
        int $driverId,
        int $vehicleSetId,
        ?int $excludeCrewId = null
    ): bool {
        $sql = "SELECT COUNT(*)
                FROM crews c
                JOIN driver_vehicle_blocks dvb ON dvb.id = c.driver_vehicle_block_id
                WHERE c.contractor_id = ?
                  AND dvb.driver_id = ?
                  AND dvb.vehicle_set_id = ?";
        $params = [$contractorId, $driverId, $vehicleSetId];

        if ($excludeCrewId !== null && $excludeCrewId > 0) {
            $sql .= ' AND c.id != ?';
            $params[] = $excludeCrewId;
        }

        $stmt = $localPdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Route executors intentionally have no independent configurable data-scope.
     * Their visibility is derived from the accepted crew model: contractor + all
     * crew drivers + all units of the selected vehicle set. Each component uses
     * the canonical effective scope, including current responsibility/creator
     * fallback/manual assignments for ONLY_OWN and personal overrides.
     */
    public static function canAccessCrew(PDO $pdo, int $crewId): bool
    {
        if ($crewId <= 0 || !AccessRuntime::current()->isActive()) {
            return false;
        }

        $scope = self::effectiveCrewScope();
        if ($scope === 'none') {
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT c.id,c.contractor_id,dvb.driver_id,vs.primary_vehicle_unit_id,vs.secondary_vehicle_unit_id
               FROM crews c
               JOIN driver_vehicle_blocks dvb ON dvb.id=c.driver_vehicle_block_id
               JOIN vehicle_sets vs ON vs.id=dvb.vehicle_set_id
              WHERE c.id=? LIMIT 1'
        );
        $stmt->execute([$crewId]);
        $crew = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$crew) {
            return false;
        }

        if ($scope === 'all') {
            return true;
        }

        $membershipId = AccessControlService::effectiveMembershipId();
        if ($membershipId !== null && $membershipId > 0
            && EntityResponsibilityService::effectiveBaseMembershipId($pdo, 'route_executor', $crewId) === $membershipId) {
            return true;
        }

        if (!self::canAccessContractor($pdo, (int)$crew['contractor_id'])) {
            return false;
        }

        $driverIds = self::crewDriverIds($pdo, $crewId, (int)$crew['driver_id']);
        if ($driverIds === []) {
            return false;
        }
        foreach ($driverIds as $driverId) {
            if (!self::canAccessDriver($pdo, $driverId)) {
                return false;
            }
        }

        $unitIds = array_values(array_unique(array_filter([
            (int)($crew['primary_vehicle_unit_id'] ?? 0),
            (int)($crew['secondary_vehicle_unit_id'] ?? 0),
        ])));
        if ($unitIds === []) {
            return false;
        }
        foreach ($unitIds as $unitId) {
            if (!RegulatedDataScopeService::canAccess($pdo, 'vehicle_unit', 'vehicle_units', $unitId)) {
                return false;
            }
        }

        return true;
    }

    /** @return 'all'|'own'|'none' */
    public static function effectiveCrewScope(): string
    {
        if (!AccessRuntime::current()->isActive()) {
            return 'none';
        }
        $scopes = [
            AccessControlService::effectiveDataScope('contractor'),
            AccessControlService::effectiveDataScope('driver'),
            AccessControlService::effectiveDataScope('vehicle_unit'),
        ];
        if (in_array('none', $scopes, true)) {
            return 'none';
        }
        return count(array_filter($scopes, static fn(string $scope): bool => $scope === 'all')) === 3
            ? 'all'
            : 'own';
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public static function filterVisibleCrews(PDO $pdo, array $rows): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($pdo): bool {
            return self::canAccessCrew($pdo, (int)($row['crew_id'] ?? $row['id'] ?? 0));
        }));
    }

    public static function canAccessContractor(PDO $pdo, int $contractorId): bool
    {
        return $contractorId > 0
            && AccessRuntime::current()->isActive()
            && RegulatedDataScopeService::canAccess($pdo, 'contractor', 'contractors', $contractorId);
    }

    public static function canAccessDriver(PDO $pdo, int $driverId): bool
    {
        return $driverId > 0
            && AccessRuntime::current()->isActive()
            && RegulatedDataScopeService::canAccess($pdo, 'driver', 'drivers', $driverId);
    }

    /** @param array<string,mixed> $vehicleSet */
    public static function canAccessVehicleSet(PDO $pdo, array $vehicleSet): bool
    {
        if (!AccessRuntime::current()->isActive()) {
            return false;
        }
        $unitIds = array_values(array_unique(array_filter([
            (int)($vehicleSet['primary_vehicle_unit_id'] ?? 0),
            (int)($vehicleSet['secondary_vehicle_unit_id'] ?? 0),
        ])));
        if ($unitIds === []) {
            return false;
        }
        foreach ($unitIds as $unitId) {
            if (!RegulatedDataScopeService::canAccess($pdo, 'vehicle_unit', 'vehicle_units', $unitId)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public static function filterVisibleContractors(PDO $pdo, array $rows): array
    {
        return array_values(array_filter($rows, static fn(array $row): bool => self::canAccessContractor($pdo, (int)($row['id'] ?? 0))));
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public static function filterVisibleDrivers(PDO $pdo, array $rows): array
    {
        return array_values(array_filter($rows, static fn(array $row): bool => self::canAccessDriver($pdo, (int)($row['id'] ?? 0))));
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public static function filterVisibleVehicleSets(PDO $pdo, array $rows): array
    {
        return array_values(array_filter($rows, static fn(array $row): bool => self::canAccessVehicleSet($pdo, $row)));
    }

    /** @return list<int> */
    private static function crewDriverIds(PDO $pdo, int $crewId, int $fallbackDriverId): array
    {
        try {
            $stmt = $pdo->prepare('SELECT driver_id FROM crew_drivers WHERE crew_id=? ORDER BY position,id');
            $stmt->execute([$crewId]);
            $ids = array_values(array_unique(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)))));
            if ($ids !== []) {
                return $ids;
            }
        } catch (\Throwable) {
            // Pre multi-driver rows keep the historical primary driver in the DV block.
        }
        return $fallbackDriverId > 0 ? [$fallbackDriverId] : [];
    }

}
