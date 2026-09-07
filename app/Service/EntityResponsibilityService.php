<?php

namespace App\Service;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Sparse current-responsibility projection.
 * Missing row means current responsibility still follows immutable creator projection.
 */
final class EntityResponsibilityService
{
    public const ENTITY_TYPES = ['client','contractor','vehicle_unit','driver','vehicle_set','route_executor'];
    public const REFERENCE_TYPES = ['client','contractor','vehicle_unit','driver'];

    private const TABLES = [
        'client'=>'clients',
        'contractor'=>'contractors',
        'vehicle_unit'=>'vehicle_units',
        'driver'=>'drivers',
        'vehicle_set'=>'vehicle_sets',
        'route_executor'=>'crews',
    ];

    public static function isSupported(string $entityType): bool
    {
        return in_array($entityType, self::ENTITY_TYPES, true);
    }

    /**
     * Responsibility recipients are all active central memberships in the
     * selected company whose global account is active. Role display names,
     * normalized role names and tenant role_code are not recipient authority.
     * The central membership id is the stable identity used by responsibility
     * rows, so an incomplete tenant users.membership_id projection must not hide
     * an otherwise eligible company user.
     *
     * @return list<array{id:int,membership_id:int,full_name:string,login:string}>
     */
    public static function eligibleRecipients(PDO $central, PDO $tenant, int $companyId): array
    {
        if ($companyId <= 0) {
            return [];
        }

        $memberships = $central->prepare(
            "SELECT m.id AS membership_id, a.full_name, a.login
               FROM company_memberships m
               JOIN user_accounts a ON a.id=m.account_id
               JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
              WHERE m.company_id=?
                AND m.status='active'
                AND a.status='active'
              ORDER BY a.full_name, m.id"
        );
        $memberships->execute([$companyId]);
        $centralRows = $memberships->fetchAll(PDO::FETCH_ASSOC);
        if ($centralRows === []) {
            return [];
        }

        $result = [];
        foreach ($centralRows as $row) {
            $membershipId = (int)($row['membership_id'] ?? 0);
            if ($membershipId <= 0) {
                continue;
            }
            $result[] = [
                'id' => $membershipId,
                'membership_id' => $membershipId,
                'full_name' => (string)($row['full_name'] ?? ''),
                'login' => (string)($row['login'] ?? ''),
            ];
        }

        return $result;
    }

    public static function eligibleRecipientMembershipId(
        PDO $central,
        PDO $tenant,
        int $companyId,
        int $membershipId
    ): ?int {
        if ($membershipId <= 0) {
            return null;
        }
        foreach (self::eligibleRecipients($central, $tenant, $companyId) as $recipient) {
            if ((int)$recipient['membership_id'] === $membershipId) {
                return (int)$recipient['membership_id'];
            }
        }

        return null;
    }

