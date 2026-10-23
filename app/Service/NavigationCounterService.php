<?php

namespace App\Service;

use App\Core\Database;
use PDO;
use Throwable;

require_once __DIR__.'/FinanceEmployeeCompanyFundedExpenseService.php';

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
     * @return array<string,int>
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
            $attributedIds = array_keys(FinanceEmployeeCompanyFundedExpenseService::bankAttributions($tenant));
            if ($attributedIds !== []) {
                $ids = implode(',', array_map('intval', $attributedIds));
                $resolved = (int)$tenant->query("SELECT COUNT(*) FROM bank_transactions WHERE id IN ($ids) AND classification_status IN ('UNALLOCATED','NEEDS_REVIEW') AND COALESCE(is_internal_transfer,0)=0")->fetchColumn();
                $counters['bank_attention'] = max(0, $counters['bank_attention'] - $resolved);
            }
        } catch (Throwable) {
            // Missing employee schema must not suppress the original bank warning count.
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
            if (!class_exists(WasteTripClosingService::class)) require_once __DIR__.'/WasteTripClosingService.php';
            $counters['trip_closing_blocked_unloaded'] = WasteTripClosingService::blockedUnloadedCount($tenant);
        } catch (Throwable) {
            $counters['trip_closing_blocked_unloaded'] = 0;
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

        $legacy = null;
        $warehouseStock = null;
        try {
            if (!class_exists(WasteTripLegacySourceService::class)) require_once __DIR__.'/WasteTripLegacySourceService.php';
            if (!class_exists(WarehouseStockService::class)) require_once __DIR__.'/WarehouseStockService.php';
            $legacy = WasteTripLegacySourceService::connectionFromEnvironment();
            if ($legacy instanceof PDO) {
                $warehouseStock = WarehouseStockService::load($legacy, $tenant);
                $counters['warehouse_stock_problem'] = $warehouseStock['issue_count'];
            } else $counters['warehouse_stock_problem'] = 1;
        } catch (Throwable $error) {
            error_log('warehouse stock navigation count failed: '.$error->getMessage());
            $counters['warehouse_stock_problem'] = 1;
        }

        try {
            if (!class_exists(WasteTripService::class)) require_once __DIR__.'/WasteTripService.php';
            if (!class_exists(WasteTripParityService::class)) require_once __DIR__.'/WasteTripParityService.php';
            $counters['departures_problem'] = WasteTripParityService::attentionCount($tenant, $legacy, $warehouseStock);
        } catch (Throwable $error) {
            error_log('waste trip navigation count failed: '.$error->getMessage());
            $counters['departures_problem'] = 1;
        }

        try {
            $reviewCounts = $tenant->query(
                "SELECT SUM(CASE WHEN COALESCE(cr.review_status,'work')='work' THEN 1 ELSE 0 END) work_trips,
                        SUM(CASE WHEN cr.review_status='review' THEN 1 ELSE 0 END) review_trips
                   FROM waste_trips wt
                   LEFT JOIN waste_trip_completion_reviews cr ON cr.waste_trip_id=wt.id
                  WHERE wt.deleted_at IS NULL AND wt.status IN ('in_progress','completed')"
            )->fetch(PDO::FETCH_ASSOC);
            $counters['trip_completion_problem'] = max(0, (int)($reviewCounts['work_trips'] ?? 0));
            $counters['tn_review_pending'] = max(0, (int)($reviewCounts['review_trips'] ?? 0));
        } catch (Throwable) {
            $counters['trip_completion_problem'] = 0;
            $counters['tn_review_pending'] = 0;
        }

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
            if (!class_exists(FeoPlacementService::class)) {
                require_once base_path('app/Service/FeoPlacementService.php');
            }
            if (!class_exists(FeoLegacyParityService::class)) {
                require_once base_path('app/Service/FeoLegacyParityService.php');
            }
            $placement = new FeoPlacementService();
            if ($placement->moduleReady($tenant)) {
                $consistency = $placement->consistency($tenant);
                $internalProblem = !empty($consistency['is_consistent']) ? 0 : 1;
                $legacyProblem = (new FeoLegacyParityService())->attentionCount($tenant);
                // When legacy parity is available the badge number is the actual
                // request-level mismatch count requested by the OWNER. The older
                // replay/current guard is preserved as a fallback attention flag.
                $counters['feo_placement_problem'] = $legacyProblem > 0 ? $legacyProblem : $internalProblem;
            }
        } catch (Throwable) {
            $counters['feo_placement_problem'] = 1;
        }

        try {
            if (!class_exists(FeoUtilizerService::class)) {
                require_once base_path('app/Service/FeoUtilizerService.php');
            }
            if (!class_exists(FeoMapService::class)) {
                require_once base_path('app/Service/FeoMapService.php');
            }
            $diagnostics = (new FeoMapService())->payload($tenant)['diagnostics'] ?? [];
            $counters['feo_map_problem'] = max(0,
                (int)($diagnostics['missing_coordinates_count'] ?? 0)
                + (int)($diagnostics['invalid_coordinates_count'] ?? 0)
                + (int)($diagnostics['unmatched_utilizer_count'] ?? 0)
                + (int)($diagnostics['inactive_utilizer_count'] ?? 0)
            );
        } catch (Throwable) {
            $counters['feo_map_problem'] = 0;
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
            if (!class_exists(FeoRequestCostService::class)) {
                require_once base_path('app/Service/FeoRequestCostService.php');
            }
            $costService = new FeoRequestCostService();
            if ($costService->moduleReady($tenant)) {
                $counters['feo_cost_problem'] = max(0, (int)(($costService->pageState($tenant)['integrity']['block_count'] ?? 0)));
            }
        } catch (Throwable) {
            $counters['feo_cost_problem'] = 0;
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

    /** @return array<string,int> */
    private static function emptyCounters(): array
    {
        return [
            'bank_attention' => 0,
            'cash_attention' => 0,
            'closing_documents_problem' => 0,
            'trip_closing_blocked_unloaded' => 0,
            'linear_trips_problem' => 0,
            'departures_problem' => 0,
            'warehouse_stock_problem' => 0,
            'trip_completion_problem' => 0,
            'tn_review_pending' => 0,
            'feo_freshness' => 0,
            'feo_placement_problem' => 0,
            'feo_map_problem' => 0,
            'feo_utilizer_problem' => 0,
            'feo_cost_problem' => 0,
            'production_calendar_warning' => 0,
        ];
    }
}
