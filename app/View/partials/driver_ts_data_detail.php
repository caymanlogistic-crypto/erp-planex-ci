<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">
  <section><span class="page-eyebrow">Водитель</span>
    <dl class="driver-ts-fields"><?php foreach ($sections['driver'] as [$label,$value]): ?><dt><?= e($label) ?></dt><dd><?= e($value !== '' ? $value : '—') ?></dd><?php endforeach; ?></dl>
  </section>
  <section><span class="page-eyebrow">ТС</span>
    <dl class="driver-ts-fields"><?php foreach ($sections['vehicle'] as [$label,$value]): ?><dt><?= e($label) ?></dt><dd><?= e($value !== '' ? $value : '—') ?></dd><?php endforeach; ?></dl>
  </section>
  <section class="registry-ui-modal-preview"><span class="page-eyebrow">Рейсы «Отходы»</span><p class="registry-ui-modal-text"><?php foreach ($detail['trips'] as $i=>$trip): ?><?= $i ? ', ' : '' ?>#<?= (int)$trip ?><?php endforeach; ?></p>
    <span class="page-eyebrow">Файлы</span>
    <?php if ($detail['files'] === []): ?><p class="registry-ui-modal-text">Файлы не добавлены.</p><?php else: ?>
    <div class="table-scroll registry-ui-compact-table-scroll"><table class="table registry-ui-table registry-ui-compact-table driver-ts-files-table"><thead><tr><th>Документ</th><th>Файл</th><th>Просмотр</th></tr></thead><tbody>
      <?php foreach ($detail['files'] as $doc): ?><?php $fileUrl=app_url('/company/driver-ts-data/'.(int)$detail['crew_id'].'/files/'.(int)$doc['id']); ?><tr><td><?= e((string)($doc['document_type']?:'Документ')) ?></td><td><?= e($doc['original_name']) ?></td><td><a class="btn btn-ghost" data-driver-ts-preview href="<?= e($fileUrl.'?view=1') ?>">Посмотреть</a> <a class="btn btn-ghost" href="<?= e($fileUrl) ?>">Скачать</a></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </section>
</div>
