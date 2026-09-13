<?php

namespace App\Service;

use PDO;
use Throwable;

/**
 * Automatic BANK EXPENSE -> INCOMING carrier invoice settlement.
 *
 * This is the payable-side mirror of automatic customer receipt settlement:
 * - only real posted bank EXPENSE operations are considered;
 * - the carrier is matched by INN;
 * - a unique invoice number mentioned in the bank purpose wins;
 * - partial payments are allowed while they do not exceed invoice remaining;
 * - allocations are spread across linked PAYABLE obligations by due date;
 * - ambiguous matches are left untouched for manual review.
 */
final class FinanceCarrierBankAutoSettlementService
{
    /** @return array{operations:int,allocations:int,amount:string} */
    public static function autoAllocateOutgoingCarrierPayments(PDO $pdo, array $user): array
    {
        if (!FinanceObligationService::schemaReady($pdo)) {
            return ['operations' => 0, 'allocations' => 0, 'amount' => '0.00'];
        }

        FinanceObligationService::syncAllOperationalObligations($pdo);

        $ops = $pdo->query(
            "SELECT fo.*,
                    bt.counterparty_inn AS bank_counterparty_inn,
                    bt.counterparty_name AS bank_counterparty_name,
                    bt.purpose AS bank_purpose,
                    COALESCE(bt.is_internal_transfer,0) AS bank_internal_transfer,
                    COALESCE(bt.classification_locked,0) AS bank_classification_locked,
                    UPPER(COALESCE(bt.classification_status,'UNALLOCATED')) AS bank_classification_status,
                    (fo.amount-COALESCE((SELECT SUM(a.amount)
                                           FROM finance_operation_allocations a
                                          WHERE a.operation_id=fo.id
                                            AND a.cancelled_at IS NULL),0)) AS remaining_amount
               FROM finance_operations fo
               JOIN bank_transactions bt ON bt.id=fo.bank_transaction_id
              WHERE fo.status='POSTED'
                AND fo.operation_type='EXPENSE'
                AND COALESCE(bt.is_internal_transfer,0)=0
                AND COALESCE(bt.classification_locked,0)=0
                AND UPPER(COALESCE(bt.classification_status,'UNALLOCATED'))<>'MANUAL'
                AND TRIM(COALESCE(NULLIF(bt.counterparty_inn,''),fo.counterparty_inn,''))<>''
              ORDER BY fo.operation_date,fo.id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $matchedOps = 0;
        $allocationCount = 0;
        $total = '0.00';

        foreach ($ops as $op) {
            $opRemaining = self::money((string)($op['remaining_amount'] ?? '0.00'));
            if (self::compareMoney($opRemaining, '0.00') <= 0) {
                continue;
            }

            $inn = trim((string)($op['bank_counterparty_inn'] ?? ''));
            if ($inn === '') {
                $inn = trim((string)($op['counterparty_inn'] ?? ''));
            }
            if ($inn === '') {
                continue;
            }

            $candidateStmt = $pdo->prepare(
                "SELECT DISTINCT i.*,
                        (i.amount-COALESCE((SELECT SUM(a.amount)
                                             FROM finance_operation_allocations a
                                            WHERE a.invoice_id=i.id
                                              AND a.cancelled_at IS NULL),0)) AS remaining_amount
                   FROM finance_invoices i
                   JOIN finance_invoice_links l
                     ON l.invoice_id=i.id AND l.obligation_id IS NOT NULL
                   JOIN finance_obligations o
                     ON o.id=l.obligation_id
              LEFT JOIN contractors ct
                     ON i.counterparty_entity_type='contractor'
                    AND ct.id=i.counterparty_entity_id
                    AND ct.deleted_at IS NULL
                  WHERE i.direction='INCOMING'
                    AND i.status NOT IN ('cancelled','paid')
                    AND (TRIM(COALESCE(i.counterparty_inn,''))=? OR TRIM(COALESCE(ct.inn,''))=?)
                    AND o.direction='PAYABLE'
                    AND o.cancelled_at IS NULL
                  ORDER BY i.invoice_date,i.id"
            );
            $candidateStmt->execute([$inn, $inn]);
            $candidates = array_values(array_filter(
                $candidateStmt->fetchAll(PDO::FETCH_ASSOC),
                static fn(array $invoice): bool => (float)($invoice['remaining_amount'] ?? 0) > 0
            ));
            if ($candidates === []) {
                continue;
            }

            $purpose = (string)($op['bank_purpose'] ?? $op['purpose'] ?? '');
            $numberMatches = [];
            foreach ($candidates as $candidate) {
                if (self::purposeMentionsInvoice($purpose, (string)($candidate['number'] ?? ''))) {
                    $numberMatches[] = $candidate;
                }
            }

            $target = null;
            if (count($numberMatches) === 1
                && self::compareMoney($opRemaining, (string)$numberMatches[0]['remaining_amount']) <= 0) {
                $target = $numberMatches[0];
            } else {
                // Safe fallback used only when the amount uniquely equals one open invoice balance.
                $exact = array_values(array_filter(
                    $candidates,
                    static fn(array $invoice): bool => self::compareMoney(
                        (string)$invoice['remaining_amount'],
                        $opRemaining
                    ) === 0
                ));
                if (count($exact) === 1) {
                    $target = $exact[0];
                }
            }
            if ($target === null) {
                continue;
            }

            $owns = !$pdo->inTransaction();
            if ($owns) {
                $pdo->beginTransaction();
            }
            try {
                $created = self::allocateOperationToInvoice(
                    $pdo,
                    $op,
                    (int)$target['id'],
                    $opRemaining,
                    $user
                );
                if ($owns) {
                    $pdo->commit();
                }
            } catch (Throwable $e) {
                if ($owns && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Carrier bank auto-settlement failed for operation #' . (int)$op['id'] . ': ' . $e->getMessage());
                continue;
            }

            if ($created > 0) {
                $matchedOps++;
                $allocationCount += $created;
                $total = self::addMoney($total, $opRemaining);
            }
        }

        FinanceObligationService::syncAllOperationalObligations($pdo);
        return ['operations' => $matchedOps, 'allocations' => $allocationCount, 'amount' => $total];
    }

    private static function allocateOperationToInvoice(
        PDO $pdo,
        array $operation,
        int $invoiceId,
        string $amount,
        array $user
    ): int {
        $invoiceStmt = $pdo->prepare("SELECT * FROM finance_invoices WHERE id=? FOR UPDATE");
        $invoiceStmt->execute([$invoiceId]);
        $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice
            || (string)($invoice['direction'] ?? '') !== FinanceInvoiceService::DIRECTION_INCOMING
            || in_array((string)($invoice['status'] ?? ''), ['cancelled','paid'], true)) {
            return 0;
        }

        $linksStmt = $pdo->prepare(
            "SELECT l.*,o.source_id,o.source_parent_id,o.source_parent_type,o.source_type,o.due_date,
                    COALESCE((SELECT SUM(a.amount)
                                FROM finance_operation_allocations a
                               WHERE a.invoice_id=l.invoice_id
                                 AND a.obligation_id=l.obligation_id
                                 AND a.cancelled_at IS NULL),0) AS allocated_amount
               FROM finance_invoice_links l
               JOIN finance_obligations o ON o.id=l.obligation_id
              WHERE l.invoice_id=?
                AND o.direction='PAYABLE'
                AND o.cancelled_at IS NULL
              ORDER BY CASE WHEN o.due_date IS NULL THEN 1 ELSE 0 END,o.due_date,l.id"
        );
        $linksStmt->execute([$invoiceId]);
        $links = $linksStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($links === []) {
            return 0;
        }

        $capacity = '0.00';
        foreach ($links as $link) {
            $free = self::subMoney((string)$link['amount'], (string)$link['allocated_amount']);
            if (self::compareMoney($free, '0.00') > 0) {
                $capacity = self::addMoney($capacity, $free);
            }
        }
        if (self::compareMoney($capacity, $amount) < 0) {
            return 0;
        }

        $allocationDate = self::validDate((string)($operation['operation_date'] ?? '')) ?: date('Y-m-d');
        $remaining = $amount;
        $ids = [];
        $insert = $pdo->prepare(
            "INSERT INTO finance_operation_allocations
                (operation_id,invoice_id,obligation_id,linear_route_id,linear_route_payment_id,amount,allocation_date,method,comment,created_by_user_id,created_by_role)
             VALUES (?,?,?,?,?,?,?,'auto_exact',?,?,?)"
        );

        foreach ($links as $link) {
            if (self::compareMoney($remaining, '0.00') <= 0) {
                break;
            }
            $free = self::subMoney((string)$link['amount'], (string)$link['allocated_amount']);
            if (self::compareMoney($free, '0.00') <= 0) {
                continue;
            }
            $part = self::compareMoney($free, $remaining) <= 0 ? $free : $remaining;
            $insert->execute([
                (int)$operation['id'],
                $invoiceId,
                (int)$link['obligation_id'],
                (string)($link['source_parent_type'] ?? '') === 'LINEAR_ROUTE' ? (int)$link['source_parent_id'] : null,
                (string)($link['source_type'] ?? '') === 'LINEAR_ROUTE_PAYMENT' ? (int)$link['source_id'] : null,
                $part,
                $allocationDate,
                'Автоматическое разнесение оплаты перевозчику по входящему счёту и платёжному обязательству.',
                (int)($user['user_id'] ?? 0) ?: null,
                (string)($user['role_code'] ?? '') ?: 'company_owner',
            ]);
            $ids[] = (int)$pdo->lastInsertId();
            $remaining = self::subMoney($remaining, $part);
        }

        if (self::compareMoney($remaining, '0.00') !== 0) {
            throw new \RuntimeException('Не удалось полностью распределить оплату перевозчику по обязательствам счёта.');
        }

        foreach ($ids as $id) {
            FinanceSettlementCascadeService::cascadeAfterAllocationCreate($pdo, $id);
        }
        return count($ids);
    }

    private static function purposeMentionsInvoice(string $purpose, string $number): bool
    {
        $number = trim($number);
        if (mb_strlen($number, 'UTF-8') < 2) {
            return false;
        }

        $purposeNorm = self::normalizeText($purpose);
        $numberNorm = self::normalizeText($number);
        if ($numberNorm !== '' && str_contains($purposeNorm, $numberNorm)) {
            return true;
        }

        // Tolerate the common UI convention where invoice numbers may be stored
        // with or without the leading № sign.
        $numberWithoutSign = ltrim($numberNorm, '№# ');
        return mb_strlen($numberWithoutSign, 'UTF-8') >= 2
            && str_contains($purposeNorm, $numberWithoutSign);
    }

    private static function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/\s+/u', '', $value) ?? $value;
    }

    private static function validDate(string $value): ?string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? $value : null;
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
        [$whole,$fraction] = array_pad(explode('.', $value, 2), 2, '');
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
        [$whole,$fraction] = explode('.', $value, 2);
        $result = ((int)$whole * 100) + (int)$fraction;
        return $negative ? -$result : $result;
    }

    private static function fromCents(int $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $value = abs($value);
        return $sign . intdiv($value, 100) . '.' . str_pad((string)($value % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function addMoney(string $a, string $b): string
    {
        return self::fromCents(self::cents($a) + self::cents($b));
    }

    private static function subMoney(string $a, string $b): string
    {
        return self::fromCents(self::cents($a) - self::cents($b));
    }

    private static function compareMoney(string $a, string $b): int
    {
        return self::cents($a) <=> self::cents($b);
    }
}
