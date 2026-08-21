<?php

namespace App\Service;

use App\Core\Database;
use PDO;
use Throwable;

final class NavigationCounterService
{
    /** @var array<string,array<string,int>> */
    private static array $requestCache = [];

    /**
     * Return lightweight navigation counters for one tenant.
     *
     * Every counter is intentionally isolated. A missing/temporarily unavailable
     * schema object for one finance module must never suppress unrelated badges.
     *
     * @return array{bank_attention:int,cash_attention:int,closing_documents_problem:int}
     */
    public static function forCompany(array $config, Database $centralDb, int $companyId, ?array $knownCompany = null): array
    {
        if ($companyId <= 0) return self::emptyCounters();

        $cacheKey = (string)$companyId;
        if (isset(self::$requestCache[$cacheKey])) return self::$requestCache[$cacheKey];

        try {
            $company = $knownCompany;
            if (!is_array($company) || (int)($company['id'] ?? 0) !== $companyId || ($company['status'] ?? '') !== 'active') {
                $stmt = $centralDb->connection()->prepare("SELECT * FROM companies WHERE id = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$companyId]);
                $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$company) return self::$requestCache[$cacheKey] = self::emptyCounters();

            $tenant = (new Database(companyDatabaseConfig($config, $company)))->connection();
        } catch (Throwable) {
            return self::$requestCache[$cacheKey] = self::emptyCounters();
        }

        $counters = self::emptyCounters();

        try {
            $counters['bank_attention'] = max(0, (int)$tenant->query(
                "SELECT COUNT(*)
                   FROM bank_transactions
                  WHERE classification_status IN ('UNALLOCATED','NEEDS_REVIEW')
                    AND COALESCE(is_internal_transfer, 0) = 0"
            )->fetchColumn());
        } catch (Throwable) {
            $counters['bank_attention'] = 0;
        }

        try {
            $counters['cash_attention'] = max(
                0,
                (int)(FinanceCashResolutionService::unresolvedSummary($tenant)['count'] ?? 0)
            );
        } catch (Throwable) {
            $counters['cash_attention'] = 0;
        }

        try {
            // Deliberately call the report source itself instead of duplicating a
            // SQL predicate here. This is the architectural contract:
            // sidebar count === report rows under "Только проблемные".
            $counters['closing_documents_problem'] = max(0, ClosingDocumentService::problemCount($tenant));
        } catch (Throwable) {
            // During a staged rollout migration 077 may not yet exist. The new
            // counter alone degrades to zero; bank/cash navigation remains intact.
            $counters['closing_documents_problem'] = 0;
        }

        return self::$requestCache[$cacheKey] = $counters;
    }

    /** @return array{bank_attention:int,cash_attention:int,closing_documents_problem:int} */
    private static function emptyCounters(): array
    {
        return ['bank_attention' => 0, 'cash_attention' => 0, 'closing_documents_problem' => 0];
    }
}
