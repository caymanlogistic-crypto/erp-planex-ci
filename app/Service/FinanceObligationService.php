<?php

namespace App\Service;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class FinanceObligationService
{
    public const DIRECTION_RECEIVABLE = 'RECEIVABLE';
    public const DIRECTION_PAYABLE = 'PAYABLE';

    public const STATUS_WAITING_EVENT = 'waiting_event';
    public const STATUS_CALENDAR_MISSING = 'calendar_missing';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_OVERDUE_PARTIAL = 'overdue_partial';
    public const STATUS_CANCELLED = 'cancelled';

    public static function schemaReady(PDO $pdo): bool
    {
        return self::tableExists($pdo, 'finance_obligations')
            && self::columnExists($pdo, 'finance_invoice_links', 'obligation_id')
            && self::columnExists($pdo, 'finance_operation_allocations', 'obligation_id');
    }

    public static function syncAllLinearRoutes(PDO $pdo): array
    {
        if (!self::schemaReady($pdo)) {
            return ['routes' => 0, 'obligations' => 0];
        }

        $routeIds = $pdo->query("SELECT id FROM linear_routes WHERE deleted_at IS NULL ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;
        foreach ($routeIds as $routeId) {
            $routeId = (int)$routeId;
            $count += self::syncLinearRoute($pdo, $routeId);
            $count += LinearTripServiceFinanceService::syncRoute($pdo, $routeId);
        }
        self::backfillLegacyReferences($pdo);
        return ['routes' => count($routeIds), 'obligations' => $count];
    }

    public static function syncAllOperationalObligations(PDO $pdo): array
    {
        $linear = self::syncAllLinearRoutes($pdo);
        $waste = self::syncAllWasteTrips($pdo);
        return [
            'routes' => (int)($linear['routes'] ?? 0),
            'waste_trips' => (int)($waste['waste_trips'] ?? 0),
            'obligations' => (int)($linear['obligations'] ?? 0) + (int)($waste['obligations'] ?? 0),
        ];
    }

    public static function syncAllWasteTrips(PDO $pdo): array
    {
        if (!self::schemaReady($pdo)
            || !self::tableExists($pdo, 'waste_trips')
            || !self::tableExists($pdo, 'waste_trip_customer_payments')
            || !self::tableExists($pdo, 'waste_trip_carrier_payments')) {
            return ['waste_trips' => 0, 'obligations' => 0];
        }
        $tripIds = $pdo->query('SELECT id FROM waste_trips ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;
        foreach ($tripIds as $tripId) {
            $count += self::syncWasteTrip($pdo, (int)$tripId);
        }
        return ['waste_trips' => count($tripIds), 'obligations' => $count];
    }

    public static function syncWasteTrip(PDO $pdo, int $tripId): int
    {
        if ($tripId <= 0 || !self::schemaReady($pdo)
            || !self::tableExists($pdo, 'waste_trip_customer_payments')
            || !self::tableExists($pdo, 'waste_trip_carrier_payments')) {
            return 0;
        }
        $stmt = $pdo->prepare(
            "SELECT wt.*,
                    c.name AS client_name,c.inn AS client_inn,
                    ct.id AS carrier_contractor_id,ct.name AS carrier_name,ct.inn AS carrier_inn
               FROM waste_trips wt
               JOIN clients c ON c.id=wt.client_id
          LEFT JOIN crews cr ON cr.id=wt.route_executor_id
          LEFT JOIN contractors ct ON ct.id=cr.contractor_id
              WHERE wt.id=? LIMIT 1"
        );
        $stmt->execute([$tripId]);
        $trip = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($trip && (int)($trip['financial_model_version'] ?? 2) === 3) {
            return self::refreshPreservedWasteTripSettlements($pdo, $tripId);
        }
        if (!$trip || (isset($trip['financial_model_version']) && (int)$trip['financial_model_version'] !== 2)) return 0;

        $payments = [];
        foreach ([
            ['table'=>'waste_trip_customer_payments','party_role'=>'customer','source_type'=>'WASTE_TRIP_CUSTOMER_PAYMENT'],
            ['table'=>'waste_trip_carrier_payments','party_role'=>'carrier','source_type'=>'WASTE_TRIP_CARRIER_PAYMENT'],
        ] as $spec) {
            $rows = $pdo->prepare("SELECT * FROM {$spec['table']} WHERE waste_trip_id=? ORDER BY sort_order,id");
            $rows->execute([$tripId]);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['_party_role']=$spec['party_role'];
                $row['_source_type']=$spec['source_type'];
                $payments[]=$row;
            }
        }

        $activeKeys = [];
        if ((string)($trip['status'] ?? '') !== 'cancelled') {
            foreach ($payments as $payment) {
                if ($payment['amount'] === null) continue;
                $role = (string)$payment['_party_role'];
                $counterparty = $role === 'customer'
                    ? ['type'=>'client','id'=>(int)$trip['client_id'],'name'=>(string)$trip['client_name'],'inn'=>(string)($trip['client_inn']??'')]
                    : (((int)($trip['carrier_contractor_id']??0) > 0)
                        ? ['type'=>'contractor','id'=>(int)$trip['carrier_contractor_id'],'name'=>(string)$trip['carrier_name'],'inn'=>(string)($trip['carrier_inn']??'')]
                        : null);
                if ($counterparty === null) continue;
                $sourceKey = implode(':',['WASTE_TRIP',$tripId,$role,$counterparty['type'],$counterparty['id'],(int)($payment['sort_order']??1)]);
                $activeKeys[] = $sourceKey;
                $conditionType = trim((string)($payment['condition_type'] ?? ''));
                $daysCount = $payment['days_count'] !== null ? (int)$payment['days_count'] : null;
                $daysKind = trim((string)($payment['days_kind'] ?? 'calendar')) ?: 'calendar';
                $specificDueDate = self::validDateOrNull($payment['specific_due_date'] ?? null);
                $dateContext = [
                    'planned_loading_date' => self::validDateOrNull($trip['planned_start_date'] ?? null) ?? self::validDateOrNull($trip['planned_start_date_from'] ?? null),
                    'planned_unloading_date' => self::validDateOrNull($trip['planned_finish_date'] ?? null),
                    'actual_loading_date' => self::validDateOrNull($trip['actual_start_date'] ?? null),
                    'actual_unloading_date' => self::validDateOrNull($trip['actual_end_date'] ?? null),
                    'closing_documents_received_date' => null,
                ];
                $resolved = self::resolveDates($pdo,$dateContext,$conditionType,$daysCount,$daysKind,$specificDueDate);
                $amount = self::money((string)($payment['amount'] ?? '0.00'));
                $paidAmount = self::paidAmountForSourceKey($pdo,$sourceKey);
                $status = self::computeStatus($amount,$paidAmount,$resolved['due_date'],$resolved['state']);
                $existing = self::findBySourceKey($pdo,$sourceKey);
                $values = [
                    (int)$payment['id'],$tripId,$role === 'carrier' ? self::DIRECTION_PAYABLE : self::DIRECTION_RECEIVABLE,$role,
                    $counterparty['type'],$counterparty['id'],$counterparty['name'],$counterparty['inn'],$amount,
                    $conditionType !== '' ? $conditionType : null,$daysCount,$daysKind,$specificDueDate,
                    $resolved['event_date'],$resolved['forecast_due_date'],$resolved['due_date'],$paidAmount,$status,
                    (int)($payment['updated_by_user_id']??0) ?: null,(string)($payment['updated_by_role']??'') ?: 'system',
                ];
                if ($existing) {
                    $upd=$pdo->prepare(
                        "UPDATE finance_obligations SET source_type=?,source_id=?,source_parent_type='WASTE_TRIP',source_parent_id=?,direction=?,party_role=?,
                         counterparty_entity_type=?,counterparty_entity_id=?,counterparty_name=?,counterparty_inn=?,amount=?,condition_type=?,days_count=?,days_kind=?,specific_due_date=?,
                         event_date=?,forecast_due_date=?,due_date=?,paid_amount=?,status=?,cancelled_at=NULL,updated_by_user_id=?,updated_by_role=?,updated_at=NOW() WHERE id=?"
                    );
                    $upd->execute(array_merge([(string)$payment['_source_type']],$values,[(int)$existing['id']]));
                } else {
                    $ins=$pdo->prepare(
                        "INSERT INTO finance_obligations
                         (source_type,source_key,source_id,source_parent_type,source_parent_id,direction,party_role,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,
                          amount,condition_type,days_count,days_kind,specific_due_date,event_date,forecast_due_date,due_date,paid_amount,status,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role)
                         VALUES (?,?,?,'WASTE_TRIP',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                    );
                    $ins->execute([
                        (string)$payment['_source_type'],$sourceKey,(int)$payment['id'],$tripId,
                        $role === 'carrier' ? self::DIRECTION_PAYABLE : self::DIRECTION_RECEIVABLE,$role,
                        $counterparty['type'],$counterparty['id'],$counterparty['name'],$counterparty['inn'],$amount,
                        $conditionType !== '' ? $conditionType : null,$daysCount,$daysKind,$specificDueDate,
                        $resolved['event_date'],$resolved['forecast_due_date'],$resolved['due_date'],$paidAmount,$status,
                        (int)($payment['created_by_user_id']??0) ?: null,(string)($payment['created_by_role']??'') ?: 'system',
                        (int)($payment['updated_by_user_id']??0) ?: null,(string)($payment['updated_by_role']??'') ?: 'system',
                    ]);
                }
            }
        }

        $stale=$pdo->prepare("SELECT id,source_key FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id=? AND source_type IN ('WASTE_TRIP_CUSTOMER_PAYMENT','WASTE_TRIP_CARRIER_PAYMENT') AND cancelled_at IS NULL");
        $stale->execute([$tripId]);
        foreach ($stale->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array((string)$row['source_key'],$activeKeys,true)) {
                $pdo->prepare("UPDATE finance_obligations SET status='cancelled',cancelled_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
            }
        }
        return count($activeKeys);
    }

    /** Historical contractual amounts/terms stay fixed; subsequent real payments still settle them. */
    private static function refreshPreservedWasteTripSettlements(PDO $pdo, int $tripId): int
    {
        $query = $pdo->prepare("SELECT * FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id=? AND cancelled_at IS NULL");
        $query->execute([$tripId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $paid = self::paidAmountForSourceKey($pdo, (string)$row['source_key']);
            $state = $row['due_date'] === null ? 'waiting_event' : 'ready';
            if ($row['status'] === self::STATUS_CALENDAR_MISSING) $state = 'calendar_missing';
            $status = self::computeStatus((string)$row['amount'], $paid, $row['due_date'], $state);
            if ($paid !== $row['paid_amount'] || $status !== $row['status']) {
                $pdo->prepare('UPDATE finance_obligations SET paid_amount=?,status=?,updated_at=NOW() WHERE id=?')
                    ->execute([$paid,$status,(int)$row['id']]);
            }
        }
        return count($rows);
    }

    public static function enrichParentLabels(PDO $pdo, array &$rows): void
    {
        $wasteIds=[];
        foreach ($rows as $row) if ((string)($row['source_parent_type']??'')==='WASTE_TRIP') $wasteIds[]=(int)($row['source_parent_id']??0);
        $wasteIds=array_values(array_unique(array_filter($wasteIds)));
        $wasteMap=[];
        if ($wasteIds!==[] && self::tableExists($pdo,'waste_trips')) {
            $marks=implode(',',array_fill(0,count($wasteIds),'?'));
            $stmt=$pdo->prepare("SELECT id,business_id FROM waste_trips WHERE id IN ({$marks})");
            $stmt->execute($wasteIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $wasteMap[(int)$row['id']]=(int)$row['business_id'];
        }
        foreach ($rows as &$row) {
            $type=(string)($row['source_parent_type']??'');$id=(int)($row['source_parent_id']??0);
            $row['trip_business_id']=$type==='WASTE_TRIP' ? ($wasteMap[$id]??null) : null;
            $row['parent_label']=$type==='WASTE_TRIP'
                ? 'Перевозка отходов #'.($wasteMap[$id]??$id)
                : ($type==='LINEAR_ROUTE' ? 'Рейс #'.$id : ($type!=='' ? $type.' #'.$id : 'Платёжное обязательство'));
            $row['route_label']=$row['parent_label'];
        }
        unset($row);
    }

    public static function syncLinearRoute(PDO $pdo, int $routeId): int
    {
        if ($routeId <= 0 || !self::schemaReady($pdo)) {
            return 0;
        }

        $routeStmt = $pdo->prepare(
            "SELECT lr.*,
                    c.name AS client_name, c.inn AS client_inn,
                    ct.name AS carrier_name, ct.inn AS carrier_inn
               FROM linear_routes lr
               JOIN clients c ON c.id=lr.client_id
               JOIN contractors ct ON ct.id=lr.carrier_contractor_id
              WHERE lr.id=? AND lr.deleted_at IS NULL
              LIMIT 1"
        );
        $routeStmt->execute([$routeId]);
        $route = $routeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$route) {
            return 0;
        }

        $paymentStmt = $pdo->prepare(
            "SELECT p.*,
                    rp.principal_type,
                    rp.principal_id,
                    rp.sort_order AS principal_sort_order,
                    pc.name AS principal_client_name,
                    pc.inn AS principal_client_inn,
                    pct.name AS principal_contractor_name,
                    pct.inn AS principal_contractor_inn
               FROM linear_route_payments p
          LEFT JOIN linear_route_principals rp
                 ON rp.id=p.linear_route_principal_id AND rp.deleted_at IS NULL
          LEFT JOIN clients pc
                 ON rp.principal_type='client' AND pc.id=rp.principal_id
          LEFT JOIN contractors pct
                 ON rp.principal_type='contractor' AND pct.id=rp.principal_id
              WHERE p.linear_route_id=? AND p.deleted_at IS NULL
              ORDER BY p.party_role, COALESCE(rp.sort_order,0), p.sort_order, p.id"
        );
        $paymentStmt->execute([$routeId]);
        $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

        $activeKeys = [];
        foreach ($payments as $payment) {
            $counterparty = self::resolvePaymentCounterparty($route, $payment);
            if ($counterparty === null) {
                continue;
            }
            $direction = self::paymentDirection($payment);
            $sourceKey = self::linearSourceKey($routeId, $payment, $counterparty);
            $activeKeys[] = $sourceKey;

            $conditionType = trim((string)($payment['condition_type'] ?? ''));
            if ($conditionType === '') {
                $conditionType = match (trim((string)($payment['payment_due_type'] ?? ''))) {
                    'После загрузки' => DateCalculationService::CONDITION_AFTER_START,
                    'После выгрузки' => DateCalculationService::CONDITION_AFTER_END,
                    default => '',
                };
            }
            $daysCount = $payment['days_count'] !== null
                ? (int)$payment['days_count']
                : ($payment['payment_due_days'] !== null ? (int)$payment['payment_due_days'] : null);
            $daysKind = trim((string)($payment['days_kind'] ?? $payment['payment_due_days_kind'] ?? 'calendar')) ?: 'calendar';
            $specificDueDate = self::validDateOrNull($payment['specific_due_date'] ?? null);

            $resolved = self::resolveDates($pdo, $route, $conditionType, $daysCount, $daysKind, $specificDueDate);
            $paidAmount = self::paidAmountForSourceKey($pdo, $sourceKey);
            $amount = self::money((string)($payment['amount'] ?? '0.00'));
            $status = self::computeStatus($amount, $paidAmount, $resolved['due_date'], $resolved['state']);

            $existing = self::findBySourceKey($pdo, $sourceKey);
            if ($existing) {
                $stmt = $pdo->prepare(
                    "UPDATE finance_obligations
                        SET source_id=?, source_parent_id=?, direction=?, party_role=?,
                            counterparty_entity_type=?, counterparty_entity_id=?, counterparty_name=?, counterparty_inn=?,
                            amount=?, condition_type=?, days_count=?, days_kind=?, specific_due_date=?,
                            event_date=?, forecast_due_date=?, due_date=?, paid_amount=?, status=?, cancelled_at=NULL,
                            updated_by_user_id=?, updated_by_role=?, updated_at=NOW()
                      WHERE id=?"
                );
                $stmt->execute([
                    (int)$payment['id'], $routeId, $direction, (string)$payment['party_role'],
                    $counterparty['type'], $counterparty['id'], $counterparty['name'], $counterparty['inn'],
                    $amount, $conditionType !== '' ? $conditionType : null, $daysCount, $daysKind, $specificDueDate,
                    $resolved['event_date'], $resolved['forecast_due_date'], $resolved['due_date'], $paidAmount, $status,
                    (int)($payment['updated_by_user_id'] ?? 0) ?: null,
                    (string)($payment['updated_by_role'] ?? '') ?: 'system',
                    (int)$existing['id'],
                ]);
                $obligationId = (int)$existing['id'];
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO finance_obligations
                        (source_type,source_key,source_id,source_parent_type,source_parent_id,direction,party_role,
                         counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,
                         amount,condition_type,days_count,days_kind,specific_due_date,event_date,forecast_due_date,due_date,
                         paid_amount,status,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role)
                     VALUES
                        ('LINEAR_ROUTE_PAYMENT',? ,?,'LINEAR_ROUTE',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->execute([
                    $sourceKey, (int)$payment['id'], $routeId, $direction, (string)$payment['party_role'],
                    $counterparty['type'], $counterparty['id'], $counterparty['name'], $counterparty['inn'],
                    $amount, $conditionType !== '' ? $conditionType : null, $daysCount, $daysKind, $specificDueDate,
                    $resolved['event_date'], $resolved['forecast_due_date'], $resolved['due_date'], $paidAmount, $status,
                    (int)($payment['created_by_user_id'] ?? 0) ?: null,
                    (string)($payment['created_by_role'] ?? '') ?: 'system',
                    (int)($payment['updated_by_user_id'] ?? 0) ?: null,
                    (string)($payment['updated_by_role'] ?? '') ?: 'system',
                ]);
                $obligationId = (int)$pdo->lastInsertId();
            }

            self::attachLegacyReferencesForCurrentSource($pdo, $obligationId, (int)$payment['id']);
            self::updateCurrentRoutePayment($pdo, (int)$payment['id'], $paidAmount, $status, $resolved['forecast_due_date'], $resolved['due_date']);
        }

        $staleStmt = $pdo->prepare(
            "SELECT id,source_key FROM finance_obligations
              WHERE source_type='LINEAR_ROUTE_PAYMENT'
                AND source_parent_type='LINEAR_ROUTE'
                AND source_parent_id=?
                AND cancelled_at IS NULL"
        );
        $staleStmt->execute([$routeId]);
        foreach ($staleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array((string)$row['source_key'], $activeKeys, true)) {
                $pdo->prepare("UPDATE finance_obligations SET status='cancelled',cancelled_at=NOW(),updated_at=NOW() WHERE id=?")
                    ->execute([(int)$row['id']]);
            }
        }

        return count($activeKeys);
    }

    public static function fetchCompatibleForInvoice(
        PDO $pdo,
        string $invoiceDirection,
        ?string $counterpartyType,
        ?int $counterpartyId,
        ?int $invoiceId = null
    ): array {
        if (!self::schemaReady($pdo)) {
            return [];
        }
        self::syncAllOperationalObligations($pdo);
        $direction = strtoupper($invoiceDirection) === FinanceInvoiceService::DIRECTION_INCOMING
            ? self::DIRECTION_PAYABLE
            : self::DIRECTION_RECEIVABLE;

        $sql = "SELECT o.*,
                       lr.planned_loading_date, lr.planned_unloading_date,
                       COALESCE((SELECT SUM(l.amount) FROM finance_invoice_links l
                                 JOIN finance_invoices i ON i.id=l.invoice_id
                                WHERE l.obligation_id=o.id AND i.status<>'cancelled' AND (? IS NULL OR i.id<>?)),0) AS linked_other_amount,
                       COALESCE((SELECT SUM(l2.amount) FROM finance_invoice_links l2
                                WHERE l2.obligation_id=o.id AND l2.invoice_id=?),0) AS current_invoice_link_amount
                  FROM finance_obligations o
             LEFT JOIN linear_routes lr ON o.source_parent_type='LINEAR_ROUTE' AND lr.id=o.source_parent_id
                 WHERE o.direction=? AND o.cancelled_at IS NULL";
        $params = [$invoiceId, $invoiceId, $invoiceId, $direction];
        if ($counterpartyType !== null && $counterpartyType !== '' && $counterpartyId !== null && $counterpartyId > 0) {
            $sql .= ' AND o.counterparty_entity_type=? AND o.counterparty_entity_id=?';
            $params[] = $counterpartyType;
            $params[] = $counterpartyId;
        }
        $sql .= ' ORDER BY COALESCE(o.due_date,o.forecast_due_date) ASC, o.source_parent_id DESC, o.id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::enrichParentLabels($pdo,$rows);
        foreach ($rows as &$row) {
            $row['available_to_invoice'] = self::subMoney((string)$row['amount'], (string)$row['linked_other_amount']);
            $row['condition_label'] = DateCalculationService::CONDITION_LABELS[(string)($row['condition_type'] ?? '')] ?? 'Условие оплаты';
            $row['direction_label'] = $row['direction'] === self::DIRECTION_RECEIVABLE ? 'К получению' : 'К оплате';
        }
        unset($row);
        return $rows;
    }

    public static function replaceInvoiceLinks(PDO $pdo, int $invoiceId, array $rows, array $user): void
    {
        if (!self::schemaReady($pdo)) {
            throw new RuntimeException('Схема платёжных обязательств не установлена.');
        }
        self::syncAllOperationalObligations($pdo);

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare('SELECT * FROM finance_invoices WHERE id=? FOR UPDATE');
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new RuntimeException('Счёт не найден.');
            }

            $requestedRows = [];
            $requestedLinkMap = [];
            foreach ($rows as $row) {
                $obligationId = (int)($row['obligation_id'] ?? 0);
                $amount = self::normalizePositiveMoney((string)($row['amount'] ?? ''));
                if ($obligationId <= 0 || $amount === null) {
                    continue;
                }
                if (isset($requestedLinkMap[$obligationId])) {
                    throw new RuntimeException('Одно обязательство нельзя добавить в счёт дважды.');
                }
                $requestedRows[] = ['obligation_id' => $obligationId, 'amount' => $amount];
                $requestedLinkMap[$obligationId] = $amount;
            }
            ksort($requestedLinkMap, SORT_NUMERIC);

            $paidStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(a.amount),0)
                   FROM finance_operation_allocations a
                   JOIN finance_operations op ON op.id=a.operation_id
                  WHERE a.invoice_id=?
                    AND a.cancelled_at IS NULL
                    AND op.status='POSTED'"
            );
            $paidStmt->execute([$invoiceId]);
            $actualPaid = self::money((string)$paidStmt->fetchColumn());

            $currentStmt = $pdo->prepare(
                'SELECT obligation_id,amount
                   FROM finance_invoice_links
                  WHERE invoice_id=?
                  ORDER BY obligation_id,id
                  FOR UPDATE'
            );
            $currentStmt->execute([$invoiceId]);
            $currentLinkMap = [];
            $currentLinkRows = $currentStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($currentLinkRows as $currentLink) {
                $obligationId = (int)($currentLink['obligation_id'] ?? 0);
                if ($obligationId <= 0) {
                    continue;
                }
                $amount = self::normalizePositiveMoney((string)($currentLink['amount'] ?? ''));
                if ($amount === null) {
                    $currentLinkMap[$obligationId] = null;
                    continue;
                }
                if (array_key_exists($obligationId, $currentLinkMap)) {
                    if ($currentLinkMap[$obligationId] !== null) {
                        $currentLinkMap[$obligationId] = self::addMoney((string)$currentLinkMap[$obligationId], $amount);
                    }
                } else {
                    $currentLinkMap[$obligationId] = $amount;
                }
            }
            ksort($currentLinkMap, SORT_NUMERIC);
            $sameCanonicalLinks = $currentLinkMap === $requestedLinkMap
                && count($currentLinkRows) === count($requestedLinkMap);

            if (self::compareMoney($actualPaid, '0.00') > 0 && $currentLinkMap !== $requestedLinkMap) {
                throw new RuntimeException('После поступления оплаты нельзя менять привязки счёта к платёжным обязательствам.');
            }

            $expectedDirection = strtoupper((string)$invoice['direction']) === FinanceInvoiceService::DIRECTION_INCOMING
                ? self::DIRECTION_PAYABLE
                : self::DIRECTION_RECEIVABLE;
            $cleanRows = [];
            $total = '0.00';
            foreach ($requestedRows as $row) {
                $obligationId = (int)$row['obligation_id'];
                $amount = (string)$row['amount'];
                $obStmt = $pdo->prepare('SELECT * FROM finance_obligations WHERE id=? AND cancelled_at IS NULL FOR UPDATE');
                $obStmt->execute([$obligationId]);
                $ob = $obStmt->fetch(PDO::FETCH_ASSOC);
                if (!$ob) {
                    throw new RuntimeException('Платёжное обязательство не найдено.');
                }
                if ((string)$ob['direction'] !== $expectedDirection) {
                    throw new RuntimeException('Направление обязательства не соответствует направлению счёта.');
                }
                if (($invoice['counterparty_entity_type'] ?? null) !== null && ($invoice['counterparty_entity_id'] ?? null) !== null) {
                    if ((string)$ob['counterparty_entity_type'] !== (string)$invoice['counterparty_entity_type']
                        || (int)$ob['counterparty_entity_id'] !== (int)$invoice['counterparty_entity_id']) {
                        throw new RuntimeException('Все обязательства счёта должны относиться к выбранному контрагенту.');
                    }
                }
                $capStmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(l.amount),0)
                       FROM finance_invoice_links l
                       JOIN finance_invoices i ON i.id=l.invoice_id
                      WHERE l.obligation_id=? AND l.invoice_id<>? AND i.status<>'cancelled'"
                );
                $capStmt->execute([$obligationId, $invoiceId]);
                $already = (string)$capStmt->fetchColumn();
                $capacity = self::subMoney((string)$ob['amount'], $already);
                // An identical re-save does not consume any additional capacity. Another invoice
                // may have claimed the obligation since this invoice was originally linked.
                if (!$sameCanonicalLinks && self::compareMoney($amount, $capacity) > 0) {
                    throw new RuntimeException('Сумма связи превышает свободный остаток обязательства «' . ($ob['counterparty_name'] ?? '') . '».');
                }
                $cleanRows[$obligationId] = ['obligation' => $ob, 'amount' => $amount];
                $total = self::addMoney($total, $amount);
            }

            if ($cleanRows !== [] && self::compareMoney($total, (string)$invoice['amount']) !== 0) {
                throw new RuntimeException('Сумма связей с обязательствами должна быть равна сумме счёта.');
            }

            if ($sameCanonicalLinks) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return;
            }

            $pdo->prepare('DELETE FROM finance_invoice_links WHERE invoice_id=?')->execute([$invoiceId]);
            $insert = $pdo->prepare(
                "INSERT INTO finance_invoice_links
                    (invoice_id,obligation_id,linear_route_id,linear_route_payment_id,amount,side,created_by_user_id,created_by_role)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            foreach ($cleanRows as $item) {
                $ob = $item['obligation'];
                $side = (string)$ob['direction'] === self::DIRECTION_RECEIVABLE ? 'customer' : 'carrier';
                $insert->execute([
                    $invoiceId,
                    (int)$ob['id'],
                    (string)$ob['source_parent_type'] === 'LINEAR_ROUTE' ? (int)$ob['source_parent_id'] : null,
                    (string)$ob['source_type'] === 'LINEAR_ROUTE_PAYMENT' ? (int)$ob['source_id'] : null,
                    $item['amount'],
                    $side,
                    (int)($user['user_id'] ?? 0) ?: null,
                    (string)($user['role_code'] ?? '') ?: 'company_owner',
                ]);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function invoiceLinks(PDO $pdo, int $invoiceId): array
    {
        if (!self::schemaReady($pdo)) {
            return [];
        }
        self::backfillLegacyReferences($pdo);
        $stmt = $pdo->prepare(
            "SELECT l.*,o.source_key,o.source_parent_id,o.counterparty_name,o.amount AS obligation_amount,
                    o.due_date,o.forecast_due_date,o.condition_type,o.status AS obligation_status
               FROM finance_invoice_links l
          LEFT JOIN finance_obligations o ON o.id=l.obligation_id
              WHERE l.invoice_id=? ORDER BY l.id"
        );
        $stmt->execute([$invoiceId]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        self::enrichParentLabels($pdo,$rows);
        return $rows;
    }

    public static function autoAllocateIncomingCustomerReceipts(PDO $pdo, array $user): array
    {
        // Customer receipts require an explicit invoice choice. Keep the legacy
        // entry point inert so older callers cannot silently settle by number
        // or by a coincidentally equal amount.
        return ['operations' => 0, 'allocations' => 0, 'amount' => '0.00'];
    }

    public static function receivablesReport(PDO $pdo): array
    {
        if (!self::schemaReady($pdo)) {
            return ['rows' => [], 'summary' => self::emptyReceivablesSummary()];
        }
        self::syncAllOperationalObligations($pdo);
        $stmt = $pdo->query(
            "SELECT o.*,
                    COALESCE((SELECT SUM(l.amount) FROM finance_invoice_links l JOIN finance_invoices i ON i.id=l.invoice_id WHERE l.obligation_id=o.id AND i.status<>'cancelled'),0) AS invoiced_amount,
                    COALESCE((SELECT GROUP_CONCAT(DISTINCT i.number ORDER BY i.invoice_date SEPARATOR ', ') FROM finance_invoice_links l JOIN finance_invoices i ON i.id=l.invoice_id WHERE l.obligation_id=o.id AND i.status<>'cancelled'),'') AS invoice_numbers
               FROM finance_obligations o
              WHERE o.direction='RECEIVABLE' AND o.cancelled_at IS NULL
              ORDER BY CASE WHEN o.due_date IS NULL THEN 1 ELSE 0 END,o.due_date,o.id"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::enrichParentLabels($pdo,$rows);
        $summary = self::emptyReceivablesSummary();
        $today = new DateTimeImmutable('today');
        foreach ($rows as &$row) {
            $row['remaining_amount'] = self::subMoney((string)$row['amount'], (string)$row['paid_amount']);
            $remaining = (string)$row['remaining_amount'];
            $summary['total'] = self::addMoney($summary['total'], $remaining);
            $due = self::validDateOrNull($row['due_date'] ?? null);
            $days = 0;
            if ($due !== null && self::compareMoney($remaining, '0.00') > 0) {
                $dueDate = new DateTimeImmutable($due);
                if ($dueDate < $today) {
                    $days = (int)$dueDate->diff($today)->format('%a');
                    $summary['overdue'] = self::addMoney($summary['overdue'], $remaining);
                    if ($days <= 7) $summary['aging_1_7'] = self::addMoney($summary['aging_1_7'], $remaining);
                    elseif ($days <= 30) $summary['aging_8_30'] = self::addMoney($summary['aging_8_30'], $remaining);
                    elseif ($days <= 60) $summary['aging_31_60'] = self::addMoney($summary['aging_31_60'], $remaining);
                    else $summary['aging_61_plus'] = self::addMoney($summary['aging_61_plus'], $remaining);
                }
            }
            $row['overdue_days'] = $days;
            $row['condition_label'] = DateCalculationService::CONDITION_LABELS[(string)($row['condition_type'] ?? '')] ?? '—';
        }
        unset($row);
        return ['rows' => $rows, 'summary' => $summary];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_WAITING_EVENT => 'Ожидается событие',
            self::STATUS_CALENDAR_MISSING => 'Нужен производственный календарь',
            self::STATUS_PLANNED => 'Ожидается оплата',
            self::STATUS_PARTIALLY_PAID => 'Частично оплачено',
            self::STATUS_PAID => 'Оплачено',
            self::STATUS_OVERDUE => 'Просрочено',
            self::STATUS_OVERDUE_PARTIAL => 'Частично оплачено, просрочено',
            self::STATUS_CANCELLED => 'Отменено',
            default => $status,
        };
    }

    private static function resolvePaymentCounterparty(array $route, array $payment): ?array
    {
        return match ((string)($payment['party_role'] ?? '')) {
            'customer' => ['type' => 'client', 'id' => (int)$route['client_id'], 'name' => (string)$route['client_name'], 'inn' => (string)($route['client_inn'] ?? '')],
            'carrier' => ['type' => 'contractor', 'id' => (int)$route['carrier_contractor_id'], 'name' => (string)$route['carrier_name'], 'inn' => (string)($route['carrier_inn'] ?? '')],
            'principal' => ($payment['principal_type'] ?? null) && (int)($payment['principal_id'] ?? 0) > 0
                ? [
                    'type' => (string)$payment['principal_type'],
                    'id' => (int)$payment['principal_id'],
                    'name' => (string)(($payment['principal_type'] === 'client') ? ($payment['principal_client_name'] ?? '') : ($payment['principal_contractor_name'] ?? '')),
                    'inn' => (string)(($payment['principal_type'] === 'client') ? ($payment['principal_client_inn'] ?? '') : ($payment['principal_contractor_inn'] ?? '')),
                ]
                : null,
            default => null,
        };
    }

    private static function paymentDirection(array $payment): string
    {
        $side = strtolower(trim((string)($payment['side'] ?? '')));
        if ($side === 'expense') return self::DIRECTION_PAYABLE;
        if ($side === 'income') return self::DIRECTION_RECEIVABLE;
        return (string)($payment['party_role'] ?? '') === 'carrier' ? self::DIRECTION_PAYABLE : self::DIRECTION_RECEIVABLE;
    }

    private static function linearSourceKey(int $routeId, array $payment, array $counterparty): string
    {
        return implode(':', [
            'LINEAR_ROUTE', $routeId,
            (string)($payment['party_role'] ?? ''),
            $counterparty['type'], $counterparty['id'],
            (int)($payment['principal_sort_order'] ?? 0),
            (int)($payment['sort_order'] ?? 1),
        ]);
    }

    private static function resolveDates(PDO $pdo, array $route, string $conditionType, ?int $daysCount, string $daysKind, ?string $specificDueDate): array
    {
        $plannedStart = self::validDateOrNull($route['planned_loading_date'] ?? null);
        $plannedEnd = self::validDateOrNull($route['planned_unloading_date'] ?? null);
        $actualStart = self::validDateOrNull($route['actual_loading_date'] ?? null);
        $actualEnd = self::validDateOrNull($route['actual_unloading_date'] ?? null);
        $docsDate = self::validDateOrNull($route['closing_documents_received_date'] ?? null);

        $forecastBase = null;
        $actualBase = null;
        $state = 'ok';
        switch ($conditionType) {
            case DateCalculationService::CONDITION_PREPAYMENT:
            case DateCalculationService::CONDITION_SPECIFIC_DATE:
                return ['event_date' => $specificDueDate, 'forecast_due_date' => $specificDueDate, 'due_date' => $specificDueDate, 'state' => $specificDueDate ? 'ok' : 'waiting_event'];
            case DateCalculationService::CONDITION_START_DAY:
                $forecastBase = $plannedStart; $actualBase = $actualStart; break;
            case DateCalculationService::CONDITION_AFTER_START:
                $forecastBase = $plannedStart; $actualBase = $actualStart; break;
            case DateCalculationService::CONDITION_END_DAY:
                $forecastBase = $plannedEnd; $actualBase = $actualEnd; break;
            case DateCalculationService::CONDITION_AFTER_END:
                $forecastBase = $plannedEnd; $actualBase = $actualEnd; break;
            case DateCalculationService::CONDITION_AFTER_DOCUMENTS:
                if ($docsDate === null) {
                    return ['event_date' => null, 'forecast_due_date' => null, 'due_date' => null, 'state' => 'waiting_event'];
                }
                $actualBase = $docsDate; break;
            default:
                return ['event_date' => null, 'forecast_due_date' => null, 'due_date' => null, 'state' => 'waiting_event'];
        }

        if ($actualBase === null && in_array($conditionType, [
            DateCalculationService::CONDITION_START_DAY,
            DateCalculationService::CONDITION_AFTER_START,
            DateCalculationService::CONDITION_END_DAY,
            DateCalculationService::CONDITION_AFTER_END,
        ], true)) {
            $state = 'waiting_event';
        }

        $requiresDays = in_array($conditionType, [DateCalculationService::CONDITION_AFTER_START, DateCalculationService::CONDITION_AFTER_END, DateCalculationService::CONDITION_AFTER_DOCUMENTS], true);
        $offset = $requiresDays ? max(0, (int)$daysCount) : 0;
        try {
            $forecast = $forecastBase !== null ? self::addDays($pdo, $forecastBase, $offset, $daysKind) : null;
            $due = $actualBase !== null ? self::addDays($pdo, $actualBase, $offset, $daysKind) : null;
        } catch (RuntimeException $e) {
            if ($daysKind === DateCalculationService::DAYS_KIND_WORKING && str_contains($e->getMessage(), 'Производственный календарь')) {
                $state = 'calendar_missing';
                $forecast = null; $due = null;
            } else {
                throw $e;
            }
        }
        return ['event_date' => $actualBase, 'forecast_due_date' => $forecast, 'due_date' => $due, 'state' => $state];
    }

    private static function addDays(PDO $pdo, string $base, int $days, string $kind): string
    {
        if ($days <= 0) return $base;
        if ($kind === DateCalculationService::DAYS_KIND_WORKING) {
            return ProductionCalendarService::addWorkingDays($pdo, $base, $days);
        }
        return (new DateTimeImmutable($base))->modify('+' . $days . ' days')->format('Y-m-d');
    }

    private static function computeStatus(string $amount, string $paid, ?string $due, string $state): string
    {
        $remaining = self::subMoney($amount, $paid);
        if (self::compareMoney($remaining, '0.00') <= 0) return self::STATUS_PAID;
        if ($state === 'calendar_missing') return self::STATUS_CALENDAR_MISSING;
        if ($state === 'waiting_event' || $due === null) return self::STATUS_WAITING_EVENT;
        $overdue = $due < date('Y-m-d');
        if (self::compareMoney($paid, '0.00') > 0) return $overdue ? self::STATUS_OVERDUE_PARTIAL : self::STATUS_PARTIALLY_PAID;
        return $overdue ? self::STATUS_OVERDUE : self::STATUS_PLANNED;
    }

    private static function paidAmountForSourceKey(PDO $pdo, string $sourceKey): string
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(a.amount),0)
               FROM finance_operation_allocations a
               JOIN finance_operations op ON op.id=a.operation_id
               JOIN finance_obligations o ON o.id=a.obligation_id
              WHERE o.source_key=? AND a.cancelled_at IS NULL AND op.status='POSTED'"
        );
        $stmt->execute([$sourceKey]);
        return self::money((string)$stmt->fetchColumn());
    }

    private static function updateCurrentRoutePayment(PDO $pdo, int $paymentId, string $paid, string $status, ?string $forecast, ?string $due): void
    {
        $routeStatus = match ($status) {
            self::STATUS_PAID => RoutePaymentStatusService::STATUS_PAID,
            self::STATUS_PARTIALLY_PAID => RoutePaymentStatusService::STATUS_PARTIALLY_PAID,
            self::STATUS_OVERDUE, self::STATUS_OVERDUE_PARTIAL => RoutePaymentStatusService::STATUS_OVERDUE,
            self::STATUS_WAITING_EVENT => RoutePaymentStatusService::STATUS_WAITING_EVENT,
            default => RoutePaymentStatusService::STATUS_PLANNED,
        };
        $stmt = $pdo->prepare(
            "UPDATE linear_route_payments
                SET paid_amount=?, payment_status=?, forecast_due_date=?, calculated_due_date=?,
                    paid_at=CASE WHEN ?='paid' THEN COALESCE(paid_at,CURDATE()) ELSE NULL END,
                    status_updated_at=NOW()
              WHERE id=? AND deleted_at IS NULL"
        );
        $stmt->execute([$paid, $routeStatus, $forecast, $due, $routeStatus, $paymentId]);
    }

    private static function backfillLegacyReferences(PDO $pdo): void
    {
        if (!self::schemaReady($pdo)) return;
        $pdo->exec(
            "UPDATE finance_invoice_links l
               JOIN finance_obligations o ON o.source_type='LINEAR_ROUTE_PAYMENT' AND o.source_id=l.linear_route_payment_id
                SET l.obligation_id=o.id
              WHERE l.obligation_id IS NULL AND l.linear_route_payment_id IS NOT NULL"
        );
        $pdo->exec(
            "UPDATE finance_operation_allocations a
               JOIN finance_obligations o ON o.source_type='LINEAR_ROUTE_PAYMENT' AND o.source_id=a.linear_route_payment_id
                SET a.obligation_id=o.id
              WHERE a.obligation_id IS NULL AND a.linear_route_payment_id IS NOT NULL"
        );
    }

    private static function attachLegacyReferencesForCurrentSource(PDO $pdo, int $obligationId, int $paymentId): void
    {
        $pdo->prepare('UPDATE finance_invoice_links SET obligation_id=? WHERE obligation_id IS NULL AND linear_route_payment_id=?')
            ->execute([$obligationId, $paymentId]);
        $pdo->prepare('UPDATE finance_operation_allocations SET obligation_id=? WHERE obligation_id IS NULL AND linear_route_payment_id=?')
            ->execute([$obligationId, $paymentId]);
    }

    private static function findBySourceKey(PDO $pdo, string $sourceKey): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM finance_obligations WHERE source_key=? LIMIT 1');
        $stmt->execute([$sourceKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function emptyReceivablesSummary(): array
    {
        return ['total'=>'0.00','overdue'=>'0.00','aging_1_7'=>'0.00','aging_8_30'=>'0.00','aging_31_60'=>'0.00','aging_61_plus'=>'0.00'];
    }

    private static function validDateOrNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
            $stmt->execute([$table,$column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private static function normalizePositiveMoney(string $value): ?string
    {
        $value = str_replace([' ', ','], ['', '.'], trim($value));
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $value)) return null;
        $value = self::money($value);
        return self::compareMoney($value, '0.00') > 0 ? $value : null;
    }

    private static function money(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) return '0.00';
        $negative = str_starts_with($value, '-');
        if ($negative) $value = substr($value, 1);
        [$whole,$fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0'); if ($whole === '') $whole='0';
        $fraction = str_pad(substr($fraction,0,2),2,'0');
        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }

    private static function cents(string $value): int
    {
        $value = self::money($value);
        $negative = str_starts_with($value,'-');
        if ($negative) $value=substr($value,1);
        [$w,$f] = explode('.',$value,2);
        $n=((int)$w*100)+(int)$f;
        return $negative ? -$n : $n;
    }

    private static function fromCents(int $cents): string
    {
        $sign=$cents<0?'-':''; $cents=abs($cents);
        return $sign . intdiv($cents,100) . '.' . str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }

    private static function addMoney(string $a, string $b): string { return self::fromCents(self::cents($a)+self::cents($b)); }
    private static function subMoney(string $a, string $b): string { return self::fromCents(self::cents($a)-self::cents($b)); }
    private static function compareMoney(string $a, string $b): int { return self::cents($a) <=> self::cents($b); }
}
