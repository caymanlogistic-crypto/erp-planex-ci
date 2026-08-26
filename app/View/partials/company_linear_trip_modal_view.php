<?php

use App\Service\LinearRouteService;

$na = '—';
$routeId = (int) ($route['id'] ?? 0);
$executorName = trim((string) (($route['executor_contractor_name'] ?? '') . ' · ' . ($route['executor_driver_name'] ?? '')));
$executorPlates = trim((string) ($route['executor_primary_plate'] ?? ''));
if (!empty($route['executor_secondary_plate'])) {
    $executorPlates .= ' + ' . $route['executor_secondary_plate'];
}
$executorDisplay = $executorName !== '' ? $executorName : $na;
if ($executorPlates !== '') {
    $executorDisplay .= ' · ' . $executorPlates;
}
$routePoints = (array) ($route['route_points'] ?? ['loading' => [], 'unloading' => []]);
$customerPayments = (array) ($route['payments']['customer'] ?? []);
$carrierPayments = (array) ($route['payments']['carrier'] ?? []);
$routeAdditionalServices = is_array($routeAdditionalServices ?? null) ? $routeAdditionalServices : [];
$documentTitles = [
    'customer_document' => 'Договор/заявка с заказчиком',
    'carrier_document' => 'Договор/заявка с перевозчиком',
    'principal_document' => 'Договор/заявка с принципалом',
];

$sumPayments = static function (array $payments): float {
    $sum = 0.0;
    foreach ($payments as $payment) {
        $raw = str_replace([' ', ','], ['', '.'], (string) ($payment['amount'] ?? '0'));
        $sum += is_numeric($raw) ? (float) $raw : 0.0;
    }
    return $sum;
};
$customerTotal = $sumPayments($customerPayments);
$carrierTotal = $sumPayments($carrierPayments);
$additionalServicesTotal = 0.0;
foreach ($routeAdditionalServices as $service) {
    $additionalServicesTotal += $sumPayments(
        is_array($service['payments'] ?? null) ? $service['payments'] : []
    );
}
$margin = $customerTotal - $carrierTotal - $additionalServicesTotal;
$plannedLoading = trim((string) ($route['planned_loading_date'] ?? ''));
$actualLoading = trim((string) ($route['actual_loading_date'] ?? ''));
$displayLoading = $actualLoading !== '' ? $actualLoading : $plannedLoading;
$loadingIsPlanned = $actualLoading === '' && $plannedLoading !== '';
$plannedUnloading = trim((string) ($route['planned_unloading_date'] ?? ''));
$actualUnloading = trim((string) ($route['actual_unloading_date'] ?? ''));
$displayUnloading = $actualUnloading !== '' ? $actualUnloading : $plannedUnloading;
$unloadingIsPlanned = $actualUnloading === '' && $plannedUnloading !== '';

$renderPoints = static function (array $items) use ($na): void {
    if ($items === []) {
        echo '<span class="is-na">' . e($na) . '</span>';
        return;
    }
    echo '<div class="trip-view-points">';
    foreach (array_values($items) as $index => $address) {
        echo '<div class="trip-view-point"><span class="trip-view-point-num">' . ($index + 1) . '.</span><span>' . e((string) $address) . '</span></div>';
    }
    echo '</div>';
};

