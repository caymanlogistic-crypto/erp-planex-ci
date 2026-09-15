<?php

declare(strict_types=1);

use App\Service\MaxApiClient;
use App\Service\MaxMessagingService;

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Service/MaxApiClient.php';
require_once $root . '/app/Service/MaxMessagingService.php';

$dbName = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allowlist = array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($dbName === '' || !in_array($dbName, $allowlist, true)) {
    throw new RuntimeException('A guarded fixed runner DB is required.');
}
$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$ok = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

putenv('APP_ENCRYPTION_KEY=max-integration-test-key-only');
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->exec('DROP TABLE IF EXISTS `max_message_logs`,`max_chats`,`max_bots`');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

try {
    $pdo->exec((string)file_get_contents($root . '/database/migrations-local/133_create_max_integration.sql'));
    foreach (['max_bots','max_chats','max_message_logs'] as $table) {
        $ok((bool)$pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn(), 'Migration did not create ' . $table);
    }

    $calls = [];
    $factory = static function (string $token) use (&$calls): MaxApiClient {
        return new MaxApiClient($token, static function (string $method, string $url, ?array $body, array $headers) use (&$calls, $token): array {
            $calls[] = compact('method','url','body','headers','token');
            if (str_ends_with($url, '/me')) return ['user_id' => 501, 'first_name' => 'PLANEX Test Bot', 'username' => 'planex_test_bot', 'is_bot' => true];
            if (str_contains($url, '/chats/')) return ['chat_id' => '-7001', 'type' => 'chat', 'status' => 'active', 'title' => 'Руководство'];
            if (str_contains($url, '/subscriptions')) return ['success' => true];
            return ['message' => ['body' => ['mid' => 'm-test-1']]];
        });
    };
    $service = new MaxMessagingService($pdo, $factory);

    $botId = $service->saveBot(['name' => 'Основной бот', 'token' => 'top-secret-max-token', 'is_active' => '1']);
    $ok($botId > 0, 'Bot insert failed.');
    $rawBot = $pdo->query('SELECT * FROM max_bots WHERE id=' . $botId)->fetch(PDO::FETCH_ASSOC);
    $ok(is_array($rawBot) && $rawBot['token_encrypted'] !== 'top-secret-max-token', 'MAX token stored in plaintext.');
    $ok(str_starts_with((string)$rawBot['token_encrypted'], 'v2:'), 'MAX token must use AES-GCM v2 envelope.');
    $ok(str_starts_with((string)$rawBot['webhook_secret_encrypted'], 'v2:'), 'Webhook secret must be encrypted.');

    $service->verifyBot($botId);
    $bots = $service->listBots();
    $ok(($bots[0]['bot_user_id'] ?? '') === '501', 'Bot verification identity was not persisted.');
    $ok(!array_key_exists('token_encrypted', $bots[0]) && !array_key_exists('webhook_secret_encrypted', $bots[0]), 'Secrets leaked in listBots.');

    $chatConfigId = $service->saveChat([
        'bot_id' => $botId,
        'chat_id' => '-7001',
        'name' => 'Руководство',
        'purpose' => 'Основная группа руководства',
        'is_active' => '1',
    ]);
    $ok($chatConfigId > 0, 'Chat insert failed.');
    $active = $service->activeChats();
    $ok(count($active) === 1 && (string)$active[0]['chat_id'] === '-7001', 'Verified active chat not available for composer.');
    $service->setChatActive($chatConfigId, false);
    $ok(count($service->activeChats()) === 0, 'Disabled MAX chat remained available for composer.');
    $service->setChatActive($chatConfigId, true);
    $ok(count($service->activeChats()) === 1, 'Re-enabled active MAX chat did not return to composer.');

    $service->sendToChat($chatConfigId, 'Тест ERP → MAX', 'plain', false, 'test', 99);
    $log = $pdo->query('SELECT * FROM max_message_logs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $ok(is_array($log) && $log['status'] === 'sent' && $log['source'] === 'test' && $log['max_message_id'] === 'm-test-1', 'Successful MAX send was not logged.');

    $secret = cryptoDecrypt((string)$rawBot['webhook_secret_encrypted']);
    $failedSecret = false;
    try {
        $service->handleWebhook($botId, 'wrong-secret', ['update_type' => 'bot_added', 'chat_id' => -8002, 'is_channel' => true]);
    } catch (RuntimeException $e) {
        $failedSecret = $e->getMessage() === 'WEBHOOK_SECRET_MISMATCH';
    }
    $ok($failedSecret, 'Webhook accepted an invalid secret.');

    $service->handleWebhook($botId, $secret, ['update_type' => 'bot_added', 'chat_id' => -8002, 'is_channel' => true]);
    $service->handleWebhook($botId, $secret, ['update_type' => 'bot_added', 'chat_id' => -8002, 'is_channel' => true]);
    $count = (int)$pdo->query("SELECT COUNT(*) FROM max_chats WHERE bot_id={$botId} AND chat_id='-8002'")->fetchColumn();
    $ok($count === 1, 'Duplicate bot_added created duplicate MAX chat rows.');
    $channelId = (int)$pdo->query("SELECT id FROM max_chats WHERE bot_id={$botId} AND chat_id='-8002'")->fetchColumn();
    $service->sendToChat($channelId, 'Канал', 'markdown', false, 'manual', 99);
    $lastMessageCall = null;
    foreach (array_reverse($calls) as $call) if (str_contains($call['url'], '/messages?')) { $lastMessageCall = $call; break; }
    $ok(is_array($lastMessageCall) && ($lastMessageCall['body']['notify'] ?? false) === true, 'Channel message must force notify=true.');

    $service->handleWebhook($botId, $secret, ['update_type' => 'bot_removed', 'chat_id' => -8002]);
    $removed = $pdo->query("SELECT max_status,is_active FROM max_chats WHERE id={$channelId}")->fetch(PDO::FETCH_ASSOC);
    $ok($removed['max_status'] === 'removed' && (int)$removed['is_active'] === 0, 'bot_removed did not deactivate MAX chat.');

    echo "MAX_MYSQL_MIGRATION=PASS\n";
    echo "MAX_MYSQL_SECRET_STORAGE=PASS\n";
    echo "MAX_MYSQL_SEND_LOG=PASS\n";
    echo "MAX_MYSQL_WEBHOOK_IDEMPOTENCY=PASS\n";
    echo "MAX_MYSQL_CHANNEL_NOTIFY=PASS\n";
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('DROP TABLE IF EXISTS `max_message_logs`,`max_chats`,`max_bots`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

// Event regressions share this existing mandatory MAX CI gate and guarded DB.
(static function (): void { require __DIR__ . '/max_event_notifications_mysql_test.php'; })();
(static function (): void { require __DIR__ . '/max_events_migration_mysql_test.php'; })();
