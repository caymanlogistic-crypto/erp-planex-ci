<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Service/MaxApiClient.php';

use App\Service\MaxApiClient;

$ok = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$calls = [];
$client = new MaxApiClient('secret-token-for-test', static function (string $method, string $url, ?array $body, array $headers) use (&$calls): array {
    $calls[] = compact('method', 'url', 'body', 'headers');
    if (str_ends_with($url, '/me')) return ['user_id' => 42, 'first_name' => 'PLANEX', 'username' => 'planex_bot', 'is_bot' => true];
    if (str_contains($url, '/chats/')) return ['chat_id' => '-100500', 'type' => 'chat', 'status' => 'active', 'title' => 'Руководство'];
    if (str_contains($url, '/subscriptions')) return ['success' => true];
    return ['message' => ['body' => ['mid' => 'mid-1']]];
});

$client->getMe();
$client->getChat('-100500');
$client->sendMessage('-100500', 'Проверка', 'markdown', false);
$client->createSubscription('https://plan-ex.ru/erpv2/max/webhook/1/2', 'Secret_12345');

$ok(MaxApiClient::BASE_URL === 'https://platform-api2.max.ru', 'MAX client must use platform-api2.max.ru.');
$ok(count($calls) === 4, 'MAX client transport calls missing.');
foreach ($calls as $call) {
    $ok(in_array('Authorization: secret-token-for-test', $call['headers'], true), 'MAX token must be sent in Authorization header.');
    $ok(!str_contains($call['url'], 'access_token='), 'MAX token must never be sent in query string.');
}
$ok($calls[2]['method'] === 'POST' && str_contains($calls[2]['url'], '/messages?chat_id=-100500'), 'Message must target chat_id through POST /messages.');
$ok(($calls[2]['body']['text'] ?? '') === 'Проверка' && ($calls[2]['body']['format'] ?? '') === 'markdown' && ($calls[2]['body']['notify'] ?? true) === false, 'Message body contract invalid.');
$ok($calls[3]['method'] === 'POST' && str_ends_with($calls[3]['url'], '/subscriptions'), 'Webhook must use POST /subscriptions.');
$ok(($calls[3]['body']['secret'] ?? '') === 'Secret_12345', 'Webhook secret must be sent to MAX.');
foreach (['bot_added','bot_removed','chat_title_changed'] as $event) $ok(in_array($event, $calls[3]['body']['update_types'] ?? [], true), 'Webhook update type missing: ' . $event);

$migration = (string)file_get_contents($root . '/database/migrations-local/133_create_max_integration.sql');
$service = (string)file_get_contents($root . '/app/Service/MaxMessagingService.php');
$controller = (string)file_get_contents($root . '/app/Http/Controllers/Company/MaxMessagingController.php');
$routes = (string)file_get_contents($root . '/app/Http/Routes/company_max_messaging.php');
$registry = (string)file_get_contents($root . '/app/Security/PageRegistry.php');
$settingsView = (string)file_get_contents($root . '/app/View/pages/company_max_settings.php');
$messagesView = (string)file_get_contents($root . '/app/View/pages/company_max_messages.php');
$index = (string)file_get_contents($root . '/public/index.php');
$layout = (string)file_get_contents($root . '/app/View/layouts/main.php');
$appCss = (string)file_get_contents($root . '/public/assets/css/app.css');
$maxCss = (string)file_get_contents($root . '/public/assets/css/max-messaging.css');
$workspaceCss = (string)file_get_contents($root . '/public/assets/css/workspace-benchmark.css');

foreach (['`max_bots`','`max_chats`','`max_message_logs`','`token_encrypted`','`webhook_secret_encrypted`'] as $token) $ok(str_contains($migration, $token), 'MAX migration missing ' . $token);
$ok(!preg_match('/`token`\s+(?:VARCHAR|TEXT|MEDIUMTEXT)/i', $migration), 'MAX token must not have a plaintext DB column.');
$ok(str_contains($migration, "ENUM('test','manual','event')"), 'Message log must reserve event source for ERP automation.');
$ok(str_contains($service, 'cryptoEncrypt(') && str_contains($service, 'cryptoDecrypt('), 'MAX secrets must use existing encryption helpers.');
$ok(str_contains($service, 'unset($bot[\'token_encrypted\'], $bot[\'webhook_secret_encrypted\'])'), 'Bot secrets must be redacted from admin reads.');
$ok(str_contains($service, 'hash_equals($expectedSecret, $providedSecret)'), 'Webhook secret must use timing-safe validation.');
$ok(str_contains($service, "if (\$type === 'bot_added')") && str_contains($service, "if (\$type === 'bot_removed')"), 'Webhook chat lifecycle handlers missing.');
$ok(str_contains($service, "(string)\$chat['chat_type'] === 'channel'"), 'Channel send must force push notifications.');

