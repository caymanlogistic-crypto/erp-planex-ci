<?php
require_once base_path('app/View/components/registry_tabler_icons.php');
if (!isset($registryUiStateScope)) require base_path('app/View/partials/registry_ui_state_scope.php');
$initialRows = array_map(static function (array $row): array {
    $snapshot = $row['snapshot'];
    return [
        'id'=>(int)$row['id'],'trip_id'=>(int)$row['trip_id'],'business_id'=>(int)$row['business_id'],
        'type'=>(string)$row['contract_type'],'version'=>(int)$row['version_no'],'status'=>(string)$row['status'],
        'reason'=>(string)($row['review_reason'] ?? ''),'logist'=>(string)($row['logist_name'] ?? ''),
        'description'=>(string)($snapshot['service']['description'] ?? $snapshot['trip']['comment'] ?? ''),
        'start'=>(string)($snapshot['parameters']['start_date'] ?? ''),
        'finish'=>(string)($snapshot['parameters']['finish_date'] ?? ''),
        'contractor'=>(string)($snapshot['executor']['name'] ?? ''),
        'driver'=>(string)($snapshot['driver']['full_name'] ?? ''),
        'price'=>(string)($snapshot['parameters']['price'] ?? ''),
        'vat'=>(string)($snapshot['parameters']['vat_mode'] ?? ''),
    ];
}, $rows);
?>
<link rel="stylesheet" href="<?= app_url('/assets/css/registry-components.css') ?>?v=<?= filemtime(base_path('public/assets/css/registry-components.css')) ?>">
<section class="logist-contracts" data-logist-contracts data-owner="<?= $owner ? '1' : '0' ?>"
  data-prepare-url="<?= e(app_url('/company/logistics/contracts/prepare')) ?>"
  data-create-url="<?= e(app_url('/company/logistics/contracts/create')) ?>"
  data-detail-base="<?= e(app_url('/company/logistics/contracts')) ?>"
  data-review-base="<?= e(app_url('/company/document-generator/logist-contracts')) ?>">
  <?php $registryHeadTitle='Договоры логистов'; $registryHeadSummary='Договоры перевозчика по рейсам'; $registryHeadIconHtml=registry_tabler_header_icon('file-text'); $registryHeadActionsHtml='<button type="button" class="btn btn-primary" data-create>Создать новый договор</button>'; require base_path('app/View/partials/registry_ui_head.php'); ?>
  <div class="notice warn" role="alert" data-page-error hidden></div>
  <?php if ($formError): ?><div class="notice warn" role="alert"><?= e($formError) ?></div><?php endif; ?>
  <div class="table-card table-card--standard registry-ui-card registry-ui-scroll registry-ui-sticky-table" data-registry-id="logist-contracts" data-registry-state-scope="<?= e($registryUiStateScope) ?>"><div class="table-toolbar registry-ui-toolbar"><div class="found-label">Найдено: <b data-count><?= count($initialRows) ?></b></div><div class="toolbar-right"><button type="button" class="registry-ui-reset" hidden>Сбросить фильтр</button><div class="registry-ui-search-group"><input type="search" class="toolbar-search registry-ui-search" data-search data-registry-field="query" data-registry-default="" aria-label="Поиск по договорам" placeholder="Поиск по рейсу, подрядчику, водителю"></div></div></div><div class="table-scroll registry-ui-table-surface"><table class="table registry-ui-table registry-ui-data logist-contract-table<?= $owner ? ' is-owner' : '' ?>"><colgroup><col><col class="registry-ui-trip-cell"><col><col><col><col><col><?php if ($owner): ?><col><?php endif; ?></colgroup><thead><tr><th>ID</th><th class="registry-ui-trip-cell">ОПИСАНИЕ РЕЙСА</th><th>НАЧАЛО РЕЙСА</th><th>ПОДРЯДЧИК</th><th>ВОДИТЕЛЬ</th><th>СТАВКА</th><th>НДС</th><?php if ($owner): ?><th>ЛОГИСТ</th><?php endif; ?></tr></thead><tbody data-rows></tbody></table></div></div>
  <dialog class="modal registry-ui-modal registry-ui-modal--weight-single" data-form-dialog aria-labelledby="contract-form-title"><form class="registry-ui-modal-form" data-form novalidate><div class="modal-head"><span class="modal-title" id="contract-form-title">Новый договор</span><button type="button" class="modal-close" data-close aria-label="Закрыть">×</button></div><div class="modal-body registry-ui-modal-grid">
    <fieldset class="field"><legend class="field-label">Вид договора</legend><div class="radio-group is-horizontal"><label class="radio-item"><input type="radio" name="contract_type" value="lease" checked><span class="radio-mark"></span><span class="radio-label">Аренда</span></label><label class="radio-item"><input type="radio" name="contract_type" value="sublease"><span class="radio-mark"></span><span class="radio-label">Субаренда</span></label></div></fieldset>
    <div class="field"><label class="field-label" for="lc-trip">Рейс</label><input class="field-input" id="lc-trip" name="trip_choice" list="lc-trips" placeholder="Номер или описание рейса" autocomplete="off"><datalist id="lc-trips"></datalist><div class="field-msg is-error" data-error="trip_choice" hidden></div></div>
    <div class="field"><label class="field-label" for="lc-executor">Исполнитель рейса</label><input class="field-input" id="lc-executor" name="executor_choice" list="lc-executors" placeholder="Подрядчик или ФИО водителя" autocomplete="off"><datalist id="lc-executors"></datalist><div class="field-msg is-error" data-error="executor_choice" hidden></div></div>
    <div class="field"><label class="field-label" for="lc-start">Начало рейса</label><input class="field-input" id="lc-start" name="start_date" type="date" readonly><div class="field-msg is-error" data-error="start_date" hidden></div></div>
    <div class="field"><label class="field-label" for="lc-finish">Окончание рейса</label><input class="field-input" id="lc-finish" name="finish_date" type="date" readonly><div class="field-msg is-error" data-error="finish_date" hidden></div></div>
    <div class="field"><label class="field-label" for="lc-rate">Ставка, ₽</label><input class="field-input" id="lc-rate" name="price" inputmode="decimal" placeholder="Введите сумму"><div class="field-msg is-error" data-error="price" hidden></div></div>
    <div class="field"><label class="field-label" for="lc-vat">НДС</label><select class="field-input" id="lc-vat" name="vat_mode"><option>Без НДС</option><option>С НДС</option></select></div>
    <p class="registry-ui-modal-help">PDF скачивается сразу; руководитель проверит договор после создания.</p><div class="field-msg is-error" data-form-error hidden></div></div><div class="modal-foot"><div class="modal-foot-actions"><button type="button" class="btn btn-ghost" data-close>Отменить</button><button type="submit" class="btn btn-primary">Сгенерировать PDF</button></div></div></form></dialog>
  <dialog class="modal registry-ui-modal registry-ui-modal--reading" data-detail aria-labelledby="contract-detail-title"><div class="modal-head"><span class="modal-title" id="contract-detail-title">Договор</span><button type="button" class="modal-close" data-close aria-label="Закрыть">×</button></div><div class="modal-body registry-ui-modal-grid registry-ui-modal-grid--reading" data-detail-body></div><div class="field-msg is-error" data-detail-error hidden></div><div class="modal-foot"><div class="modal-foot-actions" data-actions></div></div></dialog>
  <script type="application/json" data-initial-rows><?= json_encode($initialRows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
  <input type="hidden" data-csrf value="<?= e($csrfToken) ?>">
</section>
