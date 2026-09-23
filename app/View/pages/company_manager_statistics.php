<?php
$companyName = (string)($company['name'] ?? 'Компания');
$metric = (string)$filters['metric'];
$metricMeta = [
    'trips'=>['label'=>'Рейсы','long'=>'Рейсы поставлены на погрузку','color'=>'#C07830'],
    'requests'=>['label'=>'Заявки','long'=>'Заявки в начатых рейсах','color'=>'#4A7A9B'],
    'weight'=>['label'=>'Вес','long'=>'Вес заявок','color'=>'#3A8278'],
];
$modeLabels = ['weeks'=>'Недели','months'=>'Месяцы','custom'=>'Период'];
$groupLabels = ['day'=>'По дням','week'=>'По неделям','month'=>'По месяцам'];
$selectedManagers = array_fill_keys(array_map('intval', $filters['manager_ids']), true);
$fmtInt = static fn(mixed $value): string => number_format((int)$value, 0, ',', ' ');
$fmtWeight = static fn(mixed $value): string => number_format((float)$value / 1000, 2, ',', ' ').' т';
$metricValue = static fn(array $row, string $key): float => $key === 'weight' ? (float)($row[$key] ?? 0) / 1000 : (float)($row[$key] ?? 0);
$metricFormat = static fn(float $value, string $key): string => $key === 'weight'
    ? number_format($value, 2, ',', ' ').' т'
    : number_format($value, 0, ',', ' ');
$delta = static function(float $current, float $previous): array {
    if ($previous <= 0) return ['text'=>$current > 0 ? 'Новый результат' : 'Нет изменений','class'=>'is-neutral'];
    $value = ($current - $previous) / $previous * 100;
    return ['text'=>($value >= 0 ? '↑ ' : '↓ ').number_format(abs($value), 0, ',', ' ').'%','class'=>$value >= 0 ? 'is-up' : 'is-down'];
};
$queryFor = static function(array $changes): string {
    $query = $_GET;
    foreach ($changes as $key=>$value) {
        if ($value === null) unset($query[$key]); else $query[$key] = $value;
    }
    return app_url('/company/statistics/managers').'?'.http_build_query($query);
};
$chart = static function(array $current, array $previous, string $key, string $color, callable $valueOf, callable $format): array {
    $width=860.0;$height=270.0;$left=54.0;$right=18.0;$top=18.0;$bottom=42.0;
    $plotW=$width-$left-$right;$plotH=$height-$top-$bottom;
    $values=array_map(static fn(array $row):float=>$valueOf($row,$key),$current);
    $previousValues=array_map(static fn(array $row):float=>$valueOf($row,$key),$previous);
    $max=max([1.0,...$values,...$previousValues]);$max*=1.12;
    $points=static function(array $rows)use($left,$top,$plotW,$plotH,$max,$valueOf,$key):array{
        $count=max(1,count($rows));$result=[];
        foreach($rows as$i=>$row){$x=$left+($count===1?$plotW/2:$plotW*$i/($count-1));$v=$valueOf($row,$key);$y=$top+$plotH-($v/$max*$plotH);$result[]=['x'=>$x,'y'=>$y,'v'=>$v,'label'=>(string)$row['label']];}
        return$result;
    };
    $currentPoints=$points($current);$previousPoints=$points($previous);
    $path=static fn(array $pts):string=>implode(' ',array_map(static fn(array$p,int$i):string=>($i===0?'M':'L').number_format($p['x'],1,'.','').' '.number_format($p['y'],1,'.',''),$pts,array_keys($pts)));
    return compact('width','height','left','right','top','bottom','plotW','plotH','max','currentPoints','previousPoints','path','color','format');
};
$chartData = $chart($statistics['trend'], $statistics['previous_trend'], $metric, $metricMeta[$metric]['color'], $metricValue, $metricFormat);
$totalMetric = $metricValue($statistics['totals'], $metric);
$statusLabels=['in_progress'=>'Вывоз начался','completed'=>'Груз сдан','cancelled'=>'Отменён'];
$periodLabel = date('d.m.Y',strtotime((string)$filters['from'])).' — '.date('d.m.Y',strtotime((string)$filters['to']));
$kpis = [
    ['label'=>'Рейсы поставлены на погрузку','value'=>$fmtInt($statistics['totals']['trips']),'current'=>(float)$statistics['totals']['trips'],'previous'=>(float)$statistics['previous_totals']['trips'],'class'=>'is-copper','note'=>'Факт начала вывоза'],
    ['label'=>'Заявки в рейсах','value'=>$fmtInt($statistics['totals']['requests']),'current'=>(float)$statistics['totals']['requests'],'previous'=>(float)$statistics['previous_totals']['requests'],'class'=>'is-steel','note'=>'Состав зафиксирован при старте'],
    ['label'=>'Вес заявок','value'=>$fmtWeight($statistics['totals']['weight']),'current'=>(float)$statistics['totals']['weight'],'previous'=>(float)$statistics['previous_totals']['weight'],'class'=>'is-patina','note'=>'Точный источник — snapshot, кг'],
    ['label'=>'Менеджеры с результатом','value'=>$fmtInt($statistics['totals']['active_managers']),'current'=>(float)$statistics['totals']['active_managers'],'previous'=>(float)$statistics['previous_totals']['active_managers'],'class'=>'is-asphalt','note'=>'Из сотрудников компании'],
];
?>
<link rel="stylesheet" href="<?= app_url('/assets/css/manager-statistics.css') ?>?v=<?= filemtime(base_path('public/assets/css/manager-statistics.css')) ?>">
<style>.manager-stats__manager-list label[hidden]{display:none}</style>

