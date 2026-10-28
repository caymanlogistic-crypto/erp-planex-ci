<?php

namespace App\Service;

trait DocumentGeneratorDataTrait
{
    private static function normalizeParameters(string $type, array $params): array
    {
        if (in_array($type, ['vehicle_lease_crew','carrier_sublease_crew','client_sublease_crew','client_upd_contract'], true)) {
            $out = [
                'contract_date'=>trim((string)($params['contract_date'] ?? '')),
                'start_date'=>trim((string)($params['start_date'] ?? '')),
                'start_time'=>trim((string)($params['start_time'] ?? '')),
                'finish_date'=>trim((string)($params['finish_date'] ?? '')),
                'price'=>trim((string)($params['price'] ?? '')),
                'vat_mode'=>trim((string)($params['vat_mode'] ?? 'без НДС')),
            ];
            if (in_array($type, ['client_sublease_crew','client_upd_contract'], true)) {
                $out['contract_number']=trim((string)($params['contract_number'] ?? ''));
                $out['payment_terms']=trim((string)($params['payment_terms'] ?? ''));
                $out['invoice_date']=trim((string)($params['invoice_date'] ?? ''));
                $out['act_date']=trim((string)($params['act_date'] ?? ''));
            }
            return $out;
        }
        return [
            'waybill_number'=>trim((string)($params['waybill_number'] ?? '')),
            'waybill_date'=>trim((string)($params['waybill_date'] ?? '')),
        ];
    }

