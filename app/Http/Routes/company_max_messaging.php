<?php

require_once base_path('app/Support/crypto_helper.php');
require_once base_path('app/Service/MaxApiClient.php');
require_once base_path('app/Service/MaxMessagingService.php');
require_once base_path('app/Http/Controllers/Company/MaxMessagingController.php');

$maxMessagingController = new \App\Http\Controllers\Company\MaxMessagingController($config, $db);
$maxSettingsPage = 'company.max_settings';
$maxMessagesPage = 'company.max_messages';

$router->get('/company/max/settings', [$maxMessagingController, 'settings'], $maxSettingsPage);
$router->post('/company/max/settings/bots/save', [$maxMessagingController, 'saveBot'], $maxSettingsPage);
$router->post('/company/max/settings/bots/{id}/verify', [$maxMessagingController, 'verifyBot'], $maxSettingsPage);
$router->post('/company/max/settings/bots/{id}/webhook', [$maxMessagingController, 'configureWebhook'], $maxSettingsPage);
$router->post('/company/max/settings/chats/save', [$maxMessagingController, 'saveChat'], $maxSettingsPage);
$router->post('/company/max/settings/chats/{id}/active', [$maxMessagingController, 'setChatActive'], $maxSettingsPage);
$router->post('/company/max/settings/chats/{id}/verify', [$maxMessagingController, 'verifyChat'], $maxSettingsPage);
$router->post('/company/max/settings/test-send', [$maxMessagingController, 'testSend'], $maxSettingsPage);

$router->get('/company/max/messages', [$maxMessagingController, 'composer'], $maxMessagesPage);
$router->post('/company/max/messages/send', [$maxMessagingController, 'sendManual'], $maxMessagesPage);

// MAX calls this endpoint without an ERP session. Authentication is the
// per-bot X-Max-Bot-Api-Secret validated by MaxMessagingService.
$router->post('/max/webhook/{companyId}/{botId}', [$maxMessagingController, 'webhook']);
