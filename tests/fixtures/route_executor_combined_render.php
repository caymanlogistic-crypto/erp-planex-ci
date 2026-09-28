<?php
require dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/core_runtime.php';

$localPdo = new class {
    public function prepare(): object
    {
        return new class {
            public function execute(): void {}
            public function fetchAll(): array { return []; }
        };
    }
};
$responsibleLogists = [
    ['membership_id' => 11, 'full_name' => 'Анна Белова', 'login' => 'anna'],
    ['membership_id' => 12, 'full_name' => 'Борис Серов', 'login' => 'boris'],
];
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/erp-ui.css">
<link rel="stylesheet" href="/assets/css/route-executor-combined-create.css">
</head><body>
<button type="button" data-route-executor-existing-create>Выбрать из имеющихся</button>
<button type="button" data-route-executor-create-btn>Создать исполнителя рейса</button>
<div class="table-card" data-erp-grid data-route-executors-grid>
  <div class="table-toolbar"><div class="found-label">Найдено: <b>2</b></div><input class="toolbar-search" placeholder="Поиск по таблице"></div>
  <div class="table-scroll"><table class="table"><tbody>
    <tr data-route-executor-id="1" data-erp-sort-date="1"><td>ИП Орлов</td><td>Иванов Иван</td></tr>
    <tr data-route-executor-id="2" data-erp-sort-date="2"><td>ООО Вектор</td><td>Петров Пётр</td></tr>
  </tbody></table></div>
  <div class="table-footer"><span class="footer-range"></span><span class="footer-total"></span></div>
</div>
<?php require BASE_PATH . '/app/View/partials/route_executor_modals.php'; ?>
<script src="/assets/js/contact-fields.js"></script>
<script src="/assets/js/legal-entity-documents.js"></script>
<script src="/assets/js/legal-entity-inn.js"></script>
<script src="/assets/js/modal-shell.js"></script>
<script src="/assets/js/app.js"></script>
<script src="/assets/js/route-executor-combined-create.js"></script>
</body></html>
