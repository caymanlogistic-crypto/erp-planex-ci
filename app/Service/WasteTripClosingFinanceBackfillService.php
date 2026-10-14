<?php
declare(strict_types=1);

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

/** One-time, insert-only historical projection with an exact per-run undo manifest. */
final class WasteTripClosingFinanceBackfillService
{
    /** @return list<array{trip_id:int,closing_document_id:int,bank_transaction_id:int,document_id:int,requirement_code:string}> */
    public static function plan(PDO $pdo): array
    {
        $tripIds = $pdo->query("SELECT DISTINCT c.waste_trip_id FROM waste_trip_closing_documents c
            JOIN documents d ON d.id=c.document_id AND d.deleted_at IS NULL
            WHERE c.deleted_at IS NULL ORDER BY c.waste_trip_id")->fetchAll(PDO::FETCH_COLUMN);
        $exists = $pdo->prepare('SELECT 1 FROM finance_closing_document_links WHERE bank_transaction_id=? AND document_id=? LIMIT 1');
        $plan = [];
        foreach ($tripIds as $tripId) {
            $documents = WasteTripClosingService::documents($pdo, (int)$tripId);
            $payments = WasteTripClosingService::paidBankTransactions($pdo, (int)$tripId);
            foreach ($payments as $payment) foreach ($documents as $document) {
                $bankId = (int)$payment['id'];
                $documentId = (int)$document['document_id'];
                $exists->execute([$bankId, $documentId]);
                if ($exists->fetchColumn()) continue;
                $plan[] = [
                    'trip_id' => (int)$tripId,
                    'closing_document_id' => (int)$document['id'],
                    'bank_transaction_id' => $bankId,
                    'document_id' => $documentId,
                    'requirement_code' => (string)$document['requirement_code'],
                ];
            }
        }
        usort($plan, static fn(array $a, array $b): int =>
            [$a['trip_id'], $a['bank_transaction_id'], $a['closing_document_id']]
            <=> [$b['trip_id'], $b['bank_transaction_id'], $b['closing_document_id']]);
        return $plan;
    }

    public static function digest(array $plan): string
    {
        return hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param list<array<string,int|string>> $plan */
    public static function apply(PDO $pdo, array $plan, int $companyId, string $sourceSha, string $manifestPath): array
    {
        if (is_file($manifestPath)) throw new RuntimeException('Backfill manifest already exists');
        $records = [];
        $pdo->beginTransaction();
        try {
            $exists = $pdo->prepare('SELECT 1 FROM finance_closing_document_links WHERE bank_transaction_id=? AND document_id=? LIMIT 1');
            $insert = $pdo->prepare('INSERT IGNORE INTO finance_closing_document_links
                (bank_transaction_id,document_id,requirement_code,is_edo) VALUES (?,?,?,0)');
            $link = $pdo->prepare('INSERT INTO waste_trip_closing_finance_links
                (finance_link_id,closing_document_id,bank_transaction_id) VALUES (?,?,?)');
            foreach ($plan as $row) {
                $exists->execute([(int)$row['bank_transaction_id'], (int)$row['document_id']]);
                if ($exists->fetchColumn()) continue;
                $insert->execute([(int)$row['bank_transaction_id'], (int)$row['document_id'], (string)$row['requirement_code']]);
                if ($insert->rowCount() !== 1) continue;
                $financeLinkId = (int)$pdo->lastInsertId();
                $link->execute([$financeLinkId, (int)$row['closing_document_id'], (int)$row['bank_transaction_id']]);
                $records[] = $row + ['finance_link_id' => $financeLinkId];
            }
            $manifest = ['version' => 1, 'company_id' => $companyId, 'source_sha' => $sourceSha,
                'created_at_utc' => gmdate('c'), 'records' => $records];
            $manifest['checksum'] = self::digest($manifest);
            self::writeExclusive($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $pdo->commit();
            return ['inserted' => count($records), 'manifest_checksum' => $manifest['checksum']];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public static function rollback(PDO $pdo, int $companyId, string $manifestPath): array
    {
        if (!is_file($manifestPath)) throw new RuntimeException('Backfill manifest missing');
        $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1 || ($manifest['company_id'] ?? null) !== $companyId) {
            throw new RuntimeException('Backfill manifest scope mismatch');
        }
        $checksum = (string)($manifest['checksum'] ?? '');
        unset($manifest['checksum']);
        if (!hash_equals($checksum, self::digest($manifest))) throw new RuntimeException('Backfill manifest checksum mismatch');
        $records = $manifest['records'] ?? null;
        if (!is_array($records)) throw new RuntimeException('Backfill manifest records invalid');
        $removed = 0;
        $pdo->beginTransaction();
        try {
            $current = $pdo->prepare('SELECT l.finance_link_id,l.closing_document_id,l.bank_transaction_id,
                    f.document_id,f.requirement_code,f.is_edo
                FROM waste_trip_closing_finance_links l
                JOIN finance_closing_document_links f ON f.id=l.finance_link_id
                WHERE l.finance_link_id=? FOR UPDATE');
            $financeExists = $pdo->prepare('SELECT 1 FROM finance_closing_document_links WHERE id=? LIMIT 1');
            $provenanceExists = $pdo->prepare('SELECT 1 FROM waste_trip_closing_finance_links WHERE finance_link_id=? LIMIT 1');
            $deleteProvenance = $pdo->prepare('DELETE FROM waste_trip_closing_finance_links WHERE finance_link_id=?');
            $deleteFinance = $pdo->prepare('DELETE FROM finance_closing_document_links WHERE id=?');
            foreach ($records as $record) {
                if (!is_array($record) || (int)($record['finance_link_id'] ?? 0) <= 0) throw new RuntimeException('Invalid recorded link');
                $id = (int)$record['finance_link_id'];
                $current->execute([$id]);
                $row = $current->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $financeExists->execute([$id]);
                    $provenanceExists->execute([$id]);
                    if ($financeExists->fetchColumn() || $provenanceExists->fetchColumn()) {
                        throw new RuntimeException('Recorded link changed since backfill: '.$id);
                    }
                    continue;
                }
                foreach (['closing_document_id', 'bank_transaction_id', 'document_id', 'requirement_code'] as $key) {
                    if ((string)$row[$key] !== (string)$record[$key]) throw new RuntimeException('Recorded link changed since backfill: '.$id);
                }
                if ((int)$row['is_edo'] !== 0) throw new RuntimeException('Recorded link was edited since backfill: '.$id);
                $deleteProvenance->execute([$id]);
                $deleteFinance->execute([$id]);
                $removed++;
            }
            $pdo->commit();
            if (!is_file($manifestPath.'.rolled-back')) self::writeExclusive($manifestPath.'.rolled-back', json_encode([
                'checksum' => $checksum, 'removed' => $removed, 'completed_at_utc' => gmdate('c')
            ], JSON_THROW_ON_ERROR));
            return ['removed' => $removed, 'already_absent' => count($records) - $removed];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private static function writeExclusive(string $path, string $content): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) throw new RuntimeException('Cannot create backfill evidence');
        try {
            @chmod($path, 0600);
            $written = 0;
            while ($written < strlen($content)) {
                $n = fwrite($handle, substr($content, $written));
                if ($n === false || $n === 0) throw new RuntimeException('Cannot persist backfill evidence');
                $written += $n;
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Cannot persist backfill evidence');
            }
        } finally {
            fclose($handle);
        }
    }
}
