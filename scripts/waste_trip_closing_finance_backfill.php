<?php
declare(strict_types=1);

// Read-only preview by default. Apply changes existing tenant business data and requires separate OWNER authorization.
if (PHP_SAPI !== 'cli') exit(1);

try {
    $root = rtrim((string)getenv('PLANEX_ROOT'), '/\\');
    $apply = getenv('WASTE_TRIP_CLOSING_BACKFILL_MODE') === 'apply';
    if (!is_file($root.'/bootstrap/app.php') || !is_file($root.'/.env')) {
        throw new RuntimeException('PLANEX_ROOT invalid');
    }
    if ($apply) {
        $sha = (string)getenv('PLANEX_SOURCE_SHA');
        $marker = (string)getenv('PLANEX_DEPLOY_MARKER');
        if (!preg_match('/^[a-f0-9]{40}$/D', $sha) || !is_file($marker)
            || trim((string)file_get_contents($marker)) !== $sha
            || getenv('WASTE_TRIP_CLOSING_BACKFILL_CONFIRM') !== 'APPLY_WASTE_TRIP_CLOSING_BACKFILL') {
            throw new RuntimeException('Exact deployed SHA and explicit backfill confirmation required');
        }
    }

    $config = require $root.'/bootstrap/app.php';
    require_once $root.'/app/Support/company_database.php';
    require_once $root.'/app/Service/WasteTripClosingService.php';
    $connect = static fn(array $db): PDO => new PDO(
        'mysql:host='.$db['host'].';port='.($db['port'] ?? 3306).';dbname='.$db['database'].';charset=utf8mb4',
        $db['username'], $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $central = $connect($config['database']);
    $companies = $central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    if (!$companies) throw new RuntimeException('No active tenants');
    $results = [];
    foreach ($companies as $company) {
        $pdo = $connect(companyDatabaseConfig($config, $company));
        if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== (string)$company['db_identifier']
            || !\App\Service\WasteTripClosingService::ready($pdo)) {
            throw new RuntimeException('Tenant database or closing schema mismatch');
        }
        $tripIds = $pdo->query("SELECT DISTINCT waste_trip_id FROM waste_trip_closing_documents
            UNION SELECT DISTINCT c.waste_trip_id FROM waste_trip_closing_finance_links l
              JOIN waste_trip_closing_documents c ON c.id=l.closing_document_id
            ORDER BY waste_trip_id")->fetchAll(PDO::FETCH_COLUMN);
        $before = (int)$pdo->query('SELECT COUNT(*) FROM waste_trip_closing_finance_links')->fetchColumn();
        $missing = 0;
        $stale = 0;
        $exists = $pdo->prepare('SELECT 1 FROM finance_closing_document_links WHERE bank_transaction_id=? AND document_id=? AND requirement_code=? LIMIT 1');
        $old = $pdo->prepare('SELECT l.closing_document_id,l.bank_transaction_id FROM waste_trip_closing_finance_links l JOIN waste_trip_closing_documents c ON c.id=l.closing_document_id WHERE c.waste_trip_id=?');
        foreach ($tripIds as $tripId) {
            $wanted = [];
            $documents = \App\Service\WasteTripClosingService::documents($pdo, (int)$tripId);
            $payments = \App\Service\WasteTripClosingService::paidBankTransactions($pdo, (int)$tripId);
            foreach ($payments as $payment) foreach ($documents as $document) {
                $bankId = (int)$payment['id'];
                $wanted[(int)$document['id'].':'.$bankId] = true;
                $exists->execute([$bankId, (int)$document['document_id'], (string)$document['requirement_code']]);
                if (!$exists->fetchColumn()) $missing++;
            }
            $old->execute([(int)$tripId]);
            foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $link) {
                if (!isset($wanted[(int)$link['closing_document_id'].':'.(int)$link['bank_transaction_id']])) $stale++;
            }
        }
        if ($apply) {
            $lockName = 'planex_trip_closing_backfill_'.substr(hash('sha256', (string)$company['db_identifier']), 0, 24);
            $lock = $pdo->prepare('SELECT GET_LOCK(?,10)');
            $lock->execute([$lockName]);
            if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Tenant backfill lock unavailable');
            try {
                $pdo->beginTransaction();
                foreach ($tripIds as $tripId) \App\Service\WasteTripClosingService::syncFinance($pdo, (int)$tripId);
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            } finally {
                $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            }
        }
        $after = (int)$pdo->query('SELECT COUNT(*) FROM waste_trip_closing_finance_links')->fetchColumn();
        $results[] = ['company_id' => (int)$company['id'], 'trips' => count($tripIds),
            'links_to_add' => $missing, 'stale_links_to_remove' => $stale,
            'generated_links_before' => $before, 'generated_links_after' => $after];
    }
    echo 'WASTE_TRIP_CLOSING_BACKFILL_SNAPSHOT='.json_encode($results, JSON_THROW_ON_ERROR)."\n";
    echo $apply ? "WASTE_TRIP_CLOSING_BACKFILL_APPLY_OK\n" : "WASTE_TRIP_CLOSING_BACKFILL_READ_ONLY_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'WASTE_TRIP_CLOSING_BACKFILL_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
