<?php

declare(strict_types=1);

namespace Planex\Tools\RolesAccess;

use RuntimeException;

final class MigrationProof
{
    public const CATEGORIES = [
        'pages', 'clients', 'contractors', 'vehicle_units', 'drivers', 'linear_trips', 'waste_trips',
    ];

    /** @param list<array<string,mixed>> $legacyUsers @return array<string,mixed> */
    public static function identityPlan(array $legacyUsers): array
    {
        $groups = [];
        foreach ($legacyUsers as $row) {
            $companyId = self::positiveInt($row['company_id'] ?? null, 'company_id');
            $source = (string) ($row['legacy_source'] ?? 'central_company_user');
            if (!in_array($source, ['central_company_user', 'tenant_user'], true)) {
                throw new RuntimeException('Unsupported legacy identity source: ' . $source);
            }
            $sourceId = self::positiveInt($row['legacy_source_id'] ?? $row['legacy_company_user_id'] ?? null, 'legacy_source_id');
            $login = trim((string) ($row['login'] ?? ''));
            $normalized = self::normalize($login);
            if ($normalized === '') {
                throw new RuntimeException("Empty normalized login for company_users {$companyId}:{$sourceId}");
            }
            $row['company_id'] = $companyId;
            $row['legacy_source'] = $source;
            $row['legacy_source_id'] = $sourceId;
            $row['login'] = $login;
            $row['normalized_login'] = $normalized;
            $groups[$normalized][] = $row;
        }
        ksort($groups, SORT_STRING);

        $accounts = [];
        $memberships = [];
        $conflicts = [];
        foreach ($groups as $normalized => $rows) {
            usort($rows, static fn(array $a, array $b): int => [$a['company_id'], $a['legacy_source'], $a['legacy_source_id']] <=> [$b['company_id'], $b['legacy_source'], $b['legacy_source_id']]);
            $fieldConflicts = [];
            $companySources = [];
            foreach ($rows as $row) {
                $companySources[(int) $row['company_id']][] = $row['legacy_source'] . ':' . $row['legacy_source_id'];
            }
            foreach ($companySources as $companyId => $sourceIds) {
                if (count($sourceIds) > 1) {
                    sort($sourceIds, SORT_STRING);
                    $fieldConflicts['_company_membership'] = array_map(
                        static fn(string $sourceRef): string => $companyId . ':' . $sourceRef,
                        $sourceIds,
                    );
                }
            }
            foreach (['full_name', 'email', 'phone', 'password_hash'] as $field) {
                $values = [];
                foreach ($rows as $row) {
                    $value = trim((string) ($row[$field] ?? ''));
                    $values[$value === '' ? '<missing>' : $value] = true;
                }
                if (count($values) > 1 || (in_array($field, ['full_name', 'password_hash'], true) && isset($values['<missing>']))) {
                    $fieldConflicts[$field] = array_keys($values);
                }
            }
            if ($fieldConflicts !== []) {
                $conflicts[] = [
                    'normalized_login' => $normalized,
                    'source_refs' => array_map(static fn(array $r): string => $r['company_id'] . ':' . $r['legacy_source'] . ':' . $r['legacy_source_id'], $rows),
                    'fields' => $fieldConflicts,
                ];
                continue;
            }
            $first = $rows[0];
            $accounts[] = [
                'migration_key' => 'account:' . hash('sha256', $normalized),
                'normalized_login' => $normalized,
                'login' => $first['login'],
                'full_name' => trim((string) ($first['full_name'] ?? '')),
                'email' => self::firstNonEmpty($rows, 'email'),
                'phone' => self::firstNonEmpty($rows, 'phone'),
                'password_hash' => self::firstNonEmpty($rows, 'password_hash'),
            ];
            foreach ($rows as $row) {
                $memberships[] = [
                    'migration_key' => self::membershipKey((int) $row['company_id'], (string) $row['legacy_source'], (int) $row['legacy_source_id']),
                    'account_migration_key' => 'account:' . hash('sha256', $normalized),
                    'company_id' => $row['company_id'],
                    'legacy_source' => $row['legacy_source'],
                    'legacy_source_id' => $row['legacy_source_id'],
                    'legacy_role_code' => (string) ($row['role'] ?? $row['role_code'] ?? ''),
                    'status' => (string) ($row['status'] ?? 'blocked'),
                ];
            }
        }
        usort($memberships, static fn(array $a, array $b): int => $a['migration_key'] <=> $b['migration_key']);
        return ['accounts' => $accounts, 'memberships' => $memberships, 'conflicts' => $conflicts, 'blocked' => $conflicts !== []];
    }

