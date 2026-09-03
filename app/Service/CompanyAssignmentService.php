<?php

namespace App\Service;

use PDO;
use RuntimeException;

/**
 * Canonical many-to-many assignment persistence for regulated company objects.
 *
 * Assignments belong to a company membership, not to the global account and not
 * to the legacy tenant role. The tenant DB is already company-scoped, therefore
 * membership_id is sufficient to isolate assignments inside this database.
 */
final class CompanyAssignmentService
{
    public const ENTITY_CLIENT = 'client';
    public const ENTITY_CONTRACTOR = 'contractor';
    public const ENTITY_VEHICLE = 'vehicle_unit';
    public const ENTITY_DRIVER = 'driver';

    public const ENTITY_TYPES = [
        self::ENTITY_CLIENT,
        self::ENTITY_CONTRACTOR,
        self::ENTITY_VEHICLE,
        self::ENTITY_DRIVER,
    ];

    public static function isSupportedEntityType(string $entityType): bool
    {
        return in_array($entityType, self::ENTITY_TYPES, true);
    }

    /**
     * Manual assignment mutation is leader-only. This method enforces that rule
     * server-side; UI visibility is not a security boundary.
     */
    public static function assign(
        PDO $pdo,
        string $entityType,
        int $entityId,
        int $membershipId,
        array $actorContext
    ): void {
        self::assertEntity($entityType, $entityId);
        self::assertMembershipId($membershipId);
        self::assertLeader($actorContext);

        $assignedBy = (int) ($actorContext['membership_id'] ?? 0);
        if ($assignedBy <= 0) {
            throw new RuntimeException('Не удалось определить membership Руководителя.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO company_user_assignments
                (entity_type, entity_id, membership_id, assigned_by_membership_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE assigned_by_membership_id = VALUES(assigned_by_membership_id)'
        );
        $stmt->execute([$entityType, $entityId, $membershipId, $assignedBy]);
    }

    /**
     * Manual removal is leader-only. Missing row is intentionally idempotent.
     */
    public static function unassign(
        PDO $pdo,
        string $entityType,
        int $entityId,
        int $membershipId,
        array $actorContext
    ): void {
        self::assertEntity($entityType, $entityId);
        self::assertMembershipId($membershipId);
        self::assertLeader($actorContext);

        $stmt = $pdo->prepare(
            'DELETE FROM company_user_assignments
              WHERE entity_type = ? AND entity_id = ? AND membership_id = ?'
        );
        $stmt->execute([$entityType, $entityId, $membershipId]);
    }

    /**
     * Creation auto-assignment is allowed for any concrete company membership,
     * including Руководитель. superadmin/system/import without a trustworthy
     * concrete membership must not create synthetic ownership.
     *
     * Returns true when assignment was persisted, false when intentionally skipped.
     */
    public static function autoAssignCreator(
        PDO $pdo,
        string $entityType,
        int $entityId,
        array $creatorContext
    ): bool {
        self::assertEntity($entityType, $entityId);

        if (!empty($creatorContext['is_superadmin'])) {
            return false;
        }
        if (empty($creatorContext['allowed'])) {
            return false;
        }

        $membershipId = (int) ($creatorContext['membership_id'] ?? 0);
        $tenantUserId = (int) ($creatorContext['tenant_user_id'] ?? 0);
        if ($membershipId <= 0 || $tenantUserId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO company_user_assignments
                (entity_type, entity_id, membership_id, assigned_by_membership_id)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$entityType, $entityId, $membershipId, $membershipId]);
        return true;
    }

    public static function isAssigned(
        PDO $pdo,
        string $entityType,
        int $entityId,
        int $membershipId
    ): bool {
        if (!self::isSupportedEntityType($entityType) || $entityId <= 0 || $membershipId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT 1 FROM company_user_assignments
              WHERE entity_type = ? AND entity_id = ? AND membership_id = ?
              LIMIT 1'
        );
        $stmt->execute([$entityType, $entityId, $membershipId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return int[] */
    public static function assignedEntityIds(PDO $pdo, string $entityType, int $membershipId): array
    {
        if (!self::isSupportedEntityType($entityType) || $membershipId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT entity_id FROM company_user_assignments
              WHERE entity_type = ? AND membership_id = ?
              ORDER BY entity_id'
        );
        $stmt->execute([$entityType, $membershipId]);
        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    /** @return int[] */
    public static function assignedMembershipIds(PDO $pdo, string $entityType, int $entityId): array
    {
        if (!self::isSupportedEntityType($entityType) || $entityId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT membership_id FROM company_user_assignments
              WHERE entity_type = ? AND entity_id = ?
              ORDER BY membership_id'
        );
        $stmt->execute([$entityType, $entityId]);
        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    private static function assertLeader(array $actorContext): void
    {
        if (empty($actorContext['allowed']) || empty($actorContext['is_leader']) || !empty($actorContext['is_superadmin'])) {
            throw new RuntimeException('Изменять закрепления может только Руководитель текущей компании.');
        }
    }

    private static function assertEntity(string $entityType, int $entityId): void
    {
        if (!self::isSupportedEntityType($entityType)) {
            throw new RuntimeException('Недопустимый тип объекта для закрепления.');
        }
        if ($entityId <= 0) {
            throw new RuntimeException('Объект для закрепления не найден.');
        }
    }

    private static function assertMembershipId(int $membershipId): void
    {
        if ($membershipId <= 0) {
            throw new RuntimeException('Сотрудник компании не найден.');
        }
    }
}