$paymentDueLabel = static function (array $payment): string {
    $conditionType = $payment['condition_type'] ?? null;
    $label = LinearRouteService::conditionTypeLabel($conditionType);
    $days = (int) ($payment['days_count'] ?? $payment['payment_due_days'] ?? 0);
    if ($days > 0) {
        $kind = (string) ($payment['days_kind'] ?? $payment['payment_due_days_kind'] ?? 'calendar');
        $label .= ' · ' . $days . ' ' . ($kind === 'working' ? 'РД' : 'БД');
    }
    $dueDate = trim((string) ($payment['calculated_due_date'] ?? ''));
    if ($dueDate !== '') {
        $label .= ' · ' . ui_date($dueDate);
    }
    return $label;
};
?>
<div class="modal-body driver-modal-body">
  <div data-trip-view-title="Просмотр данных рейса #<?= $routeId ?>" hidden></div>
  <style>
  /* ModalShell preserves .modal-body but discards top-level style/link nodes from AJAX partials. */
  #linear-trip-view-modal .driver-modal-docs .driver-doc-view-item{
    display:grid!important;
    grid-template-columns:minmax(0,1fr) 108px 30px!important;
    column-gap:8px!important;
    align-items:center!important;
    width:100%!important;
  }
  #linear-trip-view-modal .driver-modal-docs .driver-doc-view-item>.file-info{
    grid-column:1!important;
    min-width:0!important;
    width:100%!important;
    max-width:none!important;
  }
  #linear-trip-view-modal .driver-modal-docs .driver-doc-view-item>.file-action-btn{grid-column:2!important;width:108px!important}
  #linear-trip-view-modal .driver-modal-docs .driver-doc-view-item>.driver-doc-download-btn{grid-column:3!important}
  #linear-trip-view-modal .driver-modal-docs .driver-doc-view-item .file-name,
  #linear-trip-view-modal .driver-modal-docs .driver-doc-view-item .file-meta{
    display:block!important;
    width:100%!important;
    max-width:none!important;
    white-space:normal!important;
    overflow:visible!important;
    text-overflow:clip!important;
    overflow-wrap:anywhere!important;
  }
  #linear-trip-view-modal .trip-view-totals.has-additional-services{
    display:grid!important;
    grid-template-columns:repeat(4,minmax(0,1fr))!important;
  }
  #linear-trip-view-modal .trip-view-totals.has-additional-services .trip-view-total{min-width:0}
  @media(max-width:900px){
    #linear-trip-view-modal .trip-view-totals.has-additional-services{grid-template-columns:repeat(2,minmax(0,1fr))!important}
  }
  </style>
  <div class="driver-modal-layout">
    <div class="driver-modal-main trip-view-main">
      <table class="trip-view-table" aria-label="Данные рейса">
        <tbody>
          <tr>
            <td>Заказчик</td>
            <td><strong><?= e((string) ($route['client_name'] ?? $na)) ?></strong></td>
          </tr>
          <tr>
            <td>Исполнитель рейса</td>
            <td><?= e($executorDisplay) ?></td>
          </tr>
          <tr>
            <td>Начало рейса</td>
            <td><span class="trip-view-date<?= $loadingIsPlanned ? ' is-planned' : '' ?>"><span class="trip-view-date-value"><?= e($displayLoading !== '' ? ui_date($displayLoading) : '—') ?></span><?php if ($loadingIsPlanned): ?><span class="trip-view-date-note">(плановая)</span><?php endif; ?></span></td>
          </tr>
          <tr>
            <td>Окончание рейса</td>
            <td><span class="trip-view-date<?= $unloadingIsPlanned ? ' is-planned' : '' ?>"><span class="trip-view-date-value"><?= e($displayUnloading !== '' ? ui_date($displayUnloading) : '—') ?></span><?php if ($unloadingIsPlanned): ?><span class="trip-view-date-note">(плановая)</span><?php endif; ?></span></td>
          </tr>
          <tr>
            <td>Перевозимый груз</td>
            <td><?= e(trim((string) ($route['cargo_type_name'] ?? '')) !== '' ? (string) $route['cargo_type_name'] : '—') ?></td>
          </tr>
          <tr>
            <td>Загрузка</td>
            <td><?php $renderPoints((array) ($routePoints['loading'] ?? [])); ?></td>
          </tr>
          <tr>
            <td>Выгрузка</td>
            <td><?php $renderPoints((array) ($routePoints['unloading'] ?? [])); ?></td>
          </tr>
          <?php if ($isFinanceRealm): ?>
          <tr>
            <td>Заказчик</td>
            <td>
              <table class="trip-view-finance-table" aria-label="Оплаты заказчика">
                <thead><tr><th>Сумма</th><th>Оплата</th><th>НДС</th><th>Срок</th><th>Статус</th></tr></thead>
                <tbody>
                <?php foreach ($customerPayments as $payment): ?>
                  <tr>
                    <td class="amount"><?= e(LinearRouteService::formatAmount($payment['amount'] ?? null, 0)) ?> ₽</td>
                    <td><?= e(LinearRouteService::paymentMethodLabel($payment['payment_method'] ?? null)) ?></td>
                    <td><?= e(LinearRouteService::vatRateLabel(isset($payment['vat_rate']) ? (string) $payment['vat_rate'] : null)) ?></td>
                    <td><?= e($paymentDueLabel($payment)) ?></td>
                    <td class="status"><span class="<?= e(LinearRouteService::paymentStatusBadgeClass((string) ($payment['payment_status'] ?? 'unpaid'))) ?>"><?= e(LinearRouteService::paymentStatusLabel((string) ($payment['payment_status'] ?? 'unpaid'))) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                <?php if ($customerPayments === []): ?><tr><td colspan="5" class="is-na">—</td></tr><?php endif; ?>
                </tbody>
              </table>
            </td>
          </tr>
          <tr>
            <td>Перевозчик</td>
            <td>
              <table class="trip-view-finance-table" aria-label="Оплаты перевозчика">
                <thead><tr><th>Сумма</th><th>Оплата</th><th>НДС</th><th>Срок</th><th>Статус</th></tr></thead>
                <tbody>
                <?php foreach ($carrierPayments as $payment): ?>
                  <tr>
                    <td class="amount"><?= e(LinearRouteService::formatAmount($payment['amount'] ?? null, 0)) ?> ₽</td>
                    <td><?= e(LinearRouteService::paymentMethodLabel($payment['payment_method'] ?? null)) ?></td>
                    <td><?= e(LinearRouteService::vatRateLabel(isset($payment['vat_rate']) ? (string) $payment['vat_rate'] : null)) ?></td>
                    <td><?= e($paymentDueLabel($payment)) ?></td>
                    <td class="status"><span class="<?= e(LinearRouteService::paymentStatusBadgeClass((string) ($payment['payment_status'] ?? 'unpaid'))) ?>"><?= e(LinearRouteService::paymentStatusLabel((string) ($payment['payment_status'] ?? 'unpaid'))) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                <?php if ($carrierPayments === []): ?><tr><td colspan="5" class="is-na">—</td></tr><?php endif; ?>
                </tbody>
              </table>
              <div class="trip-view-totals<?= $routeAdditionalServices !== [] ? ' has-additional-services' : '' ?>">
                <div class="trip-view-total"><div class="trip-view-total-label">Заказчик</div><div class="trip-view-total-value"><?= e(LinearRouteService::formatAmount((string) $customerTotal, 0)) ?> ₽</div></div>
                <div class="trip-view-total"><div class="trip-view-total-label">Перевозчик</div><div class="trip-view-total-value"><?= e(LinearRouteService::formatAmount((string) $carrierTotal, 0)) ?> ₽</div></div>
                <?php if ($routeAdditionalServices !== []): ?>
                <div class="trip-view-total is-additional-services"><div class="trip-view-total-label">Доп. услуги</div><div class="trip-view-total-value"><?= e(LinearRouteService::formatAmount((string) $additionalServicesTotal, 0)) ?> ₽</div></div>
                <?php endif; ?>
                <div class="trip-view-total is-margin" title="Маржа = заказчик − перевозчик − доп. услуги" data-margin-includes-additional-services="1"><div class="trip-view-total-label">Маржа</div><div class="trip-view-total-value"><?= e(LinearRouteService::formatAmount((string) max(0, $margin), 0)) ?> ₽</div></div>
              </div>
            </td>
          </tr>
          <?php endif; ?>
          <?php if (trim((string) ($route['comments'] ?? '')) !== ''): ?>
          <tr>
            <td>Комментарий</td>
            <td class="trip-view-comment"><?= e((string) $route['comments']) ?></td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="driver-modal-docs">
      <div class="section-title driver-docs-heading">Документы</div>
      <div class="file-list">
        <?php $hasDocs = false; ?>
        <?php foreach ($documentTitles as $docKey => $docTitle): ?>
          <?php foreach (($docsByCode[$docKey] ?? []) as $document): $hasDocs = true; ?>
            <div class="file-item file-item-predef document-file-row has-file has-existing-file driver-doc-view-item">
              <div class="file-info"><div class="file-name"><?= e($docTitle) ?></div><div class="file-meta"><?= e($document['original_name'] ?? $document['stored_name'] ?? 'Файл') ?></div></div>
              <a href="<?= app_url('/company/documents/view?id=' . (int) $document['id']) ?>" target="_blank" rel="noopener" class="btn btn-secondary file-action-btn js-doc-popup-window"><span>Просмотр</span></a>
              <a href="<?= app_url('/company/documents/download?id=' . (int) $document['id']) ?>" class="predef-file-clear driver-doc-download-btn" download title="Скачать файл" aria-label="Скачать файл">↓</a>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <?php foreach (($docsByCode['other'] ?? []) as $document): $hasDocs = true; ?>
          <div class="file-item file-item-predef document-file-row has-file has-existing-file driver-doc-view-item">
            <div class="file-info"><div class="file-name"><?= e($document['document_type'] ?? $document['type_name'] ?? 'Документ') ?></div><div class="file-meta"><?= e($document['original_name'] ?? $document['stored_name'] ?? 'Файл') ?></div></div>
            <a href="<?= app_url('/company/documents/view?id=' . (int) $document['id']) ?>" target="_blank" rel="noopener" class="btn btn-secondary file-action-btn js-doc-popup-window"><span>Просмотр</span></a>
            <a href="<?= app_url('/company/documents/download?id=' . (int) $document['id']) ?>" class="predef-file-clear driver-doc-download-btn" download title="Скачать файл" aria-label="Скачать файл">↓</a>
          </div>
        <?php endforeach; ?>
        <?php if (!$hasDocs): ?><div class="driver-docs-empty">Документы не загружены.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<div class="modal-foot is-spaced">
  <div class="modal-foot-actions"><?php if ($canDelete): ?><button type="button" class="btn btn-ghost" data-linear-trip-delete-btn>Удалить</button><?php endif; ?></div>
  <div class="modal-foot-actions"><button type="button" class="btn btn-ghost" data-linear-trip-view-close-btn>Закрыть</button><?php if ($canEdit): ?><button type="button" class="btn btn-primary" data-linear-trip-edit-btn>Редактировать</button><?php endif; ?></div>
</div>
