<?php
$actions=['send'=>'Ожидает отправки','wait'=>'Ожидаем ответ','review'=>'Проверить ответ','collect'=>'Собрать недостающее','remind'=>'Связаться с перевозчиком','mail_error'=>'Проверить отправку','complete'=>'Полный пакет отправлен'];
$stages=['none'=>'Не отправлен','minimum'=>'Минимальный отправлен','full'=>'Полный отправлен'];
$mailStates=['pending'=>'Ожидает отправки','sending'=>'Отправляется','accepted'=>'Принято почтовым сервером','failed'=>'Не отправлено','unknown'=>'Результат не подтверждён','stale'=>'Нужен новый предпросмотр'];
$pageSummary=$mode==='request'
    ?'Запрос документов у перевозчика по рейсу перевозки отходов'
    :($mode==='collection'?'Контроль ответов, комплектности и отправленных пакетов':'Письма, вложения, карточки и история запроса');
?>
<link rel="stylesheet" href="<?= e(app_url('/assets/css/trip-documents.css')) ?>">
<section class="trip-documents" data-document-page data-request-base="<?= e(app_url('/company/documents/request')) ?>">
<div class="page-head"><div class="page-head-left"><h1 class="page-title"><?= e($pageTitle) ?></h1><div class="page-summary"><span><?= e($pageSummary) ?></span></div></div><div class="page-head-actions">
<?php if ($mode==='request'): ?><a class="btn btn-ghost" href="<?= e(app_url('/company/documents/collection')) ?>">Мои запросы</a>
<?php elseif ($mode==='detail'): ?><a class="btn btn-ghost" href="<?= e(app_url('/company/documents/collection')) ?>">← К запросам</a>
<?php elseif (!$assistant && \App\Security\AccessRuntime::current()->canAccessPage('company.documents.request')): ?><a class="btn btn-primary" href="<?= e(app_url('/company/documents/request')) ?>">Запросить документы</a><?php endif; ?>
</div></div>
<?php if ($error): ?><div class="notice danger" role="alert"><?= e($error) ?></div>
<?php elseif ($mode==='request'): ?>
<div class="panel"><div class="panel-body">
<form data-document-request method="post"><?= csrfField() ?>
<div class="trip-documents__fields">
<div class="field"><label class="field-label" for="dc-entity">Юридическое лицо</label><select id="dc-entity" name="legal_entity_id" class="field-input" required><option value="">Выберите</option><?php foreach ($entities as $entity): ?><option value="<?= (int)$entity['id'] ?>"><?= e($entity['name']) ?></option><?php endforeach; ?></select></div>
<div class="field trip-documents__trip-picker" data-trip-picker><label class="field-label" for="dc-trip-search">Рейс</label><input id="dc-trip-search" class="field-input" type="text" autocomplete="off" placeholder="Начните вводить номер, название, водителя или перевозчика" data-trip-search role="combobox" aria-autocomplete="list" aria-controls="dc-trip-options" aria-expanded="false" required><input id="dc-trip" name="trip" type="hidden"><div id="dc-trip-options" class="trip-documents__trip-options" data-trip-options role="listbox" hidden><?php foreach ($trips as $row): ?><button type="button" role="option" data-trip-option data-trip-value="<?= e($row['type'].':'.$row['id']) ?>"><?= e($row['label']) ?></button><?php endforeach; ?><p class="text-muted" data-trip-empty hidden>Рейсы не найдены.</p></div><span class="field-msg">Выберите рейс из отфильтрованного списка.</span></div>
</div>
<div class="field" data-contact-choice hidden><label class="field-label" for="dc-contact">Контакт перевозчика</label><select id="dc-contact" class="field-input"><option value="">Ввести контакт</option></select></div>
<div class="trip-documents__fields trip-documents__fields--three">
<div class="field"><label class="field-label" for="dc-name">Имя контакта</label><input id="dc-name" class="field-input" name="contact_name" required maxlength="255" autocomplete="name"></div>
<div class="field"><label class="field-label" for="dc-email">Email</label><input id="dc-email" class="field-input" name="contact_email" type="email" required maxlength="254" autocomplete="email"></div>
<div class="field"><label class="field-label" for="dc-phone">Телефон</label><input id="dc-phone" class="field-input" name="contact_phone" type="tel" required maxlength="64" autocomplete="tel"></div>
</div>
<label class="trip-documents__check"><input type="checkbox" name="has_promise" value="1"> Перевозчик обещал к…</label>
<div class="field" data-promise hidden><label class="field-label" for="dc-promise">Дата и время обещания</label><input id="dc-promise" class="field-input" name="promised_at" type="datetime-local"></div>
<div class="notice danger" role="alert" data-document-error hidden></div>
<div class="form-actions"><button class="btn btn-primary" type="submit">Проверить письмо</button></div>
</form>
<section class="trip-documents__preview" data-document-preview hidden aria-label="Предпросмотр письма">
<h2 class="panel-head-title">Письмо перевозчику</h2><dl class="trip-documents__envelope" data-envelope></dl>
<pre class="trip-documents__letter" data-letter></pre>
<div class="form-actions"><button type="button" class="btn btn-primary" data-document-send>Отправить запрос</button><button type="button" class="btn btn-ghost" data-document-edit>Изменить</button></div>
</section></div></div>
<?php elseif ($mode==='collection'): ?>
<div class="table-card table-card--toolbar-only"><div class="table-toolbar"><div class="found-label">Найдено: <b><?= count($rows) ?></b> запросов</div></div><div class="table-scroll"><table class="table"><thead><tr><th>Рейс</th><th>Ответственный Л</th><th>Пакет</th><th>Следующее действие</th><th>Проверка</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><a href="<?= e(app_url('/company/documents/collection/'.$row['id'])) ?>"><?= e($row['trip_label']) ?></a></td><td><?= e($row['responsible_name']) ?></td><td><?= e($stages[$row['package_stage']]??'') ?></td><td><?= e($actions[$row['next_action']]??'') ?></td><td><?= e($row['next_check_at']?date('d.m H:i',strtotime($row['next_check_at'])):'—') ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="text-muted">Запросов документов пока нет.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php else: ?>
<div class="panel"><div class="panel-body">
<div class="trip-documents__summary"><strong><?= e($trip['label']) ?></strong><span><?= e($trip['responsible']['full_name']) ?></span><span><?= e($trip['loading_dates']?:'Даты загрузки не указаны') ?></span></div>
<p><strong><?= e($actions[$request['next_action']]??'') ?></strong> · <?= e($stages[$request['package_stage']]??'') ?></p>
<p class="text-muted"><?= $request['promised_at']?'Обещано: '.e(date('d.m.Y H:i',strtotime($request['promised_at']))):'Срок не назван — ожидаем как можно быстрее' ?><?= $request['next_check_at']?' · Следующая проверка: '.e(date('d.m.Y H:i',strtotime($request['next_check_at']))):'' ?></p>
<div class="trip-documents__summary"><span>Контакт: <?= e($request['contact_name']) ?></span><a href="mailto:<?= e($request['contact_email']) ?>"><?= e($request['contact_email']) ?></a><span><?= e($request['contact_phone']) ?></span></div>
<?php foreach ($mailboxes as $box): if ($box['error_message']): ?><div class="notice warn"><?= e($box['email'].': '.$box['error_message']) ?></div><?php endif; endforeach; ?>
</div></div>
<div class="panel"><div class="panel-body"><h2 class="panel-head-title">Комплектность</h2>
<?php if($request['package_stage']==='full'): ?><p>Полные данные отправлены. Состав отправки сохранён в истории.</p>
<?php else: $missing=$completeness['minimum_missing']?:$completeness['full_missing']; ?>
<p><strong><?= !$completeness['full_missing']?'Полные данные готовы':(!$completeness['minimum_missing']?'Минимальный пакет готов':'Нужно дополнить карточки') ?></strong></p>
<?php if($missing): ?><ul><?php $missingGroups=[];foreach($missing as $item)$missingGroups[$item['group']][]=$item['label'];foreach($missingGroups as $group=>$labels): ?><li><?= e($group) ?>: <?= e(implode(', ',$labels)) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if($assistant && !$completeness['minimum_missing'] && ($request['package_stage']==='none' || !$completeness['full_missing'])): ?>
<h3 class="panel-head-title"><?= !$completeness['full_missing']?($request['package_stage']==='minimum'?'Дополнение до полного пакета':'Отправить полные данные'):'Отправить минимальный пакет' ?></h3>
<?php $composeKind=!$completeness['full_missing']?'full':'minimum';require base_path('app/View/partials/trip_document_compose.php');endif; ?>
<?php endif; ?>
<details><summary>Состав и документы ПР</summary><ul><?php foreach($completeness['rows'] as $item): ?><li><?= e($item['group'].' · '.$item['label']) ?> — <?= $item['present']?'есть':($item['stage']==='pr'?'можно предоставить после рейса':'не заполнено') ?><?= $item['stage']==='pr'?' · ПР':'' ?></li><?php endforeach; ?></ul></details>
</div></div>
<div class="panel"><div class="panel-body"><h2 class="panel-head-title">Карточки и документы</h2>
<div class="form-actions">
<?php if ($cards['contractor']): ?><a class="btn btn-ghost" data-document-editor="contractor" data-entity-id="<?= (int)$cards['contractor']['id'] ?>" href="<?= e(app_url('/company/contractors/'.$cards['contractor']['id'].'/edit?document_request='.$request['id'])) ?>">Подрядчик</a><?php endif; ?>
<?php if($assistant && $cards['contractor']): ?><button type="button" class="btn btn-ghost" data-document-tax-open>Налогообложение</button><?php endif; ?>
<?php foreach ($cards['drivers'] as $driver): ?><a class="btn btn-ghost" data-document-editor="driver" data-entity-id="<?= (int)$driver['id'] ?>" href="<?= e(app_url('/company/drivers/'.$driver['id'].'/edit?document_request='.$request['id'])) ?>"><?= e($driver['full_name']) ?></a><?php endforeach; ?>
<?php if ($cards['set']): ?><a class="btn btn-ghost" data-document-editor="vehicleSet" data-entity-id="<?= (int)$cards['set']['id'] ?>" href="<?= e(app_url('/company/vehicle-sets/'.$cards['set']['id'].'/edit?document_request='.$request['id'])) ?>">ТС</a><?php endif; ?>
<?php if ($cards['crew']): ?><button type="button" class="btn btn-ghost" data-route-executor-edit-link="<?= (int)$cards['crew']['id'] ?>">Исполнитель рейса</button><?php else: ?><span class="text-muted">Исполнитель ещё не указан.</span><?php endif; ?>
</div>
<?php if ($assistant): ?><details><summary>Выбрать или создать исполнителя</summary>
<form method="post" action="<?= e(app_url('/company/documents/collection/'.$request['id'].'/executor')) ?>" data-document-action><?= csrfField() ?><input type="hidden" name="version" value="<?= (int)$request['version'] ?>">
<div class="field"><label class="field-label" for="dc-executor">Исполнитель рейса</label><select class="field-input" id="dc-executor" name="crew_id" required><option value="">Выберите существующего</option><?php foreach ($executors as $executor): ?><option value="<?= (int)$executor['id'] ?>" <?= (int)$executor['id']===(int)($cards['crew']['id']??0)?'selected':'' ?>><?= e($executor['contractor_name'].' · '.$executor['full_name'].' · '.$executor['plate_number']) ?></option><?php endforeach; ?></select></div>
<div class="notice danger" role="alert" data-action-error hidden></div><div class="form-actions"><button class="btn btn-primary" type="submit">Связать с рейсом</button><button type="button" class="btn btn-ghost" data-route-executor-create-btn>Создать исполнителя</button></div>
</form><p class="text-muted">Недостающие карточки можно создать в справочниках:</p><div class="form-actions"><?php foreach (['contractors'=>'Подрядчики','drivers'=>'Водители','vehicle-sets'=>'ТС'] as $path=>$label): ?><a class="btn btn-ghost" href="<?= e(app_url('/company/'.$path.'?document_request='.$request['id'])) ?>"><?= e($label) ?></a><?php endforeach; ?></div></details><?php endif; ?>
<p class="text-muted">Загрузка файлов не отправляет пакет. Отметка «ПР» не препятствует готовности.</p></div></div>
<?php if ($assistant && $request['package_stage']!=='full'): ?>
<details class="panel"><summary class="panel-head">Запросить недостающее у перевозчика</summary><div class="panel-body">
<?php $composeKind='reminder';require base_path('app/View/partials/trip_document_compose.php'); ?>
</div></details>
<details class="panel"><summary class="panel-head">Ответ проверен — назначить следующую проверку</summary><div class="panel-body">
<form method="post" action="<?= e(app_url('/company/documents/collection/'.$request['id'].'/review')) ?>" data-document-action><?= csrfField() ?>
<div class="field"><label class="field-label" for="dc-next-check">Когда проверить недостающее</label><input class="field-input" id="dc-next-check" type="datetime-local" name="next_check_at" required></div>
<div class="notice danger" role="alert" data-action-error hidden></div><button class="btn btn-primary" type="submit">Сохранить</button></form></div></details>
<?php elseif ($assistant && $request['next_action']==='review'): ?>
<form class="panel panel-body" method="post" action="<?= e(app_url('/company/documents/collection/'.$request['id'].'/review')) ?>" data-document-action><?= csrfField() ?><div class="notice danger" role="alert" data-action-error hidden></div><button class="btn btn-primary" type="submit">Ответ после отправки пакета проверен</button></form>
<?php endif; ?>
<details class="panel" open><summary class="panel-head">Переписка и вложения <span class="text-muted"><?= count($incoming)+count($outgoing) ?></span></summary><div class="panel-body">
<?php foreach ($ambiguous as $mail): ?><article class="trip-documents__message"><p><strong>Уточните рейс для письма</strong> · <?= e($mail['received_at']) ?></p><p><?= e($mail['sender'].' · '.$mail['subject']) ?></p>
<form method="post" action="<?= e(app_url('/company/documents/collection/'.$request['id'].'/bind')) ?>" data-document-action><?= csrfField() ?><input type="hidden" name="incoming_id" value="<?= (int)$mail['id'] ?>"><div class="notice danger" role="alert" data-action-error hidden></div><button class="btn btn-primary" type="submit">Привязать к этому запросу</button></form></article><?php endforeach; ?>
<?php if (!$incoming && !$outgoing): ?><p class="text-muted">Писем пока нет.</p><?php endif; ?>
<?php foreach ($incoming as $mail): ?><article class="trip-documents__message"><strong>Получен ответ</strong> · <?= e($mail['received_at']) ?><p><?= e($mail['sender']) ?> · <?= e($mail['subject']) ?></p><pre class="trip-documents__letter"><?= e($mail['body_text']) ?></pre>
<?php foreach (json_decode($mail['attachments_json'],true) as $index=>$file): ?>
<div class="trip-documents__attachment">
<?php if (isset($file['error'])): ?><p><?= e($file['name'].' — '.$file['error']) ?></p>
<?php else: ?><a href="<?= e(app_url('/company/documents/collection/'.$request['id'].'/incoming/'.$mail['id'].'/files/'.$index)) ?>"><?= e($file['name']) ?></a>
<?php if (!empty($file['document_id'])): ?><span class="text-muted">Сохранён в карточку</span>
<?php elseif ($assistant): ?>
<details><summary>Сохранить в карточку</summary><form method="post" action="<?= e(app_url('/company/documents/collection/'.$request['id'].'/import')) ?>" data-document-action><?= csrfField() ?>
<input type="hidden" name="incoming_id" value="<?= (int)$mail['id'] ?>"><input type="hidden" name="index" value="<?= (int)$index ?>">
<div class="trip-documents__fields"><div class="field"><label class="field-label">Карточка<select name="target" class="field-input" required><option value="">Выберите</option>
<?php if ($cards['contractor']): ?><option value="contractor:<?= (int)$cards['contractor']['id'] ?>">Подрядчик · <?= e($cards['contractor']['name']) ?></option><?php endif; ?>
<?php foreach ($cards['drivers'] as $driver): ?><option value="driver:<?= (int)$driver['id'] ?>">Водитель · <?= e($driver['full_name']) ?></option><?php endforeach; ?>
<?php foreach ($cards['vehicles'] as $unit): ?><option value="vehicle_unit:<?= (int)$unit['id'] ?>"><?= e($unit['part'].' · '.$unit['plate_number']) ?></option><?php endforeach; ?>
</select></label></div><div class="field"><label class="field-label">Тип документа<select name="type_code" class="field-input" required><option value="">Выберите</option>
<?php foreach (['passport'=>'Паспорт','driver_license'=>'Водительское удостоверение','sts'=>'СТС','photo'=>'Фотография','company_card'=>'Карточка предприятия · ПР','inn_cert'=>'Свидетельство ИНН · ПР','ogrn_cert'=>'Свидетельство ОГРН · ПР','contract'=>'Договор · ПР','snils'=>'СНИЛС · ПР','diagnostic_card'=>'Диагностическая карта · ПР'] as $code=>$label): ?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label></div></div>
<div class="notice danger" role="alert" data-action-error hidden></div><button class="btn btn-primary" type="submit">Сохранить файл</button></form></details>
<?php endif; endif; ?></div><?php endforeach; ?></article><?php endforeach; ?>
<?php foreach ($outgoing as $mail): $snapshot=json_decode($mail['snapshot_json'],true); ?><details class="trip-documents__message"><summary><?= e($mailStates[$mail['status']]??$mail['status']) ?> · <?= e($mail['created_at']) ?></summary><p><?= e($snapshot['message']['from']) ?> → <?= e(implode(', ',$snapshot['message']['to'])) ?></p><p>Копия: <?= e(implode(', ',$snapshot['message']['cc'])) ?></p><p><?= e($snapshot['message']['subject']) ?></p><pre class="trip-documents__letter"><?= e($snapshot['message']['text']) ?></pre><?php if ($mail['error_message']): ?><div class="notice warn"><?= e($mail['error_message']) ?></div><?php endif; ?>
<?php if ($assistant && in_array($mail['status'],['failed','stale'],true)): $composeKind='retry';$composeMailId=$mail['id'];require base_path('app/View/partials/trip_document_compose.php');endif; ?>
<?php if($assistant && $mail['status']==='unknown'): ?><p class="text-muted">Идентификатор для проверки у почтового администратора: <?= e($mail['message_id']) ?></p><button type="button" class="btn btn-ghost" data-document-resolve="<?= (int)$mail['id'] ?>">Зафиксировать проверенный результат</button><?php endif; ?>
<?php if($snapshot['message']['attachments']): ?><ul><?php foreach($snapshot['message']['attachments'] as $fileIndex=>$file): ?><li><a href="<?= e(app_url('/company/documents/collection/'.$request['id'].'/outgoing/'.$mail['id'].'/files/'.$fileIndex)) ?>"><?= e($file['name']) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
</details><?php endforeach; ?>
</div></details>
<details class="panel"><summary class="panel-head">История</summary><div class="panel-body"><ul><?php foreach ($history as $event): ?><li><?= e($event['created_at']) ?> — <?= e($event['description']) ?><?php if ($event['max_error']): ?><span class="text-muted"> · <?= e($event['max_error']) ?></span><?php endif; ?></li><?php endforeach; ?></ul></div></details>
<?php endif; ?>
<?php if(!$error && $mode==='detail' && $assistant && $cards['contractor']): ?>
<div class="modal-overlay" id="document-tax-modal" role="dialog" aria-modal="true" aria-label="Налогообложение подрядчика" hidden>
<div class="modal"><div class="modal-head"><span class="modal-title">Налогообложение подрядчика</span><button type="button" class="modal-close" data-document-tax-close>×</button></div>
<form method="post" action="<?= e(app_url('/company/documents/collection/'.$request['id'].'/tax')) ?>" data-document-action><?= csrfField() ?><input type="hidden" name="version" value="<?= (int)$request['version'] ?>">
<div class="modal-body"><p class="text-muted">Новая запись в существующей истории подрядчика.</p><div class="field"><label class="field-label">Система налогообложения<input class="field-input" name="tax_system" maxlength="100" required value="<?= e($cards['contractor']['tax_system']??'') ?>"></label></div><div class="field"><label class="field-label">НДС<input class="field-input" name="vat_mode" maxlength="100" required value="<?= e($cards['contractor']['vat_mode']??'') ?>"></label></div><div class="field"><label class="field-label">Дата начала действия<input class="field-input" type="date" name="effective_from" required value="<?= e(date('Y-m-d')) ?>"></label></div><div class="notice danger" role="alert" data-action-error hidden></div></div>
<div class="modal-foot"><button type="button" class="btn btn-ghost" data-document-tax-close>Отмена</button><button type="submit" class="btn btn-primary">Сохранить</button></div></form></div></div>
<?php endif; ?>
<?php if(!$error && $mode==='detail' && $assistant): ?>
<div class="modal-overlay" id="document-resolve-modal" role="dialog" aria-modal="true" aria-label="Проверка отправки" hidden><div class="modal"><div class="modal-head"><span class="modal-title">Проверка отправки</span><button type="button" class="modal-close" data-document-resolve-close>×</button></div>
<form method="post" action="<?= e(app_url('/company/documents/collection/'.$request['id'].'/resolve')) ?>" data-document-action><?= csrfField() ?><input type="hidden" name="mail_id"><div class="modal-body"><p>Сначала подтвердите результат по журналу почтового сервера или у получателей. Отсутствие ответа не означает, что письмо не отправлено.</p><div class="field"><label class="field-label">Проверенный результат<select class="field-input" name="outcome" required><option value="">Выберите</option><option value="accepted">Письмо было отправлено</option><option value="failed">Подтверждено, что письмо не отправлено</option></select></label></div><div class="field"><label class="field-label">Основание проверки<textarea class="field-input" name="note" minlength="10" maxlength="500" required></textarea></label></div><div class="notice danger" role="alert" data-action-error hidden></div></div><div class="modal-foot"><button type="button" class="btn btn-ghost" data-document-resolve-close>Отмена</button><button type="submit" class="btn btn-primary">Зафиксировать</button></div></form></div></div>
<?php endif; ?>
</section>
<?php if (!$error && $mode==='detail' && $assistant) require base_path('app/View/partials/route_executor_modals.php'); ?>
<script src="<?= e(app_url('/assets/js/trip-documents.js')) ?>" defer></script>