    /**
     * Sanitized evidence for blocked identity planning. Raw login/profile values
     * are deliberately excluded; source refs and conflict field names are enough
     * to diagnose whether the blocker is duplicate membership or identity drift.
     *
     * @param list<array<string,mixed>> $conflicts
     * @return list<array{login_fingerprint:string,source_refs:list<string>,fields:list<string>}>
     */
    public static function identityConflictEvidence(array $conflicts): array
    {
        $out = [];
        foreach ($conflicts as $conflict) {
            $normalized = trim((string) ($conflict['normalized_login'] ?? ''));
            $sourceRefs = array_values(array_map('strval', is_array($conflict['source_refs'] ?? null) ? $conflict['source_refs'] : []));
            sort($sourceRefs, SORT_STRING);
            $fieldsRaw = is_array($conflict['fields'] ?? null) ? $conflict['fields'] : [];
            $fields = array_values(array_map('strval', array_keys($fieldsRaw)));
            sort($fields, SORT_STRING);
            $out[] = [
                'login_fingerprint' => substr(hash('sha256', $normalized), 0, 16),
                'source_refs' => $sourceRefs,
                'fields' => $fields,
            ];
        }
        return $out;
    }

    /**
     * Seeds only direct, live legacy grants and explicit creator links whose tenant user has a proven membership mapping.
     * @param list<array<string,mixed>> $grants
     * @param list<array<string,mixed>> $creatorLinks
     * @param array<int,int> $tenantUserToMembership
     * @return list<array<string,int|string|null>>
     */
    public static function provenAssignments(array $grants, array $creatorLinks, array $tenantUserToMembership): array
    {
        $allowed = ['client', 'contractor', 'vehicle_unit', 'driver'];
        $result = [];
        $add = static function (array $row, string $userField) use (&$result, $tenantUserToMembership, $allowed): void {
            $type = (string) ($row['entity_type'] ?? '');
            $entityId = (int) ($row['entity_id'] ?? 0);
            $tenantUserId = (int) ($row[$userField] ?? 0);
            if (!in_array($type, $allowed, true) || $entityId <= 0 || !isset($tenantUserToMembership[$tenantUserId])) {
                return;
            }
            $membershipId = (int) $tenantUserToMembership[$tenantUserId];
            $key = $type . ':' . $entityId . ':' . $membershipId;
            $result[$key] = [
                'entity_type' => $type,
                'entity_id' => $entityId,
                'membership_id' => $membershipId,
                'assigned_by_membership_id' => null,
            ];
        };
        foreach ($grants as $grant) {
            if (($grant['revoked_at'] ?? null) === null && ($grant['is_live'] ?? true) === true) {
                $add($grant, 'granted_to_user_id');
            }
        }
        foreach ($creatorLinks as $link) {
            if (($link['proven'] ?? false) === true) {
                $add($link, 'created_by_user_id');
            }
        }
        ksort($result, SORT_STRING);
        return array_values($result);
    }

    /**
     * Strict planner: every live legacy grant or claimed creator link must map
     * to a supported membership assignment or become an explicit blocker.
     *
     * @return array{assignments:array,conflicts:array,blocked:bool}
     */
    public static function assignmentPlan(array $grants, array $creatorLinks, array $tenantUserToMembership): array
    {
        $allowed = ['client', 'contractor', 'vehicle_unit', 'driver'];
        $assignments = [];
        $conflicts = [];
        $consume = static function (array $row, string $userField, string $source) use (&$assignments, &$conflicts, $tenantUserToMembership, $allowed): void {
            $type = (string) ($row['entity_type'] ?? '');
            $entityId = (int) ($row['entity_id'] ?? 0);
            $tenantUserId = (int) ($row[$userField] ?? 0);
            if (!in_array($type, $allowed, true) || $entityId <= 0 || !isset($tenantUserToMembership[$tenantUserId])) {
                $conflicts[] = ['source' => $source, 'entity_type' => $type, 'entity_id' => $entityId, 'tenant_user_id' => $tenantUserId, 'reason' => !in_array($type, $allowed, true) ? 'unsupported_entity' : (!isset($tenantUserToMembership[$tenantUserId]) ? 'missing_membership_mapping' : 'invalid_entity_id')];
                return;
            }
            $membershipId = (int) $tenantUserToMembership[$tenantUserId];
            $assignments[$type . ':' . $entityId . ':' . $membershipId] = ['entity_type' => $type, 'entity_id' => $entityId, 'membership_id' => $membershipId, 'assigned_by_membership_id' => null];
        };
        foreach ($grants as $grant) {
            $live = ($grant['revoked_at'] ?? null) === null && in_array($grant['is_live'] ?? true, [true, 1, '1'], true);
            if ($live) {
                $consume($grant, 'granted_to_user_id', 'legacy_grant');
            }
        }
        foreach ($creatorLinks as $link) {
            if (!in_array($link['proven'] ?? false, [true, 1, '1'], true)) {
                $conflicts[] = ['source' => 'creator_link', 'entity_type' => (string) ($link['entity_type'] ?? ''), 'entity_id' => (int) ($link['entity_id'] ?? 0), 'tenant_user_id' => (int) ($link['created_by_user_id'] ?? 0), 'reason' => 'creator_not_proven'];
                continue;
            }
            $consume($link, 'created_by_user_id', 'creator_link');
        }
        ksort($assignments, SORT_STRING);
        return ['assignments' => array_values($assignments), 'conflicts' => $conflicts, 'blocked' => $conflicts !== []];
    }

