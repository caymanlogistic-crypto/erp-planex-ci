<?php
$maxPlural = static function (int $count, string $one, string $few, string $many): string {
    $mod100 = $count % 100;
    $mod10 = $count % 10;
    if ($mod100 >= 11 && $mod100 <= 14) return $many;
    if ($mod10 === 1) return $one;
    if ($mod10 >= 2 && $mod10 <= 4) return $few;
    return $many;
};
$maxPlainText = static function (string $text, string $format = 'plain'): string {
    $plain = $text;
    if ($format === 'html') {
        $plain = preg_replace('/<[^>]+>/u', ' ', $plain) ?? $plain;
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } else {
        $plain = strip_tags($plain);
        $plain = preg_replace('/\[([^\]]+)\]\([^\)]+\)/u', '$1', $plain) ?? $plain;
        $plain = preg_replace('/(?:\*\*|__|~~|\+\+|\^\^|`)/u', '', $plain) ?? $plain;
        $plain = preg_replace('/(^|\s)[#>]+\s*/u', '$1', $plain) ?? $plain;
    }
    return trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
};
$maxPreview = static function (string $text, string $format = 'plain', int $limit = 130) use ($maxPlainText): string {
    return mb_strimwidth($maxPlainText($text, $format), 0, $limit, '…');
};
$maxSourceLabel = static fn (string $source): string => match ($source) {
    'manual' => 'Ручная',
    'event' => 'Событие',
    default => 'Тест',
};
$maxStatusLabel = static fn (string $status): string => $status === 'sent' ? 'Отправлено' : ($status === 'failed' ? 'Ошибка' : $status);
$maxBotCount = count($bots);
$maxChatCount = count($chats);
$maxActiveBots = array_values(array_filter($bots, static fn (array $bot): bool => !empty($bot['is_active'])));
$maxActiveBotCount = count($maxActiveBots);
$maxActiveChatCount = count($activeChats);
$maxWebhookHasError = false;
$maxWebhookAllOk = $maxActiveBotCount > 0;
foreach ($maxActiveBots as $bot) {
    $state = (string)($bot['webhook_status'] ?? 'not_configured');
    if ($state === 'error') $maxWebhookHasError = true;
    if ($state !== 'active') $maxWebhookAllOk = false;
}
if ($maxBotCount === 0) {
    $maxHealthLabel = 'Не настроено'; $maxHealthClass = 'badge-neutral';
} elseif ($maxActiveBotCount === 0) {
    $maxHealthLabel = 'Отключено'; $maxHealthClass = 'badge-neutral';
} elseif ($maxActiveChatCount === 0 || $maxWebhookHasError) {
    $maxHealthLabel = 'Требует внимания'; $maxHealthClass = 'badge-warning';
} else {
    $maxHealthLabel = 'Работает'; $maxHealthClass = 'badge-ok';
}
if ($maxActiveBotCount === 0) {
    $maxWebhookLabel = '—'; $maxWebhookClass = 'badge-neutral';
} elseif ($maxWebhookHasError) {
    $maxWebhookLabel = 'Ошибка'; $maxWebhookClass = 'badge-danger';
} elseif ($maxWebhookAllOk) {
    $maxWebhookLabel = 'OK'; $maxWebhookClass = 'badge-ok';
} else {
    $maxWebhookLabel = 'Не настроен'; $maxWebhookClass = 'badge-neutral';
}
$maxLastSendAt = (string)($logs[0]['created_at'] ?? '');
$maxRecentLogs = array_slice($logs, 0, 8);
$maxLogJson = json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">MAX — интеграция</h1>
        <div class="page-summary">Оперативный статус, отправка, настройки и журнал MAX.</div>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-primary" href="<?= app_url('/company/max/messages') ?>">Отправить сообщение</a>
    </div>
</div>

