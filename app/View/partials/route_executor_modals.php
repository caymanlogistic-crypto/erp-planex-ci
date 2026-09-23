<div class="modal-overlay" id="route-executor-create-modal" data-close-on-overlay="0" data-close-on-escape="0">
  <div class="modal modal-lg" id="route-executor-create-modal-inner">
    <div class="modal-head">
      <span class="modal-title" data-route-executor-create-title>Добавить исполнителя рейса</span>
      <button type="button" class="modal-close" data-route-executor-create-close>×</button>
    </div>
    <div class="modal-body" id="route-executor-create-modal-content">
      <div class="table-card table-card--standard" data-route-executor-create-choices>
        <div class="table-scroll">
          <table class="table">
            <thead><tr><th>Вариант создания</th><th>Что будет создано</th></tr></thead>
            <tbody>
              <tr data-route-executor-create-choice="new_contractor" tabindex="0" role="button">
                <td class="cell-name">Новый подрядчик</td>
                <td>Подрядчик, водитель и ТС</td>
              </tr>
              <tr data-route-executor-create-choice="existing_contractor" tabindex="0" role="button">
                <td class="cell-name">Старый подрядчик, новый водитель и ТС</td>
                <td>Водитель и ТС у выбранного подрядчика</td>
              </tr>
              <tr data-route-executor-create-choice="existing_data" tabindex="0" role="button">
                <td class="cell-name">Все данные уже созданы</td>
                <td>Исполнитель из готовых данных</td>
              </tr>
            </tbody>
          </table>
        </div>
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
