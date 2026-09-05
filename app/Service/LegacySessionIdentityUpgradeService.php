<?php

namespace App\Service;

use App\Security\AccessStateProvider;
use PDO;

/**
 * One-request bridge for sessions created before global Roles & Access identity.
 *
 * Identity is recovered only from migration provenance persisted in
 * company_memberships. Legacy role_code is never permission authority: it is
 * used only to select the historic source namespace, then discarded.
 */
final class LegacySessionIdentityUpgradeService
{
    public const CURRENT = 'current';
    public const ANONYMOUS = 'anonymous';
    public const UPGRADED = 'upgraded';
    public const INVALID = 'invalid';

    public function __construct(
        private readonly PDO $pdo,
        private readonly AccessStateProvider $accessStateProvider,
    ) {
    }

    /** @param array<string,mixed> $session */
    public function upgrade(array &$session): string
    {
        $nested = isset($session['user']) && is_array($session['user']) ? $session['user'] : [];

        $topCompanyId = max(0, (int) ($session['company_id'] ?? 0));
        $nestedCompanyId = max(0, (int) ($nested['company_id'] ?? 0));
        if ($topCompanyId > 0 && $nestedCompanyId > 0 && $topCompanyId !== $nestedCompanyId) {
            return self::INVALID;
        }
        $companyId = $topCompanyId > 0 ? $topCompanyId : $nestedCompanyId;

        $topRole = trim((string) ($session['role_code'] ?? ''));
        $nestedRole = trim((string) ($nested['role_code'] ?? ''));
        if ($topRole !== '' && $nestedRole !== '' && $topRole !== $nestedRole) {
            return self::INVALID;
        }
        $legacyRole = $topRole !== '' ? $topRole : $nestedRole;

        // Technical superadmin is a separate non-company authority and never
        // participates in company membership recovery.
        if ($legacyRole === 'superadmin') {
            return self::CURRENT;
        }

        $accountId = max(0, (int) ($session['account_id'] ?? ($nested['account_id'] ?? 0)));
        if ($accountId > 0) {
            if ($companyId <= 0) {
                return self::INVALID;
            }
            try {
                if (!$this->accessStateProvider->isCompanyAccessReady($companyId)) {
                    return self::INVALID;
                }
                $snapshot = $this->accessStateProvider->loadMembershipAccess($accountId, $companyId);
            } catch (\Throwable) {
                return self::INVALID;
            }
            if ($snapshot === null
                || $snapshot->membershipId <= 0
                || $snapshot->companyId !== $companyId
                || $snapshot->status !== 'active') {
                return self::INVALID;
            }
            return self::CURRENT;
        }

        $topLegacyUserId = max(0, (int) ($session['user_id'] ?? 0));
        $nestedLegacyUserId = max(0, (int) ($nested['user_id'] ?? 0));
        if ($topLegacyUserId > 0 && $nestedLegacyUserId > 0 && $topLegacyUserId !== $nestedLegacyUserId) {
            return self::INVALID;
        }
        $legacyUserId = $topLegacyUserId > 0 ? $topLegacyUserId : $nestedLegacyUserId;
        if ($legacyUserId <= 0) {
            return self::ANONYMOUS;
        }
        if ($companyId <= 0) {
            return self::INVALID;
        }

        $legacySource = match ($legacyRole) {
            'company_owner' => 'central_company_user',
            'senior_logist', 'logist' => 'tenant_user',
            default => null,
        };
        if ($legacySource === null) {
            return self::INVALID;
        }

        try {
            if (!$this->accessStateProvider->isCompanyAccessReady($companyId)) {
                return self::INVALID;
            }

            $stmt = $this->pdo->prepare(
                "SELECT m.id AS membership_id, m.account_id, m.company_id, m.status AS membership_status,
                        a.status AS account_status, c.status AS company_status
                 FROM company_memberships m
                 JOIN user_accounts a ON a.id = m.account_id
                 JOIN companies c ON c.id = m.company_id
                 WHERE m.company_id = ?
                   AND m.legacy_source = ?
                   AND m.legacy_source_id = ?"
            );
            $stmt->execute([$companyId, $legacySource, $legacyUserId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return self::INVALID;
        }

        // Even though migration 013 enforces a unique key, keep runtime fail-closed
        // if the database is corrupted or a test/provider returns ambiguity.
        if (count($rows) !== 1) {
            return self::INVALID;
        }

        $row = $rows[0];
        $accountId = (int) ($row['account_id'] ?? 0);
        $membershipId = (int) ($row['membership_id'] ?? 0);
        if ($accountId <= 0
            || $membershipId <= 0
            || (int) ($row['company_id'] ?? 0) !== $companyId
            || ($row['membership_status'] ?? '') !== 'active'
            || ($row['account_status'] ?? '') !== 'active'
            || ($row['company_status'] ?? '') !== 'active') {
            return self::INVALID;
        }

        $session['account_id'] = $accountId;
        // Compatibility only. Current authorization is resolved from account_id + company_id.
        $session['user_id'] = $accountId;
        $session['company_id'] = $companyId;
        unset(
            $session['role_code'],
            $session['role_id'],
            $session['membership_id'],
            $session['permissions'],
            $session['data_scopes']
        );
        if (!isset($session['user']) || !is_array($session['user'])) {
            $session['user'] = [];
        }
        $session['user']['account_id'] = $accountId;
        $session['user']['user_id'] = $accountId;
        $session['user']['company_id'] = $companyId;
        unset($session['user']['role_code'], $session['user']['role_id'], $session['user']['membership_id']);

        return self::UPGRADED;
    }
}