<?php if ($successFlash || $errorFlash): ?>
<div class="toast-stack max-toast-stack" aria-live="polite" aria-atomic="true">
    <?php if ($successFlash): ?>
    <div class="toast is-success" data-max-toast>
        <div class="toast-content"><div class="toast-title">MAX</div><div class="toast-desc"><?= e($successFlash) ?></div></div>
        <button type="button" class="toast-close" data-max-toast-close aria-label="Закрыть">×</button>
        <span class="toast-bar" aria-hidden="true"></span>
    </div>
    <?php endif; ?>
    <?php if ($errorFlash): ?>
    <div class="toast is-danger" data-max-toast data-max-toast-timeout="7000">
        <div class="toast-content"><div class="toast-title">MAX — ошибка</div><div class="toast-desc"><?= e($errorFlash) ?></div></div>
        <button type="button" class="toast-close" data-max-toast-close aria-label="Закрыть">×</button>
        <span class="toast-bar" aria-hidden="true"></span>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel max-settings-shell" data-max-settings-root>
    <div class="max-settings-top">
        <div class="ux-summary-strip max-summary-strip">
            <div class="max-summary-main">
                <span class="badge <?= e($maxHealthClass) ?>"><span class="dot"></span><?= e($maxHealthLabel) ?></span>
                <span><strong><?= $maxBotCount ?></strong> <?= e($maxPlural($maxBotCount, 'бот', 'бота', 'ботов')) ?></span>
                <span><strong><?= $maxChatCount ?></strong> <?= e($maxPlural($maxChatCount, 'группа', 'группы', 'групп')) ?></span>
                <span>Webhook: <span class="badge <?= e($maxWebhookClass) ?>"><span class="dot"></span><?= e($maxWebhookLabel) ?></span></span>
                <span>Последняя отправка: <strong><?= $maxLastSendAt !== '' ? e($maxLastSendAt) : '—' ?></strong></span>
            </div>
        </div>
        <nav class="ux-segmented max-settings-tabs" aria-label="Раздел MAX">
            <a href="#max-overview" class="is-active" data-max-tab="overview" aria-controls="max-overview">Обзор</a>
            <a href="#max-settings" data-max-tab="settings" aria-controls="max-settings">Настройки</a>
            <a href="#max-journal" data-max-tab="journal" aria-controls="max-journal">Журнал</a>
        </nav>
    </div>

    <section id="max-overview" class="max-tab-panel" data-max-panel="overview">
        <div class="grid two-columns max-overview-status-grid">
            <div class="table-card table-card--toolbar-only">
                <div class="table-toolbar"><div class="table-toolbar-left"><strong>Боты</strong></div><div class="table-toolbar-right"><span class="text-muted"><?= $maxActiveBotCount ?> активных</span></div></div>
                <div class="table-scroll">
                    <table class="table">
                        <thead><tr><th>Название</th><th>Статус</th><th>Webhook</th></tr></thead>
                        <tbody>
                        <?php if (!$bots): ?>
                            <tr><td colspan="3" class="col-muted">Боты MAX ещё не настроены.</td></tr>
                        <?php else: foreach ($bots as $bot): ?>
                            <?php $wh = (string)($bot['webhook_status'] ?? 'not_configured'); ?>
                            <tr>
                                <td><strong><?= e($bot['name']) ?></strong></td>
                                <td><span class="badge <?= !empty($bot['is_active']) ? 'badge-ok' : 'badge-neutral' ?>"><span class="dot"></span><?= !empty($bot['is_active']) ? 'Активен' : 'Отключён' ?></span></td>
                                <td><span class="badge <?= $wh === 'active' ? 'badge-ok' : ($wh === 'error' ? 'badge-danger' : 'badge-neutral') ?>"><span class="dot"></span><?= $wh === 'active' ? 'OK' : ($wh === 'error' ? 'Ошибка' : 'Не настроен') ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="table-card table-card--toolbar-only">
                <div class="table-toolbar"><div class="table-toolbar-left"><strong>Группы</strong></div><div class="table-toolbar-right"><span class="text-muted"><?= $maxActiveChatCount ?> активных</span></div></div>
                <div class="table-scroll">
                    <table class="table">
                        <thead><tr><th>Группа</th><th>Состояние</th></tr></thead>
                        <tbody>
                        <?php if (!$chats): ?>
                            <tr><td colspan="2" class="col-muted">Группы MAX ещё не добавлены.</td></tr>
                        <?php else: foreach ($chats as $chat): ?>
                            <?php $chatReady = !empty($chat['is_active']) && (string)$chat['max_status'] === 'active'; ?>
                            <tr>
                                <td><strong><?= e($chat['name']) ?></strong><div class="text-muted"><?= e($chat['bot_name']) ?></div></td>
                                <td><span class="badge <?= $chatReady ? 'badge-ok' : 'badge-neutral' ?>"><span class="dot"></span><?= $chatReady ? 'Активна' : (!empty($chat['is_active']) ? e((string)$chat['max_status']) : 'Отключена') ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="panel-section max-overview-send">
            <div class="max-section-inline-head">
                <div><strong>Тестовая отправка</strong><div class="text-muted">Отправляет реальное сообщение через текущий MAX test-send и записывает результат в журнал.</div></div>
            </div>
            <form method="post" action="<?= app_url('/company/max/settings/test-send') ?>" class="max-settings-grid max-settings-grid--test-send" data-max-tab-form="overview">
                <?= csrfField() ?>
                <div class="field max-span-3"><label class="field-label">Группа *</label><select class="field-select" name="chat_config_id" required><option value="">— Выберите —</option><?php foreach($activeChats as $chat): ?><option value="<?= (int)$chat['id'] ?>"><?= e($chat['name']) ?> · <?= e($chat['bot_name']) ?></option><?php endforeach; ?></select></div>
                <div class="field max-span-2"><label class="field-label">Формат</label><select class="field-select" name="format"><option value="plain">Обычный текст</option><option value="markdown">Markdown</option><option value="html">HTML</option></select></div>
                <div class="field max-span-2 max-checkbox-field"><label class="field-label">Push</label><label class="checkbox-item"><input type="checkbox" name="notify" value="1" checked><span class="checkbox-mark" aria-hidden="true"></span><span class="checkbox-label">Уведомить</span></label></div>
                <div class="field max-span-5"><label class="field-label">Сообщение *</label><textarea class="field-textarea max-test-message" name="message" maxlength="4000" rows="2" required>Тестовое сообщение из ERP PLANEX</textarea></div>
                <div class="field max-span-12 max-send-actions"><button class="btn btn-primary" type="submit" <?= !$activeChats ? 'disabled' : '' ?>>Отправить тест</button></div>
            </form>
        </div>

        <div class="table-card table-card--standard max-recent-log-card">
            <div class="table-toolbar">
                <div class="table-toolbar-left"><strong>Последние отправки</strong></div>
                <div class="toolbar-right"><button type="button" class="btn btn-secondary btn-sm" data-max-tab-target="journal">Весь журнал</button></div>
            </div>
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>Время</th><th>Группа</th><th>Результат</th><th>Сообщение</th></tr></thead>
                    <tbody>
                    <?php if (!$maxRecentLogs): ?>
                        <tr><td colspan="4" class="col-muted">Отправок пока нет.</td></tr>
                    <?php else: foreach ($maxRecentLogs as $log): ?>
                        <tr class="max-log-row" tabindex="0" data-max-log-id="<?= (int)$log['id'] ?>">
                            <td><?= e($log['created_at']) ?></td>
                            <td><?= e($log['chat_name'] ?: $log['external_chat_id'] ?: '—') ?></td>
                            <td><span class="badge <?= (string)$log['status'] === 'sent' ? 'badge-ok' : 'badge-danger' ?>"><span class="dot"></span><?= e($maxStatusLabel((string)$log['status'])) ?></span></td>
                            <td class="max-log-preview"><?= e($maxPreview((string)$log['message_text'], (string)($log['message_format'] ?? 'plain'))) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section id="max-settings" class="max-tab-panel" data-max-panel="settings" hidden>
        <div class="max-settings-section">
            <div class="max-settings-section-head">
                <div><h2 class="max-settings-section-title">Боты MAX</h2><div class="max-settings-section-sub">Технические данные и редкие действия скрыты до раскрытия конкретного бота.</div></div>
                <button type="button" class="btn btn-primary btn-sm" onclick="openModal('max-bot-add-modal')">+ Добавить бота</button>
            </div>
            <div class="max-settings-section-body max-settings-section-body--rows">
                <?php if (!$bots): ?>
                    <div class="max-empty-row">Боты MAX ещё не настроены</div>
                <?php else: foreach ($bots as $bot): $botId=(int)$bot['id']; $wh=(string)($bot['webhook_status'] ?? 'not_configured'); ?>
                    <div class="max-compact-row">
                        <div class="max-compact-primary"><strong><?= e($bot['name']) ?></strong></div>
                        <div><span class="badge <?= !empty($bot['is_active']) ? 'badge-ok' : 'badge-neutral' ?>"><span class="dot"></span><?= !empty($bot['is_active']) ? 'Активен' : 'Отключён' ?></span></div>
                        <div><span class="badge <?= $wh === 'active' ? 'badge-ok' : ($wh === 'error' ? 'badge-danger' : 'badge-neutral') ?>"><span class="dot"></span>Webhook <?= $wh === 'active' ? 'OK' : ($wh === 'error' ? 'ошибка' : 'не настроен') ?></span></div>
                        <div class="max-compact-actions"><button type="button" class="btn btn-secondary btn-sm" data-max-toggle="max-bot-detail-<?= $botId ?>">Подробнее</button></div>
                    </div>
                    <div class="panel-section max-detail-panel" id="max-bot-detail-<?= $botId ?>" hidden>
                        <div class="driver-view-card">
                            <div class="driver-view-grid">
                                <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Внутренний ID</div><div class="driver-view-cell driver-view-cell-value"><code><?= $botId ?></code></div></div>
                                <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">MAX ID</div><div class="driver-view-cell driver-view-cell-value"><code><?= e((string)($bot['bot_user_id'] ?? '—')) ?></code></div></div>
                                <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Создан</div><div class="driver-view-cell driver-view-cell-value"><?= e((string)($bot['created_at'] ?? '—')) ?></div></div>
                                <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Проверен</div><div class="driver-view-cell driver-view-cell-value"><?= e((string)($bot['verified_at'] ?? '—')) ?></div></div>
                                <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">MAX имя</div><div class="driver-view-cell driver-view-cell-value"><?= e((string)($bot['bot_first_name'] ?? '—')) ?><?= !empty($bot['bot_username']) ? ' (@'.e($bot['bot_username']).')' : '' ?></div></div>
                                <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Токен</div><div class="driver-view-cell driver-view-cell-value"><?= !empty($bot['has_token']) ? 'Сохранён зашифрованно' : 'Не задан' ?></div></div>
                                <div class="driver-view-row driver-view-row-wide"><div class="driver-view-cell driver-view-cell-label">Webhook URL</div><div class="driver-view-cell driver-view-cell-value max-break-value"><code><?= e($webhookUrls[$botId] ?? '') ?></code></div></div>
                                <?php if (!empty($bot['webhook_last_error'])): ?><div class="driver-view-row driver-view-row-wide"><div class="driver-view-cell driver-view-cell-label">Ошибка Webhook</div><div class="driver-view-cell driver-view-cell-value text-danger"><?= e($bot['webhook_last_error']) ?></div></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="max-detail-actions">
                            <form method="post" action="<?= app_url('/company/max/settings/bots/'.$botId.'/verify') ?>" data-max-tab-form="settings"><?= csrfField() ?><button class="btn btn-secondary btn-sm" type="submit">Проверить</button></form>
                            <form method="post" action="<?= app_url('/company/max/settings/bots/'.$botId.'/webhook') ?>" data-max-tab-form="settings"><?= csrfField() ?><button class="btn btn-secondary btn-sm" type="submit">Webhook</button></form>
                            <button type="button" class="btn btn-secondary btn-sm" data-max-toggle="max-bot-name-form-<?= $botId ?>">Изменить название</button>
                            <button type="button" class="btn btn-secondary btn-sm" data-max-toggle="max-bot-token-form-<?= $botId ?>">Изменить токен</button>
                            <form method="post" action="<?= app_url('/company/max/settings/bots/save') ?>" data-max-tab-form="settings">
                                <?= csrfField() ?><input type="hidden" name="id" value="<?= $botId ?>"><input type="hidden" name="name" value="<?= e($bot['name']) ?>"><?php if (empty($bot['is_active'])): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
                                <button class="btn btn-secondary btn-sm" type="submit"><?= !empty($bot['is_active']) ? 'Отключить' : 'Включить' ?></button>
                            </form>
                        </div>
                        <form method="post" action="<?= app_url('/company/max/settings/bots/save') ?>" class="max-inline-edit" id="max-bot-name-form-<?= $botId ?>" data-max-tab-form="settings" hidden>
                            <?= csrfField() ?><input type="hidden" name="id" value="<?= $botId ?>"><?php if (!empty($bot['is_active'])): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
                            <div class="field"><label class="field-label">Название в ERP</label><input class="field-input" name="name" maxlength="120" required value="<?= e($bot['name']) ?>"></div>
                            <button class="btn btn-primary btn-sm" type="submit">Сохранить</button>
                        </form>
                        <form method="post" action="<?= app_url('/company/max/settings/bots/save') ?>" class="max-inline-edit" id="max-bot-token-form-<?= $botId ?>" data-max-tab-form="settings" hidden>
                            <?= csrfField() ?><input type="hidden" name="id" value="<?= $botId ?>"><input type="hidden" name="name" value="<?= e($bot['name']) ?>"><?php if (!empty($bot['is_active'])): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
                            <div class="field"><label class="field-label">Новый API-токен MAX</label><input class="field-input" type="password" name="token" autocomplete="new-password" required placeholder="Новый токен"></div>
                            <button class="btn btn-primary btn-sm" type="submit">Сохранить токен</button>
                        </form>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="max-settings-section">
            <div class="max-settings-section-head">
                <div><h2 class="max-settings-section-title">Группы и каналы MAX</h2><div class="max-settings-section-sub">С июня 2026 MAX не выдаёт общий список через GET /chats. chat_id и технические поля скрыты в «Подробнее».</div></div>
                <button type="button" class="btn btn-primary btn-sm" onclick="openModal('max-chat-add-modal')" <?= !$bots ? 'disabled' : '' ?>>+ Добавить группу</button>
            </div>
            <div class="max-settings-section-body max-settings-section-body--table">
                <?php if (!$chats): ?>
                    <div class="max-empty-row"><?= $bots ? 'Группы ещё не добавлены' : 'Сначала добавьте и проверьте бота MAX.' ?></div>
                <?php else: ?>
                <div class="max-table-wrap"><div class="table-scroll"><table class="table">
                    <thead><tr><th>Группа</th><th>Бот</th><th>Название MAX</th><th>Статус</th><th>Действия</th></tr></thead>
                    <tbody>
                    <?php foreach ($chats as $chat): $chatId=(int)$chat['id']; $chatReady=!empty($chat['is_active']) && (string)$chat['max_status']==='active'; ?>
                        <tr>
                            <td><strong><?= e($chat['name']) ?></strong></td>
                            <td><?= e($chat['bot_name']) ?></td>
                            <td><?= e($chat['max_title'] ?: '—') ?></td>
                            <td><span class="badge <?= $chatReady ? 'badge-ok' : 'badge-neutral' ?>"><span class="dot"></span><?= $chatReady ? 'Активна' : (!empty($chat['is_active']) ? e((string)$chat['max_status']) : 'Отключена') ?></span></td>
                            <td><div class="inline-actions"><button type="button" class="btn btn-secondary btn-sm" data-max-toggle="max-chat-detail-<?= $chatId ?>">Подробнее</button><form method="post" action="<?= app_url('/company/max/settings/chats/'.$chatId.'/verify') ?>" data-max-tab-form="settings"><?= csrfField() ?><button class="btn btn-secondary btn-sm" type="submit">Проверить</button></form><form method="post" action="<?= app_url('/company/max/settings/chats/'.$chatId.'/active') ?>" data-max-tab-form="settings"><?= csrfField() ?><?php if (empty($chat['is_active'])): ?><input type="hidden" name="active" value="1"><?php endif; ?><button class="btn btn-secondary btn-sm" type="submit"><?= !empty($chat['is_active']) ? 'Отключить' : 'Включить' ?></button></form></div></td>
                        </tr>
                        <tr class="max-detail-row" id="max-chat-detail-<?= $chatId ?>" hidden><td colspan="5">
                            <div class="driver-view-card"><div class="driver-view-grid">
                                <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">chat_id</div><div class="driver-view-cell driver-view-cell-value"><code><?= e($chat['chat_id']) ?></code></div></div>
                                <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Тип</div><div class="driver-view-cell driver-view-cell-value"><?= e((string)$chat['chat_type']) ?></div></div>
                                <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Источник</div><div class="driver-view-cell driver-view-cell-value"><?= e((string)$chat['discovered_via']) ?></div></div>
                                <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Проверена</div><div class="driver-view-cell driver-view-cell-value"><?= e((string)($chat['last_verified_at'] ?? '—')) ?></div></div>
                                <div class="driver-view-row driver-view-row-wide"><div class="driver-view-cell driver-view-cell-label">Описание / назначение</div><div class="driver-view-cell driver-view-cell-value"><?= e($chat['purpose'] ?: '—') ?></div></div>
                            </div></div>
                        </td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div></div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section id="max-journal" class="max-tab-panel" data-max-panel="journal" hidden>
        <div class="table-card table-card--standard max-log-table-card">
            <div class="table-toolbar max-log-toolbar">
                <div class="found-label">Найдено: <b data-max-log-count><?= count($logs) ?></b></div>
                <div class="toolbar-right">
                    <select class="toolbar-select" data-max-log-source aria-label="Источник"><option value="">Все источники</option><option value="test">Тест</option><option value="manual">Ручная</option><option value="event">Событие</option></select>
                    <select class="toolbar-select" data-max-log-chat aria-label="Группа"><option value="">Все группы</option><?php foreach($chats as $chat): ?><option value="<?= (int)$chat['id'] ?>"><?= e($chat['name']) ?></option><?php endforeach; ?></select>
                    <select class="toolbar-select" data-max-log-status aria-label="Результат"><option value="">Все результаты</option><option value="sent">Отправлено</option><option value="failed">Ошибка</option></select>
                    <input type="text" class="toolbar-search" data-max-log-search placeholder="Поиск по журналу" autocomplete="off">
                </div>
            </div>
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>Время</th><th>Источник</th><th>Группа</th><th>Результат</th><th>Сообщение</th></tr></thead>
                    <tbody data-max-log-body>
                    <?php if (!$logs): ?>
                        <tr><td colspan="5" class="col-muted">Отправок пока нет.</td></tr>
                    <?php else: foreach ($logs as $log): $plain=$maxPlainText((string)$log['message_text'], (string)($log['message_format'] ?? 'plain')); ?>
                        <tr class="max-log-row" tabindex="0" data-max-log-id="<?= (int)$log['id'] ?>" data-source="<?= e((string)$log['source']) ?>" data-chat="<?= (int)($log['chat_config_id'] ?? 0) ?>" data-status="<?= e((string)$log['status']) ?>" data-search="<?= e(mb_strtolower($plain.' '.($log['chat_name'] ?? '').' '.($log['error_message'] ?? ''))) ?>">
                            <td><?= e($log['created_at']) ?></td>
                            <td><?= e($maxSourceLabel((string)$log['source'])) ?></td>
                            <td><?= e($log['chat_name'] ?: $log['external_chat_id'] ?: '—') ?></td>
                            <td><span class="badge <?= (string)$log['status'] === 'sent' ? 'badge-ok' : 'badge-danger' ?>"><span class="dot"></span><?= e($maxStatusLabel((string)$log['status'])) ?></span></td>
                            <td class="max-log-preview"><?= e($maxPreview((string)$log['message_text'], (string)($log['message_format'] ?? 'plain'))) ?><?php if (!empty($log['error_message'])): ?><span class="text-danger"> · <?= e(mb_strimwidth((string)$log['error_message'],0,70,'…')) ?></span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($logs): ?><div class="table-footer"><span class="footer-label">Нажмите на запись, чтобы открыть полную диагностику.</span></div><?php endif; ?>
        </div>
    </section>
