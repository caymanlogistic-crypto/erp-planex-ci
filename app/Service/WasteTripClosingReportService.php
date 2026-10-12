<?php
declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use ZipArchive;

final class WasteTripClosingReportService
{
    public const HEADERS = ['Рейс №','Направление','Водитель','Юр. лицо','ТН','Счёт','Акт','Договор','Путевой лист','Диагностическая карта','Ставка перевозчика, ₽','Дата оплаты перевозчика','Компания','Менеджер'];

    /** @param list<array<string,mixed>> $trips @return list<array<string,string>> */
    public static function rows(array $trips): array
    {
        $rows=[];
        foreach($trips as $trip){
            $slots=[];
            foreach($trip['documents']??[] as $document)$slots[(string)$document['slot_code']]=true;
            $units=$trip['transport']['units']??[];
            $cards=$units!==[];
            foreach($units as $unit)if(empty($unit['has_card'])&&empty($unit['documents']))$cards=false;
            $rate=$trip['carrier_rate']??null;
            $paid=(float)($trip['carrier_paid_amount']??0);
            $isPaid=$rate!==null&&(float)$rate>0&&$paid+0.001>=(float)$rate;
            $paidDate=(string)($trip['carrier_paid_date']??'');
            $due=(string)($trip['carrier_payment_due']??'');
            $date=$isPaid?($paidDate!==''?'Оплачено: '.date('d.m.Y',strtotime($paidDate)):'—'):($due!==''?'План: '.date('d.m.Y',strtotime($due)):'—');
            $rows[]=[
                'id'=>(string)($trip['id']??''),
                'number'=>(string)($trip['business_id']??''),
                'direction'=>(string)($trip['direction']??'—'),
                'driver'=>(string)(($trip['driver_names']??'')?:'—'),
                'legal_entity'=>(string)(($trip['carrier_name']??'')?:'—'),
                'tn'=>(int)($trip['tn_required']??0)>0 && (int)($trip['tn_scanned']??0)>=(int)$trip['tn_required']?'Есть':'Нет',
                'invoice'=>isset($slots['invoice'])?'Есть':'Нет',
                'act'=>isset($slots['act'])?'Есть':'Нет',
                'contract'=>isset($slots['contract'])?'Есть':'Нет',
                'waybill'=>isset($slots['waybill'])?'Есть':'Нет',
                'diagnostic_card'=>$cards?'Есть':'Нет',
                'rate'=>$rate===null?'—':number_format((float)$rate,2,',',' '),
                'payment_date'=>$date,
                'company'=>match((string)($trip['legal_entity_code']??'')){'planex'=>'ПЛАНЭКС','mg_logistic'=>'МГ Логистика',default=>'—'},
                'manager'=>(string)(($trip['manager_name']??'')?:'—'),
            ];
        }
        return $rows;
    }

    /** @param list<array<string,string>> $rows */
    public static function xlsx(array $rows): string
    {
        if(!class_exists(ZipArchive::class))throw new RuntimeException('На сервере недоступно создание Excel.');
        $path=tempnam(sys_get_temp_dir(),'trip_closing_report_');
        if($path===false)throw new RuntimeException('Не удалось подготовить Excel.');
        $zip=new ZipArchive();
        if($zip->open($path,ZipArchive::OVERWRITE)!==true){@unlink($path);throw new RuntimeException('Не удалось создать Excel.');}
        $keys=['number','direction','driver','legal_entity','tn','invoice','act','contract','waybill','diagnostic_card','rate','payment_date','company','manager'];
        $lines=[];$number=1;
        foreach(array_merge([array_combine($keys,self::HEADERS)],$rows) as $row){
            $cells=[];
            foreach($keys as $index=>$key){
                $column=self::column($index+1);$value=htmlspecialchars((string)($row[$key]??''),ENT_XML1|ENT_QUOTES,'UTF-8');
                $cells[]='<c r="'.$column.$number.'" t="inlineStr"><is><t xml:space="preserve">'.$value.'</t></is></c>';
            }
            $lines[]='<row r="'.$number.'">'.implode('',$cells).'</row>';$number++;
        }
        $last=max(1,$number-1);
        $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:N'.$last.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        foreach([12,48,28,30,10,10,10,10,15,23,20,24,20,27] as $i=>$width)$sheet.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>';
        $sheet.='</cols><sheetData>'.implode('',$lines).'</sheetData><autoFilter ref="A1:N'.$last.'"/></worksheet>';
        $parts=[
            '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Закрывающие документы" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
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
