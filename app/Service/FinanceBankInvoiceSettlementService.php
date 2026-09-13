<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

final class FinanceBankInvoiceSettlementService
{
    public static function fetchContext(PDO $pdo, int $bankTransactionId): ?array
    {
        if ($bankTransactionId <= 0 || !FinanceObligationService::schemaReady($pdo)) {
            return null;
        }

        FinanceObligationService::syncAllOperationalObligations($pdo);
        $row = self::fetchBankOperation($pdo, $bankTransactionId, false);
        if ($row === null || !in_array((string)$row['operation_type'], ['INCOME', 'EXPENSE'], true)) {
            return null;
        }

        $counterparty = self::resolveCounterparty($pdo, $row);
        if ($counterparty === null) {
            return null;
        }

        $remaining = FinanceAllocationService::getOperationRemainingAmount(
            $pdo,
            (int)$row['operation_id'],
            (string)$row['operation_amount']
        );
        $allocated = self::subMoney((string)$row['operation_amount'], $remaining);
        $invoices = self::fetchOpenInvoices($pdo, $row, $counterparty);

        return [
            'bank_transaction_id' => (int)$row['bank_transaction_id'],
            'operation_id' => (int)$row['operation_id'],
            'operation_type' => (string)$row['operation_type'],
            'operation_date' => (string)$row['operation_date'],
            'amount' => self::money((string)$row['operation_amount']),
            'allocated_amount' => $allocated,
            'remaining_amount' => $remaining,
            'document_number' => (string)($row['document_number'] ?? ''),
            'purpose' => (string)($row['purpose'] ?? ''),
            'counterparty' => $counterparty,
            'invoices' => $invoices,
        ];
    }

