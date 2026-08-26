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
        self::syncAllLinearRoutes($pdo);
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
        self::syncAllLinearRoutes($pdo);

        $stmt = $pdo->prepare('SELECT * FROM finance_invoices WHERE id=? FOR UPDATE');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new RuntimeException('Счёт не найден.');
        }

        $expectedDirection = strtoupper((string)$invoice['direction']) === FinanceInvoiceService::DIRECTION_INCOMING
            ? self::DIRECTION_PAYABLE
            : self::DIRECTION_RECEIVABLE;
        $cleanRows = [];
        $total = '0.00';
        foreach ($rows as $row) {
            $obligationId = (int)($row['obligation_id'] ?? 0);
            $amount = self::normalizePositiveMoney((string)($row['amount'] ?? ''));
            if ($obligationId <= 0 || $amount === null) {
                continue;
            }
            if (isset($cleanRows[$obligationId])) {
                throw new RuntimeException('Одно обязательство нельзя добавить в счёт дважды.');
            }
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
            if (self::compareMoney($amount, $capacity) > 0) {
                throw new RuntimeException('Сумма связи превышает свободный остаток обязательства «' . ($ob['counterparty_name'] ?? '') . '».');
            }
            $cleanRows[$obligationId] = ['obligation' => $ob, 'amount' => $amount];
            $total = self::addMoney($total, $amount);
        }

        if ($cleanRows !== [] && self::compareMoney($total, (string)$invoice['amount']) !== 0) {
            throw new RuntimeException('Сумма связей с обязательствами должна быть равна сумме счёта.');
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function autoAllocateIncomingCustomerReceipts(PDO $pdo, array $user): array
    {
        if (!self::schemaReady($pdo)) {
            return ['operations' => 0, 'allocations' => 0, 'amount' => '0.00'];
        }
        self::syncAllLinearRoutes($pdo);

        $ops = $pdo->query(
            "SELECT fo.*,
                    (fo.amount-COALESCE((SELECT SUM(a.amount) FROM finance_operation_allocations a WHERE a.operation_id=fo.id AND a.cancelled_at IS NULL),0)) AS remaining_amount
               FROM finance_operations fo
              WHERE fo.status='POSTED' AND fo.operation_type='INCOME'
                AND fo.counterparty_inn IS NOT NULL AND TRIM(fo.counterparty_inn)<>''
              ORDER BY fo.operation_date,fo.id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $matchedOps = 0;
        $allocationCount = 0;
        $total = '0.00';
        foreach ($ops as $op) {
            $opRemaining = self::money((string)$op['remaining_amount']);
            if (self::compareMoney($opRemaining, '0.00') <= 0) {
                continue;
            }
            $candidateStmt = $pdo->prepare(
                "SELECT DISTINCT i.*,
                        (i.amount-COALESCE((SELECT SUM(a.amount) FROM finance_operation_allocations a WHERE a.invoice_id=i.id AND a.cancelled_at IS NULL),0)) AS remaining_amount
                   FROM finance_invoices i
                   JOIN finance_invoice_links l ON l.invoice_id=i.id AND l.obligation_id IS NOT NULL
                   JOIN finance_obligations o ON o.id=l.obligation_id
                  WHERE i.direction='OUTGOING' AND i.status NOT IN ('cancelled','paid')
                    AND i.counterparty_inn=? AND o.direction='RECEIVABLE' AND o.cancelled_at IS NULL
                  ORDER BY i.invoice_date,i.id"
            );
            $candidateStmt->execute([(string)$op['counterparty_inn']]);
            $candidates = array_values(array_filter(
                $candidateStmt->fetchAll(PDO::FETCH_ASSOC),
                static fn(array $i): bool => (float)$i['remaining_amount'] > 0
            ));
            if ($candidates === []) {
                continue;
            }

            $purpose = mb_strtolower((string)($op['purpose'] ?? ''), 'UTF-8');
            $numberMatches = [];
            foreach ($candidates as $candidate) {
                $number = trim((string)($candidate['number'] ?? ''));
                if (mb_strlen($number, 'UTF-8') >= 3 && mb_stripos($purpose, mb_strtolower($number, 'UTF-8'), 0, 'UTF-8') !== false) {
                    $numberMatches[] = $candidate;
                }
            }

            $target = null;
            if (count($numberMatches) === 1 && self::compareMoney($opRemaining, (string)$numberMatches[0]['remaining_amount']) <= 0) {
                $target = $numberMatches[0];
            } else {
                $exact = array_values(array_filter(
                    $candidates,
                    static fn(array $i): bool => self::compareMoney((string)$i['remaining_amount'], $opRemaining) === 0
                ));
                if (count($exact) === 1) {
                    $target = $exact[0];
                }
            }
            if ($target === null) {
                continue;
            }

            $created = self::allocateOperationToInvoiceObligations($pdo, (int)$op['id'], (int)$target['id'], $opRemaining, $user);
            if ($created > 0) {
                $matchedOps++;
                $allocationCount += $created;
                $total = self::addMoney($total, $opRemaining);
            }
        }
        self::syncAllLinearRoutes($pdo);
        return ['operations' => $matchedOps, 'allocations' => $allocationCount, 'amount' => $total];
    }

    public static function receivablesReport(PDO $pdo): array
    {
        if (!self::schemaReady($pdo)) {
            return ['rows' => [], 'summary' => self::emptyReceivablesSummary()];
        }
        self::syncAllLinearRoutes($pdo);
        $stmt = $pdo->query(
            "SELECT o.*,
                    COALESCE((SELECT SUM(l.amount) FROM finance_invoice_links l JOIN finance_invoices i ON i.id=l.invoice_id WHERE l.obligation_id=o.id AND i.status<>'cancelled'),0) AS invoiced_amount,
                    COALESCE((SELECT GROUP_CONCAT(DISTINCT i.number ORDER BY i.invoice_date SEPARATOR ', ') FROM finance_invoice_links l JOIN finance_invoices i ON i.id=l.invoice_id WHERE l.obligation_id=o.id AND i.status<>'cancelled'),'') AS invoice_numbers
               FROM finance_obligations o
              WHERE o.direction='RECEIVABLE' AND o.cancelled_at IS NULL
              ORDER BY CASE WHEN o.due_date IS NULL THEN 1 ELSE 0 END,o.due_date,o.id"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                $forecastBase = $plannedStart; $actualBase = $actualStart ?? $plannedStart; break;
            case DateCalculationService::CONDITION_AFTER_START:
                $forecastBase = $plannedStart; $actualBase = $actualStart ?? $plannedStart; break;
            case DateCalculationService::CONDITION_END_DAY:
                $forecastBase = $plannedEnd; $actualBase = $actualEnd ?? $plannedEnd; break;
            case DateCalculationService::CONDITION_AFTER_END:
                $forecastBase = $plannedEnd; $actualBase = $actualEnd ?? $plannedEnd; break;
            case DateCalculationService::CONDITION_AFTER_DOCUMENTS:
                if ($docsDate === null) {
                    return ['event_date' => null, 'forecast_due_date' => null, 'due_date' => null, 'state' => 'waiting_event'];
                }
                $actualBase = $docsDate; break;
            default:
                return ['event_date' => null, 'forecast_due_date' => null, 'due_date' => null, 'state' => 'waiting_event'];
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

    private static function allocateOperationToInvoiceObligations(PDO $pdo, int $operationId, int $invoiceId, string $amount, array $user): int
    {
        $linksStmt = $pdo->prepare(
            "SELECT l.*,o.source_id,o.source_parent_id,o.source_parent_type,o.source_type,o.due_date,
                    COALESCE((SELECT SUM(a.amount) FROM finance_operation_allocations a WHERE a.invoice_id=l.invoice_id AND a.obligation_id=l.obligation_id AND a.cancelled_at IS NULL),0) AS allocated_amount
               FROM finance_invoice_links l
               JOIN finance_obligations o ON o.id=l.obligation_id
              WHERE l.invoice_id=? AND o.cancelled_at IS NULL
              ORDER BY CASE WHEN o.due_date IS NULL THEN 1 ELSE 0 END,o.due_date,l.id"
        );
        $linksStmt->execute([$invoiceId]);
        $links = $linksStmt->fetchAll(PDO::FETCH_ASSOC);
        $capacity = '0.00';
        foreach ($links as $link) {
            $capacity = self::addMoney($capacity, self::subMoney((string)$link['amount'], (string)$link['allocated_amount']));
        }
        if (self::compareMoney($capacity, $amount) < 0) return 0;

        $remaining = $amount;
        $ids = [];
        $insert = $pdo->prepare(
            "INSERT INTO finance_operation_allocations
                (operation_id,invoice_id,obligation_id,linear_route_id,linear_route_payment_id,amount,allocation_date,method,comment,created_by_user_id,created_by_role)
             VALUES (?,?,?,?,?,?,CURDATE(),'auto_exact',?,?,?)"
        );
        foreach ($links as $link) {
            if (self::compareMoney($remaining, '0.00') <= 0) break;
            $free = self::subMoney((string)$link['amount'], (string)$link['allocated_amount']);
            if (self::compareMoney($free, '0.00') <= 0) continue;
            $part = self::compareMoney($free, $remaining) <= 0 ? $free : $remaining;
            $insert->execute([
                $operationId, $invoiceId, (int)$link['obligation_id'],
                (string)$link['source_parent_type'] === 'LINEAR_ROUTE' ? (int)$link['source_parent_id'] : null,
                (string)$link['source_type'] === 'LINEAR_ROUTE_PAYMENT' ? (int)$link['source_id'] : null,
                $part,
                'Автоматическое разнесение поступления клиента по счёту и платёжному обязательству.',
                (int)($user['user_id'] ?? 0) ?: null,
                (string)($user['role_code'] ?? '') ?: 'company_owner',
            ]);
            $ids[] = (int)$pdo->lastInsertId();
            $remaining = self::subMoney($remaining, $part);
        }
        if (self::compareMoney($remaining, '0.00') !== 0) {
            foreach ($ids as $id) $pdo->prepare('DELETE FROM finance_operation_allocations WHERE id=?')->execute([$id]);
            return 0;
        }
        foreach ($ids as $id) FinanceSettlementCascadeService::cascadeAfterAllocationCreate($pdo, $id);
        return count($ids);
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
