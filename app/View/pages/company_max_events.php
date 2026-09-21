<?php
$eventRules = $eventRules ?? [];
$eventDeliveries = $eventDeliveries ?? [];
$eventStatusLabels = ['pending' => 'Ожидает отправки', 'sending' => 'Отправляется / результат неизвестен', 'sent' => 'Отправлено', 'failed' => 'Ошибка'];
?>
<section id="max-events" class="max-tab-panel" data-max-panel="events" hidden>
    <h2 class="section-title">Уведомления по событиям</h2>
    <p class="field-msg">Настройте чат и шаблон для нужного события. Сохранённые уведомления сохраняют свой текст и адрес.</p>
    <?php if (empty($eventsReady)): ?>
        <p class="notice notice-warning">Настройки событий ещё не подключены. Боты, чаты и ручная отправка доступны.</p>
    <?php else: ?>
        <?php
        $eventChats = array_column($chats, null, 'id');
        $eventFormats = ['plain' => 'Обычный текст', 'html' => 'HTML', 'markdown' => 'Markdown'];
        $eventDraftKey = is_array($draft ?? null) ? (string)($draft['event_key'] ?? '') : '';
        ?>
        <div class="table-card max-events-catalog">
            <div class="table-toolbar max-events-toolbar">
                <label class="field max-events-search"><span class="field-label">Поиск события или чата</span><input type="search" class="field-input" data-max-event-search placeholder="Название, описание, чат"></label>
                <label class="field"><span class="field-label">Состояние</span><select class="field-select" data-max-event-status><option value="">Все состояния</option><option value="enabled">Включено</option><option value="disabled">Отключено</option><option value="attention">Требует внимания</option></select></label>
                <label class="field"><span class="field-label">Чат</span><select class="field-select" data-max-event-chat><option value="">Все чаты</option><option value="none">Не выбран</option><?php foreach ($chats as $chat): ?><option value="<?= (int)$chat['id'] ?>"><?= e($chat['name']) ?></option><?php endforeach; ?></select></label>
                <button type="button" class="btn btn-secondary" data-max-event-reset>Сбросить</button>
            </div>
            <div class="table-scroll"><table class="table max-events-table">
                <thead><tr><th>Событие</th><th>Состояние</th><th>Чат для отправки</th><th>Формат</th><th>Действие</th></tr></thead>
                <tbody>
                <?php foreach ($eventRules as $eventIndex => $event):
                    $eventChat = $eventChats[(int)$event['chat_config_id']] ?? null;
                    $chatAvailable = $eventChat && !empty($eventChat['is_active']) && !empty($eventChat['bot_is_active']) && $eventChat['max_status'] === 'active';
                    $isDraft = $eventDraftKey === $event['event_key'];
                    $systemRequired = !empty($event['system_required']);
                    $eventState = $isDraft || (!empty($event['is_enabled']) && !$chatAvailable) ? 'attention' : (!empty($event['is_enabled']) ? 'enabled' : 'disabled');
                    $stateLabel = $isDraft ? 'Не сохранено' : ($eventState === 'attention' ? 'Проверьте чат' : ($systemRequired ? 'Системное' : ($eventState === 'enabled' ? 'Включено' : 'Отключено')));
                    $chatLabel = $eventChat ? $eventChat['name'] : 'Не выбран';
                ?>
                    <tr data-max-event-row data-event-state="<?= $eventState ?>" data-event-chat="<?= $eventChat ? (int)$eventChat['id'] : 'none' ?>" data-event-search="<?= e(mb_strtolower($event['title'].' '.$event['description'].' '.$chatLabel)) ?>">
                        <td><strong><?= e($event['title']) ?></strong><span class="max-event-row-description" title="<?= e($event['description']) ?>"><?= e($event['description']) ?></span></td>
                        <td><span class="badge <?= $eventState === 'attention' ? 'badge-warning' : ($eventState === 'enabled' ? 'badge-ok' : 'badge-neutral') ?>"><?= $stateLabel ?></span></td>
                        <td><?= e($chatLabel) ?><?php if ($eventChat && !$chatAvailable): ?><span class="max-event-row-description">Чат или бот неактивен</span><?php endif; ?></td>
                        <td><?= e($eventFormats[$event['message_format']] ?? $event['message_format']) ?></td>
                        <td><button type="button" class="btn btn-secondary btn-sm" data-max-event-edit="<?= (int)$eventIndex ?>" aria-label="<?= e('Настроить: '.$event['title']) ?>">Настроить</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="empty-state" data-max-event-empty hidden><div class="empty-title">События не найдены</div><div class="empty-desc">Измените поиск или сбросьте фильтры.</div></div>
            <div class="pagination max-events-pagination"><span data-max-event-count aria-live="polite"></span><div class="pages"><button type="button" class="btn btn-secondary btn-sm" data-max-event-prev aria-label="Предыдущая страница">Назад</button><span data-max-event-page></span><button type="button" class="btn btn-secondary btn-sm" data-max-event-next aria-label="Следующая страница">Далее</button></div></div>
        </div>
        <div class="modal-overlay max-event-overlay" id="max-event-edit-modal" role="dialog" aria-modal="true" aria-labelledby="max-event-edit-title" aria-hidden="true" hidden data-close-on-overlay="0" data-escape-locked="1">
            <form class="modal modal-lg max-event-form" method="post" action="<?= app_url('/company/max/settings/events/save') ?>" data-max-tab-form="events" data-max-event-form data-preview-url="<?= app_url('/company/max/settings/events/preview') ?>">
                <div class="modal-head"><span class="modal-title" id="max-event-edit-title"></span><button type="button" class="modal-close" data-max-event-close aria-label="Закрыть">×</button></div>
                <div class="modal-body">
                    <?= csrfField() ?><input type="hidden" name="event_key">
                    <p class="field-msg" data-max-event-description></p>
                    <div class="form-alert alert-error" data-max-event-save-error hidden><?= e($errorFlash ?? '') ?></div>
                    <div class="max-event-editor">
                        <div class="max-event-options">
                            <label class="checkbox-item"><input type="checkbox" name="is_enabled" value="1"><span class="checkbox-mark" aria-hidden="true"></span><span class="checkbox-label">Отправлять уведомления</span></label>
                            <p class="field-msg" data-max-system-required-note hidden>Сообщения ФЭО отправляются обязательно. Тексты и оформление можно менять; сохраните переменные заявок, рейса и итогов. Все варианты используют выбранный чат и Push.</p>
                            <label class="field"><span class="field-label">Чат для отправки</span><select class="field-select" name="chat_config_id"><option value="">Выберите чат</option>
                            <?php foreach ($chats as $chat): $chatEnabled = !empty($chat['is_active']) && !empty($chat['bot_is_active']) && $chat['max_status'] === 'active'; ?>
                                <option value="<?= (int)$chat['id'] ?>"><?= e($chat['name'].' · '.$chat['bot_name'].($chatEnabled ? '' : ' (неактивен)')) ?></option>
                            <?php endforeach; ?></select></label>
                            <?php if (!$chats): ?><p class="field-msg">Сначала добавьте бота и чат на вкладке «Настройки».</p><?php endif; ?>
                            <label class="field"><span class="field-label">Формат</span><select class="field-select" name="message_format"><?php foreach ($eventFormats as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?></select></label>
                            <label class="checkbox-item"><input type="checkbox" name="notify" value="1"><span class="checkbox-mark" aria-hidden="true"></span><span class="checkbox-label">Push-уведомление</span></label>
                            <span class="field-msg">В каналах MAX Push включён всегда.</span>
                        </div>
                        <div class="max-event-template">
                            <label class="field" data-max-feo-template-selector hidden><span class="field-label">Вариант шаблона</span><select class="field-select" name="feo_template_kind"></select></label>
                            <input type="hidden" name="feo_templates" disabled>
                            <input type="hidden" name="feo_templates_version" value="<?= \App\Service\MaxFeoMessageTemplates::version() ?>" disabled>
                            <label class="field"><span class="field-label">Шаблон сообщения</span><textarea class="field-textarea" name="message_template" rows="8" maxlength="4000" required></textarea></label>
                            <div class="max-event-variables"><label class="field"><span class="field-label">Переменная</span><select class="field-select" data-max-event-variable></select></label><button type="button" class="btn btn-secondary" data-max-insert>Вставить</button></div>
                            <div class="max-detail-actions"><button class="btn btn-secondary btn-sm" type="button" data-max-event-preview>Предпросмотр</button><button class="btn btn-ghost btn-sm" type="button" data-max-event-default>Стандартный шаблон</button></div>
                            <div data-max-event-preview-wrap hidden><p class="field-msg">Пример с условными данными. В MAX ничего не отправляется. HTML и Markdown показаны как текст с разметкой.</p><pre class="max-log-readable max-event-preview" data-max-event-preview-text aria-live="polite"></pre></div>
                        </div>
                    </div>
                    <p class="field-msg">Изменения применяются к новым событиям после сохранения.</p>
                </div>
                <div class="modal-foot"><span class="field-msg" data-max-event-dirty hidden>Есть несохранённые изменения</span><button type="button" class="btn btn-secondary" data-max-event-close>Отмена</button><button type="submit" class="btn btn-primary">Сохранить</button></div>
            </form>
        </div>
        <script type="application/json" id="max-event-catalog-data"><?= json_encode(['rules' => array_values($eventRules), 'draftKey' => $eventDraftKey], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        <div class="max-section-inline-head max-event-delivery-head"><h3 class="section-title">Отправки по событиям</h3><span class="muted">Последние 100</span></div>
        <p class="muted">Для адресных FEO-уведомлений повтор заново проверяет текущего ответственного, MAX ID и профильное имя в MAX, сохраняя тот же логический ключ доставки. Для остальных событий используется сохранённый текст и чат. Если MAX не ответил, перед повтором проверьте чат.</p>
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