</div>

<div class="modal-overlay" id="max-bot-add-modal" role="dialog" aria-modal="true" aria-labelledby="max-bot-add-title" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg">
        <div class="modal-head"><span class="modal-title" id="max-bot-add-title">Добавить бота MAX</span><button type="button" class="modal-close" onclick="closeModal('max-bot-add-modal')">×</button></div>
        <form method="post" action="<?= app_url('/company/max/settings/bots/save') ?>" data-max-tab-form="settings">
            <div class="modal-body">
                <?= csrfField() ?>
                <div class="form-grid-2">
                    <div class="field"><label class="field-label">Название в ERP *</label><input class="field-input" name="name" maxlength="120" required placeholder="Например: PLANEX уведомления"></div>
                    <div class="field"><label class="field-label">API-токен MAX *</label><input class="field-input" type="password" name="token" autocomplete="new-password" required placeholder="Токен будет зашифрован"></div>
                </div>
                <div class="field max-modal-checkbox"><label class="checkbox-item"><input type="checkbox" name="is_active" value="1" checked><span class="checkbox-mark" aria-hidden="true"></span><span class="checkbox-label">Активен</span></label></div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-secondary" onclick="closeModal('max-bot-add-modal')">Отмена</button><button class="btn btn-primary" type="submit">Добавить и проверить</button></div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="max-chat-add-modal" role="dialog" aria-modal="true" aria-labelledby="max-chat-add-title" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg">
        <div class="modal-head"><span class="modal-title" id="max-chat-add-title">Добавить группу MAX</span><button type="button" class="modal-close" onclick="closeModal('max-chat-add-modal')">×</button></div>
        <form method="post" action="<?= app_url('/company/max/settings/chats/save') ?>" data-max-tab-form="settings">
            <div class="modal-body">
                <?= csrfField() ?>
                <div class="form-grid-2">
                    <div class="field"><label class="field-label">Бот *</label><select class="field-select" name="bot_id" required><option value="">— Выберите —</option><?php foreach($bots as $bot): ?><option value="<?= (int)$bot['id'] ?>"><?= e($bot['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label class="field-label">chat_id *</label><input class="field-input" name="chat_id" required inputmode="numeric" placeholder="Например: -123456789"></div>
                    <div class="field"><label class="field-label">Название в ERP</label><input class="field-input" name="name" maxlength="160" placeholder="Если пусто — возьмём название из MAX"></div>
                    <div class="field"><label class="field-label">Описание / назначение</label><input class="field-input" name="purpose" maxlength="500" placeholder="Например: уведомления для логистов"></div>
                </div>
                <div class="field max-modal-checkbox"><label class="checkbox-item"><input type="checkbox" name="is_active" value="1" checked><span class="checkbox-mark" aria-hidden="true"></span><span class="checkbox-label">Использовать для отправки</span></label></div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-secondary" onclick="closeModal('max-chat-add-modal')">Отмена</button><button class="btn btn-primary" type="submit">Добавить группу</button></div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="max-log-detail-modal" role="dialog" aria-modal="true" aria-labelledby="max-log-detail-title" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg">
        <div class="modal-head"><span class="modal-title" id="max-log-detail-title">Запись журнала MAX</span><button type="button" class="modal-close" onclick="closeModal('max-log-detail-modal')">×</button></div>
        <div class="modal-body">
            <div class="driver-view-card"><div class="driver-view-grid" data-max-log-meta></div></div>
            <div class="max-log-detail-section"><div class="field-label">Читаемый текст</div><div class="max-log-readable" data-max-log-readable></div></div>
            <div class="max-log-detail-section"><div class="field-label">Raw payload</div><pre class="max-log-raw" data-max-log-raw></pre></div>
            <div class="max-log-detail-section" data-max-log-error-wrap hidden><div class="field-label">Ошибка</div><div class="text-danger" data-max-log-error></div></div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-secondary" onclick="closeModal('max-log-detail-modal')">Закрыть</button></div>
    </div>
</div>
<script type="application/json" id="max-log-details-data"><?= $maxLogJson ?: '[]' ?></script>
<script src="<?= app_url('/assets/js/max-messaging-settings.js') ?>?v=<?= filemtime(base_path('public/assets/js/max-messaging-settings.js')) ?>"></script>
