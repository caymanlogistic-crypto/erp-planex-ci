<?php
$recipientPrefix=(string)$recipientCard['prefix'];
$recipientState=$recipientCard['state'];
$recipientError=$recipientCard['error'];
?>
<section class="panel-section service-key-card report-email-card" aria-labelledby="<?= e($recipientPrefix) ?>-title" data-recipient-settings data-recipient-kind="<?= e($recipientCard['kind']) ?>"<?= $recipientCard['kind']==='report'?' data-report-email':'' ?> data-state="<?= e(json_encode($recipientState,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)) ?>" data-suggest-url="<?= e($recipientCard['suggest_url']) ?>">
  <header class="service-key-head"><div><h2 class="section-title" id="<?= e($recipientPrefix) ?>-title"><?= e($recipientCard['title']) ?></h2><p class="service-key-caption"><?= e($recipientCard['caption']) ?></p></div><span class="badge report-email-badge">Настройка</span></header>
  <div class="service-key-body">
    <?php if ($recipientError): ?><div class="notice warn" role="alert"><?= e($recipientError) ?></div><?php else: ?>
    <div class="report-email-form"><div class="field report-email-person"><label class="field-label" for="<?= e($recipientPrefix) ?>-person">Сотрудник или email</label><input class="field-input" id="<?= e($recipientPrefix) ?>-person" data-recipient-person type="search" role="combobox" aria-autocomplete="list" aria-controls="<?= e($recipientPrefix) ?>-options" aria-expanded="false" autocomplete="off" placeholder="Начните вводить фамилию"><div class="report-email-options" id="<?= e($recipientPrefix) ?>-options" role="listbox" hidden></div></div><div class="field"><label class="field-label" for="<?= e($recipientPrefix) ?>-address">Email получателя</label><input class="field-input" id="<?= e($recipientPrefix) ?>-address" data-recipient-address type="email" autocomplete="off" placeholder="name@company.ru"></div><button type="button" class="btn btn-secondary report-email-add">+ Email</button></div>
    <div class="report-email-feedback" role="status" aria-live="polite" hidden></div>
    <div class="report-email-recipients"><div class="report-email-list-title"><?= e($recipientCard['list_title']) ?> <span class="report-email-count"></span></div><ul class="report-email-list" aria-label="<?= e($recipientCard['list_title']) ?>"></ul><div class="report-email-empty" hidden>Получатели пока не добавлены.</div></div>
    <div class="report-email-footer"><form class="report-email-actions" action="<?= e($recipientCard['save_url']) ?>" method="post" data-recipient-form<?= $recipientCard['kind']==='report'?' data-report-email-form':'' ?>><?= csrfField() ?><input type="hidden" name="recipients"><input type="hidden" name="send_responsible_logist"><label class="report-email-logist"><input type="checkbox" id="<?= e($recipientPrefix) ?>-logist" data-recipient-logist><span><b>Отправлять ответственному логисту</b><small><?= e($recipientCard['logist_hint']) ?></small></span></label><button type="submit" class="btn btn-primary">Сохранить получателей</button><span class="report-email-save-state" role="status" aria-live="polite">Настройки не менялись</span></form></div>
    <?php endif; ?>
  </div>
</section>
