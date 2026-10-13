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

    /** @param list<int|string> $crewIds
     *  @return array{changed:int,conflicts:list<array{entity_type:string,entity_id:int,crew_ids:list<int>,membership_ids:list<int>}>,unassigned:list<array{entity_type:string,entity_id:int,crew_ids:list<int>}>}
     */
    public static function transferRouteExecutorCascade(
        PDO $pdo,
        array $crewIds,
        int $targetMembershipId,
        int $actorMembershipId
    ): array {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $crewIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            throw new InvalidArgumentException('Выберите хотя бы одного исполнителя рейса.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            self::transfer($pdo, 'route_executor', $ids, $targetMembershipId, $actorMembershipId);
            $result = self::reconcileRouteExecutorLinks($pdo, $actorMembershipId, $ids);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Align linked records with current executor owners. A reference shared by
     * executors of different owners is left untouched and returned as a conflict.
     * When crewIds is null, reconcile every active executor.
     *
     * @param list<int>|null $crewIds
     * @return array{changed:int,conflicts:list<array{entity_type:string,entity_id:int,crew_ids:list<int>,membership_ids:list<int>}>,unassigned:list<array{entity_type:string,entity_id:int,crew_ids:list<int>}>}
     */
    public static function reconcileRouteExecutorLinks(PDO $pdo, int $actorMembershipId, ?array $crewIds = null, bool $apply = true): array
    {
        if ($actorMembershipId <= 0 || !AccessControlService::isEffectiveSystemOwner()
            || AccessControlService::effectiveMembershipId() !== $actorMembershipId) {
            throw new RuntimeException('Сверку ответственности может выполнить только Руководитель.');
        }
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $rows = $pdo->query(
                "SELECT c.id, c.contractor_id, dvb.driver_id, dvb.vehicle_set_id,
                        vs.primary_vehicle_unit_id, vs.secondary_vehicle_unit_id,
                        COALESCE(er.membership_id, u.membership_id) AS membership_id
                   FROM crews c
                   JOIN driver_vehicle_blocks dvb ON dvb.id=c.driver_vehicle_block_id
                   JOIN vehicle_sets vs ON vs.id=dvb.vehicle_set_id
              LEFT JOIN users u ON u.id=c.created_by_user_id
              LEFT JOIN entity_responsibilities er ON er.entity_type='route_executor' AND er.entity_id=c.id
                  WHERE c.status <> 'archived'
                  ORDER BY c.id" . ($apply ? ' FOR UPDATE' : '')
            )->fetchAll(PDO::FETCH_ASSOC);
            $activeIds = array_fill_keys(array_map(static fn(array $r): int => (int)$r['id'], $rows), true);
            if ($crewIds !== null) {
                foreach ($crewIds as $id) {
                    if (!isset($activeIds[(int)$id])) {
                        throw new RuntimeException('Один из исполнителей рейса не найден или архивирован. Обновите страницу.');
                    }
                }
            }
            $extraDrivers = [];
            if ($rows !== []) {
                $driverRows = $pdo->query(
                    "SELECT cd.crew_id, cd.driver_id FROM crew_drivers cd
                       JOIN crews c ON c.id=cd.crew_id AND c.status <> 'archived'"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($driverRows as $driverRow) {
                    $extraDrivers[(int)$driverRow['crew_id']][] = (int)$driverRow['driver_id'];
                }
            }
            $links = [];
            $selected = $crewIds === null ? null : array_fill_keys(array_map('intval', $crewIds), true);
            $selectedLinks = [];
            foreach ($rows as $row) {
                $crewId = (int)$row['id'];
                $refs = [
                    'contractor' => [(int)$row['contractor_id']],
                    'driver' => array_merge([(int)$row['driver_id']], $extraDrivers[$crewId] ?? []),
                    'vehicle_set' => [(int)$row['vehicle_set_id']],
                    'vehicle_unit' => [(int)$row['primary_vehicle_unit_id'], (int)$row['secondary_vehicle_unit_id']],
                ];
                foreach ($refs as $type => $entityIds) {
                    foreach (array_unique($entityIds) as $entityId) {
                        if ($entityId <= 0) continue;
                        $key = $type . ':' . $entityId;
                        $links[$key]['entity_type'] = $type;
                        $links[$key]['entity_id'] = $entityId;
                        $links[$key]['crew_ids'][$crewId] = $crewId;
                        if ((int)$row['membership_id'] > 0) {
                            $membershipId = (int)$row['membership_id'];
                            $links[$key]['membership_ids'][$membershipId] = $membershipId;
                        } else {
                            $links[$key]['unknown'] = true;
                        }
                        if ($selected === null || isset($selected[$crewId])) $selectedLinks[$key] = true;
                    }
                }
            }
            $assignments = [];
            $conflicts = [];
            $unassigned = [];
            foreach ($selectedLinks as $key => $_) {
                $link = $links[$key];
                $owners = array_values($link['membership_ids'] ?? []);
                sort($owners, SORT_NUMERIC);
                $crewIdsForLink = array_values($link['crew_ids']);
                sort($crewIdsForLink, SORT_NUMERIC);
                $detail = ['entity_type'=>$link['entity_type'], 'entity_id'=>$link['entity_id'], 'crew_ids'=>$crewIdsForLink];
                if (count($owners) > 1) {
                    $conflicts[] = $detail + ['membership_ids'=>$owners];
                } elseif (count($owners) === 1 && empty($link['unknown'])) {
                    $assignments[$link['entity_type']][$owners[0]][] = $link['entity_id'];
                } else {
                    $unassigned[] = $detail;
                }
            }
            $changed = 0;
            foreach ($assignments as $type => $byOwner) {
                foreach ($byOwner as $membershipId => $entityIds) {
                    if ($apply) {
                        $result = self::transfer($pdo, $type, $entityIds, (int)$membershipId, $actorMembershipId);
                        $changed += $result['changed'];
                    } else {
                        foreach ($entityIds as $entityId) {
                            if (self::effectiveBaseMembershipId($pdo, $type, $entityId) !== (int)$membershipId) $changed++;
                        }
                    }
                }
            }
            if ($ownsTransaction) $pdo->commit();
            return ['changed'=>$changed, 'conflicts'=>$conflicts, 'unassigned'=>$unassigned];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
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
