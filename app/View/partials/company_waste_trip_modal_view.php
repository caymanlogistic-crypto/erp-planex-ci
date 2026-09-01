<?php

use App\Service\WasteTripService;

$isFinanceRealm = (string) ($_SESSION['role_code'] ?? '') === 'company_owner';
$tripId = (int) ($trip['id'] ?? 0);
$fmtMass = static fn($kg): string => number_format((float) $kg / 1000, 3, ',', ' ') . ' т';
$fmtDate = static fn($date): string => trim((string) $date) !== '' ? ui_date((string) $date) : '—';
$executorParts = array_values(array_filter([
    trim((string) ($trip['executor_contractor_name'] ?? '')),
    trim((string) ($trip['executor_driver_names'] ?? '')),
    trim((string) ($trip['executor_vehicle_label'] ?? '')),
], static fn(string $v): bool => $v !== ''));
$historicalExecutor = trim((string) ($trip['historical_executor_label'] ?? ''));
$executorDisplay = $executorParts !== [] ? implode(' · ', $executorParts) : ($historicalExecutor !== '' ? 'Исторический: ' . $historicalExecutor : '—');
$legacyPayload = is_array($trip['legacy_payload'] ?? null) ? $trip['legacy_payload'] : [];
$destination = ($trip['destination_type'] ?? '') === 'warehouse'
    ? 'Склад'
    : trim((string) ($trip['utilizer_short_name'] ?? $trip['utilizer_full_name'] ?? 'Утилизатор'));
