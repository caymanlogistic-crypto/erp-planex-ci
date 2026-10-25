<?php
$metric = (string)$filters['metric'];
$personalMode = (bool)($personalMode ?? false);
$reportRoute = $personalMode ? '/company/statistics/work' : '/company/statistics/managers';
$metricMeta = [
    'trips'=>['label'=>'Рейсы','long'=>'Рейсы поставлены на погрузку','color'=>'#C07830'],
    'requests'=>['label'=>'Заявки','long'=>'Заявки в начатых рейсах','color'=>'#4A7A9B'],
    'weight'=>['label'=>'Вес','long'=>'Вес заявок','color'=>'#3A8278'],
];
$companyChartColor = '#C07830';
$modeLabels = ['week'=>'Недели','month'=>'Месяцы','year'=>'Год'];
$fmtInt = static fn(mixed $value): string => number_format((int)$value, 0, ',', ' ');
$fmtWeight = static fn(mixed $value): string => number_format((float)$value / 1000, 2, ',', ' ').' т';
$metricValue = static fn(array $row, string $key): float => $key === 'weight' ? (float)($row[$key] ?? 0) / 1000 : (float)($row[$key] ?? 0);
$metricFormat = static fn(float $value, string $key): string => $key === 'weight'
    ? number_format($value, 2, ',', ' ').' т'
    : number_format($value, 0, ',', ' ');
