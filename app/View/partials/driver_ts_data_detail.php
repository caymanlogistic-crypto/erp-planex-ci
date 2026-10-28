<?php
$driverGroups = [['title' => 'Водитель', 'fields' => []]];
foreach ($sections['driver'] as [$label, $value]) {
    $value = trim((string)$value);
    if ($value === '' && preg_match('/^Водитель [2-9][0-9]*$/u', (string)$label)) {
        $driverGroups[] = ['title' => (string)$label, 'fields' => []];
    } elseif ($value !== '') {
        $driverGroups[array_key_last($driverGroups)]['fields'][] = [(string)$label, $value];
    }
}
$vehicleType = '';
$vehicleSetComment = '';
$vehicleGroups = [];
foreach ($sections['vehicle'] as [$label, $value]) {
    $label = (string)$label;
    $value = trim((string)$value);
    if ($label === 'Тип комплекта') {
        $vehicleType = $value;
    } elseif ($label === 'Комментарий к комплекту') {
        $vehicleSetComment = $value;
    } elseif ($value === '' && in_array($label, ['Автомобиль', 'Первая часть', 'Тягач', 'Прицеп', 'Полуприцеп'], true)) {
        $vehicleGroups[] = ['title' => $label, 'fields' => []];
    } elseif ($value !== '') {
        if ($vehicleGroups === []) $vehicleGroups[] = ['title' => 'ТС', 'fields' => []];
        $vehicleGroups[array_key_last($vehicleGroups)]['fields'][] = [$label, $value];
    }
}
?>
<?php $summaryGroups = array_merge($driverGroups, $vehicleGroups); ?>
<div class="driver-ts-summary">
  <?php foreach ($summaryGroups as $group): ?>
  <section class="driver-ts-summary-group"><span class="page-eyebrow"><?= e($group['title']) ?></span>
    <dl class="driver-ts-fields"><?php foreach ($group['fields'] as [$label, $value]): ?><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd><?php endforeach; ?></dl>
  </section>
  <?php endforeach; ?>
</div>
<?php if ($vehicleType !== ''): ?><p class="driver-ts-set-type">Тип комплекта: <?= e($vehicleType) ?></p><?php endif; ?>
<?php if ($vehicleSetComment !== ''): ?><p class="driver-ts-set-type">Комментарий к комплекту: <?= e($vehicleSetComment) ?></p><?php endif; ?>
<section class="driver-ts-files registry-ui-modal-preview">
  <div class="driver-ts-files-heading"><span class="page-eyebrow">Файлы</span><span class="registry-ui-modal-text">Рейсы «Отходы»: <?php foreach ($detail['trips'] as $i=>$trip): ?><?= $i ? ', ' : '' ?>#<?= (int)$trip ?><?php endforeach; ?></span></div>
  <?php if ($detail['files'] === []): ?><p class="registry-ui-modal-text">Файлы не добавлены.</p><?php else: ?>
  <div class="table-scroll registry-ui-compact-table-scroll"><table class="table registry-ui-table registry-ui-compact-table driver-ts-files-table"><thead><tr><th>Документ</th><th>Файл</th><th>Просмотр</th></tr></thead><tbody>
    <?php foreach ($detail['files'] as $index=>$doc): ?><?php $fileUrl=app_url('/company/driver-ts-data/'.(int)$detail['crew_id'].'/files/'.(int)$doc['id']); ?><tr data-driver-ts-file-row="<?= (int)$index ?>"<?= $index >= 3 ? ' hidden' : '' ?>><td><?= e((string)($doc['document_type']?:'Документ')) ?></td><td><?= e($doc['original_name']) ?></td><td><a class="btn btn-ghost" href="<?= e($fileUrl.'?view=1') ?>" target="_blank" rel="noopener">Посмотреть</a> <a class="btn btn-ghost" href="<?= e($fileUrl) ?>">Скачать</a></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if (count($detail['files']) > 3): ?><nav class="driver-ts-file-pages" data-driver-ts-file-pages aria-label="Страницы файлов"><button type="button" class="btn btn-ghost" data-driver-ts-page-prev aria-label="Предыдущая страница" disabled>‹</button><span data-driver-ts-page-label aria-live="polite">1 / <?= (int)ceil(count($detail['files']) / 3) ?></span><button type="button" class="btn btn-ghost" data-driver-ts-page-next aria-label="Следующая страница">›</button></nav><?php endif; ?>
  <?php endif; ?>
</section>
