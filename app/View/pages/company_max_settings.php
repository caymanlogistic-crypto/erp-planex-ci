<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">MAX — интеграция</h1>
        <div class="page-summary">Боты, группы, Webhook и тестовая отправка сообщений. API-токены в интерфейсе после сохранения не показываются.</div>
    </div>
    <div class="page-head-actions"><a class="btn btn-primary" href="<?= app_url('/company/max/messages') ?>">Отправить сообщение</a></div>
</div>

<?php if ($successFlash): ?><div class="notice success"><?= e($successFlash) ?></div><?php endif; ?>
<?php if ($errorFlash): ?><div class="notice warn"><?= e($errorFlash) ?></div><?php endif; ?>

<div class="panel" style="margin-bottom:18px">
    <div class="panel-head"><div><h3 class="panel-head-title">Добавить бота MAX</h3><div class="text-muted">Создайте бота на платформе MAX и вставьте его API-токен. После сохранения ERP сразу попробует GET /me.</div></div></div>
    <div class="panel-body">
        <form method="post" action="<?= app_url('/company/max/settings/bots/save') ?>" class="form-grid">
            <?= csrfField() ?>
            <div class="field"><label class="field-label">Название в ERP *</label><input class="field-input" name="name" maxlength="120" required placeholder="Например: PLANEX уведомления"></div>
            <div class="field"><label class="field-label">API-токен MAX *</label><input class="field-input" type="password" name="token" autocomplete="new-password" required placeholder="Токен будет зашифрован"></div>
            <div class="field"><label class="field-label">Статус</label><label class="checkbox-label"><input type="checkbox" name="is_active" value="1" checked> Активен</label></div>
            <div class="field" style="align-self:end"><button class="btn btn-primary" type="submit">Добавить и проверить</button></div>
        </form>
    </div>
</div>

<div class="panel" style="margin-bottom:18px">
    <div class="panel-head"><div><h3 class="panel-head-title">Боты</h3><div class="text-muted">Новый токен вводите только при его замене. Пустое поле сохраняет текущий секрет.</div></div></div>
    <div class="panel-body">
    <?php if (!$bots): ?>
        <div class="empty-state"><p class="empty-title">Боты MAX ещё не настроены</p></div>
    <?php else: ?>
        <?php foreach ($bots as $bot): $botId=(int)$bot['id']; ?>
        <div class="panel" style="margin-bottom:12px">
            <div class="panel-body">
                <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:12px">
                    <div>
                        <strong><?= e($bot['name']) ?></strong>
                        <div class="text-muted">ID <?= $botId ?> · токен: <?= !empty($bot['has_token']) ? 'сохранён' : 'нет' ?> · <?= !empty($bot['verified_at']) ? 'проверен '.e($bot['verified_at']) : 'не проверен' ?></div>
                        <?php if (!empty($bot['bot_first_name']) || !empty($bot['bot_username'])): ?><div class="text-muted">MAX: <?= e($bot['bot_first_name'] ?: 'бот') ?><?= !empty($bot['bot_username']) ? ' (@'.e($bot['bot_username']).')' : '' ?><?= !empty($bot['bot_user_id']) ? ' · user_id '.e($bot['bot_user_id']) : '' ?></div><?php endif; ?>
                    </div>
                    <div><span class="badge <?= !empty($bot['is_active']) ? 'success' : '' ?>"><?= !empty($bot['is_active']) ? 'Активен' : 'Отключён' ?></span> <span class="badge"><?= e($bot['webhook_status']) ?></span></div>
                </div>
                <form method="post" action="<?= app_url('/company/max/settings/bots/save') ?>" class="form-grid" style="margin-bottom:10px">
                    <?= csrfField() ?><input type="hidden" name="id" value="<?= $botId ?>">
                    <div class="field"><label class="field-label">Название</label><input class="field-input" name="name" maxlength="120" required value="<?= e($bot['name']) ?>"></div>
                    <div class="field"><label class="field-label">Заменить токен</label><input class="field-input" type="password" name="token" autocomplete="new-password" placeholder="Оставьте пустым, чтобы не менять"></div>
                    <div class="field"><label class="field-label">Статус</label><label class="checkbox-label"><input type="checkbox" name="is_active" value="1" <?= !empty($bot['is_active']) ? 'checked' : '' ?>> Активен</label></div>
                    <div class="field" style="align-self:end"><button class="btn btn-secondary" type="submit">Сохранить</button></div>
                </form>
                <div class="inline-actions" style="margin-bottom:10px">
                    <form method="post" action="<?= app_url('/company/max/settings/bots/'.$botId.'/verify') ?>"><?= csrfField() ?><button class="btn btn-secondary btn-sm" type="submit">Проверить бота</button></form>
                    <form method="post" action="<?= app_url('/company/max/settings/bots/'.$botId.'/webhook') ?>"><?= csrfField() ?><button class="btn btn-secondary btn-sm" type="submit">Подключить Webhook</button></form>
                </div>
                <div class="ux-tech-note"><strong>Webhook:</strong> <code><?= e($webhookUrls[$botId] ?? '') ?></code><br>MAX будет передавать группы через события bot_added / bot_removed. Секрет генерируется ERP и хранится зашифрованно.</div>
                <?php if (!empty($bot['webhook_last_error'])): ?><div class="notice warn" style="margin-top:8px"><?= e($bot['webhook_last_error']) ?></div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>

