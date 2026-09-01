<?php

use App\Service\WasteTripService;

$tripId = (int) ($trip['id'] ?? 0);
$requestIds = implode(', ', array_map(static fn(array $row): string => (string) $row['zayavka_id'], $trip['requests'] ?? []));
$allowedStatuses = WasteTripService::allowedStatusTransitions((string) ($trip['status'] ?? ''));
$isOwner = (string) ($_SESSION['role_code'] ?? '') === 'company_owner';
$planMode = (string) ($trip['planned_start_mode'] ?? 'unspecified');
?>
<div data-waste-modal-title="Изменить рейс #<?= $tripId ?>" hidden></div>
<form method="post" action="<?= app_url('/company/trips/departures/' . $tripId . '/modal-edit') ?>">
  <input type="hidden" name="lock_version" value="<?= (int) $trip['lock_version'] ?>">
  <div class="modal-body waste-modal-scroll">
    <div class="waste-form-grid">
      <div class="waste-form-field span-2"><label>Заявки ФЭО *</label><textarea name="request_ids" rows="3" required><?= e($requestIds) ?></textarea><div class="waste-request-help">До начала вывоза состав редактируется обычно. После начала вывоза корректировка состава доступна только руководителю с явным подтверждением и попадает в audit log. Snapshot уже связанных заявок не пересчитывается.</div></div>
      <?php if (($trip['status'] ?? '') === WasteTripService::STATUS_IN_PROGRESS && $isOwner): ?><div class="waste-form-field span-2"><label><input type="checkbox" name="confirm_composition_correction" value="1"> Подтверждаю существенную корректировку состава после начала вывоза</label></div><?php endif; ?>
      <div class="waste-form-field"><label>Заказчик *</label><select name="client_id" required><?php foreach ($clients as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) $trip['client_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= e((string) $item['name']) ?></option><?php endforeach; ?></select></div>
      <div class="waste-form-field"><label>Ответственный *</label><select name="responsible_user_id" required><?php foreach ($responsibleUsers as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) $trip['responsible_user_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= e((string) $item['full_name']) ?></option><?php endforeach; ?></select></div>
      <div class="waste-form-field"><label>Статус *</label><select name="status" required><?php foreach (WasteTripService::STATUS_LABELS as $value => $label): if ($value === WasteTripService::STATUS_CANCELLED || !in_array($value, $allowedStatuses, true)) continue; ?><option value="<?= e($value) ?>" <?= (string) $trip['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="waste-form-field"><label>Исполнитель</label><select name="route_executor_id"><option value="">— Пока не назначен —</option><?php foreach ($routeExecutors as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) ($trip['route_executor_id'] ?? 0) === (int) $item['id'] ? 'selected' : '' ?>><?= e(trim((string) ($item['contractor_name'] ?? '')) . ' · ' . trim((string) ($item['driver_name'] ?? ''))) ?></option><?php endforeach; ?></select></div>
      <div class="waste-form-field"><label>Назначение *</label><select name="destination_type" data-waste-destination required><option value="utilizer" <?= ($trip['destination_type'] ?? '') === 'utilizer' ? 'selected' : '' ?>>Утилизатор</option><option value="warehouse" <?= ($trip['destination_type'] ?? '') === 'warehouse' ? 'selected' : '' ?>>Склад</option></select></div>
      <div class="waste-form-field" data-waste-utilizer-field><label>Утилизатор *</label><select name="utilizer_id"><option value="">— Выберите —</option><?php foreach ($utilizers as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) ($trip['utilizer_id'] ?? 0) === (int) $item['id'] ? 'selected' : '' ?>><?= e((string) ($item['short_name'] ?: $item['full_name'])) ?></option><?php endforeach; ?></select></div>
      <div class="waste-form-field span-2"><label>Планирование *</label><select name="planned_start_mode" data-waste-plan-mode required><option value="exact" <?= $planMode === 'exact' ? 'selected' : '' ?>>Точная дата</option><option value="range" <?= $planMode === 'range' ? 'selected' : '' ?>>Диапазон дат</option><?php if ($planMode === 'unspecified'): ?><option value="unspecified" selected>Не указано (legacy)</option><?php endif; ?></select><div class="waste-date-mode" style="margin-top:8px"><input type="date" name="planned_start_date" data-waste-plan-exact value="<?= e((string) ($trip['planned_start_date'] ?? '')) ?>"><input type="date" name="planned_start_date_from" data-waste-plan-from value="<?= e((string) ($trip['planned_start_date_from'] ?? '')) ?>"><input type="date" name="planned_start_date_to" data-waste-plan-to value="<?= e((string) ($trip['planned_start_date_to'] ?? '')) ?>"></div></div>
      <div class="waste-form-field"><label>Фактическое начало</label><input type="date" name="actual_start_date" value="<?= e((string) ($trip['actual_start_date'] ?? '')) ?>"></div>
      <div class="waste-form-field"><label>Фактическое окончание</label><input type="date" name="actual_end_date" value="<?= e((string) ($trip['actual_end_date'] ?? '')) ?>"></div>
      <div class="waste-form-field span-2"><label>Комментарий</label><textarea name="comment" rows="3"><?= e((string) ($trip['comment'] ?? '')) ?></textarea></div>
    </div>
  </div>
  <div class="modal-foot is-spaced"><div></div><div class="modal-foot-actions"><button type="button" class="btn btn-ghost" onclick="closeModal('waste-trip-edit-modal')">Отмена</button><button type="submit" class="btn btn-primary">Сохранить</button></div></div>
</form>