$planned = '—';
if (!empty($trip['planned_start_date'])) {
    $planned = $fmtDate($trip['planned_start_date']);
} elseif (!empty($trip['planned_start_date_from']) && !empty($trip['planned_start_date_to'])) {
    $planned = $fmtDate($trip['planned_start_date_from']) . ' – ' . $fmtDate($trip['planned_start_date_to']);
}
$totalMassKg = 0.0;
foreach (($trip['requests'] ?? []) as $request) {
    $totalMassKg += (float) ($request['snapshot_mass_kg'] ?? 0);
}
?>
<div data-waste-modal-title="Просмотр рейса #<?= $tripId ?>" hidden></div>
<div class="modal-body waste-modal-scroll">
  <table class="trip-view-table" aria-label="Данные рейса перевозки отходов">
    <tbody>
      <tr><td>Статус</td><td><span class="<?= e(WasteTripService::statusBadgeClass((string) $trip['status'])) ?>"><?= e(WasteTripService::statusLabel((string) $trip['status'])) ?></span></td></tr>
      <tr><td>Заказчик</td><td><strong><?= e((string) ($trip['client_name'] ?? '—')) ?></strong></td></tr>
      <tr><td>Ответственный</td><td><?= e((string) ($trip['responsible_name'] ?? '—')) ?></td></tr>
      <tr><td>Исполнитель</td><td><?= e($executorDisplay) ?></td></tr>
      <tr><td>Назначение</td><td><?= e($destination !== '' ? $destination : '—') ?></td></tr>
      <tr><td>Плановая дата</td><td><?= e($planned) ?></td></tr>
      <tr><td>Фактическое начало</td><td><?= e($fmtDate($trip['actual_start_date'] ?? null)) ?></td></tr>
      <tr><td>Фактическое окончание</td><td><?= e($fmtDate($trip['actual_end_date'] ?? null)) ?></td></tr>
      <tr><td>Масса snapshot</td><td><strong><?= e($fmtMass($totalMassKg)) ?></strong></td></tr>
      <?php if (trim((string) ($trip['comment'] ?? '')) !== ''): ?><tr><td>Комментарий</td><td><?= nl2br(e((string) $trip['comment'])) ?></td></tr><?php endif; ?>
      <?php if (($trip['status'] ?? '') === WasteTripService::STATUS_CANCELLED): ?><tr><td>Причина отмены</td><td><?= e((string) ($trip['cancellation_reason'] ?? '—')) ?></td></tr><tr><td>Отменён</td><td><?= e((string) ($trip['cancelled_at'] ?? '—')) ?> · user #<?= (int) ($trip['cancelled_by_user_id'] ?? 0) ?> · <?= e((string) ($trip['cancelled_by_role'] ?? '—')) ?></td></tr><?php endif; ?>
      <?php if (!empty($trip['legacy_audit'])): ?>
        <tr><td>Источник</td><td>Legacy flights #<?= (int) $trip['legacy_audit']['legacy_flight_id'] ?></td></tr>
        <tr><td>Legacy масса</td><td><?= e($fmtMass($trip['legacy_audit']['legacy_total_mass_kg'] ?? 0)) ?></td></tr>
        <tr><td>ERP snapshot при импорте</td><td><?= e($fmtMass($trip['legacy_audit']['snapshot_total_mass_kg'] ?? 0)) ?> · заявок <?= (int) ($trip['legacy_audit']['snapshot_request_count'] ?? 0) ?></td></tr>
        <tr><td>Расхождение массы</td><td><?= e(number_format((float) ($trip['legacy_audit']['mass_delta_kg'] ?? 0), 3, ',', ' ') . ' кг') ?></td></tr>
        <?php if (trim((string) ($legacyPayload['route_type'] ?? '')) !== ''): ?><tr><td>Legacy маршрут</td><td><?= e((string) $legacyPayload['route_type']) ?><?php if (!empty($legacyPayload['source_warehouse_id'])): ?> · склад-источник #<?= (int) $legacyPayload['source_warehouse_id'] ?><?php endif; ?><?php if (!empty($legacyPayload['destination_warehouse_id'])): ?> · склад-назначение #<?= (int) $legacyPayload['destination_warehouse_id'] ?><?php endif; ?></td></tr><?php endif; ?>
        <?php if ($isFinanceRealm): ?>
          <tr><td>Legacy стоимость</td><td><?= $trip['legacy_audit']['legacy_cost'] !== null ? e(number_format((float) $trip['legacy_audit']['legacy_cost'], 2, ',', ' ') . ' ₽') : '—' ?></td></tr>
          <tr><td>Legacy условия оплаты</td><td><?= e((string) ($trip['legacy_audit']['legacy_payment_terms'] ?? '—')) ?></td></tr>
        <?php endif; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="section-title" style="margin-top:20px">Заявки ФЭО</div>
  <div class="table-scroll">
    <table class="table">
      <thead><tr><th>#</th><th>Заявка</th><th>Грузоотправитель</th><th>Адрес погрузки</th><th>Отход</th><th>Snapshot масса</th></tr></thead>
      <tbody>
      <?php foreach (($trip['requests'] ?? []) as $index => $request): ?>
        <tr>
          <td><?= $index + 1 ?></td>
          <td class="col-mono"><?= e((string) $request['zayavka_id']) ?></td>
          <td><?= e((string) ($request['naim_oo_gruzootpravitel'] ?? '—')) ?></td>
          <td><?= e((string) ($request['mno_adres_pogruzki'] ?? '—')) ?></td>
          <td><?= e((string) ($request['naim_otkhoda_fkko'] ?? '—')) ?></td>
          <td class="col-mono"><?= e($fmtMass($request['snapshot_mass_kg'] ?? 0)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="modal-foot is-spaced">
  <div class="modal-foot-actions">
    <?php if (!in_array((string) $trip['status'], [WasteTripService::STATUS_COMPLETED, WasteTripService::STATUS_CANCELLED], true)): ?>
      <form method="post" action="<?= app_url('/company/trips/departures/' . $tripId . '/cancel') ?>" onsubmit="return confirm('Отменить рейс? Заявки снова станут доступны для нового рейса.');">
        <input type="hidden" name="lock_version" value="<?= (int) $trip['lock_version'] ?>">
        <input type="text" name="cancellation_reason" required maxlength="500" placeholder="Причина отмены" style="min-width:260px">
        <button type="submit" class="btn btn-ghost">Отменить рейс</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="modal-foot-actions"><button type="button" class="btn btn-secondary" onclick="closeModal('waste-trip-view-modal')">Закрыть</button></div>
</div>
