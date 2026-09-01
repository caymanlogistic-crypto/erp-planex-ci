<?php

use App\Service\WasteTripService;

$isFinanceRealm = (string) ($_SESSION['role_code'] ?? '') === 'company_owner';
$fmtMass = static fn($kg): string => number_format((float) $kg / 1000, 3, ',', ' ') . ' т';
$fmtDate = static fn($date): string => trim((string) $date) !== '' ? ui_date((string) $date) : '—';
?>
<style>
.legacy-trip-card{margin-bottom:14px}.legacy-trip-head{display:flex;gap:14px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap}.legacy-trip-meta{display:flex;gap:8px 16px;flex-wrap:wrap;color:var(--text-muted,#667085);font-size:13px}.legacy-request-list{overflow-wrap:anywhere}.legacy-map-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.legacy-map-grid label{display:block;font-size:12px;font-weight:600;margin-bottom:5px}.legacy-map-grid select{width:100%}.legacy-warning{font-size:13px;margin-top:8px}.legacy-source-details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px 18px;margin-top:12px;font-size:13px}.legacy-source-details b{display:block;font-size:11px;color:var(--text-muted,#667085);text-transform:uppercase;margin-bottom:2px}@media(max-width:1100px){.legacy-map-grid{grid-template-columns:repeat(2,1fr)}.legacy-source-details{grid-template-columns:repeat(2,1fr)}}@media(max-width:700px){.legacy-map-grid,.legacy-source-details{grid-template-columns:1fr}}
</style>

<div class="page-head">
  <div class="page-head-left"><h1 class="page-title">Импорт старых рейсов</h1><div class="page-summary"><span>Read-only сверка legacy flights с canonical рейсами ERP. По умолчанию показаны только отсутствующие.</span></div></div>
  <div class="page-head-actions"><a class="btn btn-secondary" href="<?= app_url('/company/trips/departures') ?>">К реестру рейсов</a><?php if ($showImported): ?><a class="btn btn-ghost" href="<?= app_url('/company/trips/departures/import') ?>">Только отсутствующие</a><?php else: ?><a class="btn btn-ghost" href="<?= app_url('/company/trips/departures/import?show_imported=1') ?>">Показать уже импортированные</a><?php endif; ?></div>
</div>

<?php if ($formSuccess): ?><div class="notice success"><?= e($formSuccess) ?></div><?php endif; ?>
<?php if ($formError): ?><div class="notice warn"><?= e($formError) ?></div><?php endif; ?>
<?php if ($legacyError): ?><div class="notice warn"><?= e($legacyError) ?></div><?php endif; ?>

<?php if (!$legacyError && $legacyCandidates === []): ?>
<div class="panel"><div class="panel-body"><div class="empty-state"><p class="empty-title">Legacy-рейсов для импорта не найдено.</p><p class="empty-desc">Все прочитанные legacy IDs уже имеют immutable source identity в ERP либо таблица пуста.</p></div></div></div>
<?php endif; ?>

<?php foreach ($legacyCandidates as $candidate): ?>
  <?php
    $legacyId = (int) $candidate['id'];
    $requestIds = (array) ($candidate['request_ids'] ?? []);
    $missing = (array) ($candidate['missing_request_ids'] ?? []);
    $occupied = (array) ($candidate['occupied_requests'] ?? []);
    $mappedStatus = $candidate['mapped_status'] ?? null;
  ?>
  <div class="panel legacy-trip-card">
    <div class="panel-body">
      <div class="legacy-trip-head">
        <div>
          <h3 style="margin:0 0 6px">Legacy #<?= $legacyId ?> <?php if (!empty($candidate['is_imported'])): ?><span class="badge badge-ok">ERP #<?= (int) $candidate['erp_waste_trip_id'] ?></span><?php endif; ?></h3>
          <div class="legacy-trip-meta"><span>Создан: <?= e($fmtDate($candidate['block_date'] ?? null)) ?></span><span>Статус: <?= e((string) ($candidate['status'] ?? '—')) ?><?= $mappedStatus ? ' → ' . e(WasteTripService::statusLabel((string) $mappedStatus)) : '' ?></span><span>Выгрузка: <?= e((string) ($candidate['unload_type'] ?? '—')) ?></span></div>
        </div>
        <div><b><?= count($requestIds) ?></b> заявок · <?= e($fmtMass($candidate['legacy_mass_kg'] ?? 0)) ?></div>
      </div>

      <div class="legacy-source-details">
        <div><b>Заявки</b><span class="legacy-request-list"><?= e(implode(', ', $requestIds)) ?></span></div>
        <div><b>ERP resolution</b><?= (int) ($candidate['found_count'] ?? 0) ?>/<?= count($requestIds) ?> найдено</div>
        <div><b>Mass reconciliation</b>ERP <?= e($fmtMass($candidate['current_mass_kg'] ?? 0)) ?> · Δ <?= e(number_format((float) ($candidate['mass_delta_kg'] ?? 0), 3, ',', ' ') . ' кг') ?></div>
        <div><b>План</b><?= e($candidate['planned_start_date'] ? $fmtDate($candidate['planned_start_date']) : (($candidate['planned_start_date_from'] ?? null) && ($candidate['planned_start_date_to'] ?? null) ? $fmtDate($candidate['planned_start_date_from']) . ' – ' . $fmtDate($candidate['planned_start_date_to']) : '—')) ?></div>
        <div><b>Факт</b><?= e($fmtDate($candidate['actual_start_date'] ?? null)) ?> → <?= e($fmtDate($candidate['actual_end_date'] ?? null)) ?></div>
        <div><b>Legacy исполнитель</b><?= e(trim((string) ($candidate['legacy_contractor_name'] ?? '')) ?: '—') ?> · <?= e(trim((string) ($candidate['legacy_driver_name'] ?? '')) ?: '—') ?><?php if (trim((string) ($candidate['legacy_vehicle_make_plate'] ?? '')) !== ''): ?> · <?= e((string) $candidate['legacy_vehicle_make_plate']) ?><?php endif; ?></div>
        <div><b>Подсказки заказчика из FEO</b><?php $hints=(array)($candidate['customer_hints']??[]); ?><?= $hints===[] ? '—' : e(implode(' / ', array_map(static fn(array $h): string => trim(($h['name']??'') . (($h['inn']??'')!=='' ? ' · ИНН '.($h['inn']??'') : '')), $hints))) ?></div>
        <div><b>Legacy менеджер</b><?= e(trim((string) ($candidate['legacy_manager_name'] ?? '')) ?: '—') ?></div>
        <div><b>Комментарий</b><?= e(trim((string) ($candidate['comment'] ?? '')) ?: '—') ?></div>
        <?php if ($isFinanceRealm): ?>
          <div><b>Legacy стоимость</b><?= ($candidate['cost'] ?? '') !== '' ? e(number_format((float) $candidate['cost'], 2, ',', ' ') . ' ₽') : '—' ?></div>
          <div><b>Legacy условия оплаты</b><?= e(trim((string) ($candidate['payment_terms'] ?? '')) ?: '—') ?></div>
        <?php endif; ?>
      </div>

      <?php if ($missing !== []): ?><div class="notice warn legacy-warning">BLOCKED: отсутствуют canonical FEO заявки: <?= e(implode(', ', $missing)) ?></div><?php endif; ?>
      <?php if ($occupied !== []): ?><div class="notice warn legacy-warning">BLOCKED: заявки уже заняты активными ERP рейсами: <?php foreach ($occupied as $zid => $tripId): ?><?= e((string) $zid) ?> → #<?= (int) $tripId ?>; <?php endforeach; ?></div><?php endif; ?>
      <?php if ($mappedStatus === null): ?><div class="notice warn legacy-warning">BLOCKED: неизвестный legacy status «<?= e((string) ($candidate['status'] ?? '')) ?>».</div><?php endif; ?>

      <?php if (empty($candidate['is_imported']) && !empty($candidate['ready_for_mapping'])): ?>
      <form method="post" action="<?= app_url('/company/trips/departures/import/' . $legacyId) ?>">
        <div class="legacy-map-grid">
          <div><label>ERP заказчик *</label><select name="client_id" required><option value="">— Выберите вручную —</option><?php foreach ($clients as $item): ?><option value="<?= (int) $item['id'] ?>"><?= e((string) $item['name']) ?></option><?php endforeach; ?></select></div>
          <div><label>ERP исполнитель<?= in_array($mappedStatus, [WasteTripService::STATUS_ASSIGNED, WasteTripService::STATUS_IN_PROGRESS, WasteTripService::STATUS_COMPLETED], true) ? ' *' : '' ?></label><select name="route_executor_id" <?= in_array($mappedStatus, [WasteTripService::STATUS_ASSIGNED, WasteTripService::STATUS_IN_PROGRESS, WasteTripService::STATUS_COMPLETED], true) ? 'required' : '' ?>><option value="">— Не назначен —</option><?php foreach ($routeExecutors as $item): ?><option value="<?= (int) $item['id'] ?>"><?= e(trim((string) ($item['contractor_name'] ?? '')) . ' · ' . trim((string) ($item['driver_name'] ?? ''))) ?></option><?php endforeach; ?></select></div>
          <div><label>ERP ответственный *</label><select name="responsible_user_id" required><option value="">— Выберите вручную —</option><?php foreach ($responsibleUsers as $item): ?><option value="<?= (int) $item['id'] ?>"><?= e((string) $item['full_name']) ?></option><?php endforeach; ?></select></div>
          <?php if (($candidate['unload_type'] ?? '') === 'OO'): ?><div><label>ERP утилизатор *</label><select name="utilizer_id" required><option value="">— Выберите вручную —</option><?php foreach ($utilizers as $item): ?><option value="<?= (int) $item['id'] ?>"><?= e((string) ($item['short_name'] ?: $item['full_name'])) ?></option><?php endforeach; ?></select></div><?php else: ?><div><label>Назначение</label><input value="Склад (без создания warehouse ledger)" readonly></div><?php endif; ?>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:14px"><button type="submit" class="btn btn-primary" onclick="return confirm('Импортировать legacy рейс #<?= $legacyId ?>? Операция создаст immutable source identity.');">Импортировать</button></div>
      </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
