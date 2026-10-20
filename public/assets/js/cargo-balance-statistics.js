(() => {
  'use strict';
  const root = document.querySelector('[data-cargo-balance]');
  if (!root) return;
  const payload = JSON.parse(document.getElementById('cargo-balance-data').textContent);
  const dailyRows = payload.daily;
  const formedTripCount = payload.forecast.formed_trip_count;
  const projectedNew = payload.forecast.projected_new;
  const today = payload.today, colors = {available:'#C07830',started:'#4A7A9B'};
  const labels = {week:'Недели',month:'Месяцы',year:'Год'};
  let mode = 'week';
  const fmt = n => new Intl.NumberFormat('ru-RU').format(n);
  const short = s => s.slice(8,10)+'.'+s.slice(5,7);
  const safe = s => String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const iso = d => d.toISOString().slice(0,10);
  const date = s => new Date(s+'T00:00:00Z');
  const addDays = (s,n) => {const d=date(s);d.setUTCDate(d.getUTCDate()+n);return iso(d)};
  function periods() {
    const result = [];
    const current = date(today);
    if (mode === 'week') {
      const monday = new Date(current);
      monday.setUTCDate(monday.getUTCDate() - ((monday.getUTCDay()+6)%7));
      for (let i=4;i>=0;i--) {
        const fromDate = new Date(monday);
        fromDate.setUTCDate(fromDate.getUTCDate()-7*i);
        const from=iso(fromDate), to=i===0?today:addDays(from,6);
        const thursday=new Date(fromDate);
        thursday.setUTCDate(thursday.getUTCDate()+3);
        const yearStart=new Date(Date.UTC(thursday.getUTCFullYear(),0,1));
        const weekNo=1+Math.floor((thursday-yearStart)/604800000);
        result.push({from,to,label:'Нед. '+weekNo+' · '+short(from)});
      }
      const nextFrom=addDays(iso(monday),7), nextTo=addDays(nextFrom,6);
      const nextThursday=date(addDays(nextFrom,3));
      const nextYearStart=new Date(Date.UTC(nextThursday.getUTCFullYear(),0,1));
      const nextWeekNo=1+Math.floor((nextThursday-nextYearStart)/604800000);
      result.push({from:nextFrom,to:nextTo,label:'Нед. '+nextWeekNo+' · '+short(nextFrom),forecast:true});
    } else if (mode === 'month') {
      for(let i=3;i>=0;i--) {
        const month = new Date(Date.UTC(current.getUTCFullYear(),current.getUTCMonth()-i,1));
        const from=iso(month), end=new Date(Date.UTC(month.getUTCFullYear(),month.getUTCMonth()+1,0));
        const to=i===0?today:iso(end);
        const name=month.toLocaleDateString('ru-RU',{month:'short',timeZone:'UTC'}).replace('.','');
        result.push({from,to,label:name.charAt(0).toUpperCase()+name.slice(1)+' '+month.getUTCFullYear()});
      }
    } else {
      for(let monthIndex=0;monthIndex<=current.getUTCMonth();monthIndex++) {
        const month=new Date(Date.UTC(current.getUTCFullYear(),monthIndex,1));
        const from=iso(month),end=new Date(Date.UTC(current.getUTCFullYear(),monthIndex+1,0));
        const to=monthIndex===current.getUTCMonth()?today:iso(end);
        const name=month.toLocaleDateString('ru-RU',{month:'short',timeZone:'UTC'}).replace('.','');
        result.push({from,to,label:name.charAt(0).toUpperCase()+name.slice(1)+' '+current.getUTCFullYear()});
      }
    }
    return result.filter(p=>p.to>=dailyRows[0].date);
  }
  function snapshot(at) {
    const row=dailyRows.filter(e=>e.date<=at).at(-1);
    return row ? {available:row.assigned,started:row.started} : {available:0,started:0};
  }
  function rows() {
    return periods().map(p=>{
      if (p.forecast) {
        const stock=snapshot(today);
        return {...p,received:0,withdrawn:0,started:projectedNew,
          available:stock.available,carried:stock.started+projectedNew};
      }
      const flow=dailyRows.filter(e=>e.date>=p.from&&e.date<=p.to)
        .reduce((sum,e)=>{sum.received+=e.received;sum.withdrawn+=e.withdrawn;sum.started+=e.started_new;return sum},
          {received:0,withdrawn:0,started:0});
      const stock=snapshot(p.to);
      return {...p,...flow,available:stock.available,carried:stock.started};
    });
  }
  const fixed = n => Number(n).toFixed(1);
  function curve(points) {
    let path='M'+fixed(points[0][0])+' '+fixed(points[0][1]);
    for(let i=0;i<points.length-1;i++) {
      const p=points[Math.max(0,i-1)],a=points[i],b=points[i+1],q=points[Math.min(points.length-1,i+2)],k=.82/6;
      const clamp=y=>Math.max(12,Math.min(143,y));
      path+=' C'+fixed(a[0]+(b[0]-p[0])*k)+' '+fixed(clamp(a[1]+(b[1]-p[1])*k))+
        ' '+fixed(b[0]-(q[0]-a[0])*k)+' '+fixed(clamp(b[1]-(q[1]-a[1])*k))+' '+fixed(b[0])+' '+fixed(b[1]);
    }
    return path;
  }
  function geometry() {
    return matchMedia('(max-width:760px)').matches
      ? {width:400,left:34,right:370,mobile:true}
      : {width:920,left:58,right:862,mobile:false};
  }
  function graph(data,g) {
    const max=Math.max(10,...data.flatMap(r=>[r.available,r.carried]))*1.12;
    const x=i=>data.length===1?(g.left+g.right)/2:g.left+(g.right-g.left)*i/(data.length-1), y=v=>143-131*v/max;
    const points={available:data.map((r,i)=>[x(i),y(r.available)]),started:data.map((r,i)=>[x(i),y(r.carried)])};
    let svg='<svg class="manager-stats__line-chart cargo-report__chart" viewBox="0 0 '+g.width+' 174" role="img" aria-label="Динамика двух состояний заявок"><defs>';
    for(const [key,c] of Object.entries(colors)) svg+='<linearGradient id="cargo-'+key+'-area" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="'+c+'" stop-opacity=".22"/><stop offset=".72" stop-color="'+c+'" stop-opacity=".05"/><stop offset="1" stop-color="'+c+'" stop-opacity="0"/></linearGradient>';
    svg+='</defs>';
    for(let i=0;i<=4;i++){const gy=12+131*i/4;svg+='<line class="grid-line" x1="'+g.left+'" x2="'+g.right+'" y1="'+fixed(gy)+'" y2="'+fixed(gy)+'"/><text class="axis-label" x="'+(g.left-8)+'" y="'+fixed(gy+3)+'" text-anchor="end">'+fmt(Math.round(max*(1-i/4)))+'</text>'}
    for(const [key,pts] of Object.entries(points)) {
      const actual=data.at(-1).forecast?pts.slice(0,-1):pts;
      svg+='<path class="cargo-report__area" d="'+curve(actual)+' L'+fixed(actual.at(-1)[0])+' 143 L'+g.left+' 143 Z" fill="url(#cargo-'+key+'-area)"/>';
    }
    for(const [key,pts] of Object.entries(points)) {
      const actual=data.at(-1).forecast?pts.slice(0,-1):pts;
      svg+='<path class="cargo-report__line" pathLength="1" d="'+curve(actual)+'" fill="none" stroke="'+colors[key]+'" stroke-width="'+(key==='available'?3:2.5)+'"/>';
      if(data.at(-1).forecast) {
        const a=actual.at(-1),b=pts.at(-1);
        svg+='<path class="cargo-report__forecast-line" d="M'+fixed(a[0])+' '+fixed(a[1])+' L'+fixed(b[0])+' '+fixed(b[1])+'" fill="none" stroke="'+colors[key]+'" stroke-width="'+(key==='available'?3:2.5)+'"/>';
      }
    }
    svg+='<line class="cargo-report__guide" x1="0" x2="0" y1="12" y2="143"/>';
    data.forEach((r,i)=>{
      for(const [key,pts] of Object.entries(points)) svg+='<circle class="cargo-report__node '+(r.forecast?'is-forecast':'')+'" data-period="'+i+'" cx="'+fixed(pts[i][0])+'" cy="'+fixed(pts[i][1])+'" r="4.5" fill="#fff" stroke="'+colors[key]+'" stroke-width="2" style="--series-color:'+colors[key]+'"/>';
      if((!g.mobile&&data.length<=8)||i%2===0||i===data.length-1) svg+='<text class="axis-label" x="'+fixed(x(i))+'" y="168" text-anchor="middle">'+safe(g.mobile?short(r.from):r.label)+'</text>';
      const l=i? (x(i-1)+x(i))/2:g.left, right=i===data.length-1?g.right:(x(i)+x(i+1))/2;
      svg+='<rect class="cargo-report__hit" data-period="'+i+'" x="'+fixed(l)+'" y="12" width="'+fixed(right-l)+'" height="131" fill="transparent" tabindex="0" role="button" aria-label="'+(r.forecast?'Прогноз. ':'')+safe(r.label)+': доступно '+r.available+', в работе '+r.carried+'"/>';
    });
    return svg+'</svg>';
  }
  function render() {
    const data=rows(),last=data.findLast(r=>!r.forecast),first=data[0],total=data.reduce((a,r)=>{for(const k of ['received','withdrawn','started'])a[k]+=r[k];return a},{received:0,withdrawn:0,started:0});
    const plan=data.find(r=>r.forecast);
    const planRow=plan?'<tr class="cargo-report__plan-row" data-period="'+(data.length-1)+'" tabindex="0"><th scope="row"><div class="manager-stats__period-label"><strong>План</strong><span>'+short(plan.from)+'–'+short(plan.to)+'</span><em>'+fmt(formedTripCount)+' рейсов</em></div></th><td class="num">—</td><td class="num">—</td><td class="num">'+fmt(plan.started)+'</td><td class="num is-available">'+fmt(plan.available)+'</td><td class="num is-started">'+fmt(plan.carried)+'</td><td class="num is-idle">'+fmt(plan.available-plan.carried)+'</td></tr>':'';
    const g=geometry();
    const kpis=[
      ['Доступно',last.available,'Все заявки в размещении',''],
      ['В работе',last.carried,'Каждая заявка учтена один раз','is-steel'],
      ['Передано за период',total.received,'Поступили в размещении','is-patina'],
      ['Отозвано за период',total.withdrawn,'Исключены из доступных','is-asphalt']
    ];
    root.innerHTML='<div class="page-head manager-stats__head"><div class="page-head-left"><h1 class="page-title">Забор и сдача груза</h1><div class="page-summary"><span>Сколько заявок в размещении, в работе и без движения.</span></div></div></div>'+
      '<div class="manager-stats__kpis">'+kpis.map(([label,value,note,kind])=>'<article class="manager-stats__kpi '+kind+'"><div class="manager-stats__kpi-label">'+label+'</div><div class="manager-stats__kpi-row"><strong>'+fmt(value)+'</strong></div><div class="manager-stats__kpi-note">'+note+'</div></article>').join('')+'</div>'+
      '<section class="manager-stats__card cargo-report__trend"><header class="manager-stats__card-head"><div><h2>Динамика заявок</h2><p>'+short(first.from)+'.'+first.from.slice(0,4)+' — '+short(data.at(-1).to)+'.'+data.at(-1).to.slice(0,4)+(data.at(-1).forecast?' · пунктир — прогноз при старте всех сформированных рейсов':' · остаток на конец периода')+'</p></div><div class="manager-stats__chart-controls"><div class="manager-stats__field manager-stats__field--mode"><span class="manager-stats__field-label">Режим отчёта</span><nav class="manager-stats__segments" role="tablist" aria-label="Режим отчёта">'+Object.entries(labels).map(([key,label])=>'<button type="button" role="tab" class="'+(key===mode?'is-active':'')+'" data-mode="'+key+'" aria-selected="'+(key===mode)+'">'+label+'</button>').join('')+'</nav></div></div></header>'+
      '<div class="manager-stats__chart-body">'+graph(data,g)+'<div class="cargo-report__popup" aria-hidden="true"></div><div class="manager-stats__legend"><span><i style="background:'+colors.available+'"></i><strong>Доступно</strong></span><span><i style="background:'+colors.started+'"></i><strong>В работе</strong></span>'+(data.at(-1).forecast?'<span><i class="cargo-report__forecast-key"></i><strong>Прогноз</strong></span>':'')+'</div></div></section>'+
      '<section class="manager-stats__card manager-stats__periods"><header class="manager-stats__card-head"><div><h2>Точные значения по периодам</h2><p>Остатки — на конец периода; поступление, отзыв и начало вывоза — за период.</p></div><span class="manager-stats__card-meta">Заявки, шт. <span class="cargo-report__scroll-hint">· таблица прокручивается вправо →</span></span></header>'+
      '<div class="table-scroll"><table class="table cargo-report__table"><thead><tr><th scope="col">Период</th><th scope="col" class="num">Передано</th><th scope="col" class="num">Отозвано</th><th scope="col" class="num">Начат вывоз</th><th scope="col" class="num">Доступно</th><th scope="col" class="num">В работе</th><th scope="col" class="num">Без движения</th></tr></thead><tbody>'+
      planRow+data.map((r,i)=>r.forecast?'':'<tr data-period="'+i+'" tabindex="0"><th scope="row"><div class="manager-stats__period-label"><strong>'+safe(r.label)+'</strong><span>'+short(r.from)+'–'+short(r.to)+'</span></div></th><td class="num">'+fmt(r.received)+'</td><td class="num">'+fmt(r.withdrawn)+'</td><td class="num">'+fmt(r.started)+'</td><td class="num is-available">'+fmt(r.available)+'</td><td class="num is-started">'+fmt(r.carried)+'</td><td class="num is-idle">'+fmt(r.available-r.carried)+'</td></tr>').reverse().join('')+'</tbody></table></div></section>';
    root.querySelectorAll('[data-mode]').forEach(el=>{
      el.addEventListener('click',()=>{mode=el.dataset.mode;render()});
      el.addEventListener('keydown',event=>{
        const order=Object.keys(labels),current=order.indexOf(el.dataset.mode);
        if(event.key==='ArrowRight'||event.key==='ArrowLeft') {
          event.preventDefault();
          mode=order[(current+(event.key==='ArrowRight'?1:order.length-1))%order.length];
          render();
          root.querySelector('[data-mode="'+mode+'"]').focus();
        }
      });
    });
    const guide=root.querySelector('.cargo-report__guide'), popup=root.querySelector('.cargo-report__popup'), chartEl=root.querySelector('.cargo-report__chart');
    const tableRows=[...root.querySelectorAll('tbody tr[data-period]')], nodes=[...root.querySelectorAll('.cargo-report__node')];
    function activate(index) {
      guide.classList.toggle('is-visible',index!==null);popup.classList.toggle('is-visible',index!==null);
      popup.setAttribute('aria-hidden',String(index===null));
      tableRows.forEach(el=>el.classList.toggle('is-period-active',Number(el.dataset.period)===index));
      nodes.forEach(el=>el.classList.toggle('is-period-active',Number(el.dataset.period)===index));
      if(index===null)return;
      const r=data[index],x=data.length===1?(g.left+g.right)/2:g.left+(g.right-g.left)*index/(data.length-1);
      guide.setAttribute('x1',x);guide.setAttribute('x2',x);
      popup.style.left='clamp(8px, calc('+(x/g.width*100)+'% - 270px), calc(100% - 268px))';
      popup.innerHTML='<strong>'+(r.forecast?'Прогноз · ':'')+safe(r.label)+' · '+short(r.from)+'–'+short(r.to)+'</strong>'+
        '<span style="color:'+colors.available+'">Доступно <b>'+fmt(r.available)+'</b></span>'+
        '<span style="color:'+colors.started+'">В работе <b>'+fmt(r.carried)+'</b></span>'+
        '<small>'+(r.forecast?'Если стартуют все '+fmt(formedTripCount)+' сформированных рейсов: +'+fmt(projectedNew)+' уникальных заявок.':'Передано +'+fmt(r.received)+' · Отозвано −'+fmt(r.withdrawn)+' · Начат вывоз '+fmt(r.started))+'</small>';
    }
    root.querySelectorAll('.cargo-report__hit').forEach(el=>{
      for(const type of ['pointerenter','pointermove','focus'])el.addEventListener(type,()=>activate(Number(el.dataset.period)));
      el.addEventListener('blur',()=>activate(null));
    });
    tableRows.forEach(el=>{el.addEventListener('pointerenter',()=>activate(Number(el.dataset.period)));el.addEventListener('pointerleave',()=>activate(null));el.addEventListener('focus',()=>activate(Number(el.dataset.period)));el.addEventListener('blur',()=>activate(null))});
    chartEl.addEventListener('pointerleave',()=>activate(null));
  }
  render();
  let mobile=geometry().mobile;
  window.addEventListener('resize',()=>{const next=geometry().mobile;if(next!==mobile){mobile=next;render()}});
})();