$ok(substr_count($controller, "requireRole(['company_owner'])") >= 8, 'MAX admin/manual actions must be owner-only.');
$ok(substr_count($controller, 'verifyCsrfRequest();') >= 6, 'MAX mutation routes must validate CSRF.');
$ok(str_contains($controller, "HTTP_X_MAX_BOT_API_SECRET"), 'MAX webhook header validation source missing.');
$ok(str_contains($controller, "getenv('APP_PUBLIC_URL')"), 'MAX webhook public URL override missing.');
$ok(str_contains($controller, "'test'") && str_contains($controller, "'manual'"), 'MAX send sources missing.');

$ok(str_contains($routes, "app/Support/crypto_helper.php"), 'MAX routes must explicitly load the encryption helper.');
$ok(str_contains($routes, "'company.max_settings'") && str_contains($routes, "'company.max_messages'"), 'MAX protected page keys missing.');
$ok(str_contains($routes, "/chats/{id}/active") && str_contains($service, 'setChatActive('), 'MAX chat activation control missing.');
$ok(str_contains($routes, "post('/max/webhook/{companyId}/{botId}'") && !str_contains($routes, "post('/max/webhook/{companyId}/{botId}', [\$maxMessagingController, 'webhook'],"), 'Webhook must be public at Router level and authenticate with MAX secret.');
$ok(str_contains($registry, "'company.max_messages'") && str_contains($registry, "'company.max_settings'") && substr_count($registry, 'ownerOnly: true') >= 4, 'MAX PageRegistry owner-only entries missing.');
$ok(str_contains($index, "company_max_messaging.php"), 'MAX routes must be registered in front controller.');
$ok(str_contains($layout, '/company/max/messages') && str_contains($layout, '/company/max/settings'), 'Owner fallback navigation must expose MAX pages.');

$ok(str_contains($settingsView, 'type="password"') && str_contains($settingsView, 'Тестовая отправка'), 'MAX settings must mask token input and provide test send.');
$ok(str_contains($settingsView, 'С июня 2026') && !str_contains($settingsView, 'Получить все группы'), 'MAX settings must not promise deprecated GET /chats discovery.');
$ok(str_contains($layout, "'/company/max/settings',"), 'MAX settings must use the same wide workspace contract as Clients.');
$ok(str_contains($workspaceCss, '.workspace-shell-1690{width:min(1690px,calc(100% - 12px));max-width:1690px'), 'System wide workspace must remain exactly 1690px max width.');
$ok(str_contains($appCss, "@import url('./max-messaging.css');"), 'MAX messaging stylesheet must be loaded by the system bundle.');
$ok(substr_count($settingsView, 'class="panel max-settings-shell"') === 1, 'MAX settings must render one system information box.');
$ok(substr_count($settingsView, 'class="max-settings-section"') === 4, 'MAX settings system box must contain four compact functional sections.');
$ok(substr_count($settingsView, 'class="checkbox-item"') >= 4 && substr_count($settingsView, 'class="checkbox-mark"') >= 4, 'MAX settings must use system checkboxes instead of native browser blue checkboxes.');
$ok(!str_contains($settingsView, '<label class="checkbox-label"><input type="checkbox"'), 'MAX settings must not use legacy native checkbox markup.');
$ok(!str_contains($settingsView, 'style="'), 'MAX settings must not rely on page-local inline layout styles.');
$ok(str_contains($maxCss, '.max-settings-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr))') && str_contains($maxCss, '.max-settings-section+.max-settings-section{border-top:1px solid var(--line-soft)}'), 'MAX settings compact grid/section layout contract missing.');
$ok(str_contains($messagesView, 'chat_ids[]') && str_contains($messagesView, 'maxlength="4000"'), 'Manual MAX composer must support multiple groups and API text limit.');

echo "MAX_API_V2_CONTRACT=PASS\n";
echo "MAX_SECRET_STORAGE_CONTRACT=PASS\n";
echo "MAX_WEBHOOK_CONTRACT=PASS\n";
echo "MAX_OWNER_UI_CONTRACT=PASS\n";
echo "MAX_MESSAGE_LOG_CONTRACT=PASS\n";
