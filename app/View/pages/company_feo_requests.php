<?php
$rows = $listing['rows'] ?? [];
$total = (int)($listing['total'] ?? 0);
$currentPage = (int)($listing['page'] ?? 1);
$pages = (int)($listing['pages'] ?? 1);
$fmtDate = static function ($value): string {
    if (!$value) return '—';
    $ts = strtotime((string)$value);
    return $ts === false ? e((string)$value) : date('d.m.Y', $ts);
};
$fmtDateTime = static function ($value): string {
    if (!$value) return '—';
    $ts = strtotime((string)$value);
    return $ts === false ? e((string)$value) : date('d.m.Y H:i', $ts);
};
$fmtMass = static function ($value): string {
    if ($value === null || $value === '') return '—';
    return rtrim(rtrim(number_format((float)$value, 4, ',', ' '), '0'), ',');
};
$pageUrl = static function (int $page) use ($search): string {
    $query = ['page'=>$page];
    if ($search !== '') $query['q'] = $search;
    return app_url('/company/feo/requests') . '?' . http_build_query($query);
};
?>
<style>
.feo-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:14px}.feo-actions{display:flex;gap:8px;flex-wrap:wrap}.feo-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:10px 12px;border-bottom:1px solid var(--border,#e5e7eb)}.feo-search{display:flex;gap:8px;align-items:center;min-width:360px}.feo-search .input{min-width:300px}.feo-summary{font-size:13px;color:var(--muted,#6b7280)}.feo-table{table-layout:fixed;width:100%}.feo-table th,.feo-table td{vertical-align:top}.feo-table th:nth-child(1){width:8%}.feo-table th:nth-child(2){width:9%}.feo-table th:nth-child(3){width:15%}.feo-table th:nth-child(4){width:14%}.feo-table th:nth-child(5){width:9%}.feo-table th:nth-child(6){width:18%}.feo-table th:nth-child(7){width:11%}.feo-table th:nth-child(8){width:7%}.feo-table th:nth-child(9){width:9%}.feo-clip{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.feo-row{cursor:pointer}.feo-row:hover{background:var(--accent-soft,#f0fdfa)}.feo-pagination{display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-top:1px solid var(--border,#e5e7eb)}.feo-pages{display:flex;gap:6px}.feo-import-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-top:12px}.feo-stat{border:1px solid var(--border,#e5e7eb);border-radius:8px;padding:10px}.feo-stat b{display:block;font-size:20px}.feo-stat span{font-size:12px;color:var(--muted,#6b7280)}.feo-history-table th,.feo-history-table td{white-space:nowrap}.feo-history-table .file{max-width:260px;white-space:normal}.feo-detail-grid{display:grid;grid-template-columns:minmax(200px,38%) 1fr;gap:0;border:1px solid var(--border,#e5e7eb);border-radius:8px;overflow:hidden}.feo-detail-label,.feo-detail-value{padding:8px 10px;border-bottom:1px solid var(--border,#e5e7eb)}.feo-detail-label{font-size:12px;font-weight:600;background:#f8fafc}.feo-detail-value{font-size:13px;word-break:break-word}.feo-detail-grid>:nth-last-child(-n+2){border-bottom:0}.feo-import-note{font-size:12px;color:var(--muted,#6b7280);margin-top:6px}.feo-error{color:#b91c1c;margin-top:10px}.feo-success{color:#166534;margin-top:10px}.feo-duplicate{padding:10px;border-radius:8px;background:#fff7ed;color:#9a3412;margin-top:10px}.is-hidden{display:none!important}@media(max-width:1100px){.feo-search{min-width:0}.feo-search .input{min-width:220px}.feo-table th:nth-child(4),.feo-table td:nth-child(4),.feo-table th:nth-child(7),.feo-table td:nth-child(7){display:none}}
</style>

<div class="feo-head">
    <div>
        <h1 class="page-title">Все заявки ФЭО</h1>
        <div class="page-summary"><span>Tenant-реестр заявок ФЭО. Данные хранятся только в базе текущей компании.</span></div>
    </div>
    <div class="feo-actions">
        <button type="button" class="btn btn-secondary" onclick="window.openModal && window.openModal('feo-history-modal')">История загрузок</button>
        <button type="button" class="btn btn-primary" onclick="window.openModal && window.openModal('feo-import-modal')">Загрузить XLSX</button>
    </div>
</div>

<?php if ($dbError !== null): ?>
<div class="notice warn"><?= e($dbError) ?></div>
<?php else: ?>
<div class="table-card table-card--standard">
    <div class="feo-toolbar">
        <div class="feo-summary">Заявок: <b><?= $total ?></b><?php if ($search !== ''): ?> · поиск «<?= e($search) ?>»<?php endif; ?></div>
        <form method="get" action="<?= app_url('/company/feo/requests') ?>" class="feo-search">
            <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="ID, заказчик, организация, регион, адрес, ИНН">
            <button class="btn btn-secondary" type="submit">Найти</button>
            <?php if ($search !== ''): ?><a class="btn btn-ghost" href="<?= app_url('/company/feo/requests') ?>">Сброс</a><?php endif; ?>
        </form>
    </div>
    <?php if ($rows): ?>
    <table class="table feo-table">
        <thead><tr>
            <th>Заявка</th><th>Дата</th><th>Заказчик</th><th>Грузоотправитель</th><th>Регион</th><th>Адрес погрузки</th><th>Отход</th><th>Брутто, т</th><th>ЖДВ</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr class="feo-row" data-feo-id="<?= (int)$row['id'] ?>" title="Двойной клик — открыть все поля заявки">
                <td class="col-mono"><b><?= e((string)$row['zayavka_id']) ?></b></td>
                <td class="col-mono"><?= $fmtDate($row['data_zayavki'] ?? null) ?></td>
                <td><span class="feo-clip" title="<?= e((string)($row['polnoe_naim_zakazchika'] ?? '')) ?>"><?= e((string)($row['polnoe_naim_zakazchika'] ?: '—')) ?></span></td>
                <td><span class="feo-clip" title="<?= e((string)($row['naim_oo_gruzootpravitel'] ?? '')) ?>"><?= e((string)($row['naim_oo_gruzootpravitel'] ?: '—')) ?></span></td>
                <td><span class="feo-clip"><?= e((string)($row['mno_region'] ?: '—')) ?></span></td>
                <td><span class="feo-clip" title="<?= e((string)($row['mno_adres_pogruzki'] ?? '')) ?>"><?= e((string)($row['mno_adres_pogruzki'] ?: '—')) ?></span></td>
                <td><span class="feo-clip" title="<?= e((string)($row['naim_otkhoda_fkko'] ?? '')) ?>"><?= e((string)($row['naim_otkhoda_fkko'] ?: '—')) ?></span></td>
                <td class="col-mono"><?= $fmtMass($row['mass_brutto'] ?? null) ?></td>
                <td class="col-mono"><?= $fmtDate($row['zayavka_zhdv_po'] ?? null) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="feo-pagination">
        <span class="feo-summary">Страница <?= $currentPage ?> из <?= $pages ?></span>
        <div class="feo-pages">
            <?php if ($currentPage > 1): ?><a class="btn btn-ghost btn-toolbar" href="<?= e($pageUrl($currentPage - 1)) ?>">Назад</a><?php endif; ?>
            <?php if ($currentPage < $pages): ?><a class="btn btn-ghost btn-toolbar" href="<?= e($pageUrl($currentPage + 1)) ?>">Вперёд</a><?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="empty-state compact"><p><?= $search !== '' ? 'По вашему запросу заявок не найдено.' : 'Заявки ФЭО ещё не загружены.' ?></p></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div id="feo-import-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg">
        <div class="modal-head"><span class="modal-title">Загрузка заявок ФЭО</span><button type="button" class="modal-close" data-close-modal="feo-import-modal">&times;</button></div>
        <div class="modal-body">
            <form id="feo-analyze-form" action="<?= app_url('/company/feo/requests/import/analyze') ?>" method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <div class="field"><label class="field-label">Файл XLSX</label><input class="input" type="file" name="feo_xlsx" accept=".xlsx" required><div class="feo-import-note">Сначала ERP анализирует файл и показывает разницу. Данные изменятся только после подтверждения.</div></div>
                <div class="mt-section"><button class="btn btn-secondary" type="submit" id="feo-analyze-btn">Анализировать</button></div>
            </form>
            <div id="feo-analysis-error" class="feo-error is-hidden"></div>
            <div id="feo-analysis-result" class="is-hidden">
                <div class="feo-import-stats">
                    <div class="feo-stat"><b data-stat="unique_requests">0</b><span>в файле</span></div>
                    <div class="feo-stat"><b data-stat="new_count">0</b><span>новых</span></div>
                    <div class="feo-stat"><b data-stat="updated_count">0</b><span>обновится</span></div>
                    <div class="feo-stat"><b data-stat="unchanged_count">0</b><span>без изменений</span></div>
                    <div class="feo-stat"><b data-stat="missing_count">0</b><span>пропало из файла</span></div>
                </div>
                <div id="feo-duplicate-warning" class="feo-duplicate is-hidden">Этот файл уже применён. Повторный импорт заблокирован, пока предыдущая загрузка не откатана.</div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-ghost" data-close-modal="feo-import-modal">Отмена</button>
            <form method="post" action="<?= app_url('/company/feo/requests/import/commit') ?>" id="feo-commit-form" class="inline-form is-hidden">
                <?= csrfField() ?><input type="hidden" name="preview_token" id="feo-preview-token"><button type="submit" class="btn btn-primary" id="feo-commit-btn">Применить загрузку</button>
            </form>
        </div>
    </div>
</div>

<div id="feo-history-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg">
        <div class="modal-head"><span class="modal-title">История загрузок ФЭО</span><button type="button" class="modal-close" data-close-modal="feo-history-modal">&times;</button></div>
        <div class="modal-body">
            <?php if ($imports): ?>
            <div class="table-scroll"><table class="table feo-history-table"><thead><tr><th>Дата</th><th>Файл</th><th>Всего</th><th>Новых</th><th>Обновлено</th><th>Пропало</th><th>Статус</th><th></th></tr></thead><tbody>
                <?php foreach ($imports as $imp): ?>
                <tr>
                    <td class="col-mono"><?= $fmtDateTime($imp['created_at'] ?? null) ?></td>
                    <td class="file" title="<?= e((string)$imp['filename']) ?>"><?= e((string)$imp['filename']) ?></td>
                    <td><?= (int)$imp['unique_requests'] ?></td><td><?= (int)$imp['new_count'] ?></td><td><?= (int)$imp['updated_count'] ?></td><td><?= (int)$imp['missing_count'] ?></td>
                    <td><?php if (($imp['status'] ?? '') === 'applied'): ?><span class="badge badge-ok"><span class="dot"></span>Применён</span><?php else: ?><span class="badge badge-neutral"><span class="dot"></span>Откатан</span><?php endif; ?></td>
                    <td>
                    <?php if (!empty($imp['can_rollback']) && in_array((string)($_SESSION['role_code'] ?? ''), ['company_owner','senior_logist'], true)): ?>
                        <form method="post" action="<?= app_url('/company/feo/requests/import/rollback') ?>" onsubmit="return confirm('Откатить последнюю загрузку ФЭО? Новые заявки этой загрузки будут удалены, обновлённые — восстановлены.');">
                            <?= csrfField() ?><input type="hidden" name="import_id" value="<?= (int)$imp['id'] ?>"><button class="btn btn-danger btn-toolbar" type="submit">Откатить</button>
                        </form>
                    <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <?php else: ?><div class="empty-state compact"><p>Загрузок пока нет.</p></div><?php endif; ?>
            <div class="feo-import-note">Откат выполняется строго последовательно: доступна только последняя активная загрузка.</div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close-modal="feo-history-modal">Закрыть</button></div>
    </div>
</div>

<div id="feo-detail-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-lg">
        <div class="modal-head"><span class="modal-title" id="feo-detail-title">Заявка ФЭО</span><button type="button" class="modal-close" data-close-modal="feo-detail-modal">&times;</button></div>
        <div class="modal-body"><div id="feo-detail-loading">Загрузка…</div><div id="feo-detail-grid" class="feo-detail-grid is-hidden"></div><div id="feo-detail-error" class="feo-error is-hidden"></div></div>
        <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close-modal="feo-detail-modal">Закрыть</button></div>
    </div>
</div>

<script>
(function(){
  var analyzeForm=document.getElementById('feo-analyze-form');
  if(analyzeForm){
    analyzeForm.addEventListener('submit',async function(ev){
      ev.preventDefault();
      var btn=document.getElementById('feo-analyze-btn'), error=document.getElementById('feo-analysis-error'), result=document.getElementById('feo-analysis-result'), commit=document.getElementById('feo-commit-form'), duplicate=document.getElementById('feo-duplicate-warning');
      error.classList.add('is-hidden'); result.classList.add('is-hidden'); commit.classList.add('is-hidden'); duplicate.classList.add('is-hidden'); btn.disabled=true; btn.textContent='Анализируем…';
      try{
        var response=await fetch(analyzeForm.action,{method:'POST',body:new FormData(analyzeForm),credentials:'same-origin'});
        var data=await response.json();
        if(!response.ok||!data.success)throw new Error(data.message||'Ошибка анализа файла');
        Object.keys(data.stats||{}).forEach(function(key){var el=result.querySelector('[data-stat="'+key+'"]');if(el)el.textContent=data.stats[key];});
        document.getElementById('feo-preview-token').value=data.token||''; result.classList.remove('is-hidden');
        if(data.stats&&data.stats.duplicate_file){duplicate.classList.remove('is-hidden');commit.classList.add('is-hidden');}else{commit.classList.remove('is-hidden');}
      }catch(err){error.textContent=err.message||String(err);error.classList.remove('is-hidden');}
      finally{btn.disabled=false;btn.textContent='Анализировать';}
    });
  }

  document.querySelectorAll('[data-feo-id]').forEach(function(row){
    row.addEventListener('dblclick',async function(){
      var id=this.getAttribute('data-feo-id'), modal='feo-detail-modal', grid=document.getElementById('feo-detail-grid'), loading=document.getElementById('feo-detail-loading'), error=document.getElementById('feo-detail-error');
      grid.innerHTML='';grid.classList.add('is-hidden');error.classList.add('is-hidden');loading.classList.remove('is-hidden');if(window.openModal)window.openModal(modal);
      try{
        var url='<?= app_url('/company/feo/requests') ?>/'+encodeURIComponent(id)+'/json';
        var response=await fetch(url,{credentials:'same-origin'}), data=await response.json();
        if(!response.ok||!data.success)throw new Error(data.message||'Не удалось открыть заявку');
        document.getElementById('feo-detail-title').textContent='Заявка ФЭО '+(data.zayavka_id||'');
        (data.fields||[]).forEach(function(item){var l=document.createElement('div'),v=document.createElement('div');l.className='feo-detail-label';v.className='feo-detail-value';l.textContent=item.label||item.field;v.textContent=(item.value===null||item.value==='')?'—':item.value;grid.appendChild(l);grid.appendChild(v);});
        grid.classList.remove('is-hidden');
      }catch(err){error.textContent=err.message||String(err);error.classList.remove('is-hidden');}
      finally{loading.classList.add('is-hidden');}
    });
  });
}());
</script>
