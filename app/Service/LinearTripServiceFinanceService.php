<?php

namespace App\Service;

use PDO;
use Throwable;

final class LinearTripServiceFinanceService
{
    private const SOURCE_TYPE = 'LINEAR_ROUTE_SERVICE_PAYMENT';

    public static function syncRoute(PDO $pdo, int $routeId): int
    {
        if ($routeId <= 0 || !self::schemaReady($pdo) || !LinearTripServiceLineService::schemaReady($pdo)) {
            return 0;
        }

        $stmt = $pdo->prepare(
            "SELECT sp.*, s.linear_route_id, s.contractor_id, s.service_name,
                    c.name AS contractor_name, c.inn AS contractor_inn
               FROM linear_route_service_payments sp
               JOIN linear_route_services s
                 ON s.id=sp.linear_route_service_id AND s.deleted_at IS NULL
               JOIN contractors c ON c.id=s.contractor_id
              WHERE s.linear_route_id=? AND sp.deleted_at IS NULL
              ORDER BY s.sort_order,s.id,sp.sort_order,sp.id"
        );
        $stmt->execute([$routeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $activeKeys = [];
        foreach ($rows as $row) {
            $paymentId = (int) ($row['id'] ?? 0);
            if ($paymentId <= 0) {
                continue;
            }

            $sourceKey = self::sourceKey($paymentId);
            $activeKeys[] = $sourceKey;
            $amount = self::money((string) ($row['amount'] ?? '0'));
            $dueDate = self::validDateOrNull($row['calculated_due_date'] ?? null);
            $paidAmount = self::paidAmount($pdo, $sourceKey);
            $status = self::status($amount, $paidAmount, $dueDate, (string) ($row['condition_type'] ?? ''));

            $existing = self::findBySourceKey($pdo, $sourceKey);
            if ($existing !== null) {
                $update = $pdo->prepare(
                    "UPDATE finance_obligations
                        SET source_id=?,source_parent_id=?,direction='PAYABLE',party_role='service',
                            counterparty_entity_type='contractor',counterparty_entity_id=?,counterparty_name=?,counterparty_inn=?,
                            amount=?,condition_type=?,days_count=?,days_kind=?,specific_due_date=?,
                            event_date=NULL,forecast_due_date=?,due_date=?,paid_amount=?,status=?,cancelled_at=NULL,
                            updated_by_user_id=?,updated_by_role=?,updated_at=NOW()
                      WHERE id=?"
                );
                $update->execute([
                    $paymentId,
                    $routeId,
                    (int) $row['contractor_id'],
                    (string) ($row['contractor_name'] ?? ''),
                    (string) ($row['contractor_inn'] ?? ''),
                    $amount,
                    self::nullableString($row['condition_type'] ?? null),
                    $row['days_count'] !== null ? (int) $row['days_count'] : null,
                    self::nullableString($row['days_kind'] ?? null),
                    self::validDateOrNull($row['specific_due_date'] ?? null),
                    $dueDate,
                    $dueDate,
                    $paidAmount,
                    $status,
                    (int) ($row['updated_by_user_id'] ?? 0) ?: null,
                    (string) ($row['updated_by_role'] ?? '') ?: 'system',
                    (int) $existing['id'],
                ]);
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO finance_obligations
                        (source_type,source_key,source_id,source_parent_type,source_parent_id,direction,party_role,
                         counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,
                         amount,condition_type,days_count,days_kind,specific_due_date,event_date,forecast_due_date,due_date,
                         paid_amount,status,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role)
                     VALUES
                        (?,?,?,'LINEAR_ROUTE',?,'PAYABLE','service','contractor',?,?,?,?,?,?,?,?,NULL,?,?,?,?,?,?,?,?)"
                );
                $insert->execute([
                    self::SOURCE_TYPE,
                    $sourceKey,
                    $paymentId,
                    $routeId,
                    (int) $row['contractor_id'],
                    (string) ($row['contractor_name'] ?? ''),
                    (string) ($row['contractor_inn'] ?? ''),
                    $amount,
                    self::nullableString($row['condition_type'] ?? null),
                    $row['days_count'] !== null ? (int) $row['days_count'] : null,
                    self::nullableString($row['days_kind'] ?? null),
                    self::validDateOrNull($row['specific_due_date'] ?? null),
                    $dueDate,
                    $dueDate,
                    $paidAmount,
                    $status,
                    (int) ($row['created_by_user_id'] ?? 0) ?: null,
                    (string) ($row['created_by_role'] ?? '') ?: 'system',
                    (int) ($row['updated_by_user_id'] ?? 0) ?: null,
                    (string) ($row['updated_by_role'] ?? '') ?: 'system',
                ]);
            }

            $pdo->prepare('UPDATE linear_route_service_payments SET paid_amount=?,status=?,updated_at=NOW() WHERE id=?')
                ->execute([$paidAmount, $status, $paymentId]);
        }

        $stale = $pdo->prepare(
            "SELECT id,source_key
               FROM finance_obligations
              WHERE source_type=? AND source_parent_type='LINEAR_ROUTE' AND source_parent_id=? AND cancelled_at IS NULL"
        );
        $stale->execute([self::SOURCE_TYPE, $routeId]);
        foreach ($stale->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array((string) $row['source_key'], $activeKeys, true)) {
                $pdo->prepare("UPDATE finance_obligations SET status='cancelled',cancelled_at=NOW(),updated_at=NOW() WHERE id=?")
                    ->execute([(int) $row['id']]);
            }
        }

        return count($activeKeys);
    }

    private static function schemaReady(PDO $pdo): bool
    {
        return self::tableExists($pdo, 'finance_obligations')
            && self::tableExists($pdo, 'finance_operation_allocations');
    }

    private static function sourceKey(int $paymentId): string
    {
        return self::SOURCE_TYPE . ':' . $paymentId;
    }

    private static function findBySourceKey(PDO $pdo, string $sourceKey): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM finance_obligations WHERE source_key=? LIMIT 1');
        $stmt->execute([$sourceKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function paidAmount(PDO $pdo, string $sourceKey): string
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(a.amount),0)
               FROM finance_operation_allocations a
               JOIN finance_operations op ON op.id=a.operation_id
               JOIN finance_obligations o ON o.id=a.obligation_id
              WHERE o.source_key=? AND a.cancelled_at IS NULL AND op.status='POSTED'"
        );
        $stmt->execute([$sourceKey]);
        return self::money((string) $stmt->fetchColumn());
    }

    private static function status(string $amount, string $paid, ?string $dueDate, string $conditionType): string
    {
        $remaining = self::cents($amount) - self::cents($paid);
        if ($remaining <= 0) {
            return 'paid';
        }
        if ($dueDate === null) {
            return $conditionType === '' ? 'waiting_event' : 'waiting_event';
        }
        $overdue = $dueDate < date('Y-m-d');
        if (self::cents($paid) > 0) {
            return $overdue ? 'overdue_partial' : 'partially_paid';
        }
        return $overdue ? 'overdue' : 'planned';
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
            $stmt->execute([$table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private static function validDateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function money(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            return '0.00';
        }
        $negative = str_starts_with($value, '-');
        if ($negative) {
            $value = substr($value, 1);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        if ($whole === '') {
            $whole = '0';
        }
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }

    private static function cents(string $value): int
    {
        $value = self::money($value);
        $negative = str_starts_with($value, '-');
        if ($negative) {
            $value = substr($value, 1);
        }
        [$whole, $fraction] = explode('.', $value, 2);
        $cents = ((int) $whole * 100) + (int) $fraction;
        return $negative ? -$cents : $cents;
    }
}
