<div class="modal-overlay" id="route-executor-delete-modal" data-close-on-overlay="0" data-close-on-escape="1" role="dialog" aria-modal="true" aria-labelledby="route-executor-delete-title">
  <div class="modal" style="width:min(800px, calc(100vw - 24px));">
    <div class="modal-head">
      <span class="modal-title" id="route-executor-delete-title">Удалить исполнителя рейса?</span>
      <button type="button" class="modal-close" data-route-executor-delete-close aria-label="Закрыть">&times;</button>
    </div>
    <div class="modal-body">
      <div class="driver-delete-confirm-head">
        <span class="modal-icon is-danger" aria-hidden="true">!</span>
        <div>
          <p class="driver-delete-confirm-title" data-route-executor-delete-heading>Проверяем участие в рейсах…</p>
          <p class="driver-delete-confirm-text" data-route-executor-delete-summary></p>
        </div>
      </div>
      <div class="field mt-section is-hidden" data-route-executor-delete-password-wrap>
        <label class="field-label" for="route-executor-delete-password">Пароль удаления</label>
      </div>
      <div class="notice warn is-hidden" data-route-executor-delete-error role="alert"></div>
    </div>
    <div class="modal-foot is-spaced">
      <div></div>
      <div class="modal-foot-actions">
        <button type="button" class="btn btn-ghost" data-route-executor-delete-close>Отмена</button>
        <button type="button" class="btn btn-danger" data-route-executor-delete-confirm disabled>Удалить</button>
      </div>
    </div>
  </div>
</div>
