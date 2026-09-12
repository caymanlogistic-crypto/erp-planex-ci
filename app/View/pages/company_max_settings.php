<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">MAX — интеграция</h1>
        <div class="page-summary">Боты, группы, Webhook, тестовая отправка и журнал сообщений.</div>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-primary" href="<?= app_url('/company/max/messages') ?>">Отправить сообщение</a>
    </div>
</div>

<?php if ($successFlash): ?><div class="notice success max-settings-notice"><?= e($successFlash) ?></div><?php endif; ?>
<?php if ($errorFlash): ?><div class="notice warn max-settings-notice"><?= e($errorFlash) ?></div><?php endif; ?>

<div class="panel max-settings-shell">
    <section class="max-settings-section" aria-labelledby="max-bots-title">
        <div class="max-settings-section-head">
            <div>
                <h2 class="max-settings-section-title" id="max-bots-title">Боты MAX</h2>
                <div class="max-settings-section-sub">API-токен сохраняется зашифрованно и после сохранения не показывается. Новый бот сразу проверяется через GET /me.</div>
            </div>
        </div>
        <div class="max-settings-section-body">
            <form method="post" action="<?= app_url('/company/max/settings/bots/save') ?>" class="max-settings-grid max-settings-grid--bot-add">
                <?= csrfField() ?>
                <div class="field max-span-4">
                    <label class="field-label">Название в ERP *</label>
                    <input class="field-input" name="name" maxlength="120" required placeholder="Например: PLANEX уведомления">
                </div>
                <div class="field max-span-5">
                    <label class="field-label">API-токен MAX *</label>
                    <input class="field-input" type="password" name="token" autocomplete="new-password" required placeholder="Токен будет зашифрован">
                </div>
                <div class="field max-span-1 max-checkbox-field">
                    <label class="field-label">Статус</label>
                    <label class="checkbox-item">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <span class="checkbox-mark" aria-hidden="true"></span>
                        <span class="checkbox-label">Активен</span>
                    </label>
                </div>
                <div class="field max-span-2 max-action-field">
                    <button class="btn btn-primary" type="submit">Добавить и проверить</button>
                </div>
            </form>

            <?php if (!$bots): ?>
                <div class="max-empty-row">Боты MAX ещё не настроены</div>
            <?php else: ?>
                <div class="max-bot-list">
                    <?php foreach ($bots as $bot): $botId=(int)$bot['id']; ?>
                    <div class="max-bot-item">
                        <div class="max-bot-summary">
                            <div class="max-bot-main">
                                <strong><?= e($bot['name']) ?></strong>
                                <span>ID <?= $botId ?> · токен <?= !empty($bot['has_token']) ? 'сохранён' : 'не задан' ?> · <?= !empty($bot['verified_at']) ? 'проверен '.e($bot['verified_at']) : 'не проверен' ?></span>
                                <?php if (!empty($bot['bot_first_name']) || !empty($bot['bot_username'])): ?>
                                    <span>MAX: <?= e($bot['bot_first_name'] ?: 'бот') ?><?= !empty($bot['bot_username']) ? ' (@'.e($bot['bot_username']).')' : '' ?><?= !empty($bot['bot_user_id']) ? ' · user_id '.e($bot['bot_user_id']) : '' ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="max-bot-summary-actions">
                                <span class="badge <?= !empty($bot['is_active']) ? 'badge-ok' : '' ?>"><span class="dot"></span><?= !empty($bot['is_active']) ? 'Активен' : 'Отключён' ?></span>
                                <span class="badge"><?= e($bot['webhook_status']) ?></span>
                                <form method="post" action="<?= app_url('/company/max/settings/bots/'.$botId.'/verify') ?>"><?= csrfField() ?><button class="btn btn-secondary btn-sm" type="submit">Проверить</button></form>
                                <form method="post" action="<?= app_url('/company/max/settings/bots/'.$botId.'/webhook') ?>"><?= csrfField() ?><button class="btn btn-secondary btn-sm" type="submit">Webhook</button></form>
                            </div>
                        </div>
                        <form method="post" action="<?= app_url('/company/max/settings/bots/save') ?>" class="max-settings-grid max-settings-grid--bot-edit">
                            <?= csrfField() ?><input type="hidden" name="id" value="<?= $botId ?>">
                            <div class="field max-span-4"><label class="field-label">Название</label><input class="field-input" name="name" maxlength="120" required value="<?= e($bot['name']) ?>"></div>
                            <div class="field max-span-5"><label class="field-label">Заменить токен</label><input class="field-input" type="password" name="token" autocomplete="new-password" placeholder="Пусто — оставить текущий"></div>
                            <div class="field max-span-1 max-checkbox-field"><label class="field-label">Статус</label><label class="checkbox-item"><input type="checkbox" name="is_active" value="1" <?= !empty($bot['is_active']) ? 'checked' : '' ?>><span class="checkbox-mark" aria-hidden="true"></span><span class="checkbox-label">Активен</span></label></div>
                            <div class="field max-span-2 max-action-field"><button class="btn btn-secondary" type="submit">Сохранить</button></div>
                        </form>
                        <div class="max-webhook-line"><strong>Webhook:</strong> <code><?= e($webhookUrls[$botId] ?? '') ?></code></div>
                        <?php if (!empty($bot['webhook_last_error'])): ?><div class="notice warn max-inline-notice"><?= e($bot['webhook_last_error']) ?></div><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="max-settings-section" aria-labelledby="max-chats-title">
        <div class="max-settings-section-head">
            <div>
                <h2 class="max-settings-section-title" id="max-chats-title">Группы и каналы MAX</h2>
                <div class="max-settings-section-sub">С июня 2026 MAX не выдаёт общий список через GET /chats. chat_id добавляется вручную или приходит через Webhook.</div>
            </div>
        </div>
        <div class="max-settings-section-body">
            <?php if ($bots): ?>
            <form method="post" action="<?= app_url('/company/max/settings/chats/save') ?>" class="max-settings-grid max-settings-grid--chat-add">
                <?= csrfField() ?>
                <div class="field max-span-2"><label class="field-label">Бот *</label><select class="field-select" name="bot_id" required><option value="">— Выберите —</option><?php foreach($bots as $bot): ?><option value="<?= (int)$bot['id'] ?>"><?= e($bot['name']) ?></option><?php endforeach; ?></select></div>
                <div class="field max-span-2"><label class="field-label">chat_id *</label><input class="field-input" name="chat_id" required inputmode="numeric" placeholder="Например: -123456789"></div>
                <div class="field max-span-3"><label class="field-label">Название в ERP</label><input class="field-input" name="name" maxlength="160" placeholder="Если пусто — возьмём из MAX"></div>
                <div class="field max-span-2"><label class="field-label">Назначение</label><input class="field-input" name="purpose" maxlength="500" placeholder="Руководство / Логисты"></div>
                <div class="field max-span-1 max-checkbox-field"><label class="field-label">Статус</label><label class="checkbox-item"><input type="checkbox" name="is_active" value="1" checked><span class="checkbox-mark" aria-hidden="true"></span><span class="checkbox-label">Активна</span></label></div>
                <div class="field max-span-2 max-action-field"><button class="btn btn-primary" type="submit">Добавить группу</button></div>
            </form>
            <?php else: ?>
                <div class="max-empty-row max-empty-row--compact">Сначала добавьте и проверьте бота MAX.</div>
            <?php endif; ?>

            <?php if (!$chats): ?>
                <div class="max-empty-row">Группы ещё не добавлены</div>
            <?php else: ?>
                <div class="max-table-wrap">
                    <div class="table-scroll">
                        <table class="table">
                            <thead><tr><th>Группа</th><th>Бот</th><th>chat_id</th><th>MAX</th><th>Назначение</th><th>Действия</th></tr></thead>
                            <tbody>
                            <?php foreach($chats as $chat): ?>
                                <tr>
                                    <td><strong><?= e($chat['name']) ?></strong><div class="text-muted"><?= !empty($chat['is_active']) ? 'активна' : 'отключена' ?> · <?= e($chat['discovered_via']) ?></div></td>
                                    <td><?= e($chat['bot_name']) ?></td>
                                    <td><code><?= e($chat['chat_id']) ?></code></td>
                                    <td><?= e($chat['max_title'] ?: '—') ?><div class="text-muted"><?= e($chat['chat_type']) ?> · <?= e($chat['max_status']) ?></div></td>
                                    <td><?= e($chat['purpose'] ?: '—') ?></td>
                                    <td><div class="inline-actions"><form method="post" action="<?= app_url('/company/max/settings/chats/'.(int)$chat['id'].'/verify') ?>"><?= csrfField() ?><button class="btn btn-secondary btn-sm" type="submit">Проверить</button></form><form method="post" action="<?= app_url('/company/max/settings/chats/'.(int)$chat['id'].'/active') ?>"><?= csrfField() ?><?php if(empty($chat['is_active'])): ?><input type="hidden" name="active" value="1"><?php endif; ?><button class="btn btn-secondary btn-sm" type="submit"><?= !empty($chat['is_active']) ? 'Отключить' : 'Включить' ?></button></form></div></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="max-settings-section" aria-labelledby="max-test-title">
        <div class="max-settings-section-head">
            <div>
                <h2 class="max-settings-section-title" id="max-test-title">Тестовая отправка</h2>
                <div class="max-settings-section-sub">Реальное сообщение через POST /messages. Результат сохраняется в журнал.</div>
            </div>
        </div>
        <div class="max-settings-section-body">
            <form method="post" action="<?= app_url('/company/max/settings/test-send') ?>" class="max-settings-grid max-settings-grid--test-send">
                <?= csrfField() ?>
                <div class="field max-span-4"><label class="field-label">Группа *</label><select class="field-select" name="chat_config_id" required><option value="">— Выберите —</option><?php foreach($activeChats as $chat): ?><option value="<?= (int)$chat['id'] ?>"><?= e($chat['name']) ?> · <?= e($chat['bot_name']) ?></option><?php endforeach; ?></select></div>
                <div class="field max-span-3"><label class="field-label">Формат</label><select class="field-select" name="format"><option value="plain">Обычный текст</option><option value="markdown">Markdown</option><option value="html">HTML</option></select></div>
                <div class="field max-span-2 max-checkbox-field"><label class="field-label">Push</label><label class="checkbox-item"><input type="checkbox" name="notify" value="1" checked><span class="checkbox-mark" aria-hidden="true"></span><span class="checkbox-label">Уведомить</span></label></div>
                <div class="field max-span-3"></div>
                <div class="field max-span-10"><label class="field-label">Сообщение *</label><textarea class="field-textarea max-test-message" name="message" maxlength="4000" rows="3" required>Тестовое сообщение из ERP PLANEX</textarea></div>
                <div class="field max-span-2 max-action-field"><button class="btn btn-primary" type="submit" <?= !$activeChats ? 'disabled' : '' ?>>Отправить тест</button></div>
            </form>
        </div>
    </section>

    <section class="max-settings-section" aria-labelledby="max-log-title">
        <div class="max-settings-section-head">
            <div>
                <h2 class="max-settings-section-title" id="max-log-title">Журнал MAX</h2>
                <div class="max-settings-section-sub">Последние 50 попыток отправки.</div>
            </div>
        </div>
        <div class="max-settings-section-body max-settings-section-body--table">
            <?php if (!$logs): ?>
                <div class="max-empty-row">Отправок пока нет</div>
            <?php else: ?>
                <div class="max-table-wrap">
                    <div class="table-scroll">
                        <table class="table">
                            <thead><tr><th>Время</th><th>Источник</th><th>Группа</th><th>Результат</th><th>Сообщение / ошибка</th></tr></thead>
                            <tbody>
                            <?php foreach($logs as $log): ?><tr><td><?= e($log['created_at']) ?></td><td><?= e($log['source']) ?></td><td><?= e($log['chat_name'] ?: $log['external_chat_id'] ?: '—') ?></td><td><strong><?= e($log['status']) ?></strong></td><td><?= e(mb_strimwidth((string)$log['message_text'],0,180,'…')) ?><?php if(!empty($log['error_message'])): ?><div class="text-danger"><?= e($log['error_message']) ?></div><?php endif; ?></td></tr><?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>
