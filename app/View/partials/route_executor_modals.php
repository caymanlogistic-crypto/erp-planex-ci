<div class="modal-overlay" id="route-executor-create-modal" data-close-on-overlay="0" data-close-on-escape="0">
  <div class="modal route-executor-modal-inner source-create-modal" id="route-executor-create-modal-inner">
    <div class="modal-head">
      <span class="modal-title">Создать исполнителя рейса</span>
      <button type="button" class="modal-close" data-route-executor-create-close>×</button>
    </div>
    <div class="modal-body" id="route-executor-create-modal-content">
      <div class="notice warn is-hidden" data-combined-feedback role="alert"></div>
      <?php
      $routeDocTypes = ['contractor' => [], 'driver' => [], 'vehicle_unit' => []];
      foreach (array_keys($routeDocTypes) as $routeDocEntity) {
          try {
              $routeDocStmt = $localPdo->prepare('SELECT id, name, code, entity_type FROM document_types WHERE entity_type = ? OR entity_type IS NULL ORDER BY sort_order, name');
              $routeDocStmt->execute([$routeDocEntity]);
              $routeDocTypes[$routeDocEntity] = $routeDocStmt->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable $ignored) {
              $routeDocTypes[$routeDocEntity] = [];
          }
      }
      ?>
      <div class="source-combined-grid">
        <?php
        $leEntityType = 'contractor';
        $leFormId = 'lab-contractor-form';
        $leFormAction = app_url('/company/contractors/create');
        $leDocTypes = $routeDocTypes['contractor'];
        $leIsModal = true;
        $leShowInlineActions = false;
        $leHiddenFields = ['is_modal' => '1'];
        require base_path('app/View/partials/legal_entity_create_form.php');

        $driverCreateFormMode = 'modal';
        $driverFormId = 'lab-driver-form';
        $driverFormAction = app_url('/company/drivers/modal-create');
        $driverFormClass = '';
        $docTypes = $routeDocTypes['driver'];
        require base_path('app/View/partials/company_driver_create_form.php');

        $vehicleSetCreateFormMode = 'modal';
        $vehicleSetFormId = 'lab-vehicle-form';
        $vehicleSetFormAction = app_url('/company/vehicle-sets/create');
        $vehicleSetFormClass = '';
        $docTypes = $routeDocTypes['vehicle_unit'];
        require base_path('app/View/partials/company_vehicle_set_create_form.php');
        ?>
      </div>
    </div>
    <div class="modal-foot is-spaced" id="route-executor-create-modal-foot">
      <div class="modal-required-note"><span class="req">*</span> — обязательные поля</div>
      <div class="modal-foot-actions">
        <button type="button" class="btn btn-ghost" data-route-executor-create-close>Отмена</button>
        <button type="button" class="btn btn-primary" data-source-create-submit>Создать исполнителя рейса</button>
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
