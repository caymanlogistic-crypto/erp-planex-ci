<?php

use App\Core\Database;
use App\Service\FinanceInvoicePrepaymentService;
use App\Service\FinanceInvoiceService;
use App\Service\FinanceObligationService;
use App\Service\GeneratedClientInvoiceDocxParser;

verifyCsrfRequest();

$files = $_FILES['invoice_files'] ?? $_FILES['invoice_file'] ?? null;
if (!is_array($files)) {
    $_SESSION['invoice_error'] = 'Выберите DOCX-счета для загрузки.';
    redirect_to('/company/finance/invoices');
}
$uploads = [];
$names = $files['name'] ?? [];
foreach (is_array($names) ? array_keys($names) : [0] as $index) {
    $uploads[] = [
        'name' => is_array($names) ? ($files['name'][$index] ?? '') : $names,
        'tmp_name' => is_array($names) ? ($files['tmp_name'][$index] ?? '') : ($files['tmp_name'] ?? ''),
        'size' => is_array($names) ? ($files['size'][$index] ?? 0) : ($files['size'] ?? 0),
        'error' => is_array($names) ? ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) : ($files['error'] ?? UPLOAD_ERR_NO_FILE),
    ];
}
if ($uploads === [] || count($uploads) > 20) {
    $_SESSION['invoice_error'] = 'Выберите не более 20 DOCX-счетов за один раз.';
    redirect_to('/company/finance/invoices');
}

