<?php

namespace App\Service;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Tenant-local many-to-many assignments keyed by the central membership id.
 *
 * The table is provisioned by the tenant 131+ migration owned by Agent 07.
 * There is deliberately no cross-database foreign key to the central DB.
 */
final class EntityAssignmentService
{
    public const ENTITY_TYPES = ['client', 'contractor', 'vehicle_unit', 'driver'];

    public static function isSupported(string $entityType): bool
    {
        return in_array($entityType, self::ENTITY_TYPES, true);
    }

    public static function isAssigned(PDO $pdo, string $entityType, int $entityId, int $membershipId): bool
    {
        self::assertIdentity($entityType, $entityId, $membershipId);
        $stmt = $pdo->prepare(
            "SELECT 1 FROM entity_assignments
              WHERE entity_type = ? AND entity_id = ? AND membership_id = ?
                AND assignment_kind = 'manual'
              LIMIT 1"
        );
        $stmt->execute([$entityType, $entityId, $membershipId]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return list<int> */
    public static function membershipIds(PDO $pdo, string $entityType, int $entityId): array
    {
        self::assertEntity($entityType, $entityId);
        $stmt = $pdo->prepare(
            "SELECT membership_id FROM entity_assignments
              WHERE entity_type = ? AND entity_id = ? AND assignment_kind = 'manual'
              ORDER BY membership_id"
        );
        $stmt->execute([$entityType, $entityId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Called in the same transaction as object creation. A missing concrete
     * company membership (system/import/integration/superadmin) creates no row.
     */
    public static function autoAssignCreator(
        PDO $pdo,
        string $entityType,
        int $entityId,
        ?int $membershipId
    ): void {
        self::assertEntity($entityType, $entityId);
        if ($membershipId === null || $membershipId <= 0) {
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO entity_assignments
                (entity_type, entity_id, membership_id, assigned_by_membership_id, assignment_kind)
             VALUES (?, ?, ?, ?, 'creator_auto')
             ON DUPLICATE KEY UPDATE membership_id = VALUES(membership_id)"
        );
        $stmt->execute([$entityType, $entityId, $membershipId, $membershipId]);
    }

    /** Execute canonical creation and creator assignment as one transaction. */
    public static function createAssigned(PDO $pdo, string $entityType, int $membershipId, callable $create): int
    {
        if (!self::isSupported($entityType) || $membershipId <= 0) {
            throw new InvalidArgumentException('Invalid assigned creation context.');
        }
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $entityId = (int) $create();
            self::autoAssignCreator($pdo, $entityType, $entityId, $membershipId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $entityId;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Owner-only authoritative replacement. Assignments are independent per
     * entity type and are never removed merely because a scope/role/status changes.
     *
     * @param list<int|string> $membershipIds
     */
    public static function replace(
        PDO $pdo,
        string $entityType,
        int $entityId,
        array $membershipIds,
        int $actorMembershipId
    ): void {
        self::assertEntity($entityType, $entityId);
        if (!AccessControlService::isEffectiveSystemOwner()
            || AccessControlService::effectiveMembershipId() !== $actorMembershipId
            || $actorMembershipId <= 0) {
            throw new RuntimeException('Изменять закрепления может только Руководитель.');
        }

        $normalized = array_values(array_unique(array_filter(
            array_map('intval', $membershipIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($normalized, SORT_NUMERIC);

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $lock = $pdo->prepare(
                "SELECT membership_id FROM entity_assignments
                  WHERE entity_type = ? AND entity_id = ? AND assignment_kind = 'manual' FOR UPDATE"
            );
            $lock->execute([$entityType, $entityId]);
            $lock->fetchAll(PDO::FETCH_COLUMN);

            if ($normalized === []) {
                $delete = $pdo->prepare("DELETE FROM entity_assignments WHERE entity_type = ? AND entity_id = ? AND assignment_kind = 'manual'");
                $delete->execute([$entityType, $entityId]);
            } else {
                $marks = implode(',', array_fill(0, count($normalized), '?'));
                $delete = $pdo->prepare(
                    "DELETE FROM entity_assignments
                      WHERE entity_type = ? AND entity_id = ? AND assignment_kind = 'manual'
                        AND membership_id NOT IN ({$marks})"
                );
                $delete->execute(array_merge([$entityType, $entityId], $normalized));

                $insert = $pdo->prepare(
                    "INSERT INTO entity_assignments
                        (entity_type, entity_id, membership_id, assigned_by_membership_id, assignment_kind)
                     VALUES (?, ?, ?, ?, 'manual')
                     ON DUPLICATE KEY UPDATE
                        assigned_by_membership_id = VALUES(assigned_by_membership_id),
                        assignment_kind = 'manual'"
                );
                foreach ($normalized as $membershipId) {
                    $insert->execute([$entityType, $entityId, $membershipId, $actorMembershipId]);
                }
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function assertEntity(string $entityType, int $entityId): void
    {
        if (!self::isSupported($entityType) || $entityId <= 0) {
            throw new InvalidArgumentException('Unsupported assignment target.');
        }
    }

    private static function assertIdentity(string $entityType, int $entityId, int $membershipId): void
    {
        self::assertEntity($entityType, $entityId);
        if ($membershipId <= 0) {
            throw new InvalidArgumentException('Invalid membership id.');
        }
    }
}
