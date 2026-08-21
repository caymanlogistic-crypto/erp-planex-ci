<?php if (!empty($successFlash)): ?><div class="notice success"><?= e($successFlash) ?></div><?php endif; ?>
<?php if (!empty($errorFlash)): ?><div class="notice warn"><?= e($errorFlash) ?></div><?php endif; ?>
<?php if (!empty($dbError)): ?><div class="notice warn"><?= e($dbError) ?></div><?php endif; ?>
<div class="ux-shell" data-ux-page="closing-document-exceptions">
 <div class="page-head">
  <div class="page-head-left"><h1 class="page-title">Исключения по документам</h1><div class="page-summary">ИНН из этого списка не требуют закрывающих документов. Все исходящие платежи с таким ИНН исключаются из проблемного счётчика.</div></div>
  <div class="page-head-actions"><a class="btn btn-secondary btn--toolbar" href="<?= app_url('/company/finance/closing-documents') ?>">Назад к документам</a></div>
 </div>
 <?php if (empty($schemaReady) && empty($dbError)): ?><div class="notice warn"><b>Для настроек требуется актуальная tenant schema.</b></div><?php elseif (!empty($schemaReady)): ?>
 <div class="table-card table-card--standard">
  <div class="table-toolbar"><div class="table-toolbar-left"><strong>Добавить исключение</strong></div></div>
  <form method="post" action="<?= app_url('/company/finance/closing-documents/exceptions/add') ?>" class="ux-filter-body">
   <div class="ux-filter-field"><label>ИНН</label><input class="field-input" name="inn" inputmode="numeric" maxlength="20" placeholder="Например: 7702070139" required></div>
   <div class="ux-filter-field is-search"><label>Комментарий</label><input class="field-input" name="comment" maxlength="255" placeholder="Например: банковские комиссии"></div>
   <div class="ux-filter-field"><label>&nbsp;</label><button class="btn btn-primary" type="submit">+ Добавить</button></div>
  </form>
 </div>
 <div class="table-card table-card--standard">
  <div class="table-toolbar"><div class="table-toolbar-left"><strong>ИНН без требования документов</strong></div></div>
  <?php if(empty($exceptions)):?><div class="ux-empty"><strong>Исключений пока нет</strong>По всем исходящим платежам действуют обычные правила документов.</div><?php else:?><div class="table-scroll"><table class="table"><thead><tr><th>ИНН</th><th>Получатель из последней выписки</th><th>Комментарий</th><th></th></tr></thead><tbody>
  <?php foreach($exceptions as $rule):?><tr><td class="col-mono"><strong><?= e((string)$rule['inn']) ?></strong></td><td><?= e((string)($rule['counterparty_name']?:'—')) ?></td><td><?= e((string)($rule['comment']?:'—')) ?></td><td class="col-tight"><form method="post" action="<?= app_url('/company/finance/closing-documents/exceptions/remove') ?>" onsubmit="return confirm('Удалить исключение? Документы по этому ИНН снова будут требоваться.');"><input type="hidden" name="id" value="<?= (int)$rule['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Удалить</button></form></td></tr><?php endforeach;?>
  </tbody></table></div><?php endif;?>
 </div>
 <?php endif;?>
</div>
