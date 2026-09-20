<div class="service-keys" data-service-keys>
  <div class="page-head"><div class="page-head-left"><h1 class="page-title">Ключи и сервисы</h1><div class="page-summary">Подключения к внешним сервисам для вашей компании.</div></div></div>
  <div class="service-keys-grid">
  <?php if ($dbError): ?><div class="form-alert alert-error" role="alert"><div class="alert-body"><?= e($dbError) ?></div></div><?php else: ?>
    <section class="panel-section service-key-card" aria-labelledby="yandex-title">
      <header class="service-key-head">
        <div class="service-key-identity"><span class="service-key-icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg></span><div><h2 class="section-title" id="yandex-title">Яндекс Геокодер</h2><p class="service-key-caption">Адреса и координаты</p></div></div>
        <span class="badge <?= $keyConfigured?'badge-ok':'badge-warning' ?>" data-key-status><?= $keyConfigured?'Ключ сохранён':'Нужен ключ' ?></span>
      </header>
      <div class="service-key-body">
        <p>Определяет координаты по адресу и точность найденного места.</p>
        <div class="service-key-usage"><span>Используется в ФЭО</span><p>Перенос заявок · Определение координат</p></div>
        <div class="form-alert alert-success" data-key-message role="status" hidden><div class="alert-body" data-key-message-text></div></div>
        <button type="button" class="btn btn-secondary" data-key-edit <?= $keyConfigured?'':'hidden' ?>>Заменить ключ</button>
        <form action="<?= e(app_url('/company/settings/services/yandex')) ?>" method="post" data-key-form <?= $keyConfigured?'hidden':'' ?>>
          <?= csrfField() ?>
          <div class="field"><label class="field-label" for="service-yandex-key">API-ключ Геокодера</label><input class="field-input" type="password" id="service-yandex-key" name="api_key" autocomplete="new-password" minlength="20" maxlength="200" required placeholder="Введите новый API-ключ" aria-describedby="service-key-hint"><div class="field-msg" id="service-key-hint">Сохранённое значение не отображается. Новый ключ заменит его после сохранения.</div></div>
          <div class="service-key-actions"><button class="btn btn-primary" type="submit">Сохранить ключ</button><button class="btn btn-ghost" type="button" data-key-cancel <?= $keyConfigured?'':'hidden' ?>>Отмена</button></div>
        </form>
      </div>
      <footer class="service-key-footer">Ключ хранится зашифрованно. Управление доступно руководителю.</footer>
    </section>
  <?php endif; ?>
    <section class="panel-section service-key-card" aria-labelledby="mail-settings-title">
      <header class="service-key-head"><div><h2 class="section-title" id="mail-settings-title">Рабочая почта</h2><p class="service-key-caption">Общие настройки IMAP / SMTP</p></div><span class="badge <?= $mailConfigured?'badge-ok':'badge-warning' ?>" data-mail-settings-status><?= $mailConfigured?'Настроено':'Не сохранено' ?></span></header>
      <div class="service-key-body">
        <?php if ($mailError): ?><div class="notice warn" role="alert"><?= e($mailError) ?></div><?php else: ?>
        <p>Серверы для рабочих ящиков сотрудников. Логин и пароль каждого ящика задаются в карточке пользователя.</p>
        <form action="<?= e(app_url('/company/settings/services/mail')) ?>" method="post" data-mail-settings-form>
          <?= csrfField() ?>
          <?php foreach (['imap'=>'IMAP — получение','smtp'=>'SMTP — отправка'] as $protocol=>$label): ?>
          <div class="service-mail-fields">
            <div class="field"><label class="field-label" for="mail-<?= $protocol ?>-host"><?= e($label) ?></label><input class="field-input" id="mail-<?= $protocol ?>-host" name="<?= $protocol ?>_host" value="<?= e($mailSettings[$protocol.'_host']) ?>" maxlength="253" required></div>
            <div class="field"><label class="field-label" for="mail-<?= $protocol ?>-port">Порт</label><input class="field-input" id="mail-<?= $protocol ?>-port" type="number" min="1" max="65535" name="<?= $protocol ?>_port" value="<?= (int)$mailSettings[$protocol.'_port'] ?>" required></div>
          </div>
          <?php endforeach; ?>
          <div class="field"><label class="field-label" for="mail-encryption">Защита подключения</label><select class="field-select" id="mail-encryption" name="encryption"><option value="SSL">SSL</option></select></div>
          <div class="notice is-hidden" data-mail-settings-message role="status"></div>
          <div class="service-key-actions"><button class="btn btn-primary" type="submit">Сохранить настройки</button></div>
        </form>
        <?php endif; ?>
      </div>
      <footer class="service-key-footer">Начальные настройки — Spaceweb. Проверка ящиков не отправляет писем.</footer>
    </section>
  </div>
</div>
<script src="<?= app_url('/assets/js/service-keys.js') ?>" defer></script>
