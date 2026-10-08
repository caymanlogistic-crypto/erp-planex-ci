<?php

namespace App\Service;

use PDO;
use RuntimeException;

/** Keeps one electronic customer invoice in step with a generated Waste Trip package. */
final class ClientPackageInvoiceSyncService
{
    public static function synchronize(PDO $pdo, array $prepared, int $userId, string $role): int
    {
        if (!$pdo->inTransaction()) throw new RuntimeException('Счёт обновляется в транзакции комплекта документов.');
        $trip = (array)($prepared['trip'] ?? []);
        $client = (array)($prepared['client'] ?? []);
        $snapshot = (array)($prepared['snapshot'] ?? []);
        $tripId = (int)($trip['id'] ?? 0);
        $clientId = (int)($client['id'] ?? 0);
        $number = (string)($prepared['package_number'] ?? '');
        $amount = FinanceInvoiceService::normalizeMoneyInput((string)($prepared['package_amount'] ?? ''));
        $variant = (string)($prepared['package_variant'] ?? 'act');
        $invoiceType = $variant === 'upd' ? 'client_upd_invoice' : 'client_invoice';
        $date = (string)($prepared['package_dates'][$invoiceType] ?? '');
        $basis = trim((string)($snapshot['service']['description'] ?? ''));
        if ($tripId <= 0 || $clientId <= 0 || $number === '' || $amount === null || $date === '') {
            throw new RuntimeException('Данные рейса для электронного счёта неполны.');
        }

        FinanceObligationService::syncWasteTrip($pdo, $tripId);
        $obligations = $pdo->prepare(
            "SELECT id,amount FROM finance_obligations
              WHERE source_parent_type='WASTE_TRIP' AND source_parent_id=?
                AND direction='RECEIVABLE' AND party_role='customer'
                AND counterparty_entity_type='client' AND counterparty_entity_id=?
                AND cancelled_at IS NULL ORDER BY id FOR UPDATE"
        );
        $obligations->execute([$tripId, $clientId]);
        $rows = $obligations->fetchAll(PDO::FETCH_ASSOC);
        $links = [];
        $totalCents = 0;
        foreach ($rows as $row) {
            $value = FinanceInvoiceService::normalizeMoneyInput((string)$row['amount']);
            if ($value === null) continue;
            $links[] = ['obligation_id' => (int)$row['id'], 'amount' => $value];
            [$whole, $fraction] = explode('.', $value, 2);
            $totalCents += (int)$whole * 100 + (int)$fraction;
        }
        if ($links === [] || sprintf('%d.%02d', intdiv($totalCents, 100), $totalCents % 100) !== $amount) {
            throw new RuntimeException('Сумма обязательств рейса не совпадает с печатным счётом. Проверьте финансовые условия рейса.');
        }

        $businessId = (int)($trip['business_id'] ?? 0);
        if (ClientPackageInvoiceNumberPolicy::historical($businessId) !== null) {
            if (ClientPackageInvoiceNumberPolicy::number($businessId, $amount) !== $number) {
                throw new RuntimeException('Номер исторического счёта рейса изменился после проверки. Повторите проверку комплекта.');
            }
            $historical = ClientPackageInvoiceNumberPolicy::existingHistoricalInvoice($pdo, $businessId, $tripId, $clientId, true);
            if ((string)$historical['invoice_date'] !== $date) {
                throw new RuntimeException('Дата исторического счёта изменилась после проверки. Повторите проверку комплекта.');
            }
            $actual = $pdo->prepare('SELECT obligation_id,amount,linear_route_id,linear_route_payment_id FROM finance_invoice_links WHERE invoice_id=? ORDER BY obligation_id,id FOR UPDATE');
            $actual->execute([(int)$historical['id']]);
            $actualLinks = $actual->fetchAll(PDO::FETCH_ASSOC);
            $expectedById = [];
            foreach ($links as $link) $expectedById[(int)$link['obligation_id']] = $link['amount'];
            $actualById = [];
            foreach ($actualLinks as $link) {
                $obligationId = (int)($link['obligation_id'] ?? 0);
                if ($obligationId <= 0 || $link['linear_route_id'] !== null || $link['linear_route_payment_id'] !== null
                    || isset($actualById[$obligationId])) {
                    throw new RuntimeException('Исторический счёт связан с другими обязательствами. Комплект не создан.');
                }
                $actualById[$obligationId] = FinanceInvoiceService::normalizeMoneyInput((string)$link['amount']);
            }
            if ($actualById !== $expectedById) {
                throw new RuntimeException('Связи исторического счёта не совпадают с обязательствами рейса. Комплект не создан.');
            }
            return (int)$historical['id'];
        }

        $linkedNumbers = $pdo->prepare(
            "SELECT DISTINCT i.number FROM finance_invoice_links l
               JOIN finance_invoices i ON i.id=l.invoice_id
              WHERE l.obligation_id IN (" . implode(',', array_fill(0, count($links), '?')) . ")
                AND i.direction='OUTGOING' AND i.cancelled_at IS NULL AND i.status<>'cancelled'"
        );
        $linkedNumbers->execute(array_column($links, 'obligation_id'));
        $alreadyLinkedNumbers = array_map('strval', $linkedNumbers->fetchAll(PDO::FETCH_COLUMN));
        if ($alreadyLinkedNumbers !== [] && !in_array($number, $alreadyLinkedNumbers, true)) {
            throw new RuntimeException('Для рейса #' . $businessId . ' уже связан выставленный счёт № '
                . implode(', ', $alreadyLinkedNumbers) . ' вместо ожидаемого № ' . $number
                . '. Проверьте, не пропущено ли исключение в нумерации; комплект не создан.');
        }

        $query = $pdo->prepare(
            "SELECT * FROM finance_invoices WHERE direction='OUTGOING' AND number=?
               ORDER BY id FOR UPDATE"
        );
        $query->execute([$number]);
        $matches = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) > 1) {
            throw new RuntimeException('Найдено несколько электронных счетов с этим номером. Устраните дубли перед формированием комплекта.');
        }
        $existing = $matches[0] ?? null;
        if ($existing !== null) {
            if ((string)$existing['counterparty_entity_type'] !== 'client'
                || ((int)$existing['counterparty_entity_id'] !== $clientId
                    && (string)$existing['counterparty_inn'] !== (string)($client['inn'] ?? ''))) {
                throw new RuntimeException('Счёт с этим номером принадлежит другому покупателю. Комплект не перезаписан.');
            }
            if ($existing['cancelled_at'] !== null || (string)$existing['status'] === 'cancelled') {
                throw new RuntimeException('Счёт с этим номером аннулирован. Автоматическое восстановление запрещено.');
            }
            $linkCount = $pdo->prepare('SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id=?');
            $linkCount->execute([(int)$existing['id']]);
            if ((int)$linkCount->fetchColumn() === 0
                && ((string)$existing['invoice_date'] !== $date || (string)$existing['amount'] !== $amount)) {
                throw new RuntimeException('Найден ранее внесённый счёт с таким номером и другими датой или суммой. Комплект не перезаписан.');
            }
            $otherTrip = $pdo->prepare(
                "SELECT COUNT(*) FROM finance_invoice_links l
                  LEFT JOIN finance_obligations o ON o.id=l.obligation_id
                 WHERE l.invoice_id=? AND
                    (l.linear_route_id IS NOT NULL OR l.linear_route_payment_id IS NOT NULL
                     OR (l.obligation_id IS NOT NULL AND
                         (o.source_parent_type<>'WASTE_TRIP' OR o.source_parent_id<>?)))"
            );
            $otherTrip->execute([(int)$existing['id'], $tripId]);
            if ((int)$otherTrip->fetchColumn() > 0) {
                throw new RuntimeException('Счёт с этим номером связан с другим рейсом. Комплект не перезаписан.');
            }
            $paid = $pdo->prepare(
                "SELECT COALESCE(SUM(a.amount),0) FROM finance_operation_allocations a
                  JOIN finance_operations op ON op.id=a.operation_id
                 WHERE a.invoice_id=? AND a.cancelled_at IS NULL AND op.status='POSTED'"
            );
            $paid->execute([(int)$existing['id']]);
            if ((float)$paid->fetchColumn() > 0.0 || (float)$existing['paid_amount'] > 0.0) {
                if ((string)$existing['invoice_date'] !== $date
                    || (string)$existing['amount'] !== $amount
                    || (int)$existing['counterparty_entity_id'] !== $clientId
                    || (string)($existing['basis'] ?? '') !== $basis) {
                    throw new RuntimeException('По счёту уже есть оплата. Изменение даты, суммы, покупателя или основания требует отдельной сверки; комплект не перезаписан.');
                }
            }
        }

        $data = [
            'direction' => FinanceInvoiceService::DIRECTION_OUTGOING,
            'number' => $number,
            'invoice_date' => $date,
            'counterparty_entity_type' => 'client',
            'counterparty_entity_id' => $clientId,
            'counterparty_name' => (string)($client['name'] ?? ''),
            'counterparty_inn' => (string)($client['inn'] ?? ''),
            'amount' => $amount,
            'vat_rate' => null,
            'basis' => $basis,
            'planned_payment_date' => null,
            'comment' => 'Создан при формировании комплекта документов рейса #' . (int)($trip['business_id'] ?? 0) . '.',
            'status' => 'issued',
        ];
        $invoiceId = $existing === null
            ? FinanceInvoiceService::createInvoice($pdo, $data, $userId, $role)
            : (int)$existing['id'];
        if ($existing !== null && (float)$existing['paid_amount'] <= 0.0) {
            FinanceInvoiceService::updateInvoice($pdo, $invoiceId, $data, $userId, $role);
        }
        $user = ['user_id' => $userId, 'role_code' => $role];
        FinanceObligationService::replaceInvoiceLinks($pdo, $invoiceId, $links, $user);
        FinanceInvoicePrepaymentService::reconcileInvoice($pdo, $invoiceId, $user);
        return $invoiceId;
    }
}
