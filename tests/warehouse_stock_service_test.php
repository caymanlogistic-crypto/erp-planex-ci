<?php

require_once __DIR__.'/../app/Service/WarehouseStockService.php';

use App\Service\WarehouseStockService;

$warehouses = [['id'=>1,'name'=>'Краснодар'], ['id'=>2,'name'=>'Иркутск']];
$flights = [
    ['id'=>10,'comment'=>'Прибытие','source_warehouse_id'=>null,'destination_warehouse_id'=>1,'zayavki_ids'=>'123, 456','driver_name'=>'Иванов','vehicle'=>'А 123 АА'],
    ['id'=>11,'comment'=>'Вывоз','source_warehouse_id'=>1,'destination_warehouse_id'=>null,'zayavki_ids'=>'123','driver_name'=>'Петров','vehicle'=>'В 456 ВВ'],
];
$movements = [
    ['id'=>1,'movement_type'=>'receipt','warehouse_id'=>1,'flight_id'=>10,'zayavka_id'=>123,'mass_netto'=>'1.500','movement_date'=>'2026-09-01','status'=>'active'],
    ['id'=>2,'movement_type'=>'receipt','warehouse_id'=>1,'flight_id'=>10,'zayavka_id'=>456,'mass_netto'=>'0.500','movement_date'=>'2026-09-01','status'=>'active'],
    ['id'=>3,'movement_type'=>'issue','warehouse_id'=>1,'flight_id'=>11,'zayavka_id'=>123,'mass_netto'=>'1.500','movement_date'=>'2026-09-02','status'=>'active'],
];
$erp = [
    ['business_id'=>10,'legacy_source_warehouse_id'=>null,'legacy_destination_warehouse_id'=>1,'zayavka_id'=>123,'snapshot_mass_kg'=>1500],
    ['business_id'=>10,'legacy_source_warehouse_id'=>null,'legacy_destination_warehouse_id'=>1,'zayavka_id'=>456,'snapshot_mass_kg'=>500],
    ['business_id'=>11,'legacy_source_warehouse_id'=>1,'legacy_destination_warehouse_id'=>null,'zayavka_id'=>123,'snapshot_mass_kg'=>1500],
];
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['current'][1] !== ['request_count'=>1,'weight_kg'=>500,'request_ids'=>[456]]) throw new RuntimeException('Неверный текущий остаток или список заявок.');
if ($data['groups'][1][0]['stock_count'] !== 1 || $data['groups'][1][0]['stock_kg'] !== 500) throw new RuntimeException('Неверный остаток после вывоза.');
if ($data['groups'][1][1]['stock_count'] !== 2 || $data['groups'][1][1]['stock_kg'] !== 2000) throw new RuntimeException('Неверный остаток после прихода.');
if ($data['issue_count'] !== 0) throw new RuntimeException('Ложное расхождение.');
$withoutWarehouseRequest = array_values(array_filter($erp, static fn(array $row): bool => !((int)$row['business_id'] === 10 && (string)$row['zayavka_id'] === '456')));
$difference = WarehouseStockService::build($warehouses, $movements, $flights, $withoutWarehouseRequest);
if ($difference['groups'][1][1]['warehouse_only_request_ids'] !== ['456'] || $difference['groups'][1][1]['trip_only_request_ids'] !== []) {
    throw new RuntimeException('Не выявлена заявка склада вне состава рейса ERP.');
}
if ($difference['current'][1] !== ['request_count'=>0,'weight_kg'=>0,'request_ids'=>[]]
    || $difference['groups'][1][1]['mass_kg'] !== 1500
    || $difference['groups'][1][1]['request_count'] !== 1
    || !$difference['groups'][1][1]['requests'][1]['excluded']
    || $difference['groups'][1][1]['requests'][1]['before_kg'] !== $difference['groups'][1][1]['requests'][1]['after_kg']) {
    throw new RuntimeException('Удалённая из рейса заявка должна остаться видимой, но не влиять на движения и остаток.');
}
if (!isset($difference['issues'][10]) || !str_contains(implode(' ', $difference['issues'][10]['messages']), 'на складе есть заявки вне рейса ERP: 456')) {
    throw new RuntimeException('Расхождение состава склада и рейса не попало в сверку.');
}
if (!str_contains(implode(' ', array_map([WarehouseStockService::class, 'displayMessage'], $difference['issues'][10]['messages'])), 'не участвуют в остатке')) {
    throw new RuntimeException('Удалённая заявка не объяснена в сверке.');
}
if ($difference['issues'][10]['name'] !== 'Прибытие') throw new RuntimeException('Наименование рейса не передано в расхождения.');
$withAddedReceipt = $withoutWarehouseRequest;
$withAddedReceipt[] = ['business_id'=>10,'legacy_source_warehouse_id'=>null,'legacy_destination_warehouse_id'=>1,'zayavka_id'=>789,'snapshot_mass_kg'=>750];
$added = WarehouseStockService::build($warehouses, $movements, $flights, $withAddedReceipt);
if ($added['current'][1] !== ['request_count'=>1,'weight_kg'=>750,'request_ids'=>[789]]
    || $added['groups'][1][1]['mass_kg'] !== 2250
    || $added['groups'][1][1]['request_count'] !== 2
    || $added['groups'][1][1]['trip_only_request_ids'] !== ['789']
    || !$added['groups'][1][1]['is_derived_receipt']) {
    throw new RuntimeException('Добавленная в рейс заявка не учтена сразу по снимку ERP.');
}
$legacyDestination = array_map(static function (array $row): array {
    if ((int)$row['business_id'] === 10) $row['legacy_destination_warehouse_id'] = null;
    return $row;
}, $withAddedReceipt);
$addedWithLegacyDestination = WarehouseStockService::build($warehouses, $movements, $flights, $legacyDestination);
if ($addedWithLegacyDestination['current'][1] !== $added['current'][1]) {
    throw new RuntimeException('Новая заявка не учтена, когда склад назначения известен только старой базе.');
}
$recordedAdded = $movements;
$recordedAdded[] = ['id'=>4,'movement_type'=>'receipt','warehouse_id'=>1,'flight_id'=>10,'zayavka_id'=>789,
    'mass_netto'=>'0.750','movement_date'=>'2026-09-01','status'=>'active'];