$messages = [];
$localPdo = null;
try {
    $companyId = (int)(getSessionCompanyId() ?? 0);
    $company = $db->fetch('SELECT * FROM companies WHERE id = ?', [$companyId]);
    if (!$company || ($company['status'] ?? '') !== 'active') {
        throw new RuntimeException('Компания недоступна.');
    }
    $localPdo = (new Database(companyDatabaseConfig($config, $company)))->connection();
    applyLocalMigrations($localPdo);
    FinanceObligationService::syncAllOperationalObligations($localPdo);
    $user = $_SESSION['user'] ?? [];
    $userId = (int)($user['user_id'] ?? 0);
    $roleCode = (string)($user['role_code'] ?? '');

    foreach ($uploads as $upload) {
        $storedPath = null;
        $lockName = null;
        $name = mb_substr(basename((string)$upload['name']), 0, 120);
        try {
            if ((int)$upload['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException($upload['error'] === UPLOAD_ERR_NO_FILE
                    ? 'Файл не выбран.'
                    : 'Ошибка загрузки файла. Проверьте размер и попробуйте ещё раз.');
            }
            if (strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'docx'
                || (int)$upload['size'] <= 0
                || (int)$upload['size'] > 5 * 1024 * 1024
                || !is_uploaded_file((string)$upload['tmp_name'])) {
                throw new RuntimeException('Допустим только DOCX-файл размером до 5 МБ.');
            }
            $parsed = GeneratedClientInvoiceDocxParser::parse((string)$upload['tmp_name']);
            if ((string)($company['inn'] ?? '') === '' || (string)$company['inn'] !== $parsed['seller_inn']) {
                throw new RuntimeException('ИНН поставщика в счёте не совпадает с ИНН текущей компании.');
            }
            $clientStmt = $localPdo->prepare("SELECT id,name,inn FROM clients WHERE inn=? AND status='active' AND deleted_at IS NULL");
            $clientStmt->execute([$parsed['buyer_inn']]);
            $clients = $clientStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($clients) !== 1) {
                throw new RuntimeException(count($clients) === 0
                    ? 'Покупатель с ИНН из счёта не найден среди активных клиентов. Сначала добавьте клиента.'
                    : 'По ИНН покупателя найдено несколько клиентов. Уточните справочник перед загрузкой.');
            }
            $client = $clients[0];

            // Serialize imports of the same invoice even when no matching row exists yet.
            $lockName = 'invoice-import-' . substr(hash('sha256', $companyId . ':' . $parsed['buyer_inn'] . ':' . $parsed['number']), 0, 40);
            $lock = $localPdo->prepare('SELECT GET_LOCK(?, 5)');
            $lock->execute([$lockName]);
            if ((int)$lock->fetchColumn() !== 1) {
                throw new RuntimeException('Счёт сейчас загружается другим пользователем. Повторите позже.');
            }
            $localPdo->beginTransaction();
            $duplicate = $localPdo->prepare("SELECT id FROM finance_invoices WHERE direction='OUTGOING' AND number=? AND counterparty_entity_type='client' AND (counterparty_entity_id=? OR counterparty_inn=?) LIMIT 1 FOR UPDATE");
            $duplicate->execute([$parsed['number'], (int)$client['id'], $parsed['buyer_inn']]);
            if ($duplicate->fetchColumn() !== false) {
                $localPdo->rollBack();
                $messages[] = ['type' => 'warn', 'text' => 'Данные счёта №' . $parsed['number'] . ' не загружены: такой счёт уже есть.'];
                continue;
            }

            $invoiceId = FinanceInvoiceService::createInvoice($localPdo, [
                'direction' => FinanceInvoiceService::DIRECTION_OUTGOING,
                'number' => $parsed['number'],
                'invoice_date' => $parsed['invoice_date'],
                'counterparty_entity_type' => 'client',
                'counterparty_entity_id' => (int)$client['id'],
                'counterparty_name' => (string)$client['name'],
                'counterparty_inn' => (string)$client['inn'],
                'amount' => $parsed['amount'],
                'vat_rate' => $parsed['vat_rate'],
                'basis' => $parsed['basis'],
                'planned_payment_date' => null,
                'comment' => 'Загружен из DOCX, созданного генератором документов.',
                'status' => 'issued',
            ], $userId, $roleCode);

            $linked = false;
            if (preg_match('/^\d{2}-(\d+)$/D', $parsed['number'], $numberMatch)) {
                $candidates = array_values(array_filter(
                    FinanceObligationService::fetchCompatibleForInvoice($localPdo, 'OUTGOING', 'client', (int)$client['id']),
                    static fn(array $row): bool => (int)($row['trip_business_id'] ?? 0) === (int)$numberMatch[1]
                        && (string)($row['available_to_invoice'] ?? '') === $parsed['amount']
                ));
                if (count($candidates) === 1) {
                    FinanceObligationService::replaceInvoiceLinks($localPdo, $invoiceId, [
                        ['obligation_id' => (int)$candidates[0]['id'], 'amount' => $parsed['amount']],
                    ], $user);
                    FinanceInvoicePrepaymentService::reconcileInvoice($localPdo, $invoiceId, $user);
                    $linked = true;
                }
            }

            $relativeDir = 'companies/' . $companyId . '/documents/finance_invoice/' . $invoiceId;
            $absoluteDir = storage_path($relativeDir);
            if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
                throw new RuntimeException('Не удалось создать каталог для счёта.');
            }
            $storedName = bin2hex(random_bytes(16)) . '.docx';
            $storedPath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;
            if (!move_uploaded_file((string)$upload['tmp_name'], $storedPath)) {
                throw new RuntimeException('Не удалось сохранить DOCX счёта.');
            }
            $document = $localPdo->prepare("INSERT INTO documents(entity_type,entity_id,document_type,original_name,stored_name,relative_path,mime_type,file_size,status,uploaded_by_user_id,uploaded_by_role) VALUES('finance_invoice',?,?,?,?,?,?,?,'uploaded',?,?)");
            $document->execute([
                $invoiceId, 'Счёт клиенту', mb_substr(basename((string)$upload['name']), 0, 500),
                $storedName, $relativeDir . '/' . $storedName,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                (int)$upload['size'], $userId ?: null, $roleCode ?: null,
            ]);
            $localPdo->commit();
            $messages[] = ['type' => 'success', 'text' => 'Счёт №' . $parsed['number'] . ' загружен.'
                . ($linked ? ' Платёжное обязательство привязано.' : ' Проверьте и при необходимости привяжите платёжное обязательство через редактирование.')];
        } catch (Throwable $e) {
            if ($localPdo->inTransaction()) $localPdo->rollBack();
            if ($storedPath !== null && is_file($storedPath)) @unlink($storedPath);
            $messages[] = ['type' => 'warn', 'text' => ($name !== '' ? $name . ': ' : '') . $e->getMessage()];
        } finally {
            if ($lockName !== null) {
                $release = $localPdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            }
        }
    }
} catch (Throwable $e) {
    if ($localPdo instanceof PDO && $localPdo->inTransaction()) $localPdo->rollBack();
    $_SESSION['invoice_error'] = $e->getMessage();
}
$_SESSION['invoice_import_messages'] = $messages;
redirect_to('/company/finance/invoices');
