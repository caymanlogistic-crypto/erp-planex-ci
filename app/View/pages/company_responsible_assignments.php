<?php
$tabs=['route_executor'=>['label'=>'Исполнители рейса','url'=>'/company/responsible-assignments?tab=route_executor'],'contractor'=>['label'=>'Подрядчики','url'=>'/company/responsible-assignments?tab=contractor'],'driver'=>['label'=>'Водители','url'=>'/company/responsible-assignments?tab=driver'],'vehicle_set'=>['label'=>'ТС','url'=>'/company/responsible-assignments?tab=vehicle_set']];
$tabLabels=['route_executor'=>'Исполнители рейса','contractor'=>'Подрядчики','driver'=>'Водители','vehicle_set'=>'ТС'];$showCascade=in_array($activeTab,['contractor','driver','vehicle_set'],true);$tabLabel=$tabLabels[$activeTab]??'';
$unassignedCount=count(array_filter($items??[],static fn(array $i):bool=>empty($i['logist_membership_id'])));
?>
<?php if($company===null):?><div class="notice warn">Компания не найдена.</div>
<?php elseif(($company['status']??'')!=='active'):?><div class="page-head"><div class="page-head-left"><h1 class="page-title">Ответственные логисты</h1></div></div><div class="notice warn">Работа с назначениями недоступна.</div>
<?php elseif(isset($dbError)):?><div class="page-head"><div class="page-head-left"><h1 class="page-title">Ответственные логисты</h1></div></div><div class="notice danger"><?=e($dbError)?></div>
<?php else:?>
<div class="ux-shell" data-ux-page="responsible-assignments">
 <div class="page-head"><div class="page-head-left"><h1 class="page-title">Ответственные логисты</h1><div class="page-summary"><span>Передача ответственности за исполнителей, подрядчиков, водителей и транспорт между логистами.</span></div></div></div>
 <?php if($successMessage):?><div class="notice ok mb-section"><?=e($successMessage)?></div><?php endif;?><?php if($formError):?><div class="notice warn mb-section"><?=e($formError)?></div><?php endif;?>
 <div class="ux-summary-strip"><div><strong>Важно:</strong> переназначение меняет владельца рабочей записи. Массовую операцию используйте для передачи портфеля между сотрудниками.</div><div><?=count($items??[])?> записей · <?=$unassignedCount?> без ответственного</div></div>
 <nav class="ux-segmented" aria-label="Тип данных"><?php foreach($tabs as $key=>$tab):?><a href="<?=app_url($tab['url'])?>" class="<?=$activeTab===$key?'is-active':''?>"><?=e($tab['label'])?></a><?php endforeach;?></nav>
 <?php if(empty($items)):?><div class="ux-section"><div class="ux-empty"><strong>Нет записей</strong>В разделе «<?=e($tabLabel)?>» нет активных записей для переназначения.</div></div>
 <?php else:?>
 <form id="massReassignToolbarForm" method="post" action="<?=app_url('/company/responsible-assignments/reassign')?>" onsubmit="return massReassignConfirm(this)" class="ux-action-zone">
  <?=csrfField()?>
  <input type="hidden" name="entity_type" value="<?=e($activeTab)?>">
  <div><div class="ux-action-zone-title">Массовое переназначение · <span id="selectedEntityCount">0 выбрано</span></div><div class="ux-action-zone-sub">Отметьте нужные строки ниже, выберите нового логиста и подтвердите передачу.<?php if($activeTab==='route_executor'):?> Вместе с исполнителем перейдут связанные подрядчик, все водители и ТС.<?php endif;?></div></div>
  <div class="ux-action-zone-controls"><?php if($showCascade):?><label class="checkbox-inline" title="Одновременно перенести связанные назначения исполнителей рейса"><input type="checkbox" name="cascade" value="1"> Вместе с исполнителями рейса</label><?php endif;?><select name="new_logist_id" class="field-select" style="width:220px"><option value="">Новый логист…</option><?php foreach($logists as $l):?><option value="<?=$l['membership_id']?>" data-membership-id="<?=$l['membership_id']?>"><?=e($l['full_name'])?> (<?=e($l['login'])?>)</option><?php endforeach;?></select><button type="submit" class="btn btn-primary btn-sm">Переназначить выбранные</button></div>
 </form>
 <div id="reassignFormError" class="form-alert alert-error mb-compact" style="display:none"></div>
 <div class="ux-table"><div class="ux-table-head"><div><div class="ux-table-title"><?=e($tabLabel)?></div><div class="ux-table-meta">Текущий ответственный виден сразу; индивидуальную передачу можно выполнить в строке.</div></div><div class="ux-table-meta">Показано: <?=count($items)?></div></div><div class="table-scroll"><table class="table"><thead><tr><th style="width:34px"><input type="checkbox" id="toggleAllAssignments" title="Выбрать все"></th>
 <?php if($activeTab==='route_executor'):?><th>Подрядчик</th><th>Водитель</th><th>ТС</th><?php elseif($activeTab==='contractor'):?><th>Подрядчик</th><th>ИНН</th><?php elseif($activeTab==='driver'):?><th>Водитель</th><th>Телефон</th><?php else:?><th>ТС</th><th>Госномер</th><?php endif;?>
 <th>Сейчас отвечает</th><th>Передать</th></tr></thead><tbody>
 <?php foreach($items as $item):$entityId=$item[$activeTab==='route_executor'?'crew_id':'id'];?>
 <tr><td><input type="checkbox" class="js-mass-entity-checkbox" name="entity_ids[]" value="<?=$entityId?>" form="massReassignToolbarForm"></td>
 <?php if($activeTab==='route_executor'):?><td><?=e($item['contractor_name']??'—')?></td><td><?=e($item['driver_name']??'—')?></td><td class="col-mono"><?=e($item['plates']??'—')?></td>
 <?php elseif($activeTab==='contractor'):?><td><div class="ux-primary-cell"><strong><?=e($item['name']??'—')?></strong><span>Подрядчик</span></div></td><td class="col-mono"><?=e($item['inn']??'')?:'—'?></td>
 <?php elseif($activeTab==='driver'):?><td><div class="ux-primary-cell"><strong><?=e($item['full_name']??'—')?></strong><span>Водитель</span></div></td><td class="col-mono"><?=e($item['phone']??'—')?></td>
 <?php else:?><td><?=e(ui_set_type($item['set_type']??null))?></td><td class="col-mono"><?=e($item['plate_number']??'—')?></td><?php endif;?>
 <td><?php if(!empty($item['logist_name'])):?><span class="badge badge-neutral"><span class="dot"></span><?=e($item['logist_name'])?></span><?php else:?><span class="badge badge-warning"><span class="dot"></span>Не назначен</span><?php endif;?></td>
 <td><form method="post" action="<?=app_url('/company/responsible-assignments/reassign')?>" class="inline-actions"><?=csrfField()?><input type="hidden" name="entity_type" value="<?=e($activeTab)?>"><input type="hidden" name="entity_ids[]" value="<?=$entityId?>"><?php if($showCascade):?><input type="hidden" name="cascade" value="0"><?php endif;?><select name="new_logist_id" class="field-select select-sm"><option value="">Выберите логиста…</option><?php foreach($logists as $l):$sel=((int)$l['membership_id']===(int)($item['logist_membership_id']??0))?' selected':'';?><option value="<?=$l['membership_id']?>" data-membership-id="<?=$l['membership_id']?>"<?=$sel?>><?=e($l['full_name'])?></option><?php endforeach;?></select><button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('<?=$activeTab==='route_executor'?'Передать исполнителя, подрядчика, всех водителей и ТС выбранному логисту?':'Передать ответственность выбранному логисту?'?>')">Передать</button></form></td></tr>
 <?php endforeach;?></tbody></table></div></div>
 <?php endif;?>