$recorded = WarehouseStockService::build($warehouses, $recordedAdded, $flights, $withAddedReceipt);
if ($recorded['current'][1]['weight_kg'] !== 750 || $recorded['groups'][1][1]['is_derived_receipt']) {
    throw new RuntimeException('Появившееся реальное поступление задвоило расчётную заявку.');
}
$flights[1]['zayavki_ids'] = '123,456';
$erp[] = ['business_id'=>11,'legacy_source_warehouse_id'=>1,'legacy_destination_warehouse_id'=>null,'zayavka_id'=>456,'snapshot_mass_kg'=>500];
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['groups'][1][0]['request_ids'] !== ['123'] || $data['groups'][1][0]['trip_only_request_ids'] !== ['456']) throw new RuntimeException('Не выявлена дополнительная заявка рейса.');
if (!str_contains(implode(' ', $data['issues'][11]['messages']), 'со склада списано 1 из 2 заявок рейса. Не списаны: 456.')) throw new RuntimeException('Недостающее списание не объяснено понятным текстом.');
if ($data['issues'][11]['movement_label'] !== 'Складской журнал: 1 заявка · ERP: 2 заявки') throw new RuntimeException('Источники состава не разделены в подписи журнала.');
if (WarehouseStockService::displayMessage('Состав заявок: только в старой базе —; только в ERP 302980, 303129.')
    !== 'Рассинхронизация баз: в рейсе ERP есть заявки 302980, 303129, которых нет в списке рейса старой базы.') throw new RuntimeException('Рассинхронизация при заявках только в ERP не объяснена.');
if (WarehouseStockService::displayMessage('Состав заявок: только в старой базе 302980; только в ERP —.')
    !== 'Рассинхронизация баз: в списке рейса старой базы есть заявки 302980, которых нет в рейсе ERP.') throw new RuntimeException('Рассинхронизация при заявках только в старой базе не объяснена.');
if (WarehouseStockService::displayMessage('Состав заявок: только в старой базе 302980; только в ERP 303129.')
    !== 'Рассинхронизация баз: состав заявок рейса различается. Только в старой базе: 302980. Только в ERP: 303129.') throw new RuntimeException('Двусторонняя рассинхронизация не объяснена.');
if (WarehouseStockService::displayMessage('Масса по рейсу: без изменений.') !== 'Масса по рейсу: без изменений.') throw new RuntimeException('Другая ошибка изменила текст.');
if ($data['groups'][1][0]['request_count'] !== 1 || $data['groups'][1][0]['mass_kg'] !== 1500 || $data['issue_count'] !== 1) throw new RuntimeException('Дополнительная заявка изменила складской итог или не попала в сверку.');
$erp[0]['legacy_destination_warehouse_id'] = 2;
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['issue_count'] !== 2 || !isset($data['issues'][10])) throw new RuntimeException('Не обнаружено расхождение складов.');
$erp[] = ['business_id'=>12,'erp_name'=>'Рейс только в ERP','legacy_source_warehouse_id'=>1,'legacy_destination_warehouse_id'=>null,'zayavka_id'=>999,'snapshot_mass_kg'=>100];
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['issue_count'] !== 3 || !isset($data['issues'][12])) throw new RuntimeException('Не обнаружен лишний складской рейс ERP.');
if ($data['issues'][12]['name'] !== 'Рейс только в ERP') throw new RuntimeException('Наименование рейса только в ERP не показано.');

