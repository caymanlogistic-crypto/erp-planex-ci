<?php $pages=max(1,(int)ceil($data['total']/25)); ?>
<div class="gps-page" data-gps-page data-base="<?= e(app_url('/company/trips/gps-coverage')) ?>">
  <div class="page-head route-executors-page-head">
    <div class="page-head-left"><span class="rp-identity-icon"><svg class="page-head-tabler-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 17h2l2-5h10l2 5h2"/><path d="M5 17a2 2 0 1 0 4 0m6 0a2 2 0 1 0 4 0"/><path d="M7 12l1-4h8l1 4"/><path d="M4 7l2-2m12 0l2 2"/></svg></span><span class="rp-head-text"><h1 class="page-title">Покрытие рейсов GPS</h1>
      <span class="page-summary">Рейсы Фрегата · действующие и завершённые · GPS-данные на <?= e($data['source_at'] ? (new DateTimeImmutable($data['source_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i').' МСК' : '—') ?></span></span></div>
  </div>
  <?php if ($error): ?><div class="notice warn"><?= e($error) ?></div><?php else: ?>
  <nav class="gps-status-tabs" aria-label="Статус рейса">
    <?php foreach (['all'=>'Все рейсы','started'=>'В пути','completed'=>'Законченные'] as $tabStatus=>$tabLabel): ?>
      <a href="<?= e(app_url('/company/trips/gps-coverage'.($tabStatus==='all'?'':'?status='.$tabStatus))) ?>" class="gps-status-tab<?= $data['status']===$tabStatus?' is-active':'' ?>" <?= $data['status']===$tabStatus?'aria-current="page"':'' ?>><?= e($tabLabel) ?> <b><?= (int)$data['totals'][$tabStatus] ?></b></a>
    <?php endforeach; ?>
  </nav>
  <div class="table-card table-card--standard route-executors-card gps-card">
    <div class="table-toolbar"><div class="found-label">Найдено: <b data-visible data-total="<?= (int)$data['total'] ?>"><?= (int)$data['total'] ?></b></div>
      <div class="toolbar-right">
        <div class="toolbar-sort"><span class="toolbar-sort-label">Порог GPS:</span><select class="toolbar-select" aria-label="Порог свежести GPS" data-erp-grid-sort data-gps-threshold><option value="5">Порог 5 мин</option><option value="15" selected>Порог 15 мин</option><option value="30">Порог 30 мин</option><option value="60">Порог 60 мин</option></select></div>
        <input type="search" class="toolbar-search" data-gps-search placeholder="Рейс, водитель, госномер" aria-label="Поиск по рейсу, водителю и госномеру" autocomplete="off">
      </div>
    </div>
    <div class="table-scroll"><table class="table"><colgroup><col width="12%"><col width="18%"><col width="18%"><col width="13%"><col width="17%"><col width="22%"></colgroup>
      <thead><tr><th>Рейс</th><th>Описание рейса</th><th>Покрытие</th><th>Разрывы</th><th>Статус / период</th><th>Водитель / машина</th></tr></thead>
      <tbody>
      <?php foreach($data['rows'] as $row): $id=(int)$row['id']; ?>
        <tr data-gps-row data-id="<?= $id ?>" data-status="<?= e($row['status']) ?>" data-search="<?= e(mb_strtolower(implode(' ',[$id,$row['comment'],$row['driver'],$row['vehicle']]),'UTF-8')) ?>">
          <td><button type="button" class="btn btn-ghost gps-id" data-gps-expand aria-expanded="false" aria-controls="gps-detail-<?= $id ?>">№ <?= $id ?> ▾</button></td>
          <td><?= e($row['comment'] ?: '—') ?></td><td data-gps-coverage>Загрузка GPS…</td><td data-gps-gaps>—</td>
          <td><span class="route-status route-status--<?= $row['status']==='started'?'active':'neutral' ?>"><?= $row['status']==='started'?'В пути':'Завершён' ?></span><small class="route-cell-secondary"><?= e($row['actual_start_date'] ?: '—') ?> — <?= e($row['actual_end_date'] ?: 'сейчас') ?></small></td>
          <td class="route-person-cell"><strong><?= e($row['driver'] ?: '—') ?></strong><small class="route-cell-secondary"><?= e($row['vehicle'] ?: '—') ?></small></td>
        </tr><tr id="gps-detail-<?= $id ?>" class="gps-detail-row" hidden><td colspan="6"></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <div class="empty-state is-compact" data-gps-empty <?= $data['rows']?'hidden':'' ?>><p class="empty-title">Рейсы не найдены</p></div>
  </div>
  <?php if($pages>1): $query='?'.($data['status']==='all'?'':'status='.$data['status'].'&').'page='; ?><nav class="gps-pages" aria-label="Страницы рейсов"><?php if($data['page']>1): ?><a class="btn btn-ghost" href="<?= e(app_url('/company/trips/gps-coverage'.$query.($data['page']-1))) ?>">← Назад</a><?php endif; ?><span>Страница <?= (int)$data['page'] ?> из <?= $pages ?></span><?php if($data['page']<$pages): ?><a class="btn btn-ghost" href="<?= e(app_url('/company/trips/gps-coverage'.$query.($data['page']+1))) ?>">Далее →</a><?php endif; ?></nav><?php endif; ?>
  <?php endif; ?>
</div>
