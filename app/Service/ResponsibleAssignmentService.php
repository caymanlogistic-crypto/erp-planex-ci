<?php

namespace App\Service;

use DomainException;
use InvalidArgumentException;
use PDO;

final class ResponsibleAssignmentService
{
    public const ENTITY_TYPES = [
        'client' => 'client',
        'contractor' => 'contractor',
        'driver' => 'driver',
        'vehicle_unit' => 'vehicle_unit',
    ];

    public static function normalizeEntityType(?string $type): ?string
    {
        $type = trim((string) $type);

        if ($type === '') {
            return null;
        }

        return self::ENTITY_TYPES[$type] ?? null;
    }

    /**
     * Build the owner assignment-tab payload. Memberships are read from the
     * central database and constrained by company_id; entities and assignments
     * are read only from that company's tenant connection.
     *
     * @return array{
     *   success:true,
     *   memberships:list<array{id:int,full_name:string,status:string}>,
     *   selected_membership_id:int,
     *   entities:array<string,list<array{id:int,label:string,assigned:bool}>>
     * }
     */
    public static function assignmentPayload(
        PDO $central,
        PDO $tenant,
        int $companyId,
        int $selectedMembershipId
    ): array {
        if ($companyId <= 0 || $selectedMembershipId <= 0) {
            throw new InvalidArgumentException('Некорректный идентификатор сотрудника.');
        }

        $membershipStmt = $central->prepare(
            'SELECT m.id, a.full_name, m.status
             FROM company_memberships m
             JOIN user_accounts a ON a.id = m.account_id
             WHERE m.company_id = ?
             ORDER BY a.full_name, m.id'
        );
        $membershipStmt->execute([$companyId]);
        $memberships = array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'full_name' => (string) $row['full_name'],
                'status' => (string) $row['status'],
            ],
            $membershipStmt->fetchAll(PDO::FETCH_ASSOC)
        );

        if (!in_array($selectedMembershipId, array_column($memberships, 'id'), true)) {
            throw new DomainException('Сотрудник текущей компании не найден.');
        }

        $definitions = [
            'client' => [
                'table' => 'clients',
                'label' => "COALESCE(NULLIF(TRIM(e.name), ''), CONCAT('Клиент #', e.id))",
            ],
            'contractor' => [
                'table' => 'contractors',
                'label' => "COALESCE(NULLIF(TRIM(e.name), ''), CONCAT('Перевозчик #', e.id))",
            ],
            'vehicle_unit' => [
                'table' => 'vehicle_units',
                'label' => "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(e.brand), ''), NULLIF(TRIM(e.model), ''), NULLIF(TRIM(e.plate_number), ''))), ''), CONCAT('ТС #', e.id))",
            ],
            'driver' => [
                'table' => 'drivers',
                'label' => "COALESCE(NULLIF(TRIM(e.full_name), ''), CONCAT('Водитель #', e.id))",
            ],
        ];

        $entities = [];
        foreach ($definitions as $entityType => $definition) {
            $sql = sprintf(
                "SELECT e.id, %s AS label,
                        EXISTS (
                            SELECT 1 FROM entity_assignments ea
                            WHERE ea.entity_type = ?
                              AND ea.entity_id = e.id
                              AND ea.membership_id = ?
                        ) AS assigned
                 FROM `%s` e
                 WHERE e.deleted_at IS NULL
                   AND e.status <> 'archived'
                 ORDER BY label, e.id",
                $definition['label'],
                $definition['table']
            );
            $stmt = $tenant->prepare($sql);
            $stmt->execute([$entityType, $selectedMembershipId]);
            $entities[$entityType] = array_map(
                static fn(array $row): array => [
                    'id' => (int) $row['id'],
                    'label' => (string) $row['label'],
                    'assigned' => (bool) $row['assigned'],
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        }

        return [
            'success' => true,
            'memberships' => $memberships,
            'selected_membership_id' => $selectedMembershipId,
            'entities' => $entities,
        ];
    }
}
