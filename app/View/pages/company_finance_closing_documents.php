<?php
$problemCountOnPage = count(array_filter($rows ?? [], static fn(array $row): bool => !empty($row['is_problem'])));
$requirementLabels = \App\Service\ClosingDocumentService::requirementLabels();
?>
<style>
.closing-documents-card{min-width:0;max-width:100%;overflow:hidden}
.closing-documents-table-frame{display:block;width:100%;max-width:100%;min-width:0;overflow:visible}
.closing-documents-table{width:100%!important;max-width:100%!important;min-width:0!important;table-layout:fixed}
.closing-documents-table th,.closing-documents-table td{min-width:0;max-width:0;white-space:normal!important;overflow:visible;text-overflow:clip;overflow-wrap:anywhere;word-break:break-word;vertical-align:top}
.closing-documents-table .field-select,.closing-documents-table .field-input{width:100%;max-width:100%;min-width:0;box-sizing:border-box}
.closing-documents-table details,.closing-documents-table form,.closing-documents-table .stack-sm,.closing-documents-table .stack-xs{min-width:0;max-width:100%}
.closing-documents-table summary,.closing-documents-table .btn,.closing-documents-table .badge{max-width:100%;white-space:normal;overflow-wrap:anywhere}
</style>
<?php if (!empty($successFlash)): ?><div class="notice success"><?= e($successFlash) ?></div><?php endif; ?>
<?php if (!empty($errorFlash)): ?><div class="notice warn"><?= e($errorFlash) ?></div><?php endif; ?>
<?php if (!empty($dbError)): ?><div class="notice warn"><?= e($dbError) ?></div><?php endif; ?>

<div class="page-head">
 <div class="page-head-left"><h1 class="page-title">Закрывающие документы</h1><div class="page-summary">Документы по исходящим банковским платежам. Тип получателя определяется автоматически по наименованию из выписки.</div></div>
 <div class="page-head-actions">
  <a class="btn btn-secondary btn--toolbar" href="<?= app_url('/company/finance/closing-documents/exceptions') ?>">Исключения по документам</a>
  <?php if (!empty($schemaReady)): ?><?php if (!empty($problemOnly)): ?><a class="btn btn-ghost btn--toolbar" href="<?= app_url('/company/finance/closing-documents') ?>">Все операции</a><?php else: ?><a class="btn btn-secondary btn--toolbar" href="<?= app_url('/company/finance/closing-documents?problem=1') ?>">Только проблемные</a><?php endif; ?><?php endif; ?>
 </div>
</div>