    private static function validateParameters(string $type, array $params): array
    {
        $errors=[];
        $validDate = static function (string $value): bool {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$value,$m)) return false;
            return checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
        };
        if (in_array($type, ['vehicle_lease_crew','carrier_sublease_crew','client_sublease_crew','client_upd_contract'], true)) {
            foreach (['contract_date'=>'дату договора','start_date'=>'дату начала аренды','finish_date'=>'плановую дату завершения'] as $field=>$label) {
                $value=trim((string)($params[$field] ?? ''));
                if (!$validDate($value)) $errors[]='Укажите корректную ' . $label . '.';
            }
            $time=trim((string)($params['start_time'] ?? ''));
            if ($time!=='' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time)) $errors[]='Время подачи должно быть в формате ЧЧ:ММ.';
            $price=str_replace(["\u{00A0}",' '],'',str_replace(',','.',trim((string)($params['price'] ?? ''))));
            if ($price==='' || !is_numeric($price) || (float)$price<0) $errors[]='Укажите корректную стоимость договора.';
            if (trim((string)($params['vat_mode'] ?? ''))==='') $errors[]='Укажите режим НДС.';
            if (in_array($type, ['client_sublease_crew','client_upd_contract'], true)) {
                $contractNumber = trim((string)($params['contract_number'] ?? ''));
                if ($contractNumber === '' || mb_strlen($contractNumber) > 50 || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\/-]*$/u', $contractNumber)) $errors[]='Укажите корректный номер договора клиента (до 50 символов).';
                if (trim((string)($params['payment_terms'] ?? ''))==='') $errors[]='Не определены условия оплаты заказчика по рейсу.';
            }
        } elseif ($type==='waybill_driver_assignment') {
            if (trim((string)($params['waybill_number'] ?? ''))==='') $errors[]='Укажите номер путевого листа.';
            if (!$validDate(trim((string)($params['waybill_date'] ?? '')))) $errors[]='Укажите корректную дату путевого листа.';
        }
        return array_values(array_unique($errors));
    }

    private static function optionalDisplay(mixed $value): string
    {
        $text=trim((string)$value);
        return $text==='' ? '—' : $text;
    }

    private static function vehicleTypeLabel(string $type, bool $secondary, bool $hasSecondary): string
    {
        return match ($type) {
            'tractor' => 'Тягач',
            'semi_trailer' => 'Полуприцеп',
            'trailer' => 'Прицеп',
            'truck' => $secondary ? 'Грузовик' : 'Транспортное средство',
            'single' => $secondary ? 'Транспортная единица' : 'Транспортное средство',
            default => $secondary ? 'Транспортная единица' : ($hasSecondary ? 'Тягач' : 'Транспортное средство'),
        };
    }

    private static function vehicleTypeGenitive(string $type, bool $secondary): string
    {
        return match ($type) {
            'tractor' => 'тягача',
            'semi_trailer' => 'полуприцепа',
            'trailer' => 'прицепа',
            'truck', 'single' => 'автомобиля',
            default => $secondary ? 'прицепа/полуприцепа' : 'автомобиля',
        };
    }

    private static function vehicleIdentity(array $unit): string
    {
        $parts=[];
        foreach (['model','plate'] as $field) {
            $value=trim((string)($unit[$field] ?? ''));
            if ($value!=='') $parts[]=$value;
        }
        if ($parts!==[]) return implode(' / ',$parts);
        $id=(int)($unit['id'] ?? 0);
        return $id>0 ? '#' . $id : 'без идентификатора';
    }

    private static function buildFixedValues(array $snapshot): array
    {
        $c=is_array($snapshot['company'] ?? null)?$snapshot['company']:[];
        $e=is_array($snapshot['executor'] ?? null)?$snapshot['executor']:[];
        $d=is_array($snapshot['driver'] ?? null)?$snapshot['driver']:[];
        $v=is_array($snapshot['vehicle'] ?? null)?$snapshot['vehicle']:[];
        $t=is_array($snapshot['trailer'] ?? null)?$snapshot['trailer']:[];
        $p=is_array($snapshot['parameters'] ?? null)?$snapshot['parameters']:[];
        $s=is_array($snapshot['requests_summary'] ?? null)?$snapshot['requests_summary']:[];
        $client=is_array($snapshot['client'] ?? null)?$snapshot['client']:[];
        $service=is_array($snapshot['service'] ?? null)?$snapshot['service']:[];
        $trip=is_array($snapshot['trip'] ?? null)?$snapshot['trip']:[];
        $document=is_array($snapshot['document'] ?? null)?$snapshot['document']:[];
        $number=trim((string)($document['number'] ?? $p['contract_number'] ?? ''));
        $contractDate=(string)($p['contract_date'] ?? '');
        $invoiceDate=(string)($p['invoice_date'] ?? $contractDate);
        $actDate=(string)($p['act_date'] ?? $contractDate);
        $serviceAmountRaw=(string)($service['amount'] ?? $p['price'] ?? '');
        $price=self::formatMoneyText((string)($p['price'] ?? ''));
        $companyKpp=trim((string)($c['kpp'] ?? ''));
        $companyOgrn=trim((string)($c['ogrn'] ?? ''));
        $clientKpp=trim((string)($client['kpp'] ?? ''));
        $clientOgrn=trim((string)($client['ogrn'] ?? ''));
        $clientOgrnPrefix=strlen($clientOgrn)===15?'ОГРНИП: ':'ОГРН: ';
        return [
            'contract.number'=>trim((string)($p['contract_number'] ?? '')) ?: $number,
            'contract.date'=>self::formatRuDate($contractDate),
            'contract.date_plain'=>self::formatRuDatePlain($contractDate),
            'contract.start_date'=>self::formatRuDate((string)($p['start_date'] ?? '')),
            'contract.start_time'=>self::optionalDisplay($p['start_time'] ?? ''),
            'contract.finish_date'=>self::formatRuDate((string)($p['finish_date'] ?? '')),
            'contract.price'=>$price,
            'contract.vat_mode'=>(string)($p['vat_mode'] ?? ''),
            'contract.payment_terms'=>(string)($p['payment_terms'] ?? ''),
            'invoice.number'=>(string)($snapshot['package']['number'] ?? $number),
            'invoice.date'=>self::formatRuDatePlain($invoiceDate),
            'act.number'=>(string)($snapshot['package']['transfer_number'] ?? $number),
            'act.date'=>self::formatRuDatePlain($actDate),
            'upd.number'=>(string)($snapshot['package']['transfer_number'] ?? $number),
            'upd.date'=>self::formatRuDatePlain($actDate),
            'upd.day'=>substr($actDate, 8, 2),
            'upd.month'=>self::formatRuDatePlain($actDate) !== '' ? explode(' ', self::formatRuDatePlain($actDate))[1] : '',
            'upd.year_suffix'=>substr($actDate, 2, 2),
            'waybill.number'=>(string)($p['waybill_number'] ?? ''),
            'waybill.date'=>self::formatRuDate((string)($p['waybill_date'] ?? '')),
            'company.name'=>(string)($c['name'] ?? ''),
            'company.inn'=>(string)($c['inn'] ?? ''),
            'company.inn_kpp'=>(string)($c['inn'] ?? '') . ($companyKpp !== '' ? '/' . $companyKpp : ''),
            'company.kpp'=>$companyKpp,
            'company.kpp_line'=>$companyKpp!==''?'КПП: '.$companyKpp:'',
            'company.ogrn_line'=>$companyOgrn!==''?'ОГРН: '.$companyOgrn:'',
            'company.legal_address'=>(string)($c['legal_address'] ?? ''),
            'company.physical_address'=>self::optionalDisplay($c['physical_address'] ?? ''),
            'company.bank_name'=>(string)($c['bank_name'] ?? ''),
            'company.bank_account'=>(string)($c['bank_account'] ?? ''),
            'company.bank_corr_account'=>(string)($c['bank_corr_account'] ?? ''),
            'company.bank_bik'=>(string)($c['bank_bik'] ?? ''),
            'company.contact_phone'=>self::optionalDisplay($c['contact_phone'] ?? ''),
            'company.contact_email'=>self::optionalDisplay($c['contact_email'] ?? ''),
            'company.director_full_name'=>(string)($c['director_full_name'] ?? ''),
            'company.director_position'=>(string)($c['director_position'] ?? ''),
            'company.signature_name'=>self::signatureName((string)($c['director_full_name'] ?? '')),
            'company.summary'=>self::partySummary($c),
            'client.name'=>(string)($client['name'] ?? ''),
            'client.inn'=>(string)($client['inn'] ?? ''),
            'client.inn_kpp'=>(string)($client['inn'] ?? '') . ($clientKpp !== '' ? '/' . $clientKpp : ''),
            'client.kpp'=>$clientKpp,
            'client.kpp_line'=>$clientKpp!==''?'КПП: '.$clientKpp:'',
            'client.ogrn_line'=>$clientOgrn!==''?$clientOgrnPrefix.$clientOgrn:'',
            'client.legal_address'=>(string)($client['legal_address'] ?? ''),
            'client.physical_address'=>self::optionalDisplay($client['physical_address'] ?? ''),
            'client.bank_name'=>(string)($client['bank_name'] ?? ''),
            'client.bank_account'=>(string)($client['bank_account'] ?? ''),
            'client.bank_corr_account'=>(string)($client['bank_corr_account'] ?? ''),
            'client.bank_bik'=>(string)($client['bank_bik'] ?? ''),
            'client.director_full_name'=>(string)($client['director_full_name'] ?? ''),
            'client.director_position'=>(string)($client['director_position'] ?? ''),
            'client.signature_name'=>(string)($client['signature_name'] ?? ''),
            'client.summary'=>self::partySummary($client),
            'executor.name'=>(string)($e['name'] ?? ''),
            'executor.inn'=>(string)($e['inn'] ?? ''),
            'executor.kpp_line'=>trim((string)($e['kpp'] ?? ''))!==''?'КПП: '.(string)$e['kpp']:'',
            'executor.ogrn_line'=>trim((string)($e['ogrn'] ?? ''))!==''?((strlen((string)$e['ogrn'])===15?'ОГРНИП: ':'ОГРН: ').(string)$e['ogrn']):'',
            'executor.legal_address'=>(string)($e['legal_address'] ?? ''),
            'executor.physical_address'=>(string)($e['physical_address'] ?? ''),
            'executor.bank_name'=>(string)($e['bank_name'] ?? ''),
            'executor.bank_account'=>(string)($e['bank_account'] ?? ''),
            'executor.bank_corr_account'=>(string)($e['bank_corr_account'] ?? ''),
            'executor.bank_bik'=>(string)($e['bank_bik'] ?? ''),
            'executor.bank_inn'=>'—',
            'executor.director_full_name'=>(string)($e['director_full_name'] ?? ''),
            'executor.director_position'=>(string)($e['director_position'] ?? ''),
            'executor.signature_name'=>(string)($e['signature_name'] ?? ''),
            'driver.full_name'=>(string)($d['full_name'] ?? ''),
            'driver.passport'=>(string)($d['passport'] ?? ''),
            'driver.license'=>(string)($d['license'] ?? ''),
            'driver.signature_name'=>(string)($d['signature_name'] ?? ''),
            'vehicle.label'=>(string)($v['label'] ?? 'Транспортное средство'),
            'vehicle.model'=>(string)($v['model'] ?? ''),
            'vehicle.plate'=>(string)($v['plate'] ?? ''),
            'vehicle.vin'=>!empty($v['vin_absent']) ? 'отсутствует' : (string)($v['vin'] ?? ''),
            'trailer.label'=>(string)($t['label'] ?? ''),
            'trailer.model'=>(string)($t['model'] ?? ''),
            'trailer.plate'=>(string)($t['plate'] ?? ''),
            'trailer.vin'=>!empty($t['vin_absent']) ? 'отсутствует' : (string)($t['vin'] ?? ''),
            'trailer.display'=>(string)($t['display'] ?? ''),
            'service.description'=>(string)($service['description'] ?? ''),
            'service.route'=>(string)($service['route'] ?? ''),
            'service.amount'=>self::formatMoneyAccounting($serviceAmountRaw),
            'service.amount_ru'=>str_replace('.', ',', self::formatMoneyAccounting($serviceAmountRaw)),
            'service.amount_ru_compact'=>str_replace(' ', '', str_replace('.', ',', self::formatMoneyAccounting($serviceAmountRaw))),
            'service.amount_words'=>self::moneyToWordsRu($serviceAmountRaw),
            'service.vat_mode'=>(string)($service['vat_mode'] ?? $p['vat_mode'] ?? ''),
            'trip.business_id'=>(string)($trip['business_id'] ?? ''),
            'requests.count'=>(string)($s['count'] ?? count($snapshot['requests'] ?? [])),
            'requests.total_mass_kg'=>self::formatMassKg((float)($s['total_mass_kg'] ?? 0)),
            'requests.unload_address'=>(string)($s['unload_address'] ?? ''),
        ];
    }

    private static function validateFixedPlaceholders(array $placeholders, array $values, array $snapshot): array
    {
        $optional=['contract.start_time','company.physical_address','company.contact_phone','company.contact_email','client.physical_address','client.kpp_line','client.ogrn_line','executor.kpp_line','executor.ogrn_line','executor.physical_address','executor.bank_inn','vehicle.vin','trailer.label','trailer.model','trailer.plate','trailer.vin','trailer.display'];
        $requestPrefixes=['request.'];
        $errors=[];
        foreach ($placeholders as $name) {
            if (!is_string($name) || in_array($name,$optional,true)) continue;
            $isRequest=false;foreach($requestPrefixes as $prefix)if(str_starts_with($name,$prefix)){$isRequest=true;break;}
            if($isRequest)continue;
            if ($name==='contract.number' && (string)($snapshot['document']['number_mode'] ?? '')==='automatic') continue;
            if (!array_key_exists($name,$values)) { $errors[]='В выбранном шаблоне используется неподдерживаемое поле. Обратитесь к руководителю.'; continue; }
            if (trim((string)$values[$name])==='') $errors[]=self::missingPlaceholderMessage($name,$snapshot);
        }
        $company=$snapshot['company'];
        if (in_array('company.kpp_line',$placeholders,true) && trim((string)$company['kpp'])==='') $errors[]='У компании не заполнен КПП.';
        if (in_array('company.ogrn_line',$placeholders,true) && trim((string)$company['ogrn'])==='') $errors[]='У компании не заполнен ОГРН.';
        $client=is_array($snapshot['client'] ?? null)?$snapshot['client']:[];
        if ($client!==[] && strlen(preg_replace('/\D/','',(string)($client['inn'] ?? '')) ?? '')===10) {
            if (in_array('client.kpp_line',$placeholders,true) && trim((string)($client['kpp'] ?? ''))==='') $errors[]='У клиента-юрлица не заполнен КПП.';
            if (in_array('client.ogrn_line',$placeholders,true) && trim((string)($client['ogrn'] ?? ''))==='') $errors[]='У клиента-юрлица не заполнен ОГРН.';
        }
        $executor=$snapshot['executor'];
        if (strlen(preg_replace('/\D/','',(string)$executor['inn']) ?? '')===10) {
            if (in_array('executor.kpp_line',$placeholders,true) && trim((string)$executor['kpp'])==='') $errors[]='У Арендодателя-юрлица не заполнен КПП.';
            if (in_array('executor.ogrn_line',$placeholders,true) && trim((string)$executor['ogrn'])==='') $errors[]='У Арендодателя-юрлица не заполнен ОГРН.';
        }

        $vehicle=is_array($snapshot['vehicle'] ?? null)?$snapshot['vehicle']:[];
        if (in_array('vehicle.vin',$placeholders,true) && empty($vehicle['vin_absent']) && trim((string)($vehicle['vin'] ?? ''))==='') {
            $kind=self::vehicleTypeGenitive((string)($vehicle['type'] ?? ''),false);
            $errors[]='У ' . $kind . ' ' . self::vehicleIdentity($vehicle) . ' не заполнен VIN. Заполните его в карточке транспорта.';
        }

        $trailer=is_array($snapshot['trailer'] ?? null)?$snapshot['trailer']:[];
        $trailerId=(int)($trailer['id'] ?? 0);
        $trailerPlaceholders=['trailer.label','trailer.model','trailer.plate','trailer.vin','trailer.display'];
        $usesTrailer=false;
        foreach ($trailerPlaceholders as $name) if (in_array($name,$placeholders,true)) { $usesTrailer=true; break; }
        if ($trailerId>0 && $usesTrailer) {
            if (trim((string)($trailer['model'] ?? ''))==='') $errors[]='У второй транспортной единицы не заполнены марка/модель. Заполните карточку транспорта.';
            if (trim((string)($trailer['plate'] ?? ''))==='') $errors[]='У второй транспортной единицы не заполнен государственный номер. Заполните карточку транспорта.';
            if (empty($trailer['vin_absent']) && trim((string)($trailer['vin'] ?? ''))==='') {
                $kind=self::vehicleTypeGenitive((string)($trailer['type'] ?? ''),true);
                $errors[]='У ' . $kind . ' ' . self::vehicleIdentity($trailer) . ' не заполнен VIN. Заполните его в карточке транспорта.';
            }
        }
        return array_values(array_unique($errors));
    }

    private static function missingPlaceholderMessage(string $name, array $snapshot = []): string
    {
        $labels = [
            'company.name'=>'Наименование компании','company.inn'=>'ИНН компании','company.legal_address'=>'Юридический адрес компании',
            'company.bank_name'=>'Банк компании','company.bank_account'=>'Расчётный счёт компании','company.bank_corr_account'=>'Корреспондентский счёт компании','company.bank_bik'=>'БИК банка компании',
            'company.director_full_name'=>'ФИО руководителя компании','company.director_position'=>'Должность руководителя компании','company.signature_name'=>'ФИО подписанта компании',
            'client.name'=>'Наименование клиента','client.inn'=>'ИНН клиента','client.legal_address'=>'Юридический адрес клиента',
            'client.bank_name'=>'Банк клиента','client.bank_account'=>'Расчётный счёт клиента','client.bank_corr_account'=>'Корреспондентский счёт клиента','client.bank_bik'=>'БИК банка клиента',
            'client.director_full_name'=>'ФИО руководителя клиента','client.director_position'=>'Должность руководителя клиента','client.signature_name'=>'ФИО подписанта клиента',
            'executor.name'=>'Наименование перевозчика','executor.inn'=>'ИНН','executor.legal_address'=>'Юридический адрес',
            'executor.bank_name'=>'Банк','executor.bank_account'=>'Расчётный счёт','executor.bank_corr_account'=>'Корреспондентский счёт','executor.bank_bik'=>'БИК банка',
            'executor.director_full_name'=>'ФИО подписанта','executor.director_position'=>'Должность подписанта','executor.signature_name'=>'ФИО подписанта',
            'driver.full_name'=>'ФИО водителя','driver.passport'=>'Паспортные данные водителя','driver.license'=>'Водительское удостоверение',
            'vehicle.label'=>'Тип транспортного средства','vehicle.model'=>'Марка/модель транспортного средства','vehicle.plate'=>'Государственный номер транспортного средства','vehicle.vin'=>'VIN транспортного средства',
            'trailer.label'=>'Тип прицепа/полуприцепа','trailer.model'=>'Марка/модель прицепа/полуприцепа','trailer.plate'=>'Государственный номер прицепа/полуприцепа','trailer.vin'=>'VIN прицепа/полуприцепа','trailer.display'=>'Данные прицепа/полуприцепа',
            'contract.date'=>'Дата договора','contract.start_date'=>'Дата начала аренды','contract.finish_date'=>'Плановая дата завершения','contract.price'=>'Стоимость','contract.vat_mode'=>'Режим НДС','contract.payment_terms'=>'Условия оплаты',
            'invoice.number'=>'Номер счёта','invoice.date'=>'Дата счёта','act.number'=>'Номер акта','act.date'=>'Дата акта','upd.number'=>'Номер УПД','upd.date'=>'Дата УПД','service.description'=>'Описание услуги','service.route'=>'Маршрут услуги','service.amount'=>'Сумма услуги','service.amount_words'=>'Сумма прописью','service.vat_mode'=>'Режим НДС',
            'waybill.number'=>'Номер путевого листа','waybill.date'=>'Дата путевого листа','requests.count'=>'Количество заявок','requests.total_mass_kg'=>'Общая масса заявок','requests.unload_address'=>'Адрес выгрузки',
        ];
        $label=$labels[$name] ?? $name;
        if (str_starts_with($name,'executor.')) {
            $executor=is_array($snapshot['executor'] ?? null)?$snapshot['executor']:[];
            $executorName=trim((string)($executor['name'] ?? ''));
            $subject=$executorName!=='' ? 'У перевозчика «'.$executorName.'»' : 'У выбранного перевозчика';
            return $subject . ': не заполнено поле «' . $label . '». Заполните его в карточке перевозчика.';
        }
        if (str_starts_with($name,'client.')) {
            $client=is_array($snapshot['client'] ?? null)?$snapshot['client']:[];
            $clientName=trim((string)($client['name'] ?? ''));
            $subject=$clientName!=='' ? 'У клиента «'.$clientName.'»' : 'У выбранного клиента';
            return $subject . ': не заполнено поле «' . $label . '». Заполните его в карточке клиента.';
        }
        if (str_starts_with($name,'company.')) return 'В реквизитах ПЛАНЭКС не заполнено поле «' . $label . '».';
        if (str_starts_with($name,'driver.')) return 'Не заполнено поле «' . $label . '». Заполните его в карточке водителя.';
        if (str_starts_with($name,'vehicle.') || str_starts_with($name,'trailer.')) return 'Не заполнено поле «' . $label . '». Заполните его в карточке транспорта.';
        if (str_starts_with($name,'contract.')) return 'Не заполнен параметр договора «' . $label . '».';
        if (str_starts_with($name,'invoice.') || str_starts_with($name,'act.') || str_starts_with($name,'upd.') || str_starts_with($name,'service.')) return 'Не заполнено поле клиентского документа «' . $label . '».';
        if (str_starts_with($name,'waybill.')) return 'Не заполнен параметр путевого листа «' . $label . '».';
        if (str_starts_with($name,'requests.')) return 'Не заполнены данные заявок ФЭО: «' . $label . '».';
        return 'Не заполнено обязательное поле документа «' . $label . '».';
    }

    private static function validateRequestRows(array $rows, array $placeholders): array
    {
        $errors=[];
        foreach ($rows as $row) {
            $id=(string)($row['zayavka_id'] ?? 'без ID');$items=[];
            if (in_array('request.mass_kg',$placeholders,true) && ($row['mass_netto'] ?? null)===null) $items[]='нет массы';
            if (in_array('request.sender',$placeholders,true) && trim((string)($row['naim_oo_gruzootpravitel'] ?? ''))==='') $items[]='нет грузоотправителя';
            if (in_array('request.loading_address',$placeholders,true) && trim((string)($row['document_loading_address'] ?? $row['mno_adres_pogruzki'] ?? ''))==='') $items[]='нет адреса погрузки';
            if (in_array('requests.unload_address',$placeholders,true) && trim((string)($row['document_unloading_address'] ?? $row['adres_vygruzki_otkhodov'] ?? ''))==='') $items[]='нет места выгрузки';
            if ($items!==[]) $errors[$id]=$items;
        }
        return $errors;
    }

    private static function unloadGroups(array $rows): array
    {
        $groups=[];
        foreach ($rows as $row) {
            $address=preg_replace('/\s+/u',' ',trim((string)($row['document_unloading_address'] ?? $row['adres_vygruzki_otkhodov'] ?? ''))) ?? '';
            if ($address==='') continue;
            $groups[$address]=($groups[$address] ?? 0)+1;
        }
        return $groups;
    }

    private static function buildRequestPlaceholderRows(array $rows): array
    {
        $out=[];
        foreach ($rows as $index=>$row) {
            $kg=round(((float)($row['mass_netto'] ?? 0))*1000,3);
            $out[]=[
                'request.index'=>(string)($index+1),'request.id'=>(string)($row['zayavka_id'] ?? ''),'request.mass_kg'=>self::formatMassKg($kg),
                'request.sender'=>(string)($row['naim_oo_gruzootpravitel'] ?? ''),'request.loading_address'=>(string)($row['document_loading_address'] ?? $row['mno_adres_pogruzki'] ?? ''),'request.contacts'=>self::requestContacts($row),
            ];
        }
        return $out;
    }

    private static function formatRuDate(string $date): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$m)) return '';
        $months=['01'=>'января','02'=>'февраля','03'=>'марта','04'=>'апреля','05'=>'мая','06'=>'июня','07'=>'июля','08'=>'августа','09'=>'сентября','10'=>'октября','11'=>'ноября','12'=>'декабря'];
        if (!checkdate((int)$m[2],(int)$m[3],(int)$m[1])) return '';
        return sprintf('«%s» %s %s г.',$m[3],$months[$m[2]] ?? $m[2],$m[1]);
    }

    private static function formatDateSimple(string $date): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$m)) return '';
        return $m[3].'.'.$m[2].'.'.$m[1];
    }

    private static function formatMoneyText(string $value): string
    {
        $normalized=str_replace(["\u{00A0}",' '],'',str_replace(',','.',$value));
        if ($normalized==='' || !is_numeric($normalized)) return $value;
        $number=(float)$normalized;
        $decimals=abs($number-round($number))<0.00001 ? 0 : 2;
        return number_format($number,$decimals,',',' ');
    }

    private static function formatRuDatePlain(string $date): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$m)) return '';
        $months=['01'=>'января','02'=>'февраля','03'=>'марта','04'=>'апреля','05'=>'мая','06'=>'июня','07'=>'июля','08'=>'августа','09'=>'сентября','10'=>'октября','11'=>'ноября','12'=>'декабря'];
        if (!checkdate((int)$m[2],(int)$m[3],(int)$m[1])) return '';
        return ((int)$m[3]) . ' ' . ($months[$m[2]] ?? $m[2]) . ' ' . $m[1];
    }

    private static function formatMoneyAccounting(string $value): string
    {
        $normalized=str_replace(["\u{00A0}",' '],'',str_replace(',','.',$value));
        if ($normalized==='' || !is_numeric($normalized)) return $value;
        return number_format((float)$normalized,2,'.',' ');
    }

    private static function partySummary(array $party): string
    {
        $parts=[];
        $name=trim((string)($party['name'] ?? ''));
        if ($name!=='') $parts[]=$name;
        $inn=trim((string)($party['inn'] ?? ''));
        if ($inn!=='') $parts[]='ИНН '.$inn;
        $kpp=trim((string)($party['kpp'] ?? ''));
        if ($kpp!=='') $parts[]='КПП '.$kpp;
        $address=trim((string)($party['legal_address'] ?? ''));
        if ($address!=='') $parts[]=$address;
        return implode(', ',$parts);
    }

    private static function moneyToWordsRu(string $value): string
    {
        $normalized=str_replace(["\u{00A0}",' '],'',str_replace(',','.',$value));
        if ($normalized==='' || !is_numeric($normalized)) return '';
        $cents=(int)round(((float)$normalized)*100);
        if ($cents<0) return '';
        $rub=intdiv($cents,100);
        $kop=$cents%100;
        $words=self::integerToWordsRu($rub);
        $words=$words===''?'ноль':$words;
        $first=function_exists('mb_substr')?mb_strtoupper(mb_substr($words,0,1,'UTF-8'),'UTF-8'):strtoupper(substr($words,0,1));
        $rest=function_exists('mb_substr')?mb_substr($words,1,null,'UTF-8'):substr($words,1);
        return $first.$rest.' '.self::ruPlural($rub,'рубль','рубля','рублей').' '.str_pad((string)$kop,2,'0',STR_PAD_LEFT).' '.self::ruPlural($kop,'копейка','копейки','копеек');
    }

    private static function integerToWordsRu(int $number): string
    {
        if ($number===0) return 'ноль';
        $unitsMale=['','один','два','три','четыре','пять','шесть','семь','восемь','девять'];
        $unitsFemale=['','одна','две','три','четыре','пять','шесть','семь','восемь','девять'];
        $teens=[10=>'десять',11=>'одиннадцать',12=>'двенадцать',13=>'тринадцать',14=>'четырнадцать',15=>'пятнадцать',16=>'шестнадцать',17=>'семнадцать',18=>'восемнадцать',19=>'девятнадцать'];
        $tens=[2=>'двадцать',3=>'тридцать',4=>'сорок',5=>'пятьдесят',6=>'шестьдесят',7=>'семьдесят',8=>'восемьдесят',9=>'девяносто'];
        $hundreds=[1=>'сто',2=>'двести',3=>'триста',4=>'четыреста',5=>'пятьсот',6=>'шестьсот',7=>'семьсот',8=>'восемьсот',9=>'девятьсот'];
        $groups=[3=>['миллиард','миллиарда','миллиардов',false],2=>['миллион','миллиона','миллионов',false],1=>['тысяча','тысячи','тысяч',true],0=>['','','',false]];
        $chunks=[];
        foreach ([3,2,1,0] as $group) {
            $div=1000**$group;
            $chunk=intdiv($number,$div)%1000;
            if ($chunk===0) continue;
            $parts=[];
            $h=intdiv($chunk,100); if ($h>0) $parts[]=$hundreds[$h];
            $last=$chunk%100;
            if ($last>=10 && $last<=19) $parts[]=$teens[$last];
            else {
                $ten=intdiv($last,10); if ($ten>=2) $parts[]=$tens[$ten];
                $unit=$last%10; if ($unit>0) $parts[]=($groups[$group][3]?$unitsFemale:$unitsMale)[$unit];
            }
            if ($group>0) $parts[]=self::ruPlural($chunk,$groups[$group][0],$groups[$group][1],$groups[$group][2]);
            $chunks[]=implode(' ',$parts);
        }
        return implode(' ',$chunks);
    }

    private static function ruPlural(int $number, string $one, string $few, string $many): string
    {
        $n=abs($number)%100;
        if ($n>=11 && $n<=19) return $many;
        return match ($n%10) {1=>$one,2,3,4=>$few,default=>$many};
    }

    private static function signatureName(string $fullName): string
    {
        $parts=preg_split('/\s+/u',trim($fullName)) ?: [];
        if ($parts===[]) return '';
        $last=array_shift($parts);$initials='';
        foreach (array_slice($parts,0,2) as $part) { if ($part!=='' && preg_match('/^./us',$part,$m)) $initials.=$m[0].'.'; }
        return trim($last . ($initials!=='' ? ' '.$initials : ''));
    }

    private static function failed(array $errors): array
    {
        return ['ok'=>false,'errors'=>$errors,'warnings'=>[],'request_errors'=>[],'missing_request_ids'=>[],'found_count'=>0,'total_mass_kg'=>0.0,'total_mass_kg_formatted'=>'0','unload_groups'=>[]];
    }

    private static function json(mixed $value): string
    {
        return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
}