<div class="panel" style="margin-bottom:18px">
    <div class="panel-head"><div><h3 class="panel-head-title">Группы и каналы MAX</h3><div class="text-muted">С июня 2026 MAX не выдаёт общий список через GET /chats. chat_id можно сохранить вручную или получить через Webhook.</div></div></div>
    <div class="panel-body">
        <?php if ($bots): ?>
        <form method="post" action="<?= app_url('/company/max/settings/chats/save') ?>" class="form-grid" style="margin-bottom:18px">
            <?= csrfField() ?>
            <div class="field"><label class="field-label">Бот *</label><select class="field-select" name="bot_id" required><option value="">— Выберите —</option><?php foreach($bots as $bot): ?><option value="<?= (int)$bot['id'] ?>"><?= e($bot['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label class="field-label">chat_id *</label><input class="field-input" name="chat_id" required inputmode="numeric" placeholder="Например: -123456789"></div>
            <div class="field"><label class="field-label">Название в ERP</label><input class="field-input" name="name" maxlength="160" placeholder="Если пусто — возьмём название из MAX"></div>
            <div class="field"><label class="field-label">Назначение</label><input class="field-input" name="purpose" maxlength="500" placeholder="Например: Руководство / Логисты"></div>
            <div class="field"><label class="field-label">Статус</label><label class="checkbox-label"><input type="checkbox" name="is_active" value="1" checked> Использовать для отправки</label></div>
            <div class="field" style="align-self:end"><button class="btn btn-primary" type="submit">Добавить и проверить группу</button></div>
        </form>
        <?php endif; ?>

        <?php if (!$chats): ?><div class="empty-state"><p class="empty-title">Группы ещё не добавлены</p></div><?php else: ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Группа</th><th>Бот</th><th>chat_id</th><th>MAX</th><th>Назначение</th><th></th></tr></thead><tbody>
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
        </tbody></table></div>
        <?php endif; ?>
    </div>
</div>

<div class="panel" style="margin-bottom:18px">
    <div class="panel-head"><div><h3 class="panel-head-title">Тестовая отправка</h3><div class="text-muted">Реальное сообщение через POST /messages. Результат сохраняется в журнал.</div></div></div>
    <div class="panel-body">
        <form method="post" action="<?= app_url('/company/max/settings/test-send') ?>">
            <?= csrfField() ?>
            <div class="form-grid">
                <div class="field"><label class="field-label">Группа *</label><select class="field-select" name="chat_config_id" required><option value="">— Выберите —</option><?php foreach($activeChats as $chat): ?><option value="<?= (int)$chat['id'] ?>"><?= e($chat['name']) ?> · <?= e($chat['bot_name']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label class="field-label">Формат</label><select class="field-select" name="format"><option value="plain">Обычный текст</option><option value="markdown">Markdown</option><option value="html">HTML</option></select></div>
                <div class="field"><label class="field-label">Push</label><label class="checkbox-label"><input type="checkbox" name="notify" value="1" checked> Уведомить получателей</label></div>
            </div>
            <div class="field" style="margin-top:12px"><label class="field-label">Сообщение *</label><textarea class="field-textarea" name="message" maxlength="4000" rows="4" required>Тестовое сообщение из ERP PLANEX</textarea></div>
            <div style="margin-top:12px"><button class="btn btn-primary" type="submit" <?= !$activeChats ? 'disabled' : '' ?>>Отправить тест</button></div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><div><h3 class="panel-head-title">Журнал MAX</h3><div class="text-muted">Последние 50 попыток отправки.</div></div></div>
    <div class="panel-body">
        <?php if (!$logs): ?><div class="empty-state"><p class="empty-title">Отправок пока нет</p></div><?php else: ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Время</th><th>Источник</th><th>Группа</th><th>Результат</th><th>Сообщение / ошибка</th></tr></thead><tbody>
        <?php foreach($logs as $log): ?><tr><td><?= e($log['created_at']) ?></td><td><?= e($log['source']) ?></td><td><?= e($log['chat_name'] ?: $log['external_chat_id'] ?: '—') ?></td><td><strong><?= e($log['status']) ?></strong></td><td><?= e(mb_strimwidth((string)$log['message_text'],0,180,'…')) ?><?php if(!empty($log['error_message'])): ?><div class="text-danger"><?= e($log['error_message']) ?></div><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>
</div>
