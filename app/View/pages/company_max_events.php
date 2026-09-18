<?php
$eventRules = $eventRules ?? [];
$eventDeliveries = $eventDeliveries ?? [];
$eventStatusLabels = ['pending' => 'Ожидает отправки', 'sending' => 'Отправляется / результат неизвестен', 'sent' => 'Отправлено', 'failed' => 'Ошибка'];
?>
<section id="max-events" class="max-tab-panel" data-max-panel="events" hidden>
    <div class="max-section-heading"><h2>Уведомления по событиям</h2></div>
    <p class="muted">Выберите чат и настройте сообщение для каждого события. Изменения действуют для новых событий; ранее сохранённые уведомления сохраняют свой текст и адрес.</p>
    <?php if (empty($eventsReady)): ?>
        <p class="notice notice-warning">Настройки событий ещё не подключены. Боты, чаты и ручная отправка доступны.</p>
    <?php else: ?>
        <?php foreach ($eventRules as $event): ?>
        <details class="max-event-card" <?= !empty($event['is_enabled']) ? 'open' : '' ?>>
        <summary><strong><?= e($event['title']) ?></strong><span class="badge <?= !empty($event['is_enabled']) ? 'badge-ok' : 'badge-neutral' ?>"><?= !empty($event['is_enabled']) ? 'Включено' : 'Отключено' ?></span></summary>
        <form method="post" action="<?= app_url('/company/max/settings/events/save') ?>" class="max-event-form" data-max-tab-form="events" data-max-event-form data-preview-url="<?= app_url('/company/max/settings/events/preview') ?>">
            <?= csrfField() ?>
            <input type="hidden" name="event_key" value="<?= e($event['event_key']) ?>">
            <div class="max-section-heading"><h3><?= e($event['title']) ?></h3>
                <label class="checkbox-item"><input type="checkbox" name="is_enabled" value="1" <?= !empty($event['is_enabled']) ? 'checked' : '' ?>><span class="checkbox-mark"></span><span>Отправлять уведомления</span></label>
            </div>
            <p class="muted"><?= e($event['description']) ?></p>
            <div class="max-event-editor">
                <div>
                    <div class="form-group"><label>Чат для отправки</label><select class="form-control" name="chat_config_id" aria-label="Чат для отправки">
                        <option value="">Выберите чат</option>
                        <?php foreach ($chats as $chat): $chatEnabled = !empty($chat['is_active']) && !empty($chat['bot_is_active']) && $chat['max_status'] === 'active'; ?>
                        <option value="<?= (int)$chat['id'] ?>" <?= (int)$event['chat_config_id'] === (int)$chat['id'] ? 'selected' : '' ?>><?= e($chat['name'] . ' · ' . $chat['bot_name'] . ($chatEnabled ? '' : ' (неактивен)')) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                    <?php if (!$chats): ?><p class="muted">Сначала добавьте бота и чат на вкладке «Настройки».</p><?php endif; ?>
                    <div class="form-group"><label>Формат</label><select class="form-control" name="message_format" aria-label="Формат шаблона">
                        <?php foreach (['plain' => 'Обычный текст', 'html' => 'HTML', 'markdown' => 'Markdown'] as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $event['message_format'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select></div>
                    <label class="checkbox-item"><input type="checkbox" name="notify" value="1" <?= !empty($event['notify']) ? 'checked' : '' ?>><span class="checkbox-mark"></span><span>Push-уведомление</span></label>
                    <p class="muted">В каналах MAX Push включён всегда.</p>
                </div>
                <div>
                    <div class="form-group"><label>Шаблон сообщения</label><textarea class="form-control" name="message_template" rows="8" maxlength="4000" required aria-label="Шаблон сообщения"><?= e($event['message_template']) ?></textarea></div>
                    <div class="max-event-variables" aria-label="Переменные шаблона">
                        <?php foreach ($event['variables'] as $key => $variable): ?><button class="btn btn-secondary btn-sm" type="button" data-max-insert="<?= e('{{' . $key . '}}') ?>" title="<?= e($variable[0]) ?>"><?= e($variable[0]) ?></button><?php endforeach; ?>
                    </div>
                    <div class="inline-actions">
                        <button class="btn btn-primary" type="submit">Сохранить событие</button>
                        <button class="btn btn-secondary" type="button" data-max-event-preview>Предпросмотр</button>
                        <button class="btn btn-secondary" type="button" data-max-event-default="<?= e($event['template']) ?>">Стандартный шаблон</button>
                    </div>
                    <div data-max-event-preview-wrap hidden><p class="muted">Пример с условными данными. В MAX ничего не отправляется. Для HTML и Markdown показан текст с разметкой.</p><pre class="max-event-preview" data-max-event-preview-text aria-live="polite"></pre></div>
                </div>
            </div>
        </form>
        </details>
        <?php endforeach; ?>
        <div class="max-section-heading"><h3>Отправки по событиям</h3><span class="muted">Последние 100</span></div>
        <p class="muted">Повтор использует сохранённый текст и чат. Если MAX не ответил, сообщение могло дойти: перед повтором проверьте чат.</p>
        <div class="max-table-wrap"><div class="table-scroll"><table class="table">
            <thead><tr><th>Время / событие</th><th>Чат</th><th>Сообщение</th><th>Результат</th><th>Действия</th></tr></thead>
            <tbody>
            <?php if (!$eventDeliveries): ?><tr><td colspan="5" class="muted">Отправок пока нет. Включите событие и сохраните новый блок.</td></tr><?php endif; ?>
            <?php foreach ($eventDeliveries as $delivery): ?>
                <tr><td><?= e($delivery['created_at']) ?><br><?= e(\App\Service\MaxEventCatalog::all()[$delivery['event_key']]['title'] ?? $delivery['event_key']) ?> #<?= e($delivery['entity_key']) ?></td>
                    <td><?= e($delivery['chat_name'] ?? $delivery['external_chat_id']) ?></td>
                    <td><details><summary><?= e(mb_strimwidth($delivery['message_text'], 0, 90, '…')) ?: 'Текст недоступен' ?></summary><pre class="max-event-preview"><?= e($delivery['message_text']) ?></pre></details></td>
                    <td><?= e($eventStatusLabels[$delivery['status']] ?? $delivery['status']) ?><br><span class="muted">Попыток: <?= (int)$delivery['attempts'] ?></span><?php if ($delivery['error_message']): ?><div class="text-danger"><?= e($delivery['error_message']) ?></div><?php endif; ?></td>
                    <td><?php if ($delivery['status'] !== 'sent'): ?><form method="post" action="<?= app_url('/company/max/settings/events/deliveries/' . (int)$delivery['id'] . '/retry') ?>" data-max-tab-form="events" data-max-event-retry><?= csrfField() ?><button class="btn btn-secondary btn-sm" type="submit"><?= $delivery['status'] === 'pending' ? 'Отправить' : 'Повторить' ?></button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    <?php endif; ?>
</section>