$queryFor = static function(array $changes) use ($filters, $metric, $reportRoute): string {
    $query = ['mode'=>(string)$filters['mode'],'metric'=>$metric];
    if (!empty($filters['include_warehouse_loads'])) $query['warehouse'] = '1';
    foreach ($changes as $key=>$value) {
        if ($value === null) unset($query[$key]); else $query[$key] = $value;
    }
    return app_url($reportRoute).'?'.http_build_query($query);
};
$chart = static function(array $current, string $key, callable $valueOf): array {
    $width=920.0;$height=171.6;$left=58.0;$right=58.0;$top=12.0;$bottom=27.6;
    $plotW=$width-$left-$right;$plotH=$height-$top-$bottom;
    $values=array_map(static fn(array $row):float=>$valueOf($row,$key),$current);
    $max=max([1.0,...$values])*1.12;
    $points=static function(array $rows)use($left,$top,$plotW,$plotH,$max,$valueOf,$key):array{
        $count=max(1,count($rows));$result=[];
        foreach($rows as$i=>$row){$x=$left+($count===1?$plotW/2:$plotW*$i/($count-1));$v=$valueOf($row,$key);$y=$top+$plotH-($v/$max*$plotH);$result[]=['x'=>$x,'y'=>$y,'v'=>$v,'label'=>(string)$row['label']];}
        return$result;
    };
    $currentPoints=$points($current);
    $number=static fn(float $value):string=>number_format($value,1,'.','');
    $path=static function(array $pts)use($number,$top,$plotH):string{
        $count=count($pts);
        if($count===0)return'';
        if($count===1)return'M'.$number((float)$pts[0]['x']).' '.$number((float)$pts[0]['y']);
        $bottom=$top+$plotH;$smooth=.82;
        $result='M'.$number((float)$pts[0]['x']).' '.$number((float)$pts[0]['y']);
        for($i=0;$i<$count-1;$i++){
            $previous=$pts[max(0,$i-1)];$current=$pts[$i];$next=$pts[$i+1];$after=$pts[min($count-1,$i+2)];
            $cp1x=(float)$current['x']+(((float)$next['x']-(float)$previous['x'])/6*$smooth);
            $cp1y=max($top,min($bottom,(float)$current['y']+(((float)$next['y']-(float)$previous['y'])/6*$smooth)));
            $cp2x=(float)$next['x']-(((float)$after['x']-(float)$current['x'])/6*$smooth);
            $cp2y=max($top,min($bottom,(float)$next['y']-(((float)$after['y']-(float)$current['y'])/6*$smooth)));
            $result.=' C'.$number($cp1x).' '.$number($cp1y).' '.$number($cp2x).' '.$number($cp2y).' '.$number((float)$next['x']).' '.$number((float)$next['y']);
        }
        return$result;
    };
    return compact('width','height','left','right','top','bottom','plotW','plotH','max','currentPoints','points','path');
};
$chartData = $chart($statistics['trend'], $metric, $metricValue);
$lineColors = ['#4A7A9B','#6A8A50','#B89228','#6D5B8C','#5C7A88','#8A6A44','#7A6478'];
$managerChartSeries = [];
foreach ($statistics['manager_trends'] as $index=>$series) {
    $seriesColor = $lineColors[$index % count($lineColors)];
    $managerChartSeries[] = $series + ['color'=>$seriesColor,'points'=>$chartData['points']($series['values'])];
}
$visiblePeriodRows = array_values(array_filter(
    array_reverse($statistics['period_rows']),
    static fn(array $row): bool => (int)($row['trips'] ?? 0) > 0 || (int)($row['requests'] ?? 0) > 0 || (float)($row['weight'] ?? 0) > 0
));
$periodLabel = date('d.m.Y',strtotime((string)$filters['from'])).' — '.date('d.m.Y',strtotime((string)$filters['to']));
$tableMeasures = ['trips'=>'Рейсы','requests'=>'Заявки','weight'=>'Вес, т'];
$managerTotals = [];
foreach ($statistics['managers'] as $row) $managerTotals[(int)$row['membership_id']] = $row;
$kpis = [
    ['label'=>'Рейсы поставлены на погрузку','value'=>$fmtInt($statistics['totals']['trips']),'current'=>(float)$statistics['totals']['trips'],'previous'=>(float)$statistics['previous_totals']['trips'],'class'=>'is-copper','note'=>'Факт начала вывоза'],
    ['label'=>'Заявки в рейсах','value'=>$fmtInt($statistics['totals']['requests']),'current'=>(float)$statistics['totals']['requests'],'previous'=>(float)$statistics['previous_totals']['requests'],'class'=>'is-steel','note'=>'Состав рейса на момент старта'],
    ['label'=>'Вес заявок','value'=>$fmtWeight($statistics['totals']['weight']),'current'=>(float)$statistics['totals']['weight'],'previous'=>(float)$statistics['previous_totals']['weight'],'class'=>'is-patina','note'=>'Вес по зафиксированному составу'],
    ['label'=>'Логисты с результатом','value'=>$fmtInt($statistics['totals']['active_managers']),'current'=>(float)$statistics['totals']['active_managers'],'previous'=>(float)$statistics['previous_totals']['active_managers'],'class'=>'is-asphalt','note'=>'Только активная роль «Логист»'],
];
if ($personalMode) {
    $myTrips = (int)($statistics['managers'][0]['trips'] ?? 0);
    $kpis[3] = ['label'=>'Мои рейсы','value'=>$fmtInt($myTrips),'current'=>(float)$myTrips,
        'previous'=>0.0,'class'=>'is-asphalt','note'=>'С 38-й недели'];
}
?>
<div class="manager-stats" data-manager-statistics>
    <div class="page-head manager-stats__head">
        <div class="page-head-left">
            <h1 class="page-title"><?= $personalMode ? 'Статистика работы' : 'Работа логистов' ?></h1>
            <div class="page-summary"><span><?= $personalMode ? 'Мои показатели и общий результат компании по фактическому началу вывоза.' : 'Динамика компании и вклад каждого логиста по фактическому началу вывоза.' ?></span></div>
        </div>
    </div>

    <?php if ($dbError !== null): ?>
        <div class="notice warn"><?= e($dbError) ?></div>
    <?php else: ?>
        <div class="manager-stats__kpis">
            <?php foreach($kpis as$kpi):?><article class="manager-stats__kpi <?=$kpi['class']?>"><div class="manager-stats__kpi-label"><?=e($kpi['label'])?></div><div class="manager-stats__kpi-row"><strong><?=e($kpi['value'])?></strong></div><div class="manager-stats__kpi-note"><?=e($kpi['note'])?></div></article><?php endforeach;?>
        </div>

        <div class="manager-stats__charts">
            <section class="manager-stats__card manager-stats__trend">
                <header class="manager-stats__card-head"><div><h2><?= $personalMode ? 'Динамика компании и моя' : 'Динамика компании и логистов' ?></h2><p><?=e($periodLabel)?> · <?=e($metricMeta[$metric]['long'])?></p></div><div class="manager-stats__chart-controls"><div class="manager-stats__field manager-stats__field--mode manager-stats__warehouse-field erp-segmented-field--compact"><span class="manager-stats__field-label">Загрузки со склада</span><nav class="manager-stats__segments erp-segments--equal" aria-label="Загрузки со склада"><a class="<?=!empty($filters['include_warehouse_loads'])?'is-active':''?>" href="<?=e($queryFor(['warehouse'=>'1']))?>" <?=!empty($filters['include_warehouse_loads'])?'aria-current="true"':''?>>Да</a><a class="<?=empty($filters['include_warehouse_loads'])?'is-active':''?>" href="<?=e($queryFor(['warehouse'=>null]))?>" <?=empty($filters['include_warehouse_loads'])?'aria-current="true"':''?>>Нет</a></nav></div><div class="manager-stats__field manager-stats__field--mode"><span class="manager-stats__field-label">Режим отчёта</span><nav class="manager-stats__segments" aria-label="Режим отчёта"><?php foreach($modeLabels as$value=>$label):?><a class="<?=$filters['mode']===$value?'is-active':''?>" href="<?=e($queryFor(['mode'=>$value]))?>"><?=e($label)?></a><?php endforeach;?></nav></div><nav class="manager-stats__metric-tabs" aria-label="Показатель графиков"><?php foreach($metricMeta as$key=>$meta):?><a class="<?=$metric===$key?'is-active':''?>" href="<?=e($queryFor(['metric'=>$key]))?>"><?=e($meta['label'])?></a><?php endforeach;?></nav></div></header>
                <div class="manager-stats__chart-body">
                    <?php if($statistics['trend']===[]):?><div class="manager-stats__empty">Нет данных для построения графика.</div><?php else:?>
                    <svg class="manager-stats__line-chart" viewBox="0 0 <?=$chartData['width']?> <?=$chartData['height']?>" role="img" aria-label="Динамика показателя <?=e($metricMeta[$metric]['label'])?>">
                        <defs>
                            <linearGradient id="manager-stats-company-area" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="<?=$companyChartColor?>" stop-opacity=".22"/><stop offset=".72" stop-color="<?=$companyChartColor?>" stop-opacity=".05"/><stop offset="1" stop-color="<?=$companyChartColor?>" stop-opacity="0"/></linearGradient>
                            <?php foreach($managerChartSeries as$seriesIndex=>$series):?><linearGradient id="manager-stats-manager-area-<?=$seriesIndex?>" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="<?=$series['color']?>" stop-opacity=".22"/><stop offset=".72" stop-color="<?=$series['color']?>" stop-opacity=".05"/><stop offset="1" stop-color="<?=$series['color']?>" stop-opacity="0"/></linearGradient><?php endforeach;?>
                        </defs>
                        <?php for($i=0;$i<=4;$i++):$y=$chartData['top']+$chartData['plotH']*$i/4;$v=$chartData['max']*(1-$i/4);?><line x1="<?=$chartData['left']?>" y1="<?=$y?>" x2="<?=$chartData['left']+$chartData['plotW']?>" y2="<?=$y?>" class="grid-line"/><text x="<?=$chartData['left']-8?>" y="<?=$y+4?>" text-anchor="end" class="axis-label"><?=e($metricFormat($v,$metric))?></text><?php endfor;?>
                        <?php if($chartData['currentPoints']!==[]):$area=$chartData['path']($chartData['currentPoints']).' L'.$chartData['currentPoints'][count($chartData['currentPoints'])-1]['x'].' '.($chartData['top']+$chartData['plotH']).' L'.$chartData['currentPoints'][0]['x'].' '.($chartData['top']+$chartData['plotH']).' Z';?><path class="manager-stats__chart-area" d="<?=$area?>" fill="url(#manager-stats-company-area)"/><?php endif;?>
                        <?php foreach($managerChartSeries as$seriesIndex=>$series):if($series['points']===[])continue;$first=$series['points'][0];$last=$series['points'][count($series['points'])-1];$area=$chartData['path']($series['points']).' L'.$last['x'].' '.($chartData['top']+$chartData['plotH']).' L'.$first['x'].' '.($chartData['top']+$chartData['plotH']).' Z';?><path class="manager-stats__chart-area manager-stats__chart-area--manager" d="<?=$area?>" fill="url(#manager-stats-manager-area-<?=$seriesIndex?>)" aria-hidden="true"/><?php endforeach;?>
                        <?php foreach($managerChartSeries as$seriesIndex=>$series):?><path class="manager-stats__chart-line is-manager" data-series="manager" data-series-name="<?=e($series['name'])?>" pathLength="1" d="<?=$chartData['path']($series['points'])?>" fill="none" stroke="<?=$series['color']?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="--series-index:<?=$seriesIndex+1?>"/><?php endforeach;?>
                        <?php if($chartData['currentPoints']!==[]):?><path class="manager-stats__chart-line is-company" data-series="company" pathLength="1" d="<?=$chartData['path']($chartData['currentPoints'])?>" fill="none" stroke="<?=$companyChartColor?>" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="--series-index:0"/><?php endif;?>
                        <?php foreach($managerChartSeries as$seriesIndex=>$series):foreach($series['points']as$pointIndex=>$point):$nodeLabel=$series['name'].' · '.$point['label'].' — '.$metricFormat($point['v'],$metric);?><g class="manager-stats__chart-node is-manager" data-series-name="<?=e($series['name'])?>" data-period-label="<?=e($point['label'])?>" data-node-index="<?=$pointIndex?>" tabindex="0" role="img" aria-label="<?=e($nodeLabel)?>" transform="translate(<?=$point['x']?> <?=$point['y']?>)" style="--series-index:<?=$seriesIndex+1?>;--node-index:<?=$pointIndex?>;--node-color:<?=$series['color']?>"><circle r="3.5"/></g><?php endforeach;endforeach;?>
                        <?php foreach($chartData['currentPoints'] as$i=>$point):$nodeLabel='Компания · '.$point['label'].' — '.$metricFormat($point['v'],$metric);?><g class="manager-stats__chart-node is-company" data-series-name="Компания" data-period-label="<?=e($point['label'])?>" data-node-index="<?=$i?>" tabindex="0" role="img" aria-label="<?=e($nodeLabel)?>" transform="translate(<?=$point['x']?> <?=$point['y']?>)" style="--series-index:0;--node-index:<?=$i?>;--node-color:<?=$companyChartColor?>"><circle r="4"/></g><?php $step=max(1,(int)ceil(count($chartData['currentPoints'])/12));if($i%$step===0||$i===count($chartData['currentPoints'])-1):?><text x="<?=$point['x']?>" y="<?=$chartData['height']-7.2?>" text-anchor="middle" class="axis-label"><?=e($point['label'])?></text><?php endif;endforeach;?>
                    </svg>
                    <div class="manager-stats__legend"><span><i style="background:<?=$companyChartColor?>"></i><strong>Компания</strong></span><?php foreach($managerChartSeries as$series):?><span><i style="background:<?=$series['color']?>"></i><?=e($series['name'])?></span><?php endforeach;?></div>
                    <?php endif;?>
                </div>
            </section>

        </div>

        <section class="manager-stats__card manager-stats__periods">
            <header class="manager-stats__card-head"><div><h2>Точные значения по периодам</h2><p><?= $personalMode ? 'Каждая строка показывает мои показатели и общий итог компании.' : 'Каждая строка показывает результат каждого логиста и общий итог за '.($filters['group']==='month'?'месяц':'неделю').'.' ?></p></div><span class="manager-stats__card-meta">Рейсы · Заявки · Вес</span></header>
            <div class="table-scroll"><?php if($personalMode): ?><table class="table manager-stats__period-table is-all-metrics"><?php else: ?><table class="table manager-stats__period-table is-all-metrics" style="min-width:<?=max(1020,174+(count($managerChartSeries)+1)*225)?>px"><?php endif; ?><thead>
                <tr><th rowspan="2" scope="col">Период</th><?php foreach($managerChartSeries as$series):?><th colspan="3" scope="colgroup"><?=e($series['name'])?></th><?php endforeach;?><th colspan="3" scope="colgroup" class="is-total"><?= $personalMode ? 'Компания' : 'Итого' ?></th></tr>
                <tr><?php foreach($managerChartSeries as$series):foreach($tableMeasures as$measure=>$label):?><th class="num <?=$measure==='trips'?'is-group-start':''?>" scope="col"><?=e($label)?></th><?php endforeach;endforeach;?><?php foreach($tableMeasures as$measure=>$label):?><th class="num is-total <?=$measure==='trips'?'is-group-start':''?>" scope="col"><?=e($label)?></th><?php endforeach;?></tr>
            </thead><tbody>
                <?php foreach($visiblePeriodRows as$row):?><tr><td><div class="manager-stats__period-label"><strong><?=e($row['label'])?></strong><span><?=e(date('d.m',strtotime($row['from'])).($row['from']!==$row['to']?'–'.date('d.m',strtotime($row['to'])):''))?></span></div></td><?php foreach($managerChartSeries as$series):$value=$row['manager_values'][(int)$series['membership_id']]??[];foreach($tableMeasures as$measure=>$label):?><td class="num <?=$measure==='trips'?'is-group-start':''?>"><?=e($metricFormat($metricValue($value,$measure),$measure))?></td><?php endforeach;endforeach;?><?php foreach($tableMeasures as$measure=>$label):?><td class="num is-total <?=$measure==='trips'?'is-group-start':''?>"><?=e($metricFormat($metricValue($row,$measure),$measure))?></td><?php endforeach;?></tr><?php endforeach;?>
                <?php if($visiblePeriodRows===[]):?><tr><td colspan="<?=1+3*(1+count($managerChartSeries))?>" class="manager-stats__empty">Нет периодов с ненулевым итогом.</td></tr><?php endif;?>
            </tbody><tfoot><tr><th>Итого за период</th><?php foreach($managerChartSeries as$series):$total=$managerTotals[(int)$series['membership_id']]??[];foreach($tableMeasures as$measure=>$label):?><th class="num <?=$measure==='trips'?'is-group-start':''?>"><?=e($metricFormat($metricValue($total,$measure),$measure))?></th><?php endforeach;endforeach;?><?php foreach($tableMeasures as$measure=>$label):?><th class="num is-total <?=$measure==='trips'?'is-group-start':''?>"><?=e($metricFormat($metricValue($statistics['totals'],$measure),$measure))?></th><?php endforeach;?></tr></tfoot></table></div>
        </section>
    <?php endif; ?>
</div>
