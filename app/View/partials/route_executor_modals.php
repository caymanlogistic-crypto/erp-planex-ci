<div class="modal-overlay" id="route-executor-create-modal" data-close-on-overlay="0" data-close-on-escape="0">
  <div class="modal route-executor-modal-inner route-executor-create-modal-inner" id="route-executor-create-modal-inner">
    <div class="modal-head">
      <span class="modal-title" data-route-executor-create-title>Добавить исполнителя рейса</span>
      <button type="button" class="modal-close" data-route-executor-create-close>×</button>
    </div>
    <div class="modal-body" id="route-executor-create-modal-content">
      <div class="route-executor-create-choices" data-route-executor-create-choices>
        <p class="route-executor-choice-intro">Выберите, какие данные уже есть в справочниках.</p>
        <button type="button" class="route-executor-choice" data-route-executor-create-choice="new_contractor">
          <span class="route-executor-choice-icon">+</span>
          <span><strong>Новый подрядчик</strong><small>Создать подрядчика, водителя и ТС в одной компактной форме</small></span>
          <span class="route-executor-choice-arrow">→</span>
        </button>
        <button type="button" class="route-executor-choice" data-route-executor-create-choice="existing_contractor">
          <span class="route-executor-choice-icon">↗</span>
          <span><strong>Старый подрядчик, новый водитель и ТС</strong><small>Сначала выбрать подрядчика, затем заполнить водителя и транспорт</small></span>
          <span class="route-executor-choice-arrow">→</span>
        </button>
        <button type="button" class="route-executor-choice route-executor-choice--secondary" data-route-executor-create-choice="existing_data">
          <span class="route-executor-choice-icon">✓</span>
          <span><strong>Все данные уже созданы</strong><small>Собрать исполнителя из готовых подрядчика, водителей и ТС</small></span>
          <span class="route-executor-choice-arrow">→</span>
        </button>
      </div>
    </div>
    <div class="modal-foot is-spaced" id="route-executor-create-modal-foot" style="display:none">
      <div class="modal-required-note"><span class="req">*</span> — обязательные поля</div>
      <div class="modal-foot-actions">
        <button type="button" class="btn btn-ghost" data-route-executor-create-close>Отмена</button>
        <button type="submit" form="route-executor-create-form" class="btn btn-primary">Создать исполнителя рейса</button>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="route-executor-view-modal" data-close-on-overlay="0" data-close-on-escape="0">
  <div class="modal route-executor-modal-inner" id="route-executor-view-modal-inner">
    <div class="modal-head">
      <span class="modal-title">Исполнитель рейса</span>
      <button type="button" class="modal-close" data-route-executor-view-close>×</button>
    </div>
    <div class="modal-body" id="route-executor-view-modal-content">
      <div class="driver-modal-loading">Загрузка...</div>
    </div>
  </div>
</div>
