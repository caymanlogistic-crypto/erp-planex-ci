<?php

namespace App\Service;

use App\Security\AccessRequestContext;
use App\Security\EffectiveAccess;
use App\Security\EffectiveAccessResolver;
use App\Security\PageRegistry;
use App\Security\PdoAccessStateProvider;
use DomainException;
use InvalidArgumentException;
use PDO;

/** Owner-facing explanation/editor for explicit M:N assignments. */
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
        $type = trim((string)$type);
        return $type !== '' ? (self::ENTITY_TYPES[$type] ?? null) : null;
    }

    /**
     * Explain what one membership can actually see and why. Effective scope is
     * resolved by the same central ACL resolver used by authorization. Checkbox
     * state is intentionally limited to factual assignment_kind=manual rows.
     *
     * @return array<string,mixed>
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
            'SELECT m.id,m.account_id,m.role_id,m.status,a.full_name,r.system_key
               FROM company_memberships m
               JOIN user_accounts a ON a.id=m.account_id
               JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
              WHERE m.company_id=?
              ORDER BY a.full_name,m.id'
        );
        $membershipStmt->execute([$companyId]);
        $membershipRows = $membershipStmt->fetchAll(PDO::FETCH_ASSOC);
        $selected = null;
        $memberships = [];
        foreach ($membershipRows as $row) {
            $mapped = [
                'id'=>(int)$row['id'],
                'account_id'=>(int)$row['account_id'],
                'full_name'=>(string)$row['full_name'],
                'status'=>(string)$row['status'],
                'is_system_owner'=>(string)($row['system_key'] ?? '') === CompanyRoleService::OWNER_SYSTEM_KEY,
            ];
            $memberships[] = $mapped;
            if ((int)$row['id'] === $selectedMembershipId) $selected = $row;
        }
        if (!is_array($selected)) {
            throw new DomainException('Сотрудник текущей компании не найден.');
        }

        $access = (new EffectiveAccessResolver(new PageRegistry(), new PdoAccessStateProvider($central)))
            ->resolve(new AccessRequestContext((int)$selected['account_id'], $companyId));

        $roleScopes = [];
        $roleScopeStmt = $central->prepare('SELECT entity_type,scope FROM role_data_scopes WHERE role_id=?');
        $roleScopeStmt->execute([(int)$selected['role_id']]);
        foreach ($roleScopeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $roleScopes[(string)$row['entity_type']] = (string)$row['scope'];
        }
        $overrides = [];
        $overrideStmt = $central->prepare('SELECT entity_type,scope FROM membership_data_scope_overrides WHERE membership_id=?');
        $overrideStmt->execute([$selectedMembershipId]);
        foreach ($overrideStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $overrides[(string)$row['entity_type']] = (string)$row['scope'];
        }

        $definitions = [
            'client'=>[
                'table'=>'clients',
                'label'=>"COALESCE(NULLIF(TRIM(e.name), ''), CONCAT('Клиент #', e.id))",
            ],
            'contractor'=>[
                'table'=>'contractors',
                'label'=>"COALESCE(NULLIF(TRIM(e.name), ''), CONCAT('Перевозчик #', e.id))",
            ],
            'vehicle_unit'=>[
                'table'=>'vehicle_units',
                'label'=>"COALESCE(NULLIF(TRIM(CONCAT_WS(' ',NULLIF(TRIM(e.brand),''),NULLIF(TRIM(e.model),''),NULLIF(TRIM(e.plate_number),''))),''),CONCAT('ТС #',e.id))",
            ],
            'driver'=>[
                'table'=>'drivers',
                'label'=>"COALESCE(NULLIF(TRIM(e.full_name), ''), CONCAT('Водитель #', e.id))",
            ],
        ];

        $entities = [];
        $summaries = [];
        foreach ($definitions as $entityType=>$definition) {
            $effectiveScope = $access->isActive() ? $access->dataScope($entityType) : EffectiveAccess::SCOPE_NONE;
            $scopeSource = !$access->isActive()
                ? 'membership_inactive'
                : ($access->isSystemOwner() ? 'system_owner' : (array_key_exists($entityType, $overrides) ? 'personal_override' : 'role'));

            $sql = sprintf(
                "SELECT e.id,%s AS label,e.created_by_membership_id,
                        er.membership_id AS responsibility_membership_id,
                        EXISTS(SELECT 1 FROM entity_assignments ea
                                WHERE ea.entity_type=? AND ea.entity_id=e.id AND ea.membership_id=?
                                  AND ea.assignment_kind='manual') AS explicit_assigned,
                        EXISTS(SELECT 1 FROM entity_assignments ea
                                WHERE ea.entity_type=? AND ea.entity_id=e.id AND ea.membership_id=?
                                  AND ea.assignment_kind='legacy_unclassified') AS legacy_unclassified
                   FROM `%s` e
              LEFT JOIN entity_responsibilities er ON er.entity_type=? AND er.entity_id=e.id
                  WHERE e.deleted_at IS NULL AND e.status<>'archived'
               ORDER BY label,e.id",
                $definition['label'],
                $definition['table']
            );
            $stmt = $tenant->prepare($sql);
            $stmt->execute([$entityType,$selectedMembershipId,$entityType,$selectedMembershipId,$entityType]);

            $accessibleCount = 0;
            $explicitCount = 0;
            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $creatorId = (int)($row['created_by_membership_id'] ?? 0);
                $responsibilityId = (int)($row['responsibility_membership_id'] ?? 0);
                $hasResponsibilityOverride = $responsibilityId > 0;
                $baseId = $hasResponsibilityOverride ? $responsibilityId : $creatorId;
                $explicit = (bool)$row['explicit_assigned'];
                $legacyRaw = (bool)$row['legacy_unclassified'];
                // Unclassified non-creator grants remain factual access. Ambiguous creator-like rows
                // are redundant until responsibility is explicitly transferred, then do not override it.
                $legacyAccess = $legacyRaw && ($selectedMembershipId !== $creatorId || !$hasResponsibilityOverride);
                $baseResponsible = $baseId === $selectedMembershipId && $baseId > 0;

                $accessible = match ($effectiveScope) {
                    EffectiveAccess::SCOPE_ALL => true,
                    EffectiveAccess::SCOPE_OWN => $baseResponsible || $explicit || $legacyAccess,
                    default => false,
                };
                if ($accessible) $accessibleCount++;
                if ($explicit) $explicitCount++;

                $reasons = [];
                if ($effectiveScope === EffectiveAccess::SCOPE_ALL) {
                    $reasons[] = 'scope_all';
                } elseif ($effectiveScope === EffectiveAccess::SCOPE_NONE) {
                    $reasons[] = 'scope_none';
                } elseif ($baseResponsible) {
                    $reasons[] = $hasResponsibilityOverride ? 'base_responsibility' : 'creator_ownership';
                }
                if ($explicit) $reasons[] = 'explicit_assignment';
                if ($legacyAccess) $reasons[] = 'legacy_assignment';

                $rows[] = [
                    'id'=>(int)$row['id'],
                    'label'=>(string)$row['label'],
                    'accessible'=>$accessible,
                    'explicit_assigned'=>$explicit,
                    'assigned'=>$explicit, // compatibility for current JS during rollout
                    'created_by_selected'=>$creatorId === $selectedMembershipId && $creatorId > 0,
                    'base_responsible'=>$baseResponsible,
                    'responsibility_override'=>$hasResponsibilityOverride,
                    'legacy_assignment'=>$legacyAccess,
                    'access_reasons'=>$reasons,
                ];
            }
            $entities[$entityType] = $rows;
            $summaries[$entityType] = [
                'scope'=>$effectiveScope,
                'scope_source'=>$scopeSource,
                'role_scope'=>$roleScopes[$entityType] ?? EffectiveAccess::SCOPE_NONE,
                'personal_override'=>$overrides[$entityType] ?? null,
                'total_count'=>count($rows),
                'accessible_count'=>$accessibleCount,
                'explicit_assignment_count'=>$explicitCount,
            ];
        }

        return [
            'success'=>true,
            'memberships'=>$memberships,
            'selected_membership_id'=>$selectedMembershipId,
            'selected_membership_status'=>(string)$selected['status'],
            'entities'=>$entities,
            'summaries'=>$summaries,
        ];
    }
}
