<?php
$kindLabels=['initial'=>'Начальное состояние','time'=>'Время','marks'=>'Отметки','scan'=>'Сканы','comment'=>'Комментарий','review'=>'Проверка рейса'];
$fieldLabels=[
    'arrived_at'=>'Прибытие','departed_at'=>'Убытие',
    'sender_signature_status'=>'Подпись ОО','driver_signature_status'=>'Подпись водителя',
    'tn_datetime_status'=>'Дата и время указаны','recovery_source'=>'Данные восстановлены',
    'tn_absent'=>'Нет ТН','comment'=>'Комментарий','file'=>'Скан ТН','download_name'=>'Имя файла','active'=>'В комплекте',
    'sender_signature_present'=>'Подпись ОО — наличие','driver_signature_present'=>'Подпись водителя — наличие',
    'tn_datetime_missing'=>'Время отсутствует','data_from_tracker'=>'Восстановлено по трекеру',
    'data_from_driver'=>'Со слов вод./мен','data_invented'=>'Прежняя отметка «Придуманы»','review_status'=>'Статус проверки',
];
$valueText=static function(string $field,mixed $value): string {
    if ($value===null || $value==='') return 'Не указано';
    if (in_array($field,['sender_signature_status','driver_signature_status'],true)) return ['yes'=>'Есть','no'=>'Нет'][$value]??(string)$value;
    if ($field==='tn_datetime_status') return ['full'=>'Полностью','partial'=>'Частично','no'=>'Нет'][$value]??(string)$value;
    if ($field==='recovery_source') return ['tracker'=>'По трекеру','driver'=>'Со слов вод./мен','invented'=>'Со слов вод./мен'][$value]??(string)$value;
    if ($field==='review_status') return ['work'=>'В работе','review'=>'На проверке','archive'=>'Проверено / архив'][$value]??(string)$value;
    if (in_array($field,['tn_absent','active','sender_signature_present','driver_signature_present','tn_datetime_missing','data_from_tracker','data_from_driver','data_invented'],true)) return (int)$value===1?'Да':'Нет';
    return (string)$value;
};
$filterString=static fn(string $key): string=>is_scalar($filters[$key]??null)?(string)$filters[$key]:'';
$pageUrl=static function(int $page) use ($filters): string {
    $query=[];
    foreach (['from','to','user','kind','q'] as $key) if (is_scalar($filters[$key]??null) && trim((string)$filters[$key])!=='') $query[$key]=(string)$filters[$key];
    $query['page']=$page;
    return app_url('/company/registration-journal?'.http_build_query($query));
};
?>
<section class="registration-journal">
  <header class="registration-journal-heading"><div><p class="registration-journal-eyebrow">ЖУРНАЛЫ / РЕГИСТРАЦИЯ ЗАЯВОК</p><h1>Журнал регистрации заявок</h1><p>История работы с датами, отметками, сканами и комментариями по заявкам рейсов.</p></div><span class="registration-journal-readonly">Только просмотр</span></header>
  <?php if ($error): ?><div class="form-alert alert-error" role="alert"><div class="alert-body"><?= e($error) ?></div></div><?php else: ?>
  <form class="registration-journal-filter" role="search" method="get" action="<?= e(app_url('/company/registration-journal')) ?>" data-journal-filter>
    <label>С даты<input type="date" name="from" value="<?= e($filterString('from')) ?>"></label>
    <label>По дату<input type="date" name="to" value="<?= e($filterString('to')) ?>"></label>
    <label>Пользователь<select name="user"><option value="">Все пользователи</option><?php foreach ($journal['users'] as $user): ?><option value="<?= e($user) ?>"<?= $filterString('user')===$user?' selected':'' ?>><?= e($user) ?></option><?php endforeach; ?></select></label>
    <label>Действие<select name="kind"><option value="">Все действия</option><?php foreach ($kindLabels as $kind=>$label): ?><option value="<?= e($kind) ?>"<?= $filterString('kind')===$kind?' selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label class="registration-journal-search">Рейс или заявка<input type="search" name="q" value="<?= e($filterString('q')) ?>" placeholder="Например, 284 или 306250"></label>
  </form>
  <div class="registration-journal-results"><strong>Найдено: <?= (int)$journal['total'] ?></strong><span>Сначала новые</span></div>
  <div class="registration-journal-list"><table class="registration-journal-table"><thead><tr><th scope="col">ДАТА И ВРЕМЯ ДЕЙСТВИЯ</th><th scope="col">ПОЛЬЗОВАТЕЛЬ</th><th scope="col">РЕЙС / ЗАЯВКА</th><th scope="col">ВИД</th><th scope="col">ДЕЙСТВИЕ</th><th scope="col">ИЗМЕНЕНИЯ · БЫЛО → СТАЛО</th></tr></thead><tbody>
  <?php foreach ($journal['rows'] as $event): ?>
    <tr class="registration-journal-event"><td data-label="Дата и время действия"><time datetime="<?= e((string)$event['occurred_at']) ?>"><?= e(date('d.m.Y H:i',strtotime((string)$event['occurred_at']))) ?></time></td><td data-label="Пользователь"><b><?= e((string)$event['actor_login']) ?></b></td><td data-label="Рейс / заявка"><b>Рейс #<?= e((string)$event['trip_number']) ?></b><small><?= $event['request_number']===null?'Весь рейс':'Заявка '.e((string)$event['request_number']) ?></small></td><td data-label="Вид"><span class="registration-journal-kind"><?= e($kindLabels[$event['event_kind']]??(string)$event['event_kind']) ?></span></td><td data-label="Действие"><?= e((string)$event['action_text']) ?></td><td data-label="Изменения" class="registration-journal-changes">
      <?php foreach (array_unique(array_merge(array_keys($event['before']),array_keys($event['after']))) as $field): ?>
      <div class="registration-journal-delta"><b><?= e($fieldLabels[$field]??$field) ?></b><span><?= e(array_key_exists($field,$event['before'])?$valueText($field,$event['before'][$field]):'История неизвестна') ?></span><em aria-label="стало">→</em><strong><?= e(array_key_exists($field,$event['after'])?$valueText($field,$event['after'][$field]):'Удалено') ?></strong></div>
      <?php endforeach; ?>
    </td></tr>
  <?php endforeach; ?>
  </tbody></table><?php if ($journal['rows']===[]): ?><div class="registration-journal-empty">По заданным условиям записей нет.</div><?php endif; ?></div>
  <div class="registration-journal-foot"><span>Показано <?= count($journal['rows']) ?> из <?= (int)$journal['total'] ?> событий</span><?php if ($journal['pages']>1): ?><nav aria-label="Страницы журнала"><?php if ($journal['page']>1): ?><a href="<?= e($pageUrl($journal['page']-1)) ?>">← Назад</a><?php endif; ?><span>Страница <?= (int)$journal['page'] ?> из <?= (int)$journal['pages'] ?></span><?php if ($journal['page']<$journal['pages']): ?><a href="<?= e($pageUrl($journal['page']+1)) ?>">Вперёд →</a><?php endif; ?></nav><?php endif; ?></div>
  <?php endif; ?>
</section>
<link rel="stylesheet" href="<?= app_url('/assets/css/registration-journal.css') ?>">
<script src="<?= app_url('/assets/js/registration-journal.js') ?>" defer></script>
