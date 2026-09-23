<?php
$companyName = (string)($company['name'] ?? 'Компания');
$metric = (string)$filters['metric'];
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
$queryFor = static function(array $changes) use ($filters, $metric): string {
    $query = ['mode'=>(string)$filters['mode'],'metric'=>$metric];
    foreach ($changes as $key=>$value) {
        if ($value === null) unset($query[$key]); else $query[$key] = $value;
    }
    return app_url('/company/statistics/managers').'?'.http_build_query($query);
};
$chart = static function(array $current, string $key, callable $valueOf): array {
    $width=920.0;$height=286.0;$left=58.0;$right=22.0;$top=20.0;$bottom=46.0;
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
        $bottom=$top+$plotH;$smooth=.38;
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
$lineColors = ['#4A7A9B','#B84C3A','#6A8A50','#B89228','#6D5B8C','#5C7A88','#8A6A44','#7A6478'];
$managerChartSeries = [];
foreach ($statistics['manager_trends'] as $index=>$series) {
    $managerChartSeries[] = $series + ['color'=>$lineColors[$index % count($lineColors)],'points'=>$chartData['points']($series['values'])];
}
$visiblePeriodRows = array_values(array_filter(
    array_reverse($statistics['period_rows']),
    static fn(array $row): bool => abs($metricValue($row, $metric)) > 0.000001
));
$tooltipPosition = static function(array $point) use ($chartData): array {
    $width=176.0;$height=43.0;
    $x=max(4.0-(float)$point['x'],min(-$width/2,$chartData['width']-4.0-(float)$point['x']-$width));
    $y=(float)$point['y']<64.0?12.0:-($height+10.0);
    return['x'=>$x,'y'=>$y,'width'=>$width,'height'=>$height];
};
$totalMetric = $metricValue($statistics['totals'], $metric);
$statusLabels=['in_progress'=>'Вывоз начался','completed'=>'Груз сдан','cancelled'=>'Отменён'];
$periodLabel = date('d.m.Y',strtotime((string)$filters['from'])).' — '.date('d.m.Y',strtotime((string)$filters['to']));
$managerTotals = [];
foreach ($statistics['managers'] as $row) $managerTotals[(int)$row['membership_id']] = $row;
$rankMax = 1.0;
foreach ($statistics['managers'] as $row) {
    $rankMax = max($rankMax, $metricValue($row, $metric));
}
$kpis = [
    ['label'=>'Рейсы поставлены на погрузку','value'=>$fmtInt($statistics['totals']['trips']),'current'=>(float)$statistics['totals']['trips'],'previous'=>(float)$statistics['previous_totals']['trips'],'class'=>'is-copper','note'=>'Факт начала вывоза'],
    ['label'=>'Заявки в рейсах','value'=>$fmtInt($statistics['totals']['requests']),'current'=>(float)$statistics['totals']['requests'],'previous'=>(float)$statistics['previous_totals']['requests'],'class'=>'is-steel','note'=>'Состав рейса на момент старта'],
    ['label'=>'Вес заявок','value'=>$fmtWeight($statistics['totals']['weight']),'current'=>(float)$statistics['totals']['weight'],'previous'=>(float)$statistics['previous_totals']['weight'],'class'=>'is-patina','note'=>'Вес по зафиксированному составу'],
    ['label'=>'Логисты с результатом','value'=>$fmtInt($statistics['totals']['active_managers']),'current'=>(float)$statistics['totals']['active_managers'],'previous'=>(float)$statistics['previous_totals']['active_managers'],'class'=>'is-asphalt','note'=>'Только активная роль «Логист»'],
];
?>
<div class="manager-stats" data-manager-statistics>
    <div class="page-head manager-stats__head">
        <div class="page-head-left">
            <h1 class="page-title">Работа логистов</h1>
            <div class="page-summary"><span>Динамика компании и вклад каждого логиста по фактическому началу вывоза.</span></div>
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

        <div class="manager-stats__filters">
            <div class="manager-stats__filter-body">
                <div class="manager-stats__field manager-stats__field--mode"><label>Режим отчёта</label><nav class="manager-stats__segments" aria-label="Режим отчёта">
                    <?php foreach($modeLabels as$value=>$label):?><a class="<?= $filters['mode']===$value?'is-active':'' ?>" href="<?=e($queryFor(['mode'=>$value]))?>"><?=e($label)?></a><?php endforeach;?>
                </nav></div>
            </div>
        </div>

        <div class="manager-stats__kpis">
            <?php foreach($kpis as$kpi):?><article class="manager-stats__kpi <?=$kpi['class']?>"><div class="manager-stats__kpi-label"><?=e($kpi['label'])?></div><div class="manager-stats__kpi-row"><strong><?=e($kpi['value'])?></strong></div><div class="manager-stats__kpi-note"><?=e($kpi['note'])?></div></article><?php endforeach;?>
        </div>

        <div class="manager-stats__charts">
            <section class="manager-stats__card manager-stats__trend">
                <header class="manager-stats__card-head"><div><h2>Динамика компании и логистов</h2><p><?=e($periodLabel)?> · <?=e($metricMeta[$metric]['long'])?></p></div><nav class="manager-stats__metric-tabs" aria-label="Показатель графиков"><?php foreach($metricMeta as$key=>$meta):?><a class="<?=$metric===$key?'is-active':''?>" href="<?=e($queryFor(['metric'=>$key]))?>"><?=e($meta['label'])?></a><?php endforeach;?></nav></header>
                <div class="manager-stats__chart-body">
                    <?php if($statistics['trend']===[]):?><div class="manager-stats__empty">Нет данных для построения графика.</div><?php else:?>
                    <svg class="manager-stats__line-chart" viewBox="0 0 <?=$chartData['width']?> <?=$chartData['height']?>" role="img" aria-label="Динамика показателя <?=e($metricMeta[$metric]['label'])?>">
                        <defs><linearGradient id="manager-stats-company-area" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="<?=$companyChartColor?>" stop-opacity=".22"/><stop offset=".72" stop-color="<?=$companyChartColor?>" stop-opacity=".05"/><stop offset="1" stop-color="<?=$companyChartColor?>" stop-opacity="0"/></linearGradient></defs>
                        <?php for($i=0;$i<=4;$i++):$y=$chartData['top']+$chartData['plotH']*$i/4;$v=$chartData['max']*(1-$i/4);?><line x1="<?=$chartData['left']?>" y1="<?=$y?>" x2="<?=$chartData['left']+$chartData['plotW']?>" y2="<?=$y?>" class="grid-line"/><text x="<?=$chartData['left']-8?>" y="<?=$y+4?>" text-anchor="end" class="axis-label"><?=e($metricFormat($v,$metric))?></text><?php endfor;?>
                        <?php if($chartData['currentPoints']!==[]):$area=$chartData['path']($chartData['currentPoints']).' L'.$chartData['currentPoints'][count($chartData['currentPoints'])-1]['x'].' '.($chartData['top']+$chartData['plotH']).' L'.$chartData['currentPoints'][0]['x'].' '.($chartData['top']+$chartData['plotH']).' Z';?><path class="manager-stats__chart-area" d="<?=$area?>" fill="url(#manager-stats-company-area)"/><?php endif;?>
                        <?php foreach($managerChartSeries as$seriesIndex=>$series):?><path class="manager-stats__chart-line is-manager" data-series="manager" data-series-name="<?=e($series['name'])?>" pathLength="1" d="<?=$chartData['path']($series['points'])?>" fill="none" stroke="<?=$series['color']?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="--series-index:<?=$seriesIndex+1?>"><title><?=e($series['name'])?></title></path><?php endforeach;?>
                        <?php if($chartData['currentPoints']!==[]):?><path class="manager-stats__chart-line is-company" data-series="company" pathLength="1" d="<?=$chartData['path']($chartData['currentPoints'])?>" fill="none" stroke="<?=$companyChartColor?>" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="--series-index:0"/><?php endif;?>
                        <?php foreach($managerChartSeries as$seriesIndex=>$series):foreach($series['points']as$pointIndex=>$point):$tip=$tooltipPosition($point);$nodeLabel=$series['name'].' · '.$point['label'].' — '.$metricFormat($point['v'],$metric);?><g class="manager-stats__chart-node is-manager" data-series-name="<?=e($series['name'])?>" tabindex="0" role="img" aria-label="<?=e($nodeLabel)?>" transform="translate(<?=$point['x']?> <?=$point['y']?>)" style="--series-index:<?=$seriesIndex+1?>;--node-index:<?=$pointIndex?>;--node-color:<?=$series['color']?>"><circle r="3.5"/><g class="manager-stats__node-tooltip" transform="translate(<?=$tip['x']?> <?=$tip['y']?>)"><rect width="<?=$tip['width']?>" height="<?=$tip['height']?>" rx="2"/><text x="10" y="16" class="tooltip-title"><?=e($point['label'])?></text><text x="10" y="32" class="tooltip-value"><?=e($series['name'].' · '.$metricFormat($point['v'],$metric))?></text></g><title><?=e($nodeLabel)?></title></g><?php endforeach;endforeach;?>
                        <?php foreach($chartData['currentPoints'] as$i=>$point):$tip=$tooltipPosition($point);$nodeLabel='Компания · '.$point['label'].' — '.$metricFormat($point['v'],$metric);?><g class="manager-stats__chart-node is-company" data-series-name="Компания" tabindex="0" role="img" aria-label="<?=e($nodeLabel)?>" transform="translate(<?=$point['x']?> <?=$point['y']?>)" style="--series-index:0;--node-index:<?=$i?>;--node-color:<?=$companyChartColor?>"><circle r="4"/><g class="manager-stats__node-tooltip" transform="translate(<?=$tip['x']?> <?=$tip['y']?>)"><rect width="<?=$tip['width']?>" height="<?=$tip['height']?>" rx="2"/><text x="10" y="16" class="tooltip-title"><?=e($point['label'])?></text><text x="10" y="32" class="tooltip-value"><?=e('Компания · '.$metricFormat($point['v'],$metric))?></text></g><title><?=e($nodeLabel)?></title></g><?php $step=max(1,(int)ceil(count($chartData['currentPoints'])/12));if($i%$step===0||$i===count($chartData['currentPoints'])-1):?><text x="<?=$point['x']?>" y="<?=$chartData['height']-12?>" text-anchor="middle" class="axis-label"><?=e($point['label'])?></text><?php endif;endforeach;?>
                    </svg>
                    <div class="manager-stats__legend"><span><i style="background:<?=$companyChartColor?>"></i><strong>Компания</strong></span><?php foreach($managerChartSeries as$series):?><span><i style="background:<?=$series['color']?>"></i><?=e($series['name'])?></span><?php endforeach;?></div>
                    <?php endif;?>
                </div>
            </section>

            <section class="manager-stats__card manager-stats__ranking">
                <header class="manager-stats__card-head"><div><h2>Рейтинг логистов</h2><p><?=e($metricMeta[$metric]['long'])?></p></div><span class="manager-stats__card-meta"><?=count($statistics['managers'])?> логистов</span></header>
                <div class="manager-stats__rank-list">
                    <?php foreach(array_slice($statistics['managers'],0,10)as$index=>$row):$value=$metricValue($row,$metric);$width=$value/$rankMax*100;?><div class="manager-stats__rank"><div class="manager-stats__rank-line"><span><b><?=$index+1?></b><?=e($row['name'])?></span><strong><?=e($metricFormat($value,$metric))?></strong></div><div class="manager-stats__rank-track"><i style="width:<?=number_format($width,2,'.','')?>%;background:<?=$metricMeta[$metric]['color']?>"></i></div></div><?php endforeach;?>
                    <?php if($statistics['managers']===[]):?><div class="manager-stats__empty">Нет логистов для отображения.</div><?php endif;?>
                </div>
            </section>
        </div>

        <section class="manager-stats__card manager-stats__periods">
            <header class="manager-stats__card-head"><div><h2>Точные значения по периодам</h2><p>Каждая строка показывает результат каждого логиста и общий итог за <?= $filters['group']==='month'?'месяц':'неделю' ?>.</p></div><span class="manager-stats__card-meta"><?=e($metricMeta[$metric]['long'])?></span></header>
            <div class="table-scroll"><table class="table manager-stats__period-table"><thead><tr><th>Период</th><?php foreach($statistics['manager_trends']as$series):?><th class="num"><?=e($series['name'])?></th><?php endforeach;?><th class="num is-total">Итого</th></tr></thead><tbody>
                <?php foreach($visiblePeriodRows as$row):?><tr><td><div class="manager-stats__period-label"><strong><?=e($row['label'])?></strong><span><?=e(date('d.m',strtotime($row['from'])).($row['from']!==$row['to']?'–'.date('d.m',strtotime($row['to'])):''))?></span></div></td><?php foreach($statistics['manager_trends']as$series):$value=$row['manager_values'][(int)$series['membership_id']]??[];?><td class="num"><?=e($metricFormat($metricValue($value,$metric),$metric))?></td><?php endforeach;?><td class="num is-total"><?=e($metricFormat($metricValue($row,$metric),$metric))?></td></tr><?php endforeach;?>
                <?php if($visiblePeriodRows===[]):?><tr><td colspan="<?=2+count($statistics['manager_trends'])?>" class="manager-stats__empty">Нет периодов с ненулевым итогом.</td></tr><?php endif;?>
            </tbody><tfoot><tr><th>Итого за период</th><?php foreach($statistics['manager_trends']as$series):$total=$managerTotals[(int)$series['membership_id']]??[];?><th class="num"><?=e($metricFormat($metricValue($total,$metric),$metric))?></th><?php endforeach;?><th class="num is-total"><?=e($metricFormat($totalMetric,$metric))?></th></tr></tfoot></table></div>
        </section>

        <section class="manager-stats__card manager-stats__table-card">
            <header class="manager-stats__card-head"><div><h2>Итоги каждого логиста</h2><p>Сумма результатов логистов равна показателю компании.</p></div><span class="manager-stats__card-meta">Сортировка: <?=e(mb_strtolower($metricMeta[$metric]['label'],'UTF-8'))?></span></header>
            <div class="table-scroll"><table class="table manager-stats__table"><thead><tr><th>Логист</th><th class="num">Рейсы</th><th class="num">Заявки</th><th class="num">Вес</th><th class="num">Заявок / рейс</th><th class="num">Вес / рейс</th><th class="num">Доля компании</th><th class="num">Завершено</th><th class="num">Отменено</th></tr></thead><tbody>
                <?php foreach($statistics['managers']as$row):$share=$totalMetric>0?$metricValue($row,$metric)/$totalMetric*100:0;?><tr><td><strong><?=e($row['name'])?></strong></td><td class="num"><?=$fmtInt($row['trips'])?></td><td class="num"><?=$fmtInt($row['requests'])?></td><td class="num"><?=$fmtWeight($row['weight'])?></td><td class="num"><?=number_format((float)$row['requests_per_trip'],1,',',' ')?></td><td class="num"><?=$fmtWeight($row['weight_per_trip'])?></td><td class="num"><span class="manager-stats__share"><?=number_format($share,1,',',' ')?>%</span></td><td class="num"><?=$fmtInt($row['completed'])?></td><td class="num <?=$row['cancelled']>0?'is-danger':''?>"><?=$fmtInt($row['cancelled'])?></td></tr><?php endforeach;?>
                <?php if($statistics['managers']===[]):?><tr><td colspan="9" class="manager-stats__empty">Активные логисты не найдены.</td></tr><?php endif;?>
            </tbody><tfoot><tr><th>Компания · <?=e($companyName)?></th><th class="num"><?=$fmtInt($statistics['totals']['trips'])?></th><th class="num"><?=$fmtInt($statistics['totals']['requests'])?></th><th class="num"><?=$fmtWeight($statistics['totals']['weight'])?></th><th colspan="3"></th><th class="num"><?=$fmtInt($statistics['totals']['completed'])?></th><th class="num"><?=$fmtInt($statistics['totals']['cancelled'])?></th></tr></tfoot></table></div>
        </section>

        <section class="manager-stats__card manager-stats__details">
            <header class="manager-stats__card-head"><div><h2>Из чего сложился результат</h2><p>Рейсы, учтённые в показателях за выбранный период.</p></div><span class="manager-stats__card-meta">Рейсов: <?=count($statistics['details'])?><?=count($statistics['details'])===500?' · достигнут лимит':''?></span></header>
            <div class="table-scroll"><table class="table manager-stats__detail-table"><thead><tr><th>Дата результата</th><th>Логист</th><th>Рейс</th><th>Текущий статус</th><th class="num">Заявки</th><th class="num">Вес</th></tr></thead><tbody>
                <?php foreach($statistics['details']as$row):?><tr><td><?=e(date('d.m.Y',strtotime((string)$row['actual_start_date'])))?></td><td><?=e((string)$row['responsible_name_snapshot'])?></td><td><strong>#<?=(int)$row['business_id']?></strong></td><td><span class="badge <?=($row['status']==='cancelled'?'badge-danger':($row['status']==='completed'?'badge-ok':'badge-warning'))?>"><?=e($statusLabels[$row['status']]??(string)$row['status'])?></span></td><td class="num"><?=$fmtInt($row['request_count'])?></td><td class="num"><?=$fmtWeight($row['total_mass_kg'])?></td></tr><?php endforeach;?>
                <?php if($statistics['details']===[]):?><tr><td colspan="6" class="manager-stats__empty"><strong>За выбранный диапазон машины на погрузку не ставились.</strong></td></tr><?php endif;?>
            </tbody></table></div>
        </section>
    <?php endif; ?>
</div>