<div class="manager-stats" data-manager-statistics>
    <div class="page-head manager-stats__head">
        <div class="page-head-left">
            <div class="page-eyebrow">Статистика · <?= e($companyName) ?></div>
            <h1 class="page-title">Работа менеджеров</h1>
            <div class="page-summary"><span>Результат компании и вклад каждого сотрудника по фактическому началу вывоза.</span></div>
        </div>
        <div class="page-head-actions">
            <a class="btn btn-secondary" href="<?= app_url('/company/trips/departures') ?>">Открыть реестр рейсов</a>
        </div>
    </div>

    <?php if ($dbError !== null): ?>
        <div class="notice warn"><?= e($dbError) ?></div>
    <?php else: ?>
        <?php if (($statistics['scope'] ?? 'none') === 'own'): ?>
            <div class="manager-stats__scope-note"><span class="manager-stats__scope-dot"></span><strong>Ограниченный набор данных.</strong> Показаны только рейсы, доступные вам в режиме «Только свои».</div>
        <?php elseif (($statistics['scope'] ?? 'none') === 'none'): ?>
            <div class="notice warn">Страница разрешена, но для рейсов установлена область данных «Нет доступа».</div>
        <?php endif; ?>

        <form class="manager-stats__filters" method="get" action="<?= app_url('/company/statistics/managers') ?>" data-stat-filters>
            <div class="manager-stats__filter-head">
                <div><div class="manager-stats__filter-title">Период и сотрудники</div><div class="manager-stats__filter-sub"><?= e($periodLabel) ?> · <?= e($groupLabels[$filters['group']]) ?></div></div>
                <a class="btn btn-ghost btn-sm" href="<?= app_url('/company/statistics/managers') ?>">Сбросить</a>
            </div>
            <div class="manager-stats__filter-body">
                <div class="manager-stats__field manager-stats__field--mode"><label>Режим отчёта</label><div class="manager-stats__segments">
                    <?php foreach($modeLabels as$value=>$label):?><label class="<?= $filters['mode']===$value?'is-active':'' ?>"><input type="radio" name="mode" value="<?=e($value)?>" <?=$filters['mode']===$value?'checked':''?>><span><?=e($label)?></span></label><?php endforeach;?>
                </div></div>
                <div class="manager-stats__custom-dates" data-custom-dates <?= $filters['mode']==='custom'?'':'hidden' ?>>
                    <div class="manager-stats__field"><label for="stats-from">С даты</label><input class="field-input" id="stats-from" type="date" name="from" value="<?=e((string)$filters['from'])?>"></div>
                    <div class="manager-stats__field"><label for="stats-to">По дату</label><input class="field-input" id="stats-to" type="date" name="to" value="<?=e((string)$filters['to'])?>"></div>
                    <div class="manager-stats__field"><label for="stats-group">Группировка</label><select class="field-select" id="stats-group" name="group"><?php foreach($groupLabels as$value=>$label):?><option value="<?=e($value)?>" <?=$filters['group']===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div>
                </div>
                <div class="manager-stats__field manager-stats__field--managers"><label>Менеджеры</label>
                    <details class="manager-stats__manager-picker" data-manager-picker>
                        <summary><span data-manager-summary><?= $selectedManagers===[]?'Все сотрудники':'Выбрано: '.count($selectedManagers) ?></span><span aria-hidden="true">⌄</span></summary>
                        <div class="manager-stats__manager-popover">
                            <input type="search" class="field-input" placeholder="Найти сотрудника" data-manager-search>
                            <div class="manager-stats__manager-actions"><button type="button" data-manager-all>Выбрать всех</button><button type="button" data-manager-clear>Снять выбор</button></div>
                            <div class="manager-stats__manager-list">
                                <?php foreach($eligibleManagers as$manager):$id=(int)$manager['membership_id'];?><label data-manager-row><input type="checkbox" name="manager_ids[]" value="<?=$id?>" <?=isset($selectedManagers[$id])?'checked':''?>><span><?=e((string)$manager['full_name'])?></span></label><?php endforeach;?>
                            </div>
                        </div>
                    </details>
                </div>
                <input type="hidden" name="metric" value="<?=e($metric)?>">
                <div class="manager-stats__field manager-stats__field--compare"><label>Сравнение</label><label class="manager-stats__check"><input type="hidden" name="compare" value="0"><input type="checkbox" name="compare" value="1" <?=$filters['compare']?'checked':''?>><span>С прошлым периодом</span></label></div>
                <div class="manager-stats__filter-action"><button class="btn btn-primary" type="submit">Применить</button></div>
            </div>
        </form>

        <div class="manager-stats__kpis">
            <?php foreach($kpis as$kpi):$d=$delta($kpi['current'],$kpi['previous']);?><article class="manager-stats__kpi <?=$kpi['class']?>"><div class="manager-stats__kpi-label"><?=e($kpi['label'])?></div><div class="manager-stats__kpi-row"><strong><?=e($kpi['value'])?></strong><?php if($filters['compare']):?><span class="manager-stats__delta <?=$d['class']?>"><?=e($d['text'])?></span><?php endif;?></div><div class="manager-stats__kpi-note"><?=e($kpi['note'])?></div></article><?php endforeach;?>
        </div>

        <div class="manager-stats__charts">
            <section class="manager-stats__card manager-stats__trend">
                <header class="manager-stats__card-head"><div><h2>Динамика работы компании</h2><p><?=e($periodLabel)?> · <?=e($metricMeta[$metric]['long'])?></p></div><nav class="manager-stats__metric-tabs" aria-label="Показатель графиков"><?php foreach($metricMeta as$key=>$meta):?><a class="<?=$metric===$key?'is-active':''?>" href="<?=e($queryFor(['metric'=>$key]))?>"><?=e($meta['label'])?></a><?php endforeach;?></nav></header>
                <div class="manager-stats__chart-body">
                    <?php if($statistics['trend']===[]):?><div class="manager-stats__empty">Нет данных для построения графика.</div><?php else:?>
                    <svg class="manager-stats__line-chart" viewBox="0 0 <?=$chartData['width']?> <?=$chartData['height']?>" role="img" aria-label="Динамика показателя <?=e($metricMeta[$metric]['label'])?>">
                        <?php for($i=0;$i<=4;$i++):$y=$chartData['top']+$chartData['plotH']*$i/4;$v=$chartData['max']*(1-$i/4);?><line x1="<?=$chartData['left']?>" y1="<?=$y?>" x2="<?=$chartData['left']+$chartData['plotW']?>" y2="<?=$y?>" class="grid-line"/><text x="<?=$chartData['left']-8?>" y="<?=$y+4?>" text-anchor="end" class="axis-label"><?=e($metricFormat($v,$metric))?></text><?php endfor;?>
                        <?php if($filters['compare']&&$chartData['previousPoints']!==[]):?><path d="<?=$chartData['path']($chartData['previousPoints'])?>" class="previous-line"/><?php endif;?>
                        <?php if($chartData['currentPoints']!==[]):$area=$chartData['path']($chartData['currentPoints']).' L'.$chartData['currentPoints'][count($chartData['currentPoints'])-1]['x'].' '.($chartData['top']+$chartData['plotH']).' L'.$chartData['currentPoints'][0]['x'].' '.($chartData['top']+$chartData['plotH']).' Z';?><path d="<?=$area?>" fill="<?=$chartData['color']?>" opacity=".10"/><path d="<?=$chartData['path']($chartData['currentPoints'])?>" fill="none" stroke="<?=$chartData['color']?>" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/><?php endif;?>
                        <?php foreach($chartData['currentPoints'] as$i=>$point):?><circle cx="<?=$point['x']?>" cy="<?=$point['y']?>" r="4" fill="var(--surface-strong)" stroke="<?=$chartData['color']?>" stroke-width="2"><title><?=e($point['label'].' — '.$metricFormat($point['v'],$metric))?></title></circle><?php $step=max(1,(int)ceil(count($chartData['currentPoints'])/12));if($i%$step===0||$i===count($chartData['currentPoints'])-1):?><text x="<?=$point['x']?>" y="<?=$chartData['height']-12?>" text-anchor="middle" class="axis-label"><?=e($point['label'])?></text><?php endif;endforeach;?>
                    </svg>
                    <div class="manager-stats__legend"><span><i style="background:<?=$metricMeta[$metric]['color']?>"></i>Текущий период</span><?php if($filters['compare']):?><span><i class="is-previous"></i>Предыдущий период</span><?php endif;?></div>
                    <?php endif;?>
                </div>
            </section>

            <section class="manager-stats__card manager-stats__ranking">
                <header class="manager-stats__card-head"><div><h2>Рейтинг менеджеров</h2><p><?=e($metricMeta[$metric]['long'])?></p></div><span class="manager-stats__card-meta"><?=count($statistics['managers'])?> сотрудников</span></header>
                <div class="manager-stats__rank-list">
                    <?php $rankMax=max(1.0,...array_map(static fn(array$row):float=>$metricValue($row,$metric),$statistics['managers']));foreach(array_slice($statistics['managers'],0,10)as$index=>$row):$value=$metricValue($row,$metric);$width=$value/$rankMax*100;?><a class="manager-stats__rank" href="<?=e($queryFor(['manager_ids'=>[(int)$row['membership_id']]]))?>"><div class="manager-stats__rank-line"><span><b><?=$index+1?></b><?=e($row['name'])?></span><strong><?=e($metricFormat($value,$metric))?></strong></div><div class="manager-stats__rank-track"><i style="width:<?=number_format($width,2,'.','')?>%;background:<?=$metricMeta[$metric]['color']?>"></i></div></a><?php endforeach;?>
                    <?php if($statistics['managers']===[]):?><div class="manager-stats__empty">Нет сотрудников для отображения.</div><?php endif;?>
                </div>
            </section>
        </div>

        <section class="manager-stats__card manager-stats__table-card">
            <header class="manager-stats__card-head"><div><h2>Работа каждого менеджера</h2><p>Итоги сотрудников должны сходиться с общим результатом компании.</p></div><span class="manager-stats__card-meta">Сортировка: <?=e(mb_strtolower($metricMeta[$metric]['label'],'UTF-8'))?></span></header>
            <div class="table-scroll"><table class="table manager-stats__table"><thead><tr><th>Менеджер</th><th class="num">Рейсы</th><th class="num">Заявки</th><th class="num">Вес</th><th class="num">Заявок / рейс</th><th class="num">Вес / рейс</th><th class="num">Доля компании</th><th class="num">Завершено</th><th class="num">Отменено</th></tr></thead><tbody>
                <?php foreach($statistics['managers']as$row):$share=$totalMetric>0?$metricValue($row,$metric)/$totalMetric*100:0;?><tr><td><a class="manager-stats__manager-link" href="<?=e($queryFor(['manager_ids'=>[(int)$row['membership_id']]]))?>"><strong><?=e($row['name'])?></strong><?php if(!$row['is_active']):?><span>Неактивный сотрудник</span><?php endif;?></a></td><td class="num"><?=$fmtInt($row['trips'])?></td><td class="num"><?=$fmtInt($row['requests'])?></td><td class="num"><?=$fmtWeight($row['weight'])?></td><td class="num"><?=number_format((float)$row['requests_per_trip'],1,',',' ')?></td><td class="num"><?=$fmtWeight($row['weight_per_trip'])?></td><td class="num"><span class="manager-stats__share"><?=number_format($share,1,',',' ')?>%</span></td><td class="num"><?=$fmtInt($row['completed'])?></td><td class="num <?=$row['cancelled']>0?'is-danger':''?>"><?=$fmtInt($row['cancelled'])?></td></tr><?php endforeach;?>
                <?php if($statistics['managers']===[]):?><tr><td colspan="9" class="manager-stats__empty">Сотрудники не найдены.</td></tr><?php endif;?>
            </tbody><tfoot><tr><th>Компания · <?=e($companyName)?></th><th class="num"><?=$fmtInt($statistics['totals']['trips'])?></th><th class="num"><?=$fmtInt($statistics['totals']['requests'])?></th><th class="num"><?=$fmtWeight($statistics['totals']['weight'])?></th><th colspan="3"></th><th class="num"><?=$fmtInt($statistics['totals']['completed'])?></th><th class="num"><?=$fmtInt($statistics['totals']['cancelled'])?></th></tr></tfoot></table></div>
        </section>

        <section class="manager-stats__card manager-stats__details">
            <header class="manager-stats__card-head"><div><h2>Из чего сложился результат</h2><p>Каждая строка ведёт к исходному рейсу компании.</p></div><span class="manager-stats__card-meta">Рейсов: <?=count($statistics['details'])?><?=count($statistics['details'])===500?' · достигнут лимит':''?></span></header>
            <div class="table-scroll"><table class="table manager-stats__detail-table"><thead><tr><th>Дата результата</th><th>Менеджер</th><th>Рейс</th><th>Текущий статус</th><th class="num">Заявки</th><th class="num">Вес</th><th></th></tr></thead><tbody>
                <?php foreach($statistics['details']as$row):?><tr><td><?=e(date('d.m.Y',strtotime((string)$row['actual_start_date'])))?></td><td><?=e((string)$row['responsible_name_snapshot'])?></td><td><strong>#<?=(int)$row['business_id']?></strong></td><td><span class="badge <?=($row['status']==='cancelled'?'badge-danger':($row['status']==='completed'?'badge-ok':'badge-warning'))?>"><?=e($statusLabels[$row['status']]??(string)$row['status'])?></span></td><td class="num"><?=$fmtInt($row['request_count'])?></td><td class="num"><?=$fmtWeight($row['total_mass_kg'])?></td><td class="num"><a class="manager-stats__open" href="<?=app_url('/company/trips/departures?open_trip='.(int)$row['id'])?>">Открыть →</a></td></tr><?php endforeach;?>
                <?php if($statistics['details']===[]):?><tr><td colspan="7" class="manager-stats__empty"><strong>За выбранный период машины на погрузку не ставились.</strong><span>Измените период или сбросьте фильтр менеджеров.</span></td></tr><?php endif;?>
            </tbody></table></div>
        </section>
    <?php endif; ?>
</div>
<script src="<?= app_url('/assets/js/manager-statistics.js') ?>?v=<?= filemtime(base_path('public/assets/js/manager-statistics.js')) ?>"></script>
