(() => {
  const page=document.querySelector('[data-gps-page]');if(!page)return;
  const rows=[...page.querySelectorAll('[data-gps-row]')];
  const threshold=page.querySelector('[data-gps-threshold]'),search=page.querySelector('[data-gps-search]');
  const cache=new Map();let generation=0;
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const duration=s=>{s=Math.max(0,Math.round(s||0));const d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60);return [d?d+' д':'',h?h+' ч':'',m?m+' мин':''].filter(Boolean).join(' ')||'<1 мин';};
  const msk=v=>{if(!v)return '—';const d=new Date(v.replace(' ','T')+'Z');return new Intl.DateTimeFormat('ru-RU',{timeZone:'Europe/Moscow',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}).format(d);};
  const reason={missing_dates:'Нет дат рейса',outside_log_window:'Нет полного GPS-архива за период',unmatched:'Трекер по водителю и госномеру не найден',ambiguous:'Найдено несколько подходящих трекеров',long_interval:'Период длиннее 90 дней',too_many_rows:'Слишком много записей GPS за период',unknown:'Нельзя оценить'};
  function paint(row,result){
    const coverage=row.querySelector('[data-gps-coverage]'),gaps=row.querySelector('[data-gps-gaps]');
    if(result.assessment!=='calculated'){coverage.innerHTML='<span>Нельзя оценить</span><small class="route-cell-secondary">'+esc(reason[result.assessment]||reason.unknown)+'</small>';gaps.textContent='—';return;}
    coverage.innerHTML='<b>'+result.pct.toLocaleString('ru-RU')+'%</b><div class="gps-progress" aria-hidden="true"><i style="width:'+result.pct+'%"></i></div><small class="route-cell-secondary">'+duration(result.covered_seconds)+' из '+duration(result.total_seconds)+'</small>';
    gaps.innerHTML='<b>'+result.gaps.length+'</b><small class="route-cell-secondary">макс. '+duration(Math.max(0,...result.gaps.map(g=>g.seconds)))+'</small>';
    const detail=document.getElementById('gps-detail-'+row.dataset.id);if(!detail.hidden)paintDetail(row,result);
  }
  function paintDetail(row,result){
    const cell=document.getElementById('gps-detail-'+row.dataset.id).firstElementChild;
    if(result.assessment!=='calculated'){cell.innerHTML='<div class="gps-detail"><strong>Нельзя оценить</strong><p>'+esc(reason[result.assessment]||reason.unknown)+'. Это не означает нулевое покрытие.</p></div>';return;}
    const start=Date.parse(result.start_utc.replace(' ','T')+'Z')/1000;
    const parts=[...result.segments.map(s=>({a:s[0],b:s[1],kind:'covered'})),...result.gaps.map(g=>({a:g.start_utc,b:g.end_utc,kind:g.kind==='internal'?'gap':'edge'}))].sort((a,b)=>a.a.localeCompare(b.a));
    const timeline=parts.map(p=>{const seconds=(Date.parse(p.b.replace(' ','T')+'Z')-Date.parse(p.a.replace(' ','T')+'Z'))/1000;return '<span class="'+p.kind+'" style="flex:0 0 '+(100*seconds/result.total_seconds)+'%" title="'+msk(p.a)+' — '+msk(p.b)+'"></span>';}).join('');
    const gaps=result.gaps.slice().sort((a,b)=>b.seconds-a.seconds).map(g=>'<div class="gps-gap"><span>'+msk(g.start_utc)+' — '+msk(g.end_utc)+' <em>'+({leading:'до первой точки',trailing:'после последней',internal:'внутри рейса'}[g.kind]||'')+'</em></span><b>'+duration(g.seconds)+'</b></div>').join('')||'<div class="gps-gap">Разрывов нет</div>';
    cell.innerHTML='<div class="gps-detail"><div><h2>Шкала рейса № '+esc(row.dataset.id)+'</h2><div class="gps-timeline" role="img" aria-label="Покрытие '+result.pct+' процентов; разрывов '+result.gaps.length+'">'+timeline+'</div><div class="gps-legend"><span><i class="covered"></i>Свежая GPS-точка</span><span><i class="gap"></i>Разрыв</span><span><i class="edge"></i>Граница без точки</span></div><p>Порог '+esc(threshold.value)+' мин. Точек в рейсе: '+result.point_count+'. Последняя точка: '+msk(result.last_point_utc)+' МСК. Период: '+msk(result.start_utc)+' — '+msk(result.end_utc)+' МСК.</p></div><div><h2>Разрывы · '+result.gaps.length+'</h2><div class="gps-gap-list">'+gaps+'</div></div></div>';
  }
  async function load(row,run){
    const key=row.dataset.id+'|'+threshold.value;if(cache.has(key)){paint(row,cache.get(key));return;}
    row.querySelector('[data-gps-coverage]').textContent='Загрузка GPS…';
    try{const response=await fetch(page.dataset.base+'/'+row.dataset.id+'/coverage?minutes='+threshold.value,{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw Error('HTTP '+response.status);const data=await response.json();cache.set(key,data);if(run===generation)paint(row,data);}
    catch(e){if(run===generation)row.querySelector('[data-gps-coverage]').textContent='GPS временно недоступен';}
  }
  async function refresh(){const run=++generation;const q=search.value.trim().toLocaleLowerCase('ru');let visible=0;for(const row of rows){const show=!q||row.dataset.search.includes(q);row.hidden=!show;document.getElementById('gps-detail-'+row.dataset.id).hidden=!show||document.getElementById('gps-detail-'+row.dataset.id).hidden;if(show)visible++;}if(q)page.querySelector('[data-visible]').textContent=visible;else page.querySelector('[data-visible]').textContent=page.querySelector('[data-visible]').dataset.total;page.querySelector('[data-gps-empty]').hidden=visible>0;const selected=rows.filter(r=>!r.hidden);for(let i=0;i<selected.length;i+=2)await Promise.all(selected.slice(i,i+2).map(row=>load(row,run)));}
  rows.forEach(row=>row.querySelector('[data-gps-expand]').addEventListener('click',()=>{const detail=document.getElementById('gps-detail-'+row.dataset.id);const open=detail.hidden;page.querySelectorAll('.gps-detail-row').forEach(r=>r.hidden=true);page.querySelectorAll('[data-gps-expand]').forEach(b=>b.setAttribute('aria-expanded','false'));if(!open)return;detail.hidden=false;row.querySelector('[data-gps-expand]').setAttribute('aria-expanded','true');const data=cache.get(row.dataset.id+'|'+threshold.value);if(data)paintDetail(row,data);else detail.firstElementChild.textContent='Загрузка GPS…';}));
  threshold.addEventListener('change',refresh);search.addEventListener('input',refresh);
  refresh();
})();
