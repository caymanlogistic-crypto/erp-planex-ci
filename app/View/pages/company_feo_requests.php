<?php
$roleCode = (string)($_SESSION['role_code'] ?? '');
$canManageHistory = in_array($roleCode, ['company_owner','senior_logist'], true);
$fmtDateTime = static function ($value): string {
    if (!$value) return '—';
    $ts = strtotime((string)$value);
    return $ts === false ? e((string)$value) : date('d.m.Y H:i', $ts);
};
?>
<style>
.feo-table-card{display:block;min-width:0}
.feo-result-toolbar{min-height:36px;height:auto;padding:4px 8px;flex-wrap:wrap;gap:6px 12px}
.feo-result-summary{display:flex;align-items:center;min-width:240px;flex:1 1 260px}
.feo-result-summary .found-label{white-space:nowrap}
.feo-result-toolbar .toolbar-right{min-width:0;max-width:100%;overflow:visible;flex:0 0 auto}
.feo-search-toolbar{display:flex;align-items:center;gap:8px;min-width:0;flex-wrap:nowrap}
.feo-result-toolbar .toolbar-right .feo-search-input--ids{width:660px;min-width:660px;max-width:660px;flex:0 0 660px}
.feo-result-toolbar .toolbar-right .feo-search-input--content{width:200px;min-width:200px;max-width:200px;flex:0 0 200px}
.feo-search-message{margin:0;border-left:0;border-right:0;border-top:0;border-radius:0}
.feo-table-scroll{overflow-y:auto!important;overflow-x:hidden!important;min-width:0;max-width:100%;min-height:360px;scrollbar-gutter:stable}
.feo-table{width:100%;max-width:100%;min-width:0!important;table-layout:fixed}
.feo-table col:nth-child(1){width:8%}.feo-table col:nth-child(2){width:8%}.feo-table col:nth-child(3){width:14%}.feo-table col:nth-child(4){width:14%}.feo-table col:nth-child(5){width:24%}.feo-table col:nth-child(6){width:32%}
.feo-table th,.feo-table td{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.feo-table tbody tr[data-feo-id]{cursor:pointer}
.feo-table tbody tr[data-feo-id]:hover{background:var(--accent-bg)}
.feo-load-sentinel{display:flex;align-items:center;justify-content:center;min-height:34px;padding:7px;color:var(--text-muted);font-size:11px;border-top:1px solid var(--line-hair)}
.feo-load-sentinel[hidden]{display:none}
.feo-freshness{margin-bottom:8px}
.feo-history-modal{width:1066px;max-width:calc(100vw - 32px)}
.feo-history-modal .modal-body{padding:0!important;overflow:hidden}
.feo-history-scroll{max-height:56vh;overflow-y:auto;overflow-x:hidden}
.feo-history-table{width:100%;table-layout:fixed;margin:0}
.feo-history-table th,.feo-history-table td{white-space:nowrap;overflow-wrap:normal;overflow:hidden;text-overflow:ellipsis;vertical-align:middle}
.feo-history-table th:nth-child(1){width:14%}.feo-history-table th:nth-child(2){width:25%}.feo-history-table th:nth-child(3){width:8%}.feo-history-table th:nth-child(4){width:8%}.feo-history-table th:nth-child(5){width:9%}.feo-history-table th:nth-child(6){width:8%}.feo-history-table th:nth-child(7){width:10%}.feo-history-table th:nth-child(8){width:18%}
.feo-history-actions{display:flex;gap:4px;flex-wrap:nowrap;justify-content:flex-end}
.feo-history-note{padding:8px 10px;border-top:1px solid var(--line-soft);background:var(--surface-form);font-size:11px;color:var(--text-muted)}
.feo-import-modal{width:820px;max-width:calc(100vw - 32px)}
.feo-import-modal .modal-body{padding:18px 20px 16px}
.feo-import-form{display:grid;gap:12px}
.feo-coordinate-format{display:flex;flex-wrap:wrap;gap:12px 24px;margin:0;padding:10px 12px;border:1px solid var(--border-color,#d8d4ca);border-radius:6px}
.feo-coordinate-format label{display:flex;align-items:center;gap:6px;font-family:inherit;font-size:12px;font-weight:400;line-height:1.2;color:var(--text-main,#131210);cursor:pointer}
.feo-coordinate-format input[type="radio"]{appearance:auto;width:14px;height:14px;min-width:14px;min-height:14px;margin:0;padding:0;flex:0 0 14px;accent-color:var(--accent,#7c4718)}
.feo-import-file{margin:0}.feo-import-file .field-label{margin-bottom:6px}.feo-import-file .field-input{width:100%;margin:0}
.feo-import-help{margin-top:5px;color:var(--text-muted);font-size:11px;line-height:1.4}
.feo-overwrite-option{display:grid;grid-template-columns:20px minmax(0,1fr);align-items:start;gap:10px;margin:0;padding:11px 12px;border:1px solid var(--line-soft);background:var(--surface-form);font-size:12px;line-height:1.35;cursor:pointer}
.feo-overwrite-option input{width:18px;height:18px;margin:1px 0 0;justify-self:start}
.feo-overwrite-option b{display:block}.feo-overwrite-option small{display:block;color:var(--text-muted);margin-top:3px;line-height:1.35}
.feo-import-state{margin:0;padding:9px 11px;border:1px solid var(--line-soft);background:var(--surface-form);color:var(--text-muted);font-size:11px;line-height:1.35}
.feo-import-state.is-checking{font-weight:700;color:var(--text)}
.feo-import-state.is-ok{border-color:var(--success-line,var(--line-soft));color:var(--success-text,var(--text))}
.feo-import-state.is-error{border-color:var(--warning-line,var(--line-soft));color:var(--warning-text,var(--text))}
.feo-import-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px}
.feo-stat{border:1px solid var(--line-soft);background:var(--surface-form);padding:10px 11px;min-width:0}.feo-stat b{display:block;font-size:18px;line-height:1.15}.feo-stat span{display:block;margin-top:2px;font-size:11px;color:var(--text-muted);white-space:normal}
.feo-import-error{margin-top:10px}.feo-duplicate{margin-top:10px}
.feo-detail-modal .modal-body{padding:0!important;overflow:auto;max-height:68vh}
.feo-detail-form{margin:0}.feo-detail-grid{display:grid;grid-template-columns:minmax(260px,38%) 1fr;border:0}
.feo-detail-label,.feo-detail-value{padding:7px 9px;border-bottom:1px solid var(--line-hair)}
.feo-detail-label{background:var(--surface-form);font-size:11px;font-weight:700}.feo-detail-value{font-size:12px;overflow-wrap:anywhere;word-break:break-word}
.feo-detail-input{width:100%;min-width:0;margin:0}.feo-detail-grid>:nth-last-child(-n+2){border-bottom:0}
.feo-detail-save-error{margin:8px}.feo-detail-save-success{margin:8px}
.feo-confirm-copy{display:grid;gap:8px}.feo-confirm-warning{padding:9px 10px;border:1px solid var(--line-soft);background:var(--surface-form);font-size:11px;color:var(--text-muted)}
@media(max-width:1180px){.feo-result-toolbar{flex-wrap:wrap}.feo-result-summary{flex:1 1 100%}.feo-result-toolbar .toolbar-right{flex:1 1 100%}.feo-search-toolbar{flex-wrap:wrap}.feo-result-toolbar .toolbar-right .feo-search-input--ids{width:min(660px,86vw);min-width:280px;max-width:86vw;flex:1 1 min(660px,86vw)}.feo-result-toolbar .toolbar-right .feo-search-input--content{width:250px;min-width:200px;max-width:100%;flex:1 1 250px}.feo-import-stats{grid-template-columns:repeat(3,minmax(0,1fr))}.feo-table col:nth-child(3){width:17%}.feo-table col:nth-child(4){width:0}.feo-table th:nth-child(4),.feo-table td:nth-child(4){display:none}.feo-table col:nth-child(5){width:27%}.feo-table col:nth-child(6){width:40%}}
@media(max-width:640px){.feo-import-modal{max-width:calc(100vw - 16px)}.feo-import-modal .modal-body{padding:14px}.feo-import-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<?php if (!empty($successFlash)): ?><div class="notice success feo-freshness"><?= e((string)$successFlash) ?></div><?php endif; ?>
<?php if (!empty($errorFlash)): ?><div class="notice warn feo-freshness"><?= e((string)$errorFlash) ?></div><?php endif; ?>
<?php if (!empty($dbError)): ?><div class="notice warn feo-freshness"><?= e((string)$dbError) ?></div><?php endif; ?>

<div class="page-head feo-page-head">
    <div class="page-head-left">
        <h1 class="page-title">Все заявки ФЭО</h1>
        <div class="page-summary"><span>Реестр заявок ФЭО текущей компании. Двойной клик по строке открывает все поля заявки.</span></div>
    </div>
    <div class="page-head-actions">
        <button type="button" class="btn btn-secondary" data-open-feo-history>История загрузок</button>
        <button type="button" class="btn btn-primary" data-open-feo-import>Загрузить XLSX</button>
    </div>
</div>

<?php if (empty($dbError) && !empty($freshness['is_problem'])): ?>
<div class="notice warn feo-freshness" data-feo-freshness-warning>
    <b>Требуется актуальная загрузка ФЭО.</b>
    <?php if (empty($freshness['has_active_import'])): ?>
        Актуальный файл заявок ещё не загружен. Необходимо загрузить XLSX.
    <?php else: ?>
        Последняя активная загрузка была более 7 дней назад: <?= e($fmtDateTime($freshness['latest_at'] ?? null)) ?> · <?= e((string)($freshness['latest_filename'] ?? '')) ?>. Необходимо загрузить актуальный файл.
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($dbError)): ?>
<div class="table-card table-card--standard feo-table-card" data-feo-grid>
    <div class="table-toolbar feo-result-toolbar">
        <div class="feo-result-summary">
            <div class="found-label">Найдено: <b data-feo-total>0</b> заявок общим весом <b data-feo-total-mass>0</b> кг.</div>
        </div>
        <div class="toolbar-right feo-search-toolbar">
            <input type="text" class="toolbar-search feo-search-input feo-search-input--ids" data-feo-id-filter placeholder="Вставьте текст с 6-значными ID заявок" autocomplete="off">
            <input type="text" class="toolbar-search feo-search-input feo-search-input--content" data-feo-content-filter placeholder="Поиск по таблице" autocomplete="off">
        </div>
    </div>
    <div class="notice warn feo-search-message is-hidden" data-feo-search-message></div>
    <div class="feo-table-scroll" data-feo-scroll>
        <table class="table feo-table">
            <colgroup><col><col><col><col><col><col></colgroup>
            <thead><tr><th>ID заявки</th><th>Масса, кг</th><th>МНО, Регион</th><th>МНО, МО</th><th>Грузоотправитель</th><th>Адрес погрузки</th></tr></thead>
            <tbody data-feo-body></tbody>
        </table>
        <div class="feo-load-sentinel" data-feo-sentinel>Загрузка заявок…</div>
    </div>
</div>
<?php endif; ?>

<div id="feo-import-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg feo-import-modal">
        <div class="modal-head"><span class="modal-title">Загрузка заявок ФЭО</span><button type="button" class="modal-close" data-close-modal="feo-import-modal">&times;</button></div>
        <div class="modal-body">
            <form id="feo-analyze-form" class="feo-import-form" action="<?= app_url('/company/feo/requests/import/analyze') ?>" method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <fieldset class="feo-coordinate-format"><legend class="field-label">Формат файла</legend><label><input type="radio" name="coordinate_format" value="new" checked> Новый формат</label><label><input type="radio" name="coordinate_format" value="old"> Старый формат</label></fieldset>
                <div class="feo-import-help"><?= !empty($legacySyncConfigured) ? 'Изменения этой загрузки и её откат применяются также в старой базе.' : 'Синхронизация со старой базой для этой компании не настроена.' ?></div>
                <div class="field feo-import-file"><label class="field-label">Файл XLSX</label><input class="field-input" type="file" name="feo_xlsx" accept=".xlsx" required><div class="feo-import-help">После выбора ERP автоматически проверит формат и покажет, сколько заявок будет добавлено или изменено.</div></div>
                <label class="feo-overwrite-option"><input type="checkbox" name="overwrite_existing" value="1"><span><b>Перезаписывать существующие заявки</b><small>Оставьте выключенным, если нужно только добавить отсутствующие заявки. Существующие записи останутся без изменений.</small></span></label>
                <div class="feo-import-state" data-feo-import-state>Файл не выбран.</div>
            </form>
            <div id="feo-analysis-error" class="notice warn feo-import-error is-hidden"></div>
            <div id="feo-analysis-result" class="is-hidden">
                <div class="feo-import-stats">
                    <div class="feo-stat"><b data-stat="unique_requests">0</b><span>в файле</span></div>
                    <div class="feo-stat"><b data-stat="new_count">0</b><span>новых</span></div>
                    <div class="feo-stat"><b data-stat="updated_count">0</b><span>обновится</span></div>
                    <div class="feo-stat"><b data-stat="skipped_existing_count">0</b><span>не перезапишется</span></div>
                    <div class="feo-stat"><b data-stat="unchanged_count">0</b><span>без изменений</span></div>
                    <div class="feo-stat"><b data-stat="missing_count">0</b><span>пропало из файла</span></div>
                </div>
                <div id="feo-duplicate-warning" class="notice warn feo-duplicate is-hidden">Этот файл уже применён. Повторный импорт заблокирован, пока предыдущая загрузка не откатана.</div>
            </div>
        </div>
        <div class="modal-foot is-spaced">
            <button type="button" class="btn btn-ghost" data-close-modal="feo-import-modal">Отмена</button>
            <form method="post" action="<?= app_url('/company/feo/requests/import/commit') ?>" id="feo-commit-form" class="inline-form is-hidden">
                <?= csrfField() ?><input type="hidden" name="preview_token" id="feo-preview-token"><button type="submit" class="btn btn-primary">Загрузить</button>
            </form>
        </div>
    </div>
</div>

<div id="feo-history-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg feo-history-modal">
        <div class="modal-head"><span class="modal-title">История загрузок ФЭО</span><button type="button" class="modal-close" data-close-modal="feo-history-modal">&times;</button></div>
        <div class="modal-body">
            <?php if (!empty($imports)): ?>
            <div class="feo-history-scroll"><table class="table feo-history-table"><thead><tr><th>Дата</th><th>Файл</th><th>Всего</th><th>Новых</th><th>Обновлено</th><th>Пропало</th><th>Статус</th><th></th></tr></thead><tbody>
            <?php foreach ($imports as $imp): ?>
                <tr>
                    <td><?= e($fmtDateTime($imp['created_at'] ?? null)) ?></td>
                    <td><?= e((string)($imp['filename'] ?? '')) ?></td>
                    <td><?= (int)($imp['unique_requests'] ?? 0) ?></td><td><?= (int)($imp['new_count'] ?? 0) ?></td><td><?= (int)($imp['updated_count'] ?? 0) ?></td><td><?= (int)($imp['missing_count'] ?? 0) ?></td>
                    <td><?php if (($imp['status'] ?? '') === 'applied'): ?><span class="badge badge-ok"><span class="dot"></span>Применён</span><?php else: ?><span class="badge badge-neutral"><span class="dot"></span>Откатан</span><?php endif; ?></td>
                    <td><div class="feo-history-actions">
                    <?php if ($canManageHistory && !empty($imp['can_rollback'])): ?><button type="button" class="btn btn-secondary btn--toolbar" data-feo-history-action="rollback" data-import-id="<?= (int)$imp['id'] ?>">Откатить</button><?php endif; ?>
                    <?php if ($canManageHistory && !empty($imp['can_restore'])): ?><button type="button" class="btn btn-primary btn--toolbar" data-feo-history-action="restore" data-import-id="<?= (int)$imp['id'] ?>">Вернуть</button><?php endif; ?>
                    <?php if ($canManageHistory && !empty($imp['can_delete'])): ?><button type="button" class="btn btn-danger btn--toolbar" data-feo-history-action="delete" data-import-id="<?= (int)$imp['id'] ?>">Удалить</button><?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php else: ?><div class="empty-state compact"><p>Загрузок пока нет.</p></div><?php endif; ?>
            <div class="feo-history-note">Откат и возврат выполняются строго последовательно. Полное удаление доступно только с конца истории и не может нарушить цепочку восстановления.</div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close-modal="feo-history-modal">Закрыть</button></div>
    </div>
</div>

<div id="feo-history-confirm-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal">
        <div class="modal-head"><span class="modal-title" data-feo-confirm-title>Подтверждение</span><button type="button" class="modal-close" data-close-modal="feo-history-confirm-modal">&times;</button></div>
        <div class="modal-body"><div class="feo-confirm-copy"><div data-feo-confirm-message></div><div class="feo-confirm-warning" data-feo-confirm-note></div></div></div>
        <div class="modal-foot is-spaced">
            <button type="button" class="btn btn-ghost" data-close-modal="feo-history-confirm-modal">Отмена</button>
            <form method="post" action="" data-feo-confirm-form class="inline-form"><?= csrfField() ?><input type="hidden" name="import_id" data-feo-confirm-id><button type="submit" class="btn" data-feo-confirm-button>Подтвердить</button></form>
        </div>
    </div>
</div>

<div id="feo-detail-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg feo-detail-modal">
        <div class="modal-head"><span class="modal-title" id="feo-detail-title">Заявка ФЭО</span><button type="button" class="modal-close" data-close-modal="feo-detail-modal">&times;</button></div>
        <div class="modal-body">
            <div id="feo-detail-loading">Загрузка…</div>
            <form id="feo-detail-form" class="feo-detail-form is-hidden"><?= csrfField() ?><div id="feo-detail-grid" class="feo-detail-grid"></div></form>
            <div id="feo-detail-error" class="notice warn feo-detail-save-error is-hidden"></div>
            <div id="feo-detail-success" class="notice success feo-detail-save-success is-hidden"></div>
        </div>
        <div class="modal-foot">
            <div class="modal-foot-actions">
                <button type="button" class="btn btn-ghost" data-feo-detail-close data-close-modal="feo-detail-modal">Закрыть</button>
                <button type="button" class="btn btn-primary" data-feo-detail-edit>Редактировать</button>
                <button type="button" class="btn btn-ghost is-hidden" data-feo-detail-cancel>Отмена</button>
                <button type="submit" form="feo-detail-form" class="btn btn-primary is-hidden" data-feo-detail-save>Сохранить</button>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
  var openModalSafe=function(id){if(window.openModal)window.openModal(id);};
  document.querySelector('[data-open-feo-history]')?.addEventListener('click',function(){openModalSafe('feo-history-modal');});
  document.querySelector('[data-open-feo-import]')?.addEventListener('click',function(){openModalSafe('feo-import-modal');});

  function extractClientRequestIds(raw){
    raw=String(raw||'');if(!raw.trim())return [];
    var structured=raw.indexOf('\t')!==-1||raw.indexOf('|')!==-1,found=[];
    if(structured){
      raw.replace(/\r/g,'').split('\n').forEach(function(line){
        var cell='';
        if(line.indexOf('\t')!==-1)cell=(line.split('\t',1)[0]||'').trim();
        else if(line.indexOf('|')!==-1){var normalized=line.trim().replace(/^\|+|\|+$/g,'');cell=(normalized.split('|',1)[0]||'').trim();}
        var matches=cell.match(/(?<!\d)\d{6}(?!\d)/g)||[];matches.forEach(function(id){found.push(id);});
      });
    }else found=raw.match(/(?<!\d)\d{6}(?!\d)/g)||[];
    var seen=new Set();return found.filter(function(id){if(seen.has(id))return false;seen.add(id);return true;});
  }
  function normalizedClientIdFilter(raw){return extractClientRequestIds(raw).join(' ');}

  var grid=document.querySelector('[data-feo-grid]');
  if(grid){
    var body=grid.querySelector('[data-feo-body]'),scroll=grid.querySelector('[data-feo-scroll]'),sentinel=grid.querySelector('[data-feo-sentinel]');
    var idInput=grid.querySelector('[data-feo-id-filter]'),textInput=grid.querySelector('[data-feo-content-filter]');
    var totalEl=grid.querySelector('[data-feo-total]'),massEl=grid.querySelector('[data-feo-total-mass]'),msgEl=grid.querySelector('[data-feo-search-message]');
    var state={offset:0,limit:80,loading:false,hasMore:true,generation:0,controller:null};

    function resizeScroll(){var top=scroll.getBoundingClientRect().top,height=Math.max(360,window.innerHeight-top-8);scroll.style.height=height+'px';scroll.style.maxHeight=height+'px';}
    resizeScroll();window.addEventListener('resize',resizeScroll);
    function clean(v){return v===null||v===undefined||v===''?'—':String(v);}
    function td(text,title){var c=document.createElement('td');c.textContent=clean(text);if(title)c.title=String(title);return c;}
    function formatKg(value){var n=Number(value||0);return Number.isFinite(n)?new Intl.NumberFormat('ru-RU',{maximumFractionDigits:3}).format(n):'0';}
    function addRow(row){
      var tr=document.createElement('tr');tr.dataset.feoId=String(row.id);tr.title='Двойной клик — открыть все поля заявки';
      tr.appendChild(td(row.zayavka_id));
      var kg=row.mass_netto===null||row.mass_netto===''?'—':new Intl.NumberFormat('ru-RU',{maximumFractionDigits:0}).format(Number(row.mass_netto)*1000);tr.appendChild(td(kg));
      tr.appendChild(td(row.mno_region,row.mno_region));tr.appendChild(td(row.mno_mo,row.mno_mo));tr.appendChild(td(row.naim_oo_gruzootpravitel,row.naim_oo_gruzootpravitel));tr.appendChild(td(row.mno_adres_pogruzki,row.mno_adres_pogruzki));
      body.appendChild(tr);
    }
    function setMessage(text,problem){msgEl.textContent=text||'';msgEl.classList.toggle('is-hidden',!text);msgEl.classList.toggle('warn',!!problem);}
    function setDiagnostics(data,reset){
      if(!reset)return;
      totalEl.textContent=String(data.total||0);massEl.textContent=formatKg(data.total_mass_kg);
      var requested=Number(data.requested_count||0),missing=Array.isArray(data.missing_ids)?data.missing_ids:[];
      if(data.filter_error){setMessage(data.filter_error,true);return;}
      if(requested>0){
        if(missing.length){setMessage('Не найдены заявки: '+missing.join(', ')+'.',true);}else if(Number(data.total||0)===0&&textInput.value.trim()){setMessage('Указанные заявки есть в реестре, но поиск по содержимому не дал совпадений.',true);}else setMessage('',false);
      }else if(textInput.value.trim()&&Number(data.total||0)===0){setMessage('Поиск по содержимому ничего не нашёл. Измените запрос.',true);}
      else{setMessage('',false);}
    }
    async function load(reset){
      if(state.loading&&!reset)return;
      if(reset){state.generation++;state.offset=0;state.hasMore=true;if(state.controller)state.controller.abort();}
      if(!state.hasMore)return;
      var generation=state.generation;state.loading=true;sentinel.hidden=false;sentinel.textContent='Загрузка заявок…';state.controller=new AbortController();
      var rawIdFilter=idInput.value,normalizedIds=normalizedClientIdFilter(rawIdFilter),safeIdFilter=normalizedIds||(rawIdFilter.trim()?'__invalid__':'');
      var p=new URLSearchParams({offset:String(state.offset),limit:String(state.limit),filter_zayavki:safeIdFilter,search_text:textInput.value.trim()});
      try{
        var response=await fetch('<?= app_url('/company/feo/requests/data') ?>?'+p.toString(),{credentials:'same-origin',signal:state.controller.signal});
        var data=await response.json();if(generation!==state.generation)return;if(!response.ok||!data.success)throw new Error(data.message||'Ошибка загрузки заявок');
        var rows=Array.isArray(data.rows)?data.rows:[];if(reset)body.innerHTML='';rows.forEach(addRow);setDiagnostics(data,reset);state.offset+=rows.length;state.hasMore=!!data.has_more;
        if(!state.hasMore){sentinel.textContent=state.offset?'Все найденные заявки загружены':'Заявки не найдены';}
      }catch(err){if(err.name==='AbortError')return;setMessage(err.message||String(err),true);sentinel.textContent='Не удалось загрузить заявки';state.hasMore=false;}
      finally{if(generation===state.generation)state.loading=false;}
    }
    function resetLoad(){load(true);scroll.scrollTop=0;}
    var filterTimer=0;
    function scheduleFilter(){clearTimeout(filterTimer);filterTimer=window.setTimeout(function(){resetLoad();},280);}
    idInput.addEventListener('paste',function(ev){
      var pasted=ev.clipboardData?.getData('text/plain')||'',ids=extractClientRequestIds(pasted);
      if(ids.length){ev.preventDefault();idInput.value=ids.join(' ');scheduleFilter();}
    });
    [idInput,textInput].forEach(function(input){input.addEventListener('input',scheduleFilter);input.addEventListener('keydown',function(ev){if(ev.key==='Enter'){ev.preventDefault();clearTimeout(filterTimer);resetLoad();}});});
    var observer=new IntersectionObserver(function(entries){if(entries.some(function(e){return e.isIntersecting;})&&!state.loading&&state.hasMore)load(false);},{root:scroll,rootMargin:'300px 0px'});observer.observe(sentinel);load(true);

    var detailForm=document.getElementById('feo-detail-form'),detailGrid=document.getElementById('feo-detail-grid'),detailLoading=document.getElementById('feo-detail-loading'),detailError=document.getElementById('feo-detail-error'),detailSuccess=document.getElementById('feo-detail-success');
    var editButton=document.querySelector('[data-feo-detail-edit]'),cancelButton=document.querySelector('[data-feo-detail-cancel]'),saveButton=document.querySelector('[data-feo-detail-save]'),closeButton=document.querySelector('[data-feo-detail-close]');
    var detailState={id:0,data:null,editing:false};
    var dateFields=['data_zayavki','data_dogovora','data_sozdaniya_porucheniya','zakaz_s_ot_data_vygruzki','zayavka_zhdv_po'];
    var decimalFields=['mass_netto','mass_brutto','dlina_mesta','shirina_mesta','vysota_mesta','summarnyy_obem','zakaz_s_op_stoimost'];
    function renderDetail(editing){
      detailState.editing=editing;detailGrid.innerHTML='';detailError.classList.add('is-hidden');detailSuccess.classList.add('is-hidden');
      (detailState.data?.fields||[]).forEach(function(item){var l=document.createElement('div'),v=document.createElement('div');l.className='feo-detail-label';v.className='feo-detail-value';l.textContent=item.label||item.field;
        if(editing&&item.editable){var input=document.createElement('input');input.className='field-input feo-detail-input';input.name=item.field;input.value=item.value===null||item.value===undefined?'':String(item.value);if(dateFields.includes(item.field))input.type='date';else{input.type='text';if(decimalFields.includes(item.field))input.inputMode='decimal';}v.appendChild(input);}else v.textContent=clean(item.value);
        detailGrid.appendChild(l);detailGrid.appendChild(v);
      });
      detailForm.classList.remove('is-hidden');editButton.classList.toggle('is-hidden',editing);closeButton.classList.toggle('is-hidden',editing);cancelButton.classList.toggle('is-hidden',!editing);saveButton.classList.toggle('is-hidden',!editing);
    }
    async function openDetail(id){detailState.id=Number(id)||0;detailState.data=null;detailState.editing=false;detailGrid.innerHTML='';detailForm.classList.add('is-hidden');detailError.classList.add('is-hidden');detailSuccess.classList.add('is-hidden');editButton.classList.add('is-hidden');cancelButton.classList.add('is-hidden');saveButton.classList.add('is-hidden');closeButton.classList.remove('is-hidden');detailLoading.classList.remove('is-hidden');openModalSafe('feo-detail-modal');
      try{var r=await fetch('<?= app_url('/company/feo/requests') ?>/'+encodeURIComponent(detailState.id)+'/json',{credentials:'same-origin'}),d=await r.json();if(!r.ok||!d.success)throw new Error(d.message||'Не удалось открыть заявку');detailState.data=d;document.getElementById('feo-detail-title').textContent='Заявка ФЭО '+(d.zayavka_id||'');renderDetail(false);}catch(err){detailError.textContent=err.message||String(err);detailError.classList.remove('is-hidden');}finally{detailLoading.classList.add('is-hidden');}}
    body.addEventListener('dblclick',function(ev){var row=ev.target.closest('tr[data-feo-id]');if(row)openDetail(row.dataset.feoId);});
    editButton.addEventListener('click',function(){if(detailState.data)renderDetail(true);});
    cancelButton.addEventListener('click',function(){if(detailState.data)renderDetail(false);});
    detailForm.addEventListener('submit',async function(ev){ev.preventDefault();if(!detailState.id||!detailState.editing)return;saveButton.disabled=true;detailError.classList.add('is-hidden');detailSuccess.classList.add('is-hidden');
      try{var r=await fetch('<?= app_url('/company/feo/requests') ?>/'+encodeURIComponent(detailState.id)+'/update',{method:'POST',body:new FormData(detailForm),credentials:'same-origin'}),d=await r.json();if(!r.ok||!d.success)throw new Error(d.message||'Не удалось сохранить заявку');detailState.data=d;document.getElementById('feo-detail-title').textContent='Заявка ФЭО '+(d.zayavka_id||'');renderDetail(false);detailSuccess.textContent=d.message||'Заявка ФЭО сохранена.';detailSuccess.classList.remove('is-hidden');resetLoad();}catch(err){detailError.textContent=err.message||String(err);detailError.classList.remove('is-hidden');}finally{saveButton.disabled=false;}}
    );
  }

  var analyzeForm=document.getElementById('feo-analyze-form');
  if(analyzeForm){
    var fileInput=analyzeForm.querySelector('input[name="feo_xlsx"]'),overwriteInput=analyzeForm.querySelector('input[name="overwrite_existing"]'),importState=document.querySelector('[data-feo-import-state]');
    var formatInputs=analyzeForm.querySelectorAll('input[name="coordinate_format"]');
    var analysisGeneration=0;
    function setImportState(text,state){importState.textContent=text;importState.classList.remove('is-checking','is-ok','is-error');if(state)importState.classList.add(state);}
    async function analyzeSelectedFile(){
      var error=document.getElementById('feo-analysis-error'),result=document.getElementById('feo-analysis-result'),commit=document.getElementById('feo-commit-form'),duplicate=document.getElementById('feo-duplicate-warning');
      analysisGeneration++;var generation=analysisGeneration;error.classList.add('is-hidden');result.classList.add('is-hidden');commit.classList.add('is-hidden');duplicate.classList.add('is-hidden');document.getElementById('feo-preview-token').value='';
      if(!fileInput.files||!fileInput.files.length){setImportState('Файл не выбран.','');return;}
      var payload=new FormData(analyzeForm);
      setImportState('Проверяем файл…','is-checking');fileInput.disabled=true;overwriteInput.disabled=true;
      formatInputs.forEach(function(input){input.disabled=true;});
      try{var response=await fetch(analyzeForm.action,{method:'POST',body:payload,credentials:'same-origin'}),data=await response.json();if(generation!==analysisGeneration)return;if(!response.ok||!data.success)throw new Error(data.message||'Ошибка анализа файла');Object.keys(data.stats||{}).forEach(function(key){var el=result.querySelector('[data-stat="'+key+'"]');if(el)el.textContent=data.stats[key];});document.getElementById('feo-preview-token').value=data.token||'';result.classList.remove('is-hidden');if(data.stats&&data.stats.duplicate_file){duplicate.classList.remove('is-hidden');setImportState('Файл уже был применён.','is-error');}else{commit.classList.remove('is-hidden');setImportState('Файл проверен. Можно загрузить.','is-ok');}}catch(err){if(generation!==analysisGeneration)return;error.textContent=err.message||String(err);error.classList.remove('is-hidden');setImportState('Проверка не пройдена.','is-error');}finally{if(generation===analysisGeneration){fileInput.disabled=false;overwriteInput.disabled=false;formatInputs.forEach(function(input){input.disabled=false;});}}
    }
    fileInput.addEventListener('change',analyzeSelectedFile);overwriteInput.addEventListener('change',analyzeSelectedFile);
    formatInputs.forEach(function(input){input.addEventListener('change',analyzeSelectedFile);});
  }

  var actionConfig={
    rollback:{title:'Откатить загрузку ФЭО',message:'Откатить эту загрузку?',note:'ERP удалит заявки, добавленные этой загрузкой, и восстановит предыдущие значения изменённых заявок. После отката загрузку можно вернуть.',button:'Откатить',url:'<?= app_url('/company/feo/requests/import/rollback') ?>',className:'btn btn-secondary'},
    restore:{title:'Вернуть загрузку ФЭО',message:'Вернуть ранее откатанную загрузку?',note:'ERP повторно применит изменения из сохранённого журнала. Возврат выполняется только в безопасной LIFO-последовательности.',button:'Вернуть',url:'<?= app_url('/company/feo/requests/import/restore') ?>',className:'btn btn-primary'},
    delete:{title:'Удалить загрузку ФЭО',message:'Полностью удалить эту загрузку из системы?',note:'Если загрузка активна, ERP сначала атомарно отменит её изменения, затем удалит историю и журнал. Действие необратимо.',button:'Удалить',url:'<?= app_url('/company/feo/requests/import/delete') ?>',className:'btn btn-danger'}
  };
  document.querySelectorAll('[data-feo-history-action]').forEach(function(button){button.addEventListener('click',function(){var cfg=actionConfig[this.dataset.feoHistoryAction];if(!cfg)return;document.querySelector('[data-feo-confirm-title]').textContent=cfg.title;document.querySelector('[data-feo-confirm-message]').textContent=cfg.message;document.querySelector('[data-feo-confirm-note]').textContent=cfg.note;var form=document.querySelector('[data-feo-confirm-form]'),confirmButton=document.querySelector('[data-feo-confirm-button]');form.action=cfg.url;form.querySelector('[data-feo-confirm-id]').value=this.dataset.importId;confirmButton.textContent=cfg.button;confirmButton.className=cfg.className;openModalSafe('feo-history-confirm-modal');});});
}());
</script>