    /** @return array{scope:?string,blocked:bool,legacy:array,all:array,creator:array} */
    public static function inferTripScope(array $allIds, array $creatorIds, array $legacyVisibleIds): array
    {
        $normalize = static function (array $ids): array {
            $ids = array_values(array_unique(array_map('strval', $ids)));
            sort($ids, SORT_NATURAL);
            return $ids;
        };
        $all = $normalize($allIds);
        $creator = $normalize($creatorIds);
        $legacy = $normalize($legacyVisibleIds);
        $scope = $legacy === $all ? 'all' : ($legacy === $creator ? 'own' : ($legacy === [] ? 'none' : null));
        return ['scope' => $scope, 'blocked' => $scope === null, 'legacy' => $legacy, 'all' => $all, 'creator' => $creator];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public static function canonicalSnapshot(array $snapshot): array
    {
        $phase = strtoupper((string) ($snapshot['phase'] ?? ''));
        if (!in_array($phase, ['BEFORE', 'AFTER'], true)) {
            throw new RuntimeException('Snapshot phase must be BEFORE or AFTER.');
        }
        $sourceHead = (string) ($snapshot['source_head'] ?? '');
        if (!preg_match('/^[a-f0-9]{40}$/', $sourceHead)) {
            throw new RuntimeException('Snapshot source_head must be an exact 40-hex commit.');
        }
        if (!array_key_exists('memberships', $snapshot) || !is_array($snapshot['memberships']) || $snapshot['memberships'] === []) {
            throw new RuntimeException('Snapshot must contain at least one membership.');
        }

        $memberships = [];
        foreach ($snapshot['memberships'] as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Snapshot membership must be an object.');
            }
            $key = trim((string) ($row['migration_key'] ?? ''));
            if ($key === '' || isset($memberships[$key])) {
                throw new RuntimeException('Snapshot migration_key must be non-empty and unique.');
            }
            $normalized = [
                'migration_key' => $key,
                'company_id' => self::positiveInt($row['company_id'] ?? null, 'company_id'),
            ];
            foreach (self::CATEGORIES as $category) {
                if (!array_key_exists($category, $row) || !is_array($row[$category])) {
                    throw new RuntimeException("Snapshot {$key}.{$category} must be present as an array.");
                }
                $values = array_values(array_unique(array_map(static fn(mixed $v): string => (string) $v, $row[$category])));
                sort($values, SORT_NATURAL);
                $normalized[$category] = $values;
            }
            $memberships[$key] = $normalized;
        }
        ksort($memberships, SORT_STRING);
        return [
            'phase' => $phase,
            'source_head' => $sourceHead,
            'memberships' => array_values($memberships),
        ];
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after @param list<array<string,mixed>> $exceptions */
    public static function compare(array $before, array $after, array $exceptions = []): array
    {
        $before = self::canonicalSnapshot($before);
        $after = self::canonicalSnapshot($after);
        if ($before['phase'] !== 'BEFORE' || $after['phase'] !== 'AFTER') {
            throw new RuntimeException('Comparison requires BEFORE then AFTER snapshots.');
        }
        $index = static function (array $snapshot): array {
            $out = [];
            foreach ($snapshot['memberships'] as $row) {
                $out[$row['migration_key']] = $row;
            }
            return $out;
        };
        $left = $index($before);
        $right = $index($after);
        $keys = array_values(array_unique(array_merge(array_keys($left), array_keys($right))));
        sort($keys, SORT_STRING);
        $diffs = [];
        foreach ($keys as $key) {
            if (!isset($left[$key]) || !isset($right[$key])) {
                $diffs[] = [
                    'migration_key' => $key,
                    'category' => '_membership',
                    'removed' => isset($left[$key]) ? ['present'] : [],
                    'added' => isset($right[$key]) ? ['present'] : [],
                ];
                continue;
            }
            if ($left[$key]['company_id'] !== $right[$key]['company_id']) {
                $diffs[] = [
                    'migration_key' => $key,
                    'category' => '_company',
                    'removed' => [(string) $left[$key]['company_id']],
                    'added' => [(string) $right[$key]['company_id']],
                ];
            }
            foreach (self::CATEGORIES as $category) {
                $a = $left[$key][$category];
                $b = $right[$key][$category];
                $removed = array_values(array_diff($a, $b));
                $added = array_values(array_diff($b, $a));
                if ($removed !== [] || $added !== []) {
                    $diffs[] = ['migration_key' => $key, 'category' => $category, 'removed' => $removed, 'added' => $added];
                }
            }
        }
        $unapproved = [];
        foreach ($diffs as $diff) {
            $approved = false;
            foreach ($exceptions as $exception) {
                if (($exception['owner_approved'] ?? false) === true
                    && trim((string) ($exception['owner_decision_id'] ?? '')) !== ''
                    && trim((string) ($exception['reason'] ?? '')) !== ''
                    && self::sameDiff($diff, $exception)) {
                    $approved = true;
                    break;
                }
            }
            if (!$approved) {
                $unapproved[] = $diff;
            }
        }
        $document = ['equal' => $diffs === [], 'accepted' => $unapproved === [], 'diffs' => $diffs, 'unapproved' => $unapproved];
        $document['comparison_sha256'] = self::hash($document);
        return $document;
    }

    /** @param array<string,mixed> $manifest */
    public static function readiness(array $manifest): array
    {
        $required = ['central_schema', 'tenant_schema', 'identity_conflicts', 'before_snapshot', 'migration', 'after_snapshot', 'parity', 'independent_regression'];
        $failed = [];
        $gates = $manifest['gates'] ?? null;
        if (!is_array($gates)) {
            $failed[] = 'gates';
            $gates = [];
        }
        foreach ($required as $gate) {
            if (($gates[$gate] ?? null) !== 'PASS') {
                $failed[] = $gate;
            }
        }
        foreach (['before_sha256', 'after_sha256', 'comparison_sha256', 'central_migration_sha256', 'tenant_migration_sha256'] as $proof) {
            if (!preg_match('/^[a-f0-9]{64}$/', (string) ($manifest[$proof] ?? ''))) {
                $failed[] = $proof;
            }
        }
        if (!preg_match('/^[a-f0-9]{40}$/', (string) ($manifest['source_head'] ?? ''))) {
            $failed[] = 'source_head';
        }
        if (filter_var($manifest['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $failed[] = 'company_id';
        }
        if (filter_var($manifest['membership_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $failed[] = 'membership_count';
        }
        if (filter_var($manifest['tenant_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $failed[] = 'tenant_count';
        }
        if (($manifest['artifact_hashes_verified'] ?? false) !== true) {
            $failed[] = 'artifact_hashes_verified';
        }
        if (($manifest['comparison_accepted'] ?? false) !== true) {
            $failed[] = 'comparison_accepted';
        }
        if (($manifest['identity_conflict_count'] ?? -1) !== 0 || ($manifest['unapproved_diff_count'] ?? -1) !== 0) {
            $failed[] = 'zero_conflicts_and_diffs';
        }

        $ownerExceptionCount = $manifest['owner_exception_count'] ?? 0;
        $ownerExceptions = $manifest['owner_exceptions'] ?? [];
        if (!is_int($ownerExceptionCount) || $ownerExceptionCount < 0 || !is_array($ownerExceptions) || count($ownerExceptions) !== $ownerExceptionCount) {
            $failed[] = 'owner_exceptions';
        } else {
            foreach ($ownerExceptions as $exception) {
                if (!is_array($exception)
                    || ($exception['owner_approved'] ?? false) !== true
                    || trim((string) ($exception['owner_decision_id'] ?? '')) === ''
                    || trim((string) ($exception['reason'] ?? '')) === '') {
                    $failed[] = 'owner_exceptions';
                    break;
                }
            }
        }

        $failed = array_values(array_unique($failed));
        return ['ready' => $failed === [], 'failed_gates' => $failed, 'manifest_sha256' => self::hash($manifest)];
    }

    /** @param array<string,mixed> $value */
    public static function hash(array $value): string
    {
        self::recursiveSort($value);
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function membershipKey(int $companyId, string $source, int $sourceId): string
    {
        return "company:{$companyId}:{$source}:{$sourceId}";
    }

    private static function normalize(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /** @param list<array<string,mixed>> $rows */
    private static function firstNonEmpty(array $rows, string $field): ?string
    {
        foreach ($rows as $row) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new RuntimeException("{$field} must be a positive integer.");
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function sameDiff(array $left, array $right): bool
    {
        return ($left['migration_key'] ?? null) === ($right['migration_key'] ?? null)
            && ($left['category'] ?? null) === ($right['category'] ?? null)
            && array_values($left['removed'] ?? []) === array_values($right['removed'] ?? [])
            && array_values($left['added'] ?? []) === array_values($right['added'] ?? []);
    }

    /** @param array<string,mixed> $value */
    private static function recursiveSort(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::recursiveSort($item);
            }
        }
        unset($item);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
    }
}