</div>
<script>
function showFormError(msg){var el=document.getElementById('reassignFormError');if(el){el.textContent=msg;el.style.display='block';setTimeout(function(){el.style.display='none'},5000)}}
function updateSelectedCount(){var n=document.querySelectorAll('.js-mass-entity-checkbox:checked').length,el=document.getElementById('selectedEntityCount');if(el)el.textContent=n+' '+(n===1?'выбрана':'выбрано')}
function massReassignConfirm(form){var checked=document.querySelectorAll('.js-mass-entity-checkbox:checked');if(!checked.length){showFormError('Выберите хотя бы одну запись для переназначения.');return false}var sel=form.querySelector('select[name="new_logist_id"]');if(!sel||!sel.value){showFormError('Выберите нового логиста.');return false}var executor=form.querySelector('input[name="entity_type"]').value==='route_executor';return confirm(executor?'Передать исполнителей, связанных подрядчиков, всех водителей и ТС новому логисту?':'Передать выбранные записи новому логисту?')}
document.addEventListener('DOMContentLoaded',function(){var all=document.getElementById('toggleAllAssignments'),boxes=[].slice.call(document.querySelectorAll('.js-mass-entity-checkbox'));if(all)all.addEventListener('change',function(){boxes.forEach(function(b){b.checked=all.checked});updateSelectedCount()});boxes.forEach(function(b){b.addEventListener('change',function(){if(all)all.checked=boxes.length>0&&boxes.every(function(x){return x.checked});updateSelectedCount()})});updateSelectedCount()});
</script>
<?php endif;?>