    public static function manualAllocate(PDO $pdo, int $bankTransactionId, array $rows, array $user): array
    {
        if ($bankTransactionId <= 0 || !FinanceObligationService::schemaReady($pdo)) {
            throw new RuntimeException('Схема расчётов по счетам недоступна.');
        }
        FinanceObligationService::syncAllOperationalObligations($pdo);

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $bankOperation = self::fetchBankOperation($pdo, $bankTransactionId, true);
            if ($bankOperation === null) {
                throw new RuntimeException('Банковская операция не найдена.');
            }
            if (($bankOperation['operation_status'] ?? '') !== 'POSTED') {
                throw new RuntimeException('Распределять можно только проведённую банковскую операцию.');
            }
            if (!in_array((string)$bankOperation['operation_type'], ['INCOME', 'EXPENSE'], true)) {
                throw new RuntimeException('Для этой банковской операции расчёты по счетам неприменимы.');
            }

            $counterparty = self::resolveCounterparty($pdo, $bankOperation);
            if ($counterparty === null) {
                throw new RuntimeException('Не удалось однозначно определить клиента или перевозчика по банковской операции.');
            }

            $operationRemaining = FinanceAllocationService::getOperationRemainingAmount(
                $pdo,
                (int)$bankOperation['operation_id'],
                (string)$bankOperation['operation_amount']
            );
            if (self::compareMoney($operationRemaining, '0.00') <= 0) {
                throw new RuntimeException('Банковская операция уже полностью распределена.');
            }

            $clean = [];
            $requestedTotal = '0.00';
            foreach ($rows as $row) {
                $invoiceId = (int)($row['invoice_id'] ?? 0);
                $amount = self::normalizePositiveMoney((string)($row['amount'] ?? ''));
                if ($invoiceId <= 0 || $amount === null) {
                    continue;
                }
                if (isset($clean[$invoiceId])) {
                    throw new RuntimeException('Один счёт нельзя добавить в распределение дважды.');
                }
                $clean[$invoiceId] = $amount;
                $requestedTotal = self::addMoney($requestedTotal, $amount);
            }
            if ($clean === []) {
                throw new RuntimeException('Выберите хотя бы один счёт и укажите сумму распределения.');
            }
            if (self::compareMoney($requestedTotal, $operationRemaining) > 0) {
                throw new RuntimeException('Сумма распределения превышает нераспределённый остаток банковской операции.');
            }

            $createdIds = [];
            foreach ($clean as $invoiceId => $amount) {
                $createdIds = array_merge(
                    $createdIds,
                    self::allocateToInvoice($pdo, $bankOperation, $counterparty, (int)$invoiceId, $amount, $user)
                );
            }

            foreach ($createdIds as $allocationId) {
                FinanceSettlementCascadeService::cascadeAfterAllocationCreate($pdo, $allocationId);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            FinanceObligationService::syncAllOperationalObligations($pdo);
            $afterRemaining = FinanceAllocationService::getOperationRemainingAmount(
                $pdo,
                (int)$bankOperation['operation_id'],
                (string)$bankOperation['operation_amount']
            );

            return [
                'bank_transaction_id' => $bankTransactionId,
                'operation_id' => (int)$bankOperation['operation_id'],
                'allocations' => count($createdIds),
                'allocated_amount' => $requestedTotal,
                'remaining_amount' => $afterRemaining,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function fetchBankOperation(PDO $pdo, int $bankTransactionId, bool $forUpdate): ?array
    {
        $sql = "SELECT bt.id AS bank_transaction_id,bt.operation_date,bt.document_number,
                       bt.counterparty_name,bt.counterparty_inn,bt.purpose,
                       fo.id AS operation_id,fo.operation_type,fo.status AS operation_status,
                       fo.amount AS operation_amount,fo.counterparty_entity_type,fo.counterparty_entity_id
                  FROM bank_transactions bt
                  JOIN finance_operations fo ON fo.bank_transaction_id=bt.id AND fo.status<>'CANCELLED'
                 WHERE bt.id=?
                 ORDER BY fo.id ASC
                 LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$bankTransactionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function resolveCounterparty(PDO $pdo, array $row): ?array
    {
        $isIncome = (string)$row['operation_type'] === 'INCOME';
        $type = $isIncome ? 'client' : 'contractor';
        $table = $isIncome ? 'clients' : 'contractors';
        $entityType = (string)($row['counterparty_entity_type'] ?? '');
        $entityId = (int)($row['counterparty_entity_id'] ?? 0);

        if ($entityType === $type && $entityId > 0) {
            $stmt = $pdo->prepare("SELECT id,name,inn FROM {$table} WHERE id=? AND status='active' AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$entityId]);
            $entity = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($entity) {
                return ['type'=>$type,'id'=>(int)$entity['id'],'name'=>(string)$entity['name'],'inn'=>(string)($entity['inn'] ?? '')];
            }
        }

        $inn = trim((string)($row['counterparty_inn'] ?? ''));
        if ($inn === '') {
            return null;
        }
        $stmt = $pdo->prepare("SELECT id,name,inn FROM {$table} WHERE inn=? AND status='active' AND deleted_at IS NULL ORDER BY id LIMIT 2");
        $stmt->execute([$inn]);
        $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($entities) !== 1) {
            return null;
        }
        $entity = $entities[0];
        return ['type'=>$type,'id'=>(int)$entity['id'],'name'=>(string)$entity['name'],'inn'=>(string)($entity['inn'] ?? '')];
    }

    private static function fetchOpenInvoices(PDO $pdo, array $bankOperation, array $counterparty): array
    {
        $direction = (string)$bankOperation['operation_type'] === 'INCOME'
            ? FinanceInvoiceService::DIRECTION_OUTGOING
            : FinanceInvoiceService::DIRECTION_INCOMING;
        $stmt = $pdo->prepare(
            "SELECT i.*
               FROM finance_invoices i
              WHERE i.direction=?
                AND i.status NOT IN ('cancelled','paid')
                AND ((i.counterparty_entity_type=? AND i.counterparty_entity_id=?)
                     OR (TRIM(COALESCE(i.counterparty_inn,''))<>'' AND i.counterparty_inn=?))
              ORDER BY i.invoice_date ASC,i.id ASC"
        );
        $stmt->execute([$direction,$counterparty['type'],$counterparty['id'],$counterparty['inn']]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
            $invoiceId = (int)$invoice['id'];
            $paid = FinanceSettlementCascadeService::getInvoicePaidAmount($pdo, $invoiceId);
            $remaining = FinanceSettlementCascadeService::getInvoiceRemainingAmount($pdo, $invoiceId, (string)$invoice['amount']);
            if (self::compareMoney($remaining, '0.00') <= 0) {
                continue;
            }
            $links = FinanceObligationService::invoiceLinks($pdo, $invoiceId);
            $invoice['paid_amount'] = $paid;
            $invoice['remaining_amount'] = $remaining;
            $invoice['obligation_links'] = $links;
            $result[] = $invoice;
        }
        return $result;
    }

    private static function allocateToInvoice(
        PDO $pdo,
        array $bankOperation,
        array $counterparty,
        int $invoiceId,
        string $amount,
        array $user
    ): array {
        $stmt = $pdo->prepare('SELECT * FROM finance_invoices WHERE id=? FOR UPDATE');
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new RuntimeException('Счёт не найден.');
        }
        if (in_array((string)($invoice['status'] ?? ''), ['cancelled','paid'], true)) {
            throw new RuntimeException('Счёт №' . ($invoice['number'] ?? $invoiceId) . ' уже закрыт или аннулирован.');
        }
        $expectedDirection = (string)$bankOperation['operation_type'] === 'INCOME'
            ? FinanceInvoiceService::DIRECTION_OUTGOING
            : FinanceInvoiceService::DIRECTION_INCOMING;
        if ((string)$invoice['direction'] !== $expectedDirection) {
            throw new RuntimeException('Направление счёта не соответствует банковской операции.');
        }
        $sameEntity = (string)($invoice['counterparty_entity_type'] ?? '') === (string)$counterparty['type']
            && (int)($invoice['counterparty_entity_id'] ?? 0) === (int)$counterparty['id'];
        $sameInn = trim((string)($invoice['counterparty_inn'] ?? '')) !== ''
            && trim((string)$invoice['counterparty_inn']) === trim((string)$counterparty['inn']);
        if (!$sameEntity && !$sameInn) {
            throw new RuntimeException('Счёт №' . ($invoice['number'] ?? $invoiceId) . ' относится к другому контрагенту.');
        }
        $invoiceRemaining = FinanceSettlementCascadeService::getInvoiceRemainingAmount($pdo, $invoiceId, (string)$invoice['amount']);
        if (self::compareMoney($amount, $invoiceRemaining) > 0) {
            throw new RuntimeException('Сумма распределения превышает остаток счёта №' . ($invoice['number'] ?? $invoiceId) . '.');
        }

        $linksStmt = $pdo->prepare(
            "SELECT l.*,o.source_id,o.source_parent_id,o.source_parent_type,o.source_type,o.due_date,
                    COALESCE((SELECT SUM(a.amount)
                                FROM finance_operation_allocations a
                               WHERE a.invoice_id=l.invoice_id
                                 AND a.obligation_id=l.obligation_id
                                 AND a.cancelled_at IS NULL),0) AS allocated_amount
               FROM finance_invoice_links l
          LEFT JOIN finance_obligations o ON o.id=l.obligation_id
              WHERE l.invoice_id=?
              ORDER BY CASE WHEN o.due_date IS NULL THEN 1 ELSE 0 END,o.due_date,l.id"
        );
        $linksStmt->execute([$invoiceId]);
        $links = $linksStmt->fetchAll(PDO::FETCH_ASSOC);
        $userId = (int)($user['user_id'] ?? 0) ?: null;
        $roleCode = (string)($user['role_code'] ?? '') ?: 'company_owner';
        $created = [];

        if ($links === []) {
            $insert = $pdo->prepare(
                "INSERT INTO finance_operation_allocations
                    (operation_id,invoice_id,amount,allocation_date,method,comment,created_by_user_id,created_by_role)
                 VALUES (?,?,?,CURDATE(),'manual',?,?,?)"
            );
            $insert->execute([(int)$bankOperation['operation_id'],$invoiceId,$amount,'Ручное распределение банковской операции по счёту контрагента.',$userId,$roleCode]);
            return [(int)$pdo->lastInsertId()];
        }

        $capacity = '0.00';
        foreach ($links as $link) {
            if (empty($link['obligation_id'])) {
                continue;
            }
            $free = self::subMoney((string)$link['amount'], (string)$link['allocated_amount']);
            if (self::compareMoney($free, '0.00') > 0) {
                $capacity = self::addMoney($capacity, $free);
            }
        }
        if (self::compareMoney($capacity, $amount) < 0) {
            throw new RuntimeException('Связанные обязательства счёта №' . ($invoice['number'] ?? $invoiceId) . ' не имеют достаточного свободного остатка.');
        }

        $remaining = $amount;
        $insert = $pdo->prepare(
            "INSERT INTO finance_operation_allocations
                (operation_id,invoice_id,obligation_id,linear_route_id,linear_route_payment_id,amount,allocation_date,method,comment,created_by_user_id,created_by_role)
             VALUES (?,?,?,?,?,?,CURDATE(),'manual',?,?,?)"
        );
        foreach ($links as $link) {
            if (self::compareMoney($remaining, '0.00') <= 0 || empty($link['obligation_id'])) {
                break;
            }
            $free = self::subMoney((string)$link['amount'], (string)$link['allocated_amount']);
            if (self::compareMoney($free, '0.00') <= 0) {
                continue;
            }
            $part = self::compareMoney($free, $remaining) <= 0 ? $free : $remaining;
            $insert->execute([
                (int)$bankOperation['operation_id'],
                $invoiceId,
                (int)$link['obligation_id'],
                (string)($link['source_parent_type'] ?? '') === 'LINEAR_ROUTE' ? (int)$link['source_parent_id'] : null,
                (string)($link['source_type'] ?? '') === 'LINEAR_ROUTE_PAYMENT' ? (int)$link['source_id'] : null,
                $part,
                'Ручное распределение банковской операции по счёту и платёжному обязательству.',
                $userId,
                $roleCode,
            ]);
            $created[] = (int)$pdo->lastInsertId();
            $remaining = self::subMoney($remaining, $part);
        }
        if (self::compareMoney($remaining, '0.00') !== 0) {
            throw new RuntimeException('Не удалось полностью распределить указанную сумму по обязательствам счёта.');
        }
        return $created;
    }

    private static function normalizePositiveMoney(string $value): ?string
    {
        $value = str_replace([' ', ','], ['', '.'], trim($value));
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $value)) {
            return null;
        }
        $value = self::money($value);
        return self::compareMoney($value, '0.00') > 0 ? $value : null;
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
        $cents = ((int)$whole * 100) + (int)$fraction;
        return $negative ? -$cents : $cents;
    }

    private static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function addMoney(string $a, string $b): string { return self::fromCents(self::cents($a) + self::cents($b)); }
    private static function subMoney(string $a, string $b): string { return self::fromCents(self::cents($a) - self::cents($b)); }
    private static function compareMoney(string $a, string $b): int { return self::cents($a) <=> self::cents($b); }
}
