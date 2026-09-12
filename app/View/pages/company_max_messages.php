<div class="page-head">
    <div class="page-head-left">
        <h1 class="page-title">Сообщения MAX</h1>
        <div class="page-summary">Ручная отправка руководителем в одну или несколько настроенных групп MAX.</div>
    </div>
    <div class="page-head-actions"><a class="btn btn-secondary" href="<?= app_url('/company/max/settings') ?>">Настройки MAX</a></div>
</div>

<?php if ($successFlash): ?><div class="notice success"><?= e($successFlash) ?></div><?php endif; ?>
<?php if ($errorFlash): ?><div class="notice warn"><?= e($errorFlash) ?></div><?php endif; ?>

<div class="panel" style="margin-bottom:18px">
    <div class="panel-head"><div><h3 class="panel-head-title">Новое сообщение</h3><div class="text-muted">До 4000 символов. Можно выбрать несколько групп; каждая отправка фиксируется в журнале.</div></div></div>
    <div class="panel-body">
        <?php if (!$activeChats): ?>
            <div class="notice warn">Нет активных проверенных групп MAX. Сначала добавьте бота и группу в <a href="<?= app_url('/company/max/settings') ?>">настройках MAX</a>.</div>
        <?php else: ?>
        <form method="post" action="<?= app_url('/company/max/messages/send') ?>">
            <?= csrfField() ?>
            <div class="field">
                <label class="field-label">Куда отправить *</label>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px;margin-top:6px">
                    <?php foreach($activeChats as $chat): ?>
                    <label class="checkbox-label" style="padding:10px;border:1px solid var(--border-color,#d9dee7);border-radius:8px">
                        <input type="checkbox" name="chat_ids[]" value="<?= (int)$chat['id'] ?>">
                        <span><strong><?= e($chat['name']) ?></strong><br><span class="text-muted"><?= e($chat['bot_name']) ?> · <?= e($chat['chat_type']) ?></span></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field" style="margin-top:14px"><label class="field-label">Сообщение *</label><textarea class="field-textarea" name="message" maxlength="4000" rows="8" required placeholder="Введите сообщение для MAX"></textarea><div class="field-hint">Для оформления можно выбрать Markdown или HTML.</div></div>
            <div class="form-grid" style="margin-top:12px">
                <div class="field"><label class="field-label">Формат</label><select class="field-select" name="format"><option value="plain">Обычный текст</option><option value="markdown">Markdown</option><option value="html">HTML</option></select></div>
                <div class="field"><label class="field-label">Push</label><label class="checkbox-label"><input type="checkbox" name="notify" value="1" checked> Уведомить получателей</label><div class="field-hint">Для каналов MAX push включается обязательно.</div></div>
                <div class="field" style="align-self:end"><button class="btn btn-primary" type="submit" onclick="return confirm('Отправить сообщение в выбранные группы MAX?')">Отправить в MAX</button></div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><div><h3 class="panel-head-title">Последние отправки</h3><div class="text-muted">Ручные, тестовые и будущие системные сообщения используют единый журнал.</div></div></div>
    <div class="panel-body">
        <?php if (!$logs): ?><div class="empty-state"><p class="empty-title">История пока пуста</p></div><?php else: ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Время</th><th>Тип</th><th>Группа</th><th>Статус</th><th>Текст</th></tr></thead><tbody>
        <?php foreach($logs as $log): ?>
            <tr><td><?= e($log['created_at']) ?></td><td><?= e($log['source']) ?></td><td><?= e($log['chat_name'] ?: $log['external_chat_id'] ?: '—') ?></td><td><?= e($log['status']) ?></td><td><?= e(mb_strimwidth((string)$log['message_text'],0,220,'…')) ?><?php if(!empty($log['error_message'])): ?><div class="text-danger"><?= e($log['error_message']) ?></div><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>
</div>