$flights[] = ['id'=>13,'comment'=>'Начался','status'=>'started','source_warehouse_id'=>1,'destination_warehouse_id'=>null,'actual_start_date'=>'2026-09-03','zayavki_ids'=>'456,999','driver_name'=>'Петров','vehicle'=>'В 456 ВВ'];
$erp[] = ['business_id'=>13,'status'=>'in_progress','actual_start_date'=>'2026-09-03','legacy_source_warehouse_id'=>1,'legacy_destination_warehouse_id'=>null,'zayavka_id'=>456,'snapshot_mass_kg'=>500];
$erp[] = ['business_id'=>13,'status'=>'in_progress','actual_start_date'=>'2026-09-03','legacy_source_warehouse_id'=>1,'legacy_destination_warehouse_id'=>null,'zayavka_id'=>999,'snapshot_mass_kg'=>100];
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['current'][1] !== ['request_count'=>0,'weight_kg'=>0,'request_ids'=>[]]) throw new RuntimeException('Начатый рейс не списал складской остаток.');
if ($data['groups'][1][0]['request_ids'] !== ['456'] || !$data['groups'][1][0]['is_derived']) throw new RuntimeException('Рейс должен показывать только списанную складскую заявку.');
$flights[2]['status'] = 'completed';
$erp[5]['status'] = 'completed';
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['current'][1]['weight_kg'] !== 0) throw new RuntimeException('Завершённый рейс повторно списал груз.');
$movements[] = ['id'=>4,'movement_type'=>'issue','warehouse_id'=>1,'flight_id'=>13,'zayavka_id'=>456,'mass_netto'=>'0.500','movement_date'=>'2026-09-03','status'=>'active'];
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['current'][1]['weight_kg'] !== 0 || $data['groups'][1][0]['is_derived']) throw new RuntimeException('Дублируется реальное списание складского журнала.');
$flights[] = ['id'=>286,'comment'=>'Сверка','status'=>'completed','source_warehouse_id'=>null,'destination_warehouse_id'=>1,'zayavki_ids'=>'311706','driver_name'=>'Иванов','vehicle'=>'А 123 АА'];
$movements[] = ['id'=>5,'movement_type'=>'receipt','warehouse_id'=>1,'flight_id'=>286,'zayavka_id'=>311706,'mass_netto'=>'2.129','movement_date'=>'2026-09-04','status'=>'active'];
$erp[] = ['business_id'=>286,'legacy_source_warehouse_id'=>null,'legacy_destination_warehouse_id'=>1,'zayavka_id'=>999,'snapshot_mass_kg'=>669];
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
$message = 'Краснодар / поступление: на складе есть заявки вне рейса ERP: 311706.';
if (($data['issues'][286]['reconcile_targets'][$message] ?? null) !== ['warehouse_id'=>1,'direction'=>'in']) throw new RuntimeException('Кнопка должна относиться к нужному складу и рейсу.');
$flights[] = ['id'=>309,'comment'=>'Вывоз с другой массой','status'=>'completed','source_warehouse_id'=>1,'destination_warehouse_id'=>null,'zayavki_ids'=>'311706','driver_name'=>'Иванов','vehicle'=>'А 123 АА'];
$movements[] = ['id'=>6,'movement_type'=>'issue','warehouse_id'=>1,'flight_id'=>309,'zayavka_id'=>311706,'mass_netto'=>'4.258','movement_date'=>'2026-09-05','status'=>'active'];
$erp[] = ['business_id'=>309,'legacy_source_warehouse_id'=>1,'legacy_destination_warehouse_id'=>null,'zayavka_id'=>311706,'snapshot_mass_kg'=>2129];
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
$massTargets = array_values($data['issues'][309]['reconcile_targets'] ?? []);
if ($massTargets !== [['warehouse_id'=>1,'direction'=>'out']]) throw new RuntimeException('Рейс с одной лишь разницей масс тоже можно сверить.');
$original = $data;
$message = $original['issues'][309]['messages'][0];
$exception = ['id'=>1,'flight_id'=>309,'message_hash'=>WarehouseStockService::messageHash($message),
    'message_text'=>$message,'trip_name'=>'Вывоз с другой массой','warehouse_name'=>'Краснодар',
    'approved_by_user_id'=>7,'approved_at'=>'2026-10-01 10:00:00','revoked_by_user_id'=>null,'revoked_at'=>null];
$excluded = WarehouseStockService::build($warehouses, $movements, $flights, $erp, [$exception]);
if (in_array($message, $excluded['issues'][309]['messages'] ?? [], true)) throw new RuntimeException('Согласованное расхождение осталось ошибкой.');
if ($excluded['current'] !== $original['current'] || $excluded['groups'] !== $original['groups']) throw new RuntimeException('Исключение изменило складской учёт.');
$exception['message_hash'] = WarehouseStockService::messageHash($message.' изменено');
$changed = WarehouseStockService::build($warehouses, $movements, $flights, $erp, [$exception]);
if (!in_array($message, $changed['issues'][309]['messages'] ?? [], true)) throw new RuntimeException('Старое исключение скрывает изменившееся расхождение.');
$exception['message_hash'] = WarehouseStockService::messageHash($message);
$exception['revoked_at'] = '2026-10-01 11:00:00';
$restored = WarehouseStockService::build($warehouses, $movements, $flights, $erp, [$exception]);
if (!in_array($message, $restored['issues'][309]['messages'] ?? [], true)) throw new RuntimeException('Возвращённое расхождение не стало ошибкой.');
echo "warehouse stock service OK\n";