<?php if (empty($schemaReady) && empty($dbError)): ?>
<div class="notice warn"><b>Для раздела требуется актуальная tenant schema.</b></div>
<?php elseif (!empty($schemaReady)): ?>
<div class="table-card table-card--standard bank-finance-card closing-documents-card">
 <div class="table-toolbar"><div class="table-toolbar-left"><strong><?= !empty($problemOnly) ? 'Проблемные операции' : 'Исходящие банковские расходы' ?></strong><span class="field-note">Проблемных: <?= (int)$problemCountOnPage ?></span></div><div class="field-note">ООО → ЮЛ · ИП → ИП · без ООО/ИП → Самозанятый</div></div>
 <div class="closing-documents-table-frame"><table class="table closing-documents-table"><colgroup><col style="width:6%"><col style="width:29%"><col style="width:15%"><col style="width:10%"><col style="width:13%"><col style="width:14%"><col style="width:13%"></colgroup><thead><tr><th>Дата / №</th><th>Платёж</th><th>Получатель</th><th>Тип</th><th>Комплект</th><th>Документы</th><th>Действия</th></tr></thead><tbody>
 <?php if (empty($rows)): ?><tr><td colspan="7"><div class="ux-empty"><strong><?= !empty($problemOnly) ? 'Проблемных операций нет' : 'Нет операций для отображения' ?></strong></div></td></tr><?php endif; ?>
 <?php foreach ($rows as $row):
   $profile=$row['profile']??null;$inn=(string)($row['normalized_inn']??'');$availableDocuments=$documentsByInn[$inn]??[];$acceptedCodes=[];
   foreach(($profile['requirements']??[]) as $req){foreach($req['accepted'] as $code){$acceptedCodes[$code]=$requirementLabels[$code]??$code;}}
   $amount='-'.number_format((float)($row['debit_amount']??0),2,',',' ').' ₽';
 ?>
 <tr data-closing-transaction-id="<?= (int)$row['id'] ?>"<?= !empty($row['is_problem'])?' class="is-attention"':'' ?>>
  <td class="col-tight"><strong><?= e(date('d.m.Y',strtotime((string)$row['operation_date']))) ?></strong><div class="field-note">№ <?= e((string)($row['document_number']?:$row['id'])) ?></div></td>
  <td><strong><?= e($amount) ?></strong><div class="field-note"><?= e((string)($row['purpose']??'')) ?></div></td>
  <td><strong><?= e((string)($row['counterparty_name']?:'Без наименования')) ?></strong><div class="field-note">ИНН <?= e($inn!==''?$inn:'—') ?></div></td>
  <td><?php if(!empty($row['is_exempt'])):?><span class="badge badge-success"><span class="dot"></span>Документы не требуются</span><?php if(!empty($row['exception_comment'])):?><div class="field-note"><?= e($row['exception_comment']) ?></div><?php endif; ?><?php else:?><strong><?= e((string)($profile['label']??'—')) ?></strong><?php endif; ?></td>
  <td><?php if(!empty($row['is_exempt'])):?><span class="text-muted">Исключение по ИНН</span><?php elseif(empty($row['is_problem'])):?><span class="badge badge-success"><span class="dot"></span>Комплект собран</span><?php else:?><span class="badge badge-warning"><span class="dot"></span>Требует действия</span><?php foreach(($row['missing_requirements']??[]) as $missing):?><div class="field-note">• <?= e($missing['label']) ?></div><?php endforeach; ?><?php endif; ?></td>
  <td><?php foreach(($row['links']??[]) as $link):if(!empty($link['document_deleted_at']))continue;?><div class="stack-xs"><span><?= e($requirementLabels[$link['requirement_code']]??$link['requirement_code']) ?>: <?= e($link['original_name']) ?></span><form method="post" action="<?= app_url('/company/finance/closing-documents/unlink-document') ?>" class="inline-form"><input type="hidden" name="transaction_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>"><input type="hidden" name="problem_only" value="<?= !empty($problemOnly)?'1':'0' ?>"><button type="submit" class="btn btn-ghost btn-sm">Отвязать</button></form></div><?php endforeach; ?><?php if(empty($row['links'])):?><span class="text-muted">—</span><?php endif; ?></td>
  <td><?php if(empty($row['is_exempt'])&&$profile):?>
   <?php if($inn!==''&&$availableDocuments):?><details><summary class="link-button">Привязать существующий</summary><form method="post" action="<?= app_url('/company/finance/closing-documents/link-document') ?>" class="stack-sm"><input type="hidden" name="transaction_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="problem_only" value="<?= !empty($problemOnly)?'1':'0' ?>"><select name="requirement_code" class="field-select" required><option value="">Тип документа</option><?php foreach($acceptedCodes as $code=>$label):?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach;?></select><select name="document_id" class="field-select" required><option value="">Документ этого ИНН</option><?php foreach($availableDocuments as $document):?><option value="<?= (int)$document['id'] ?>"><?= e((string)($document['type_name']?:$document['document_type']?:'Документ')) ?> · <?= e($document['original_name']) ?></option><?php endforeach;?></select><button class="btn btn-secondary btn-sm" type="submit">Привязать</button></form></details><?php endif; ?>
   <details><summary class="link-button">Загрузить документ</summary><form method="post" enctype="multipart/form-data" action="<?= app_url('/company/finance/closing-documents/upload-document') ?>" class="stack-sm"><input type="hidden" name="transaction_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="problem_only" value="<?= !empty($problemOnly)?'1':'0' ?>"><select name="requirement_code" class="field-select" required><option value="">Тип документа</option><?php foreach($acceptedCodes as $code=>$label):?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach;?></select><input type="file" name="document_file" class="field-input" required><button class="btn btn-secondary btn-sm" type="submit">Загрузить и привязать</button></form></details>
  <?php else:?><span class="field-note">Действий не требуется</span><?php endif; ?></td>
 </tr><?php endforeach; ?>
 </tbody></table></div>
</div>
<?php endif; ?>
