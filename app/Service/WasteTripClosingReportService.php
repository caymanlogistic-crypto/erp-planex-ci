<?php
declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use ZipArchive;

final class WasteTripClosingReportService
{
    public const HEADERS = ['Рейс №','Название рейса','Водитель','Юр. лицо','ТН','Счёт','Акт','Договор','Путевой лист','Диагностическая карта','Ставка перевозчика, ₽','Статус','Дата','Компания','Менеджер'];

    /** @param list<array<string,mixed>> $trips @return list<array<string,mixed>> */
    public static function rows(array $trips, ?string $today = null): array
    {
        $today ??= (new \DateTimeImmutable('now',new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        $rows=[];
        foreach($trips as $trip){
            $slots=[];
            foreach($trip['documents']??[] as $document)$slots[(string)$document['slot_code']]=true;
            $units=$trip['transport']['units']??[];
            $cardDetails=[];$cards=$units!==[];$cardsFound=0;
            foreach($units as $unit){
                $hasCard=!empty($unit['has_card'])||!empty($unit['documents']);
                if($hasCard)$cardsFound++;else $cards=false;
                $cardDetails[]=['label'=>trim((string)($unit['label']??'').' '.(string)($unit['plate']??'')),'status'=>$hasCard?'Есть':'Нет'];
            }
            $cardStatus=$units===[]?'Нет':(count($units)===1?($cards?'Есть':'Нет'):implode('; ',array_map(static fn(array $unit):string=>$unit['label'].': '.$unit['status'],$cardDetails)));
            $cardState=$cards?'present':($cardsFound>0?'partial':'missing');
            $rate=$trip['display_rate']??($trip['carrier_rate']??null);
            $carrierRate=$trip['carrier_rate']??null;
            $paid=(float)($trip['carrier_paid_amount']??0);
            $plan=$trip['carrier_payment_plan']??null;
            $isPaid=$paid>0&&(
                ($carrierRate!==null&&(float)$carrierRate>0&&$paid+0.001>=(float)$carrierRate)
                || (is_array($plan)&&array_key_exists('remaining',$plan)&&!$plan['remaining'])
                || (($carrierRate===null||(float)$carrierRate<=0)&&!is_array($plan))
            );
            $paidDate=(string)($trip['carrier_paid_date']??'');
            $due=is_array($plan)?(string)($plan['date']??''):(string)($trip['carrier_payment_due']??'');
            $confirmed=is_array($plan)&&!empty($plan['due_date'])&&($plan['status']??'')==='ready';
            $overdue=!$isPaid&&$confirmed&&$due!==''&&$due<$today;
            $days=$overdue?(new \DateTimeImmutable($due,new \DateTimeZone('Europe/Moscow')))->diff(new \DateTimeImmutable($today,new \DateTimeZone('Europe/Moscow')))->days:0;
            $dayWord=($days%100>=11&&$days%100<=14)?'дней':($days%10===1?'день':($days%10>=2&&$days%10<=4?'дня':'дней'));
            $paymentStatus=$isPaid?'Оплачено':($overdue?'Просрочка ('.$days.' '.$dayWord.')':'План');
            $paymentState=$isPaid?'present':($overdue?'missing':'partial');
            $paymentDate=$isPaid?$paidDate:$due;
            $paymentDate=$paymentDate!==''?date('d.m.Y',strtotime($paymentDate)):'—';
            $tn=(int)($trip['tn_required']??0)>0 && (int)($trip['tn_scanned']??0)>=(int)$trip['tn_required'];
            $rows[]=[
                'id'=>(string)($trip['id']??''),
                'number'=>(string)($trip['business_id']??''),
                'title'=>(string)(trim((string)($trip['comment']??''))?:'—'),
                'driver'=>(string)(($trip['driver_names']??'')?:'—'),
                'legal_entity'=>(string)(($trip['carrier_name']??'')?:'—'),
                'tn'=>$tn?'Есть':'Нет',
                'invoice'=>isset($slots['invoice'])?'Есть':'Нет',
                'act'=>isset($slots['act'])?'Есть':'Нет',
                'contract'=>isset($slots['contract'])?'Есть':'Нет',
                'waybill'=>isset($slots['waybill'])?'Есть':'Нет',
                'diagnostic_card'=>$cardStatus,
                'diagnostic_card_state'=>$cardState,
                'diagnostic_card_details'=>$cardDetails,
                'rate'=>$rate===null?'—':number_format((float)$rate,2,',',' '),
                'payment_status'=>$paymentStatus,
                'payment_state'=>$paymentState,
                'payment_date'=>$paymentDate,
                'company'=>match((string)($trip['legal_entity_code']??'')){'planex'=>'ПЛАНЭКС','mg_logistic'=>'МГ Логистика',default=>'—'},
                'manager'=>(string)(($trip['manager_name']??'')?:'—'),
            ];
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows */
    public static function xlsx(array $rows): string
    {
        if(!class_exists(ZipArchive::class))throw new RuntimeException('На сервере недоступно создание Excel.');
        $path=tempnam(sys_get_temp_dir(),'trip_closing_report_');
        if($path===false)throw new RuntimeException('Не удалось подготовить Excel.');
        $zip=new ZipArchive();
        if($zip->open($path,ZipArchive::OVERWRITE)!==true){@unlink($path);throw new RuntimeException('Не удалось создать Excel.');}
        $keys=['number','title','driver','legal_entity','tn','invoice','act','contract','waybill','diagnostic_card','rate','payment_status','payment_date','company','manager'];
        $lines=[];$number=1;
        foreach(array_merge([array_combine($keys,self::HEADERS)],$rows) as $row){
            $cells=[];
            foreach($keys as $index=>$key){
                $column=self::column($index+1);$value=htmlspecialchars((string)($row[$key]??''),ENT_XML1|ENT_QUOTES,'UTF-8');
                $style='';
                if($number>1&&in_array($key,['tn','invoice','act','contract','waybill','diagnostic_card','payment_status'],true)){
                    $status=$key==='diagnostic_card'?($row['diagnostic_card_state']??'missing'):($key==='payment_status'?($row['payment_state']??'partial'):(($row[$key]??'')==='Есть'?'present':'missing'));
                    $style=' s="'.match($status){'present'=>1,'partial'=>3,default=>2}.'"';
                }
                $cells[]='<c r="'.$column.$number.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.$value.'</t></is></c>';
            }
            $lines[]='<row r="'.$number.'">'.implode('',$cells).'</row>';$number++;
        }
        $last=max(1,$number-1);
        $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:O'.$last.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        foreach([12,48,28,30,10,10,10,10,15,42,20,23,19,20,27] as $i=>$width)$sheet.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>';
        $sheet.='</cols><sheetData>'.implode('',$lines).'</sheetData><autoFilter ref="A1:O'.$last.'"/></worksheet>';
        $parts=[
            '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Закрывающие документы" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml'=>'<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FF17603A"/><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FF9B2929"/><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FF805500"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE0F3E7"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFCE5E3"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF0C2"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="3" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml'=>$sheet,
        ];
        foreach($parts as $name=>$content)$zip->addFromString($name,$content);
        $zip->close();
        return $path;
    }

    private static function column(int $index): string
    {
        $result='';while($index>0){$index--; $result=chr(65+$index%26).$result;$index=intdiv($index,26);}return $result;
    }
}
