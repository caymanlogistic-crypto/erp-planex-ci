<?php
declare(strict_types=1);
$fmtDate=static fn(mixed $value):string=>trim((string)$value)!==''?date('d.m.Y',strtotime((string)$value)):'—';
$fmtPickupInterval=static function(mixed $arrived,mixed $departed):string{
    if(trim((string)$arrived)===''||trim((string)$departed)==='')return '';
    return date('d.m.y H:i',strtotime((string)$arrived)).' - '.date('H:i',strtotime((string)$departed));
};
$fmtMass=static fn(mixed $value):string=>number_format((float)$value,0,',',' ');
$statusChoice=static function(string $name,string $selected,array $options):void{
    foreach($options as $value=>$label):?>
      <label class="lab-choice-option"><input type="checkbox" name="<?=e($name)?>" value="<?=e($value)?>" <?=$selected===$value?'checked':''?>><?=e($label)?></label>
    <?php endforeach;
};
?>
