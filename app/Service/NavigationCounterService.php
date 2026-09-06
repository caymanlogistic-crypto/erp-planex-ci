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
     * schema object for one module must never suppress unrelated badges.
     *
     * @return array{bank_attention:int,cash_attention:int,closing_documents_problem:int,linear_trips_problem:int,departures_problem:int,feo_freshness:int,feo_utilizer_problem:int,production_calendar_warning:int}
     */
    public static function forCompany(array $config, Database $centralDb, int $companyId, ?array $knownCompany = null): array
    {
        if ($companyId <= 0) return self::emptyCounters();

        $cacheKey = (string)$companyId;
        if (isset(self::$requestCache[$cacheKey])) {
            return self::$requestCache[$cacheKey];
        }

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
            // Keep the sidebar badge on the same compliance-aware source as the
            // report itself, including EDO, manual collected and per-INN exclusions.
            $counters['closing_documents_problem'] = max(0, ClosingDocumentComplianceService::problemCount($tenant));
        } catch (Throwable) {
            // During a staged rollout migration 121 may not yet exist. The new
            // counter alone degrades to zero; bank/cash navigation remains intact.
            $counters['closing_documents_problem'] = 0;
        }

        try {
            // A linear trip needs attention when the actual unloading date is not
            // recorded and its planned unloading date has already arrived.
            $counters['linear_trips_problem'] = max(0, (int)$tenant->query(
                "SELECT COUNT(*)
                   FROM linear_routes
                  WHERE deleted_at IS NULL
                    AND planned_unloading_date IS NOT NULL
                    AND planned_unloading_date <> ''
                    AND (actual_unloading_date IS NULL OR actual_unloading_date = '')
                    AND DATE(planned_unloading_date) <= CURRENT_DATE()"
            )->fetchColumn());
        } catch (Throwable) {
            $counters['linear_trips_problem'] = 0;
        }

        // Reserved for the sibling Trips submenu. Keeping it explicit makes the
        // parent counter a true sum of child counters instead of a hard-coded
        // alias to the Linear Trips value.
        $counters['departures_problem'] = 0;

        try {
            // Same factual freshness rule as FeoRequestService::freshnessState():
            // no applied import is a problem, otherwise the latest applied import
            // becomes stale after seven days.
            $row = $tenant->query(
                "SELECT CASE WHEN created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END AS is_stale
                   FROM feo_imports
                  WHERE status = 'applied'
                  ORDER BY id DESC
                  LIMIT 1"
            )->fetchColumn();
            $counters['feo_freshness'] = $row === false ? 1 : ((int)$row === 1 ? 1 : 0);
        } catch (Throwable) {
            // Preserve the legacy helper's staged-rollout behavior: missing FEO
            // schema suppresses only this badge and never unrelated navigation.
            $counters['feo_freshness'] = 0;
        }

        try {
            if (!class_exists(FeoUtilizerService::class)) {
                require_once base_path('app/Service/FeoUtilizerService.php');
            }
            $counters['feo_utilizer_problem'] = max(
                0,
                (int)((new FeoUtilizerService())->currentPlacementProblemSummary($tenant)['count'] ?? 0)
            );
        } catch (Throwable) {
            $counters['feo_utilizer_problem'] = 0;
        }

        try {
            $counters['production_calendar_warning'] = 0;
            if ((int)date('n') === 12) {
                if (!class_exists(ProductionCalendarService::class)) {
                    require_once base_path('app/Service/ProductionCalendarService.php');
                }
                $nextYear = (int)date('Y') + 1;
                $counters['production_calendar_warning'] = ProductionCalendarService::isYearReady($tenant, $nextYear) ? 0 : 1;
            }
        } catch (Throwable) {
            // Historical behavior is fail-attentive in December when the calendar
            // status cannot be proven ready.
            $counters['production_calendar_warning'] = (int)date('n') === 12 ? 1 : 0;
        }

        self::$requestCache[$cacheKey] = $counters;
        return $counters;
    }

    /** @return array{bank_attention:int,cash_attention:int,closing_documents_problem:int,linear_trips_problem:int,departures_problem:int,feo_freshness:int,feo_utilizer_problem:int,production_calendar_warning:int} */
    private static function emptyCounters(): array
    {
        return [
            'bank_attention' => 0,
            'cash_attention' => 0,
            'closing_documents_problem' => 0,
            'linear_trips_problem' => 0,
            'departures_problem' => 0,
            'feo_freshness' => 0,
            'feo_utilizer_problem' => 0,
            'production_calendar_warning' => 0,
        ];
    }
}