    /**
     * ONLY_OWN for reference entities: current base responsibility (or creator
     * fallback) plus manual M:N assignment. creator_auto rows are not a second
     * access reason.
     *
     * @return array{sql:string,params:list<int|string>}
     */
    public static function ownCondition(
        string $entityType,
        string $idExpression,
        string $creatorExpression,
        int $membershipId
    ): array {
        if (!in_array($entityType, self::REFERENCE_TYPES, true)
            || $membershipId <= 0
            || $idExpression === ''
            || $creatorExpression === '') {
            throw new InvalidArgumentException('Unsupported responsibility condition.');
        }

        return [
            'sql' => "(COALESCE((SELECT er.membership_id FROM entity_responsibilities er
                         WHERE er.entity_type = ? AND er.entity_id = {$idExpression}), {$creatorExpression}) = ?
                       OR EXISTS (SELECT 1 FROM entity_assignments ea
                         WHERE ea.entity_type = ? AND ea.entity_id = {$idExpression}
                           AND ea.membership_id = ?
                           AND (
                               ea.assignment_kind = 'manual'
                               OR (
                                   ea.assignment_kind = 'legacy_unclassified'
                                   AND (
                                       ea.membership_id <> {$creatorExpression}
                                       OR NOT EXISTS (
                                           SELECT 1 FROM entity_responsibilities er_legacy
                                            WHERE er_legacy.entity_type = ?
                                              AND er_legacy.entity_id = {$idExpression}
                                       )
                                   )
                               )
                           )))",
            'params' => [$entityType, $membershipId, $entityType, $membershipId, $entityType],
        ];
    }

    public static function effectiveBaseMembershipId(PDO $pdo, string $entityType, int $entityId): ?int
    {
        self::assertEntity($entityType, $entityId);
        $override = $pdo->prepare(
            'SELECT membership_id FROM entity_responsibilities WHERE entity_type=? AND entity_id=? LIMIT 1'
        );
        $override->execute([$entityType, $entityId]);
        $value = $override->fetchColumn();
        if ($value !== false) {
            return (int)$value > 0 ? (int)$value : null;
        }

        return self::creatorMembershipId($pdo, $entityType, $entityId);
    }

    public static function creatorMembershipId(PDO $pdo, string $entityType, int $entityId): ?int
    {
        self::assertEntity($entityType, $entityId);
        $table = self::TABLES[$entityType];
        if ($entityType === 'route_executor') {
            $stmt = $pdo->prepare(
                'SELECT u.membership_id
                   FROM crews e
              LEFT JOIN users u ON u.id=e.created_by_user_id
                  WHERE e.id=?
                  LIMIT 1'
            );
        } else {
            $stmt = $pdo->prepare("SELECT created_by_membership_id FROM `{$table}` WHERE id=? LIMIT 1");
        }
        $stmt->execute([$entityId]);
        $value = $stmt->fetchColumn();

        return $value !== false && (int)$value > 0 ? (int)$value : null;
    }

    /**
     * Transfer base responsibility without touching created_by_* or explicit
     * assignments. Transfer back to creator removes the sparse override.
     *
     * @param list<int|string> $entityIds
     * @return array{changed:int,restored_to_creator:int,entity_ids:list<int>}
     */
    public static function transfer(
        PDO $pdo,
        string $entityType,
        array $entityIds,
        int $targetMembershipId,
        int $actorMembershipId
    ): array {
        if (!self::isSupported($entityType) || $targetMembershipId <= 0 || $actorMembershipId <= 0) {
            throw new InvalidArgumentException('Некорректные параметры переноса ответственности.');
        }
        if (!AccessControlService::isEffectiveSystemOwner()
            || AccessControlService::effectiveMembershipId() !== $actorMembershipId) {
            throw new RuntimeException('Переносить базовую ответственность может только Руководитель.');
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $entityIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            throw new InvalidArgumentException('Выберите хотя бы один объект для переноса ответственности.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $changed = 0;
            $restored = 0;
            $table = self::TABLES[$entityType];
            $entityLock = $pdo->prepare("SELECT id FROM `{$table}` WHERE id=? FOR UPDATE");
            $responsibilityLock = $pdo->prepare(
                'SELECT membership_id FROM entity_responsibilities WHERE entity_type=? AND entity_id=? FOR UPDATE'
            );
            $upsert = $pdo->prepare(
                'INSERT INTO entity_responsibilities(entity_type,entity_id,membership_id,assigned_by_membership_id)
                 VALUES(?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    membership_id=VALUES(membership_id),
                    assigned_by_membership_id=VALUES(assigned_by_membership_id),
                    updated_at=NOW()'
            );
            $delete = $pdo->prepare(
                'DELETE FROM entity_responsibilities WHERE entity_type=? AND entity_id=?'
            );

            foreach ($ids as $entityId) {
                $entityLock->execute([$entityId]);
                if ($entityLock->fetchColumn() === false) {
                    throw new RuntimeException('Один из объектов переноса не найден. Обновите страницу.');
                }

                $creatorMembershipId = self::creatorMembershipId($pdo, $entityType, $entityId);
                $responsibilityLock->execute([$entityType, $entityId]);
                $currentOverride = $responsibilityLock->fetchColumn();
                $current = $currentOverride !== false ? (int)$currentOverride : $creatorMembershipId;
                if ($current === $targetMembershipId) {
                    continue;
                }

                if ($creatorMembershipId !== null && $creatorMembershipId === $targetMembershipId) {
                    $delete->execute([$entityType, $entityId]);
                    $restored++;
                } else {
                    $upsert->execute([$entityType, $entityId, $targetMembershipId, $actorMembershipId]);
                }
                $changed++;
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'changed' => $changed,
                'restored_to_creator' => $restored,
                'entity_ids' => $ids,
            ];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<int> */
    public static function entityIdsForBaseMembership(PDO $pdo, string $entityType, int $membershipId): array
    {
        if (!self::isSupported($entityType) || $membershipId <= 0) {
            return [];
        }

        $table = self::TABLES[$entityType];
        if ($entityType === 'route_executor') {
            $creatorExpression = 'u.membership_id';
            $creatorJoin = ' LEFT JOIN users u ON u.id=e.created_by_user_id ';
            $activeClause = "e.status <> 'archived'";
        } else {
            $creatorExpression = 'e.created_by_membership_id';
            $creatorJoin = ' ';
            $activeClause = "e.deleted_at IS NULL AND e.status <> 'archived'";
        }

        $sql = "SELECT e.id
                  FROM `{$table}` e{$creatorJoin}
             LEFT JOIN entity_responsibilities er
                    ON er.entity_type=? AND er.entity_id=e.id
                 WHERE COALESCE(er.membership_id, {$creatorExpression})=?
                   AND {$activeClause}
              ORDER BY e.id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$entityType, $membershipId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function assertEntity(string $entityType, int $entityId): void
    {
        if (!self::isSupported($entityType) || $entityId <= 0) {
            throw new InvalidArgumentException('Unsupported responsibility target.');
        }
    }
}
