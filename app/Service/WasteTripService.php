<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__.'/WasteTripStartStatisticsService.php';
require_once __DIR__.'/WasteTripLegacyMetadataService.php';
require_once __DIR__.'/RegistryManagerPresentation.php';

final class WasteTripService
{
    public const STATUS_PLANNED = 'planned_route';
    public const STATUS_SEARCHING = 'searching_executor';
    public const STATUS_ASSIGNED = 'executor_assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const CUTOVER_MIGRATION = '130_waste_trip_legacy_linked_cutover.sql';
    public const CUSTOMER_PAYMENTS_MIGRATION = '134_create_waste_trip_customer_payments.sql';
    public const CARRIER_PAYMENTS_MIGRATION = '135_create_waste_trip_carrier_payments.sql';
    public const SYNCHRONIZATION_MIGRATION = '137_waste_trip_synchronization.sql';
    public const GENERAL_PAYMENTS_MIGRATION = '138_waste_trip_general_payments.sql';
    public const LEGACY_METADATA_MIGRATION = '149_waste_trip_legacy_metadata.sql';
    public const PRESERVED_SETTLEMENTS_NOTICE = 'По этому рейсу сохранены прежние взаиморасчёты: счета, оплаты, суммы и сроки обязательств. Три суммы в карточке справочные и не изменяют задолженность. Документы клиенту используют сохранённые условия.';

    public static function generalCustomerDoesNotPay(array $trip): bool
    {
        return ($trip['destination_type'] ?? null) === 'warehouse'
            || ($trip['legacy_unload_type'] ?? null) === 'SKLAD'
            || in_array(($trip['legacy_route_type'] ?? null), ['generator_to_warehouse', 'warehouse_to_warehouse'], true);
    }

    public static function hasPreservedSettlements(array $trip): bool
    {
        return (int)($trip['financial_model_version'] ?? 2) === 3;
    }

    /** The cutover audit retains the original schedule and its source row identities. */
    public static function financialCustomerPayments(PDO $pdo, array $trip): array
    {
        if (!self::hasPreservedSettlements($trip)) return (array)($trip['customer_payments'] ?? []);
        $query = $pdo->prepare("SELECT before_json,after_json FROM waste_trip_audit_log WHERE waste_trip_id=? AND action='update' ORDER BY id");
        $query->execute([(int)$trip['id']]);
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $after = json_decode((string)$row['after_json'], true, 512, JSON_THROW_ON_ERROR);
            if (($after['settlements_preserved'] ?? false) !== true) continue;
            $before = json_decode((string)$row['before_json'], true, 512, JSON_THROW_ON_ERROR);
            if (isset($before['customer_payments']) && is_array($before['customer_payments'])) return $before['customer_payments'];
        }
        throw new RuntimeException('Не найдены сохранённые финансовые условия рейса.');
    }
    public const ERP_BUSINESS_ID_START = 400;
    public const LEGACY_RANGE_WARNING_THRESHOLD = 390;
    public const LEGACY_RANGE_WARNING = 'Legacy ID range approaching ERP range start (400)';

    public const STATUS_LABELS = [
        self::STATUS_PLANNED => 'Планируемый',
        self::STATUS_SEARCHING => 'Планируемый',
        self::STATUS_ASSIGNED => 'Сформированный',
        self::STATUS_IN_PROGRESS => 'Вывоз начался',
        self::STATUS_COMPLETED => 'Груз сдан',
        self::STATUS_CANCELLED => 'Отменён',
    ];

    private const TRANSITIONS = [
        self::STATUS_PLANNED => [self::STATUS_PLANNED, self::STATUS_SEARCHING, self::STATUS_ASSIGNED, self::STATUS_CANCELLED],
        self::STATUS_SEARCHING => [self::STATUS_PLANNED, self::STATUS_SEARCHING, self::STATUS_ASSIGNED, self::STATUS_CANCELLED],
        self::STATUS_ASSIGNED => [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [self::STATUS_COMPLETED],
        self::STATUS_CANCELLED => [self::STATUS_CANCELLED],
    ];

    private const LEGACY_OWNED_INPUT_KEYS = [
        'business_id', 'comment', 'planned_start_mode', 'planned_start_date', 'planned_start_date_from',
        'planned_start_date_to', 'planned_finish_date', 'actual_start_date', 'actual_end_date', 'request_ids',
        'legal_entity_code', 'legacy_route_type', 'legacy_unload_type', 'legacy_source_warehouse_id',
        'legacy_destination_warehouse_id', 'legacy_source_warehouse_name', 'legacy_destination_warehouse_name',
    ];

    public static function schemaReady(PDO $pdo): bool
    {
        try {
            $tables = ['waste_trips', 'waste_trip_requests', 'waste_trip_customer_payments', 'waste_trip_carrier_payments', 'waste_trip_general_payments', 'waste_trip_audit_log', 'waste_trip_namespace_state', 'schema_migrations'];
            $stmt = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tables), '?')) . ')');
            $stmt->execute($tables);
            if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== count($tables)) return false;
            $old = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trip_legacy_import_audits'");
            $old->execute();
            if ((int) $old->fetchColumn() !== 0) return false;
            $requiredColumns = ['business_id', 'planned_finish_date', 'legacy_sync_error', 'legacy_last_checked_at', 'legacy_last_success_at', 'deleted_at', 'financial_model_version', 'no_markup'];
            $cols = $pdo->prepare('SELECT COLUMN_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'waste_trips\' AND COLUMN_NAME IN (' . implode(',', array_fill(0, count($requiredColumns), '?')) . ')');
            $cols->execute($requiredColumns);
            $rows = $cols->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($requiredColumns)) return false;
            foreach ($rows as $row) if ($row['COLUMN_NAME'] === 'business_id' && $row['IS_NULLABLE'] !== 'NO') return false;
            $archivalColumn = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trip_requests' AND COLUMN_NAME='removed_at'");
            if ((int)$archivalColumn->fetchColumn() !== 1) return false;
            $unique = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trips' AND INDEX_NAME='uk_waste_trip_business_id' AND NON_UNIQUE=0");
            $unique->execute();
            if ((int) $unique->fetchColumn() !== 1) return false;
            $migration = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration IN (?,?,?,?,?)');
            $migration->execute([self::CUTOVER_MIGRATION, self::CUSTOMER_PAYMENTS_MIGRATION, self::CARRIER_PAYMENTS_MIGRATION, self::SYNCHRONIZATION_MIGRATION, self::GENERAL_PAYMENTS_MIGRATION]);
            if ((int) $migration->fetchColumn() !== 5) return false;
            $archivalMigration = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');
            $archivalMigration->execute(['158_waste_trip_request_archival.sql']);
            if ((int)$archivalMigration->fetchColumn() !== 1) return false;
            $state = $pdo->query('SELECT legacy_high_water_at_cutover,last_issued_business_id,cutover_completed_at FROM waste_trip_namespace_state WHERE singleton_id=1')->fetch(PDO::FETCH_ASSOC);
            if (!$state || (int) $state['legacy_high_water_at_cutover'] < 243 || (int) $state['last_issued_business_id'] < 0 || empty($state['cutover_completed_at'])) return false;
            return true;
        } catch (Throwable) { return false; }
    }

    public static function legacyMetadataReady(PDO $pdo): bool
    {
        if (!self::schemaReady($pdo)) return false;
        $migration = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');
        $migration->execute([self::LEGACY_METADATA_MIGRATION]);
        if ((int)$migration->fetchColumn() !== 1) return false;
        $columns = WasteTripLegacyMetadataService::COLUMNS;
        $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'waste_trips\' AND COLUMN_NAME IN ('.implode(',',array_fill(0,count($columns),'?')).')');
        $query->execute($columns);
        return (int)$query->fetchColumn() === count($columns);
    }

    public const STATUS_OPTIONS = [
        self::STATUS_PLANNED => 'Планируемый',
        self::STATUS_ASSIGNED => 'Сформированный',
        self::STATUS_IN_PROGRESS => 'Вывоз начался',
        self::STATUS_COMPLETED => 'Груз сдан',
    ];

    public static function displayStatus(string $status): string { return $status === self::STATUS_SEARCHING ? self::STATUS_PLANNED : $status; }
    public static function hasNoMarkup(array $trip): bool
    { return !empty($trip['no_markup']); }
    public static function statusCellClass(string $status): string
    {
        return match (self::displayStatus($status)) {
            self::STATUS_PLANNED => 'status-planned_route',
            self::STATUS_ASSIGNED => 'status-found',
            self::STATUS_IN_PROGRESS => 'status-started',
            self::STATUS_COMPLETED => 'status-completed',
            default => '',
        };
    }
    public static function statusLabel(string $status): string { return self::STATUS_LABELS[$status] ?? $status; }
    public static function statusBadgeClass(string $status): string
    {
        return match ($status) { self::STATUS_COMPLETED=>'badge badge-ok', self::STATUS_IN_PROGRESS,self::STATUS_ASSIGNED=>'badge badge-warning', self::STATUS_CANCELLED=>'badge badge-danger', default=>'badge badge-neutral' };
    }
    public static function allowedStatusTransitions(string $from): array { return self::TRANSITIONS[$from] ?? []; }

    public static function deleteMissingLegacyTrip(PDO $pdo, int $tripId, array $actor): void
    {
        if (($actor['role_code'] ?? '') !== 'company_owner') throw new RuntimeException('Синхронизация доступна руководителю компании.');
        $pdo->beginTransaction();
        try {
            $trip = self::fetchTripForUpdate($pdo, $tripId);
            if ($trip === null || !self::isSynchronizableLegacyTrip($trip)) throw new RuntimeException('Рейс не связан со старой БД.');
            if (!empty($trip['deleted_at'])) { $pdo->commit(); return; }
            self::assertCanAccessTrip($pdo, $trip, $actor);
            // Keep the trip/audit/payment identities for issued documents and paid facts.
            // Cancelled status releases FEO claims; deleted_at removes the trip from UI/selectors.
            $pdo->prepare("UPDATE waste_trips SET deleted_at=NOW(),status='cancelled',source_system='legacy_sync',
                cancellation_reason='Рейс удалён из старой БД',cancelled_at=NOW(),
                cancelled_by_user_id=?,cancelled_by_role=?,updated_by_user_id=?,updated_by_role=?,
                legacy_sync_error=NULL,legacy_last_checked_at=NOW(),legacy_last_success_at=NOW(),lock_version=lock_version+1 WHERE id=?")
                ->execute([(int)$actor['user_id'], $actor['role_code'], (int)$actor['user_id'], $actor['role_code'], $tripId]);
            FinanceObligationService::syncWasteTrip($pdo, $tripId);
            self::logAudit($pdo, $tripId, 'cancel', ['trip'=>$trip], ['deleted'=>true,'reason'=>'legacy_source_deleted'], $actor);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public static function isSynchronizableLegacyTrip(array $trip): bool
    {
        return in_array((string)($trip['source_system'] ?? ''), ['legacy_flights','legacy_prefill','legacy_sync'], true)
            && (int)($trip['legacy_flight_id'] ?? 0) > 225
            && (int)($trip['legacy_flight_id'] ?? 0) === (int)($trip['business_id'] ?? 0);
    }

    public static function delete(PDO $pdo, int $tripId, int $expectedVersion, array $actor): void
    {
        if (($actor['role_code'] ?? '') !== 'company_owner') throw new RuntimeException('Удаление доступно руководителю компании.');
        $pdo->beginTransaction();
        try {
            $trip = self::fetchTripForUpdate($pdo, $tripId);
            if ($trip === null || !empty($trip['deleted_at'])) throw new RuntimeException('Рейс не найден.');
            self::assertCanAccessTrip($pdo, $trip, $actor);
            if ((int)$trip['lock_version'] !== $expectedVersion) throw new RuntimeException('Рейс уже был изменён. Обновите страницу.');
            $userId = (int)($actor['user_id'] ?? 0) ?: null;
            $role = (string)$actor['role_code'];
            $stmt = $pdo->prepare("UPDATE waste_trips SET deleted_at=NOW(),status='cancelled',
                cancellation_reason=COALESCE(cancellation_reason,'Рейс удалён пользователем'),
                cancelled_at=COALESCE(cancelled_at,NOW()),cancelled_by_user_id=COALESCE(cancelled_by_user_id,?),
                cancelled_by_role=COALESCE(cancelled_by_role,?),updated_by_user_id=?,updated_by_role=?,
                lock_version=lock_version+1 WHERE id=? AND lock_version=? AND deleted_at IS NULL");
            $stmt->execute([$userId,$role,$userId,$role,$tripId,$expectedVersion]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Конфликт удаления рейса. Обновите страницу.');
            FinanceObligationService::syncWasteTrip($pdo, $tripId);
            self::logAudit($pdo,$tripId,'cancel',['trip'=>$trip],['deleted'=>true,'reason'=>'user_delete'],$actor);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** Apply one validated source flight atomically, preserving its ERP identity. */
    public static function reconcileLegacyTrip(PDO $pdo, array $state, array $actor): string
    {
        if (($actor['role_code'] ?? '') !== 'company_owner') throw new RuntimeException('Синхронизация доступна руководителю компании.');
        RegulatedDataScopeService::assertCanCreate('waste_trip');
        $id = (int)($state['business_id'] ?? 0);
        if ($id < 226) throw new RuntimeException('ID старого рейса должен быть больше 225.');
        $stage = 'transaction_begin';
        try {
            $pdo->beginTransaction();
            // Share the independent-number allocator's lock as the source can grow beyond 400.
            $stage = 'identity_lock';
            self::namespaceState($pdo, true);
            $query = $pdo->prepare("SELECT * FROM waste_trips WHERE business_id=? OR (legacy_flight_id=? AND source_system IN ('legacy_flights','legacy_prefill','legacy_sync')) FOR UPDATE");
            $query->execute([$id, $id]);
            $matches = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($matches) > 1) throw new RuntimeException('Несколько ERP-рейсов связаны с этим номером старой БД.');
            $trip = $matches[0] ?? null;
            if ($trip !== null) {
                if (!self::isSynchronizableLegacyTrip($trip)) throw new RuntimeException('Номер #'.$id.' занят самостоятельным ERP-рейсом.');
                if (!RegulatedDataScopeService::canAccess($pdo,'waste_trip','waste_trips',(int)$trip['id'])) throw new RuntimeException('Рейс недоступен.');
                if (!empty($trip['deleted_at']) && self::wasManuallyDeleted($pdo,(int)$trip['id'])) {
                    $pdo->commit();
                    return 'skipped';
                }
            }
            $stage = 'request_resolution';
            $resolved = self::resolveRequestsForClaim($pdo, $state['request_ids'], false);
            $tripId = $trip === null ? null : (int)$trip['id'];
            $stage = 'claim_check';
            self::assertNoIndependentClaims($pdo, array_column($resolved, 'id'), $tripId);
            $manualExecutor = $trip === null ? null : self::manualExecutorChoice($pdo, $tripId);
            if ($manualExecutor !== null) $state['route_executor_id'] = $manualExecutor['id'];
            $data = self::normalizeInput($state + ['planned_finish_date'=>$trip['planned_finish_date'] ?? null]);
            $data['utilizer_id'] = self::resolveSynchronizedUtilizer($pdo, $data['destination_type'], $state['request_ids']);
            // Open date ranges and unmapped source executors are legitimate source states.
            $errors = self::validateInput($data, true, true, true, true, true);
            if ($errors !== []) throw new RuntimeException(implode(' ', $errors));
            $stage = 'entity_visibility';
            self::assertEntitiesVisible($pdo, $data, $actor, $manualExecutor !== null);
            $before = $trip === null ? null : ['trip'=>$trip,'requests'=>self::fetchRequestLinks($pdo,$tripId,false),'carrier_payments'=>WasteTripCarrierPaymentService::fetchForTrip($pdo,$tripId)];
            $stage = 'trip_write';
            if ($trip === null) {
                $tripId = self::insertTrip($pdo, $id, $data, 'legacy_sync', $id, $actor);
            } else {
                $assignments = [];
                foreach (array_keys(self::tripParams($data)) as $key) $assignments[] = substr($key,1).'='.$key;
                $params = self::tripUpdateParams($data,$actor) + [':trip_id'=>$tripId];
                $pdo->prepare('UPDATE waste_trips SET '.implode(',',$assignments).",source_system='legacy_sync',deleted_at=NULL,cancelled_at=NULL,cancelled_by_user_id=NULL,cancelled_by_role=NULL,updated_by_user_id=:user_id,updated_by_role=:role_code,lock_version=lock_version+1 WHERE id=:trip_id")->execute($params);
            }
            self::writeLegacyMetadata($pdo, $tripId, $state);
            $stage = 'request_links';
            self::reconcileRequestLinks($pdo, $tripId, $resolved);
            WasteTripStartStatisticsService::synchronizeTrip($pdo, $tripId);
            $stage = 'source_amounts';
            if ($trip !== null && !in_array((int)$trip['financial_model_version'], [2,3], true)) throw new RuntimeException('Требуется переход на три финансовые суммы.');
            WasteTripGeneralPaymentService::synchronizeCost($pdo,$tripId,$state['general_amount'],$actor);
            WasteTripCustomerPaymentService::synchronizeCost($pdo,$tripId,$state['carrier_cost'],$state['payment_terms'],$actor);
            if (!empty($trip['no_markup'])) WasteTripCarrierPaymentService::synchronizeNoMarkup($pdo,$tripId,$actor);
            $warnings = array_filter([$data['destination_type']==='utilizer' && $data['utilizer_id']===null ? 'Утилизатор не определён однозначно по заявкам ФЭО.' : null]);
            $pdo->prepare('UPDATE waste_trips SET legacy_sync_error=?,legacy_last_checked_at=NOW(),legacy_last_success_at=NOW() WHERE id=?')->execute([$warnings === [] ? null : implode(' ', $warnings),$tripId]);
            $stage = 'finance_obligations';
            FinanceObligationService::syncWasteTrip($pdo, $tripId);
            $stage = 'audit';
            self::logAudit($pdo,$tripId,$trip === null ? 'create' : 'update',$before,['source'=>'legacy_sync','trip'=>$data,'request_ids'=>$state['request_ids'],'carrier_cost'=>$state['carrier_cost']],$actor);
            $stage = 'commit';
            $pdo->commit();
            return $trip === null ? 'created' : 'updated';
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (!$error instanceof RuntimeException || $error instanceof \PDOException) {
                $incident = WasteTripIncidentService::record($error,['operation'=>'legacy_sync_upsert','legacy_id'=>$id,'stage'=>$stage,'actor_user_id'=>$actor['user_id'],'actor_role'=>$actor['role_code']]);
                throw new RuntimeException(self::legacySyncFailureDescription($error,$stage).' Код ошибки: '.$incident.'.',0,$error);
            }
            throw $error;
        }
    }

    private static function resolveSynchronizedUtilizer(PDO $pdo, string $destination, array $ids): ?int
    {
        if ($destination === 'warehouse') return null;
        $query = $pdo->prepare('SELECT zayavka_id,gruzopoluchatel FROM feo_requests WHERE zayavka_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
        $query->execute($ids);
        $classifications = (new FeoUtilizerService())->classifyRequests($pdo,$query->fetchAll(PDO::FETCH_ASSOC));
        $matches = [];
        foreach ($classifications as $classification) {
            if ($classification['status'] !== 'matched') return null;
            $matches[(int)$classification['utilizer_id']] = true;
        }
        return count($matches) === 1 ? (int)array_key_first($matches) : null;
    }

    private static function assertNoIndependentClaims(PDO $pdo, array $ids, ?int $tripId): void
    {
        $marks = implode(',',array_fill(0,count($ids),'?'));
        $query = $pdo->prepare("SELECT DISTINCT wt.* FROM waste_trip_requests wr JOIN waste_trips wt ON wt.id=wr.waste_trip_id WHERE wr.feo_request_id IN ({$marks}) AND wr.removed_at IS NULL AND wt.status<>'cancelled' AND wt.id<>? FOR UPDATE");
        $query->execute(array_merge($ids,[$tripId ?? 0]));
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $claim) {
            if (!self::isSynchronizableLegacyTrip($claim)) throw new RuntimeException('Заявки заняты самостоятельным ERP-рейсом #'.$claim['business_id'].'.');
        }
    }

    public static function parseRequestIds(string $raw): array
    {
        $parts=preg_split('/[\s,;]+/u',trim($raw),-1,PREG_SPLIT_NO_EMPTY)?:[];$ids=[];$seen=[];$duplicates=[];$invalid=[];
        foreach($parts as$part){$id=trim((string)$part);if($id===''||preg_match('/^[A-Za-zА-Яа-яЁё0-9._\/-]+$/u',$id)!==1){if($id!=='')$invalid[]=$id;continue;}if(isset($seen[$id])){$duplicates[]=$id;continue;}$seen[$id]=true;$ids[]=$id;}
        return['ids'=>$ids,'duplicates'=>array_values(array_unique($duplicates)),'invalid'=>array_values(array_unique($invalid))];
    }

    public static function normalizeInput(array $input): array
    {
        return['client_id'=>(int)($input['client_id']??0),'route_executor_id'=>(int)($input['route_executor_id']??0)?:null,'responsible_user_id'=>(int)($input['responsible_user_id']??0),'destination_type'=>trim((string)($input['destination_type']??'')),'utilizer_id'=>(int)($input['utilizer_id']??0)?:null,'status'=>trim((string)($input['status']??self::STATUS_PLANNED)),'planned_start_mode'=>trim((string)($input['planned_start_mode']??'')),'planned_start_date'=>self::normalizeDate($input['planned_start_date']??null),'planned_start_date_from'=>self::normalizeDate($input['planned_start_date_from']??null),'planned_start_date_to'=>self::normalizeDate($input['planned_start_date_to']??null),'planned_finish_date'=>self::normalizeDate($input['planned_finish_date']??null),'actual_start_date'=>self::normalizeDate($input['actual_start_date']??null),'actual_end_date'=>self::normalizeDate($input['actual_end_date']??null),'comment'=>self::nullableText($input['comment']??null,500),'cancellation_reason'=>self::nullableText($input['cancellation_reason']??null,500)];
    }

    public static function validateInput(array $data,bool $allowUnspecifiedPlan=false,bool $allowHistoricalUnmappedExecutor=false,bool $allowOpenRange=false,bool $allowUnmappedUtilizer=false,bool $allowLegacyMissingActualDates=false): array
    {
        $errors=[];if((int)($data['client_id']??0)<=0)$errors[]='Выберите заказчика.';if((int)($data['responsible_user_id']??0)<=0)$errors[]='Выберите ответственного сотрудника.';
        $status=(string)($data['status']??'');if(!array_key_exists($status,self::STATUS_LABELS))$errors[]='Недопустимый статус рейса.';$executorId=(int)($data['route_executor_id']??0);
        if(in_array($status,[self::STATUS_ASSIGNED,self::STATUS_IN_PROGRESS,self::STATUS_COMPLETED],true)&&$executorId<=0&&!$allowHistoricalUnmappedExecutor)$errors[]='Для выбранного статуса требуется исполнитель рейса.';
        $destination=(string)($data['destination_type']??'');if(!in_array($destination,['utilizer','warehouse'],true))$errors[]='Выберите назначение выгрузки.';elseif($destination==='utilizer'&&(int)($data['utilizer_id']??0)<=0&&!$allowUnmappedUtilizer)$errors[]='Для выгрузки у утилизатора выберите утилизатора.';elseif($destination==='warehouse'&&!empty($data['utilizer_id']))$errors[]='Для выгрузки на склад утилизатор не указывается.';
        $mode=(string)($data['planned_start_mode']??'');$exact=$data['planned_start_date']??null;$from=$data['planned_start_date_from']??null;$to=$data['planned_start_date_to']??null;
        if($mode==='exact'){if($exact===null)$errors[]='Для точной плановой даты укажите дату.';if($from!==null||$to!==null)$errors[]='Для точной даты диапазон не заполняется.';}elseif($mode==='range'){if(($from===null||$to===null)&&!$allowOpenRange)$errors[]='Для диапазона нужны обе границы дат.';if($allowOpenRange&&$from===null&&$to===null)$errors[]='Укажите хотя бы одну границу диапазона.';if($exact!==null)$errors[]='Для диапазона точная дата не заполняется.';if($from!==null&&$to!==null&&$from>$to)$errors[]='Начало планового диапазона позже окончания.';}elseif(!($allowUnspecifiedPlan&&$mode==='unspecified'&&$exact===null&&$from===null&&$to===null))$errors[]='Выберите режим плановой даты: точная дата или диапазон.';
        $plannedFinish=$data['planned_finish_date']??null;$plannedFloor=$exact??$from;if($plannedFinish!==null&&$plannedFloor!==null&&$plannedFinish<$plannedFloor)$errors[]='Плановое окончание не может быть раньше планового начала.';
        $actualStart=$data['actual_start_date']??null;$actualEnd=$data['actual_end_date']??null;if($actualStart!==null&&$actualEnd!==null&&$actualStart>$actualEnd)$errors[]='Фактическая дата начала позже даты окончания.';if(!$allowLegacyMissingActualDates&&$status===self::STATUS_IN_PROGRESS&&$actualStart===null)$errors[]='Для статуса «Вывоз начался» укажите фактическую дату начала.';if(!$allowLegacyMissingActualDates&&$status===self::STATUS_COMPLETED&&($actualStart===null||$actualEnd===null))$errors[]='Для завершённого рейса нужны обе фактические даты.';if($status===self::STATUS_CANCELLED&&trim((string)($data['cancellation_reason']??''))==='')$errors[]='Для отмены рейса укажите причину.';return$errors;
    }

    public static function createIndependent(PDO $pdo,PDO $legacyPdo,array $input,array $requestBusinessIds,array $sessionUser,?array $customerPayments=null,?array $carrierPayments=null,?array $generalPayments=null): int
    {
        RegulatedDataScopeService::assertCanCreate('waste_trip');
        $data=self::normalizeInput($input);$errors=self::validateInput($data);if($requestBusinessIds===[])$errors[]='Добавьте хотя бы одну заявку ФЭО.';if($errors!==[])throw new RuntimeException(implode(' ',$errors));if(!empty($input['no_markup'])&&($sessionUser['role_code']??'')!=='company_owner')throw new RuntimeException('Недостаточно прав для изменения себестоимости.');if(self::generalCustomerDoesNotPay($data)&&$generalPayments!==null)throw new RuntimeException('При выгрузке на склад стоимость генерального заказчика не заполняется.');self::assertEntitiesVisible($pdo,$data,$sessionUser);
        $pdo->beginTransaction();try{$businessId=self::allocateBusinessId($pdo,$legacyPdo);$resolved=self::resolveRequestsForClaim($pdo,$requestBusinessIds,true);self::assertNoActiveClaims($pdo,array_column($resolved,'id'));$tripId=self::insertTrip($pdo,$businessId,$data,null,null,$sessionUser);if(!empty($input['no_markup']))$pdo->prepare('UPDATE waste_trips SET no_markup=1 WHERE id=?')->execute([$tripId]);self::insertRequestLinks($pdo,$tripId,$resolved);WasteTripStartStatisticsService::synchronizeTrip($pdo,$tripId);if($generalPayments!==null)WasteTripGeneralPaymentService::store($pdo,$tripId,$generalPayments,$sessionUser);if($customerPayments!==null)WasteTripCustomerPaymentService::store($pdo,$tripId,$customerPayments,$sessionUser);if(!empty($input['no_markup']))WasteTripCarrierPaymentService::synchronizeNoMarkup($pdo,$tripId,$sessionUser);elseif($carrierPayments!==null)WasteTripCarrierPaymentService::store($pdo,$tripId,$carrierPayments,$sessionUser);FinanceObligationService::syncWasteTrip($pdo,$tripId);self::logAudit($pdo,$tripId,'create',null,['business_id'=>$businessId,'trip'=>$data,'request_ids'=>array_values($requestBusinessIds)],$sessionUser);$pdo->commit();return$businessId;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}

    }

    public static function materializeLinked(PDO $pdo,int $legacyId,array $operationalInput,array $sourceState,array $sessionUser): int
    {
        RegulatedDataScopeService::assertCanCreate('waste_trip');
        if($legacyId<WasteTripLegacySourceService::MIN_LEGACY_ID||(int)($sourceState['business_id']??0)!==$legacyId)throw new RuntimeException('Некорректная legacy identity.');self::assertLegacyOwnedMutationAbsent($operationalInput);$requestIds=array_values($sourceState['request_ids']??[]);if($requestIds===[])throw new RuntimeException('В legacy-рейсе нет полного состава заявок ФЭО.');
        $data=self::normalizeInput(array_merge($operationalInput,['status'=>$sourceState['status'],'planned_start_mode'=>$sourceState['planned_start_mode'],'planned_start_date'=>$sourceState['planned_start_date'],'planned_start_date_from'=>$sourceState['planned_start_date_from'],'planned_start_date_to'=>$sourceState['planned_start_date_to'],'planned_finish_date'=>$sourceState['planned_finish_date'],'actual_start_date'=>$sourceState['actual_start_date'],'actual_end_date'=>$sourceState['actual_end_date'],'comment'=>$sourceState['comment']]));$errors=self::validateInput($data,true,true,true,true,true);if($errors!==[])throw new RuntimeException(implode(' ',$errors));self::assertEntitiesVisible($pdo,$data,$sessionUser);
        $pdo->beginTransaction();try{$dup=$pdo->prepare('SELECT id FROM waste_trips WHERE business_id=? OR (source_system=? AND legacy_flight_id=?) FOR UPDATE');$dup->execute([$legacyId,WasteTripLegacySourceService::SOURCE_SYSTEM,$legacyId]);if($dup->fetchColumn()!==false)throw new RuntimeException('Этот legacy-рейс уже материализован или его business ID уже занят.');$resolved=self::resolveRequestsForClaim($pdo,$requestIds,false);self::assertNoIndependentClaims($pdo,array_column($resolved,'id'),null);$tripId=self::insertTrip($pdo,$legacyId,$data,WasteTripLegacySourceService::SOURCE_SYSTEM,$legacyId,$sessionUser);self::writeLegacyMetadata($pdo,$tripId,$sourceState);self::insertRequestLinks($pdo,$tripId,$resolved);WasteTripStartStatisticsService::synchronizeTrip($pdo,$tripId);$pdo->prepare('UPDATE waste_trips SET legacy_last_checked_at=NOW(),legacy_last_success_at=NOW(),legacy_sync_error=NULL WHERE id=?')->execute([$tripId]);self::logAudit($pdo,$tripId,'create',null,['business_id'=>$legacyId,'source_system'=>WasteTripLegacySourceService::SOURCE_SYSTEM,'request_ids'=>$requestIds],$sessionUser);$pdo->commit();return$legacyId;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    public static function update(PDO $pdo,int $tripId,array $input,array $requestBusinessIds,int $expectedVersion,array $sessionUser,?array $customerPayments=null,?array $carrierPayments=null,?array $generalPayments=null): void
    {
        if($tripId<=0||$expectedVersion<=0)throw new RuntimeException('Некорректная версия рейса. Обновите страницу и повторите действие.');$pdo->beginTransaction();
        try{$trip=self::fetchTripForUpdate($pdo,$tripId);if($trip===null)throw new RuntimeException('Рейс не найден.');self::assertCanAccessTrip($pdo,$trip,$sessionUser);if((int)$trip['lock_version']!==$expectedVersion)throw new RuntimeException('Рейс уже был изменён другим пользователем. Обновите страницу.');if((self::generalCustomerDoesNotPay($trip)||($input['destination_type']??null)==='warehouse')&&$generalPayments!==null)throw new RuntimeException('При выгрузке на склад стоимость генерального заказчика не заполняется.');if(($trip['legal_entity_code']??null)==='mg_logistic'&&$carrierPayments!==null)throw new RuntimeException('Для ООО «МГ Логистик» себестоимость не заполняется.');if(($sessionUser['role_code']??'')==='company_owner'){$noMarkup=!empty($input['no_markup']);}else{$noMarkup=!empty($trip['no_markup']);}if(!in_array((int)($trip['financial_model_version']??2),[2,3],true))throw new RuntimeException('Требуется переход на три финансовые суммы.');if($noMarkup&&self::hasPreservedSettlements($trip))throw new RuntimeException('Без наценки нельзя включить для рейса с сохранёнными историческими расчётами.');if(self::isSynchronizableLegacyTrip($trip)){self::assertSourceAmountUnchanged($generalPayments,WasteTripGeneralPaymentService::fetchForTrip($pdo,$tripId));}$customerAmountChanged=self::isSynchronizableLegacyTrip($trip)&&self::sourceAmountsDiffer($customerPayments,WasteTripCustomerPaymentService::fetchForTrip($pdo,$tripId));$linked=self::isLegacyLinked($trip);if($linked)self::assertLegacyOwnedMutationAbsent($input);$existingLinks=self::fetchRequestLinks($pdo,$tripId,true);$existingIds=array_map(static fn(array $r):string=>(string)$r['zayavka_id'],$existingLinks);
            if($linked){$requestBusinessIds=$existingIds;$data=self::normalizeInput(array_merge($input,['planned_start_mode'=>$trip['planned_start_mode'],'planned_start_date'=>$trip['planned_start_date'],'planned_start_date_from'=>$trip['planned_start_date_from'],'planned_start_date_to'=>$trip['planned_start_date_to'],'planned_finish_date'=>$trip['planned_finish_date'],'actual_start_date'=>$trip['actual_start_date'],'actual_end_date'=>$trip['actual_end_date'],'comment'=>$trip['comment']]));}else{if($requestBusinessIds===[])throw new RuntimeException('Добавьте хотя бы одну заявку ФЭО.');$data=self::normalizeInput($input);}
            $errors=self::validateInput($data,$linked&&$trip['planned_start_mode']==='unspecified',$linked&&empty($trip['route_executor_id']),($trip['source_system']??'')==='legacy_sync',($trip['source_system']??'')==='legacy_sync'&&empty($trip['utilizer_id']));if($errors!==[])throw new RuntimeException(implode(' ',$errors));self::assertEntitiesVisible($pdo,$data,$sessionUser);self::assertTransition((string)$trip['status'],$data['status']);
            $manualExecutorChanged=$linked && (int)($trip['route_executor_id']??0)!==(int)($data['route_executor_id']??0) && ((int)($data['route_executor_id']??0)>0 || self::manualExecutorChoice($pdo,$tripId)!==null);
            if(!$linked&&$existingIds!==array_values($requestBusinessIds)){if(in_array((string)$trip['status'],[self::STATUS_COMPLETED,self::STATUS_CANCELLED],true))throw new RuntimeException('Состав завершённого или отменённого рейса менять нельзя.');if((string)$trip['status']===self::STATUS_IN_PROGRESS&&(($sessionUser['role_code']??'')!=='company_owner'||(string)($input['confirm_composition_correction']??'')!=='1'))throw new RuntimeException('После начала вывоза изменение состава разрешено только руководителю с явным подтверждением корректировки.');$resolved=self::resolveRequestsForClaim($pdo,$requestBusinessIds,true);self::assertNoActiveClaims($pdo,array_column($resolved,'id'),$tripId);self::reconcileRequestLinks($pdo,$tripId,$resolved);}
            $stmt=$pdo->prepare('UPDATE waste_trips SET client_id=:client_id,route_executor_id=:route_executor_id,responsible_user_id=:responsible_user_id,destination_type=:destination_type,utilizer_id=:utilizer_id,status=:status,planned_start_mode=:planned_start_mode,planned_start_date=:planned_start_date,planned_start_date_from=:planned_start_date_from,planned_start_date_to=:planned_start_date_to,planned_finish_date=:planned_finish_date,actual_start_date=:actual_start_date,actual_end_date=:actual_end_date,comment=:comment,cancellation_reason=:cancellation_reason,no_markup=:no_markup,updated_by_user_id=:user_id,updated_by_role=:role_code,lock_version=lock_version+1 WHERE id=:id AND lock_version=:expected_version');$stmt->execute(self::tripUpdateParams($data,$sessionUser)+[':no_markup'=>$noMarkup?1:0,':id'=>$tripId,':expected_version'=>$expectedVersion]);if($stmt->rowCount()!==1)throw new RuntimeException('Конфликт изменения рейса. Обновите страницу.');WasteTripStartStatisticsService::synchronizeTrip($pdo,$tripId);if($generalPayments!==null)WasteTripGeneralPaymentService::store($pdo,$tripId,$generalPayments,$sessionUser);if($customerPayments!==null)WasteTripCustomerPaymentService::store($pdo,$tripId,$customerPayments,$sessionUser);if($noMarkup)WasteTripCarrierPaymentService::synchronizeNoMarkup($pdo,$tripId,$sessionUser);elseif($carrierPayments!==null)WasteTripCarrierPaymentService::store($pdo,$tripId,$carrierPayments,$sessionUser);FinanceObligationService::syncWasteTrip($pdo,$tripId);self::logAudit($pdo,$tripId,'update',['trip'=>$trip,'request_ids'=>$existingIds],['trip'=>$data,'request_ids'=>$requestBusinessIds]+($customerAmountChanged?['customer_amount_sync_disabled'=>true]:[])+($manualExecutorChanged?['executor_sync_disabled'=>true,'manual_route_executor_id'=>$data['route_executor_id']]:[]),$sessionUser);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }

    private static function assertSourceAmountUnchanged(?array $submitted, array $stored): void
    {
        if (self::sourceAmountsDiffer($submitted, $stored)) throw new RuntimeException('Сумма обновляется из старой БД. Можно изменить только условия оплаты.');
    }

    private static function sourceAmountsDiffer(?array $submitted, array $stored): bool
    {
        if ($submitted === null) return false;
        $signature = static function (array $rows): array {
            return array_map(static fn(array $row): ?string => $row['amount'] === null ? null : LinearRouteService::parseAmount((string)$row['amount']), $rows);
        };
        if ($stored === [] && $signature($submitted) === [null]) return false;
        return $signature($submitted) !== $signature($stored);
    }

    public static function syncLegacyOwnedState(PDO $pdo,int $tripId,array $sourceState): void
    {
        $businessId = (int)($sourceState['business_id'] ?? 0);
        $requestIds = array_values($sourceState['request_ids'] ?? []);
        if ($businessId < WasteTripLegacySourceService::MIN_SYNCHRONIZABLE_ID || $requestIds === []
            || !in_array($sourceState['status'] ?? null, [self::STATUS_PLANNED, self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED], true)) {
            throw new RuntimeException('Некорректный authoritative legacy state.');
        }
        $pdo->beginTransaction();
        try {
            $trip = self::fetchTripForUpdate($pdo, $tripId);
            if ($trip === null || !self::isLegacyLinked($trip)
                || (int)$trip['business_id'] !== $businessId
                || (int)$trip['legacy_flight_id'] !== $businessId) {
                throw new RuntimeException('Linked Waste Trip identity mismatch.');
            }
            $resolved = self::resolveRequestsForClaim($pdo, $requestIds, false);
            self::assertNoIndependentClaims($pdo, array_column($resolved, 'id'), $tripId);
            $stmt = $pdo->prepare('UPDATE waste_trips SET status=?,comment=?,planned_start_mode=?,planned_start_date=?,planned_start_date_from=?,planned_start_date_to=?,planned_finish_date=?,actual_start_date=?,actual_end_date=?,legacy_sync_error=NULL,legacy_last_checked_at=NOW(),legacy_last_success_at=NOW(),lock_version=lock_version+1 WHERE id=?');
            $stmt->execute([$sourceState['status'],$sourceState['comment'],$sourceState['planned_start_mode'],$sourceState['planned_start_date'],$sourceState['planned_start_date_from'],$sourceState['planned_start_date_to'],$sourceState['planned_finish_date'],$sourceState['actual_start_date'],$sourceState['actual_end_date'],$tripId]);
            self::writeLegacyMetadata($pdo, $tripId, $sourceState);
            self::reconcileRequestLinks($pdo, $tripId, $resolved);
            WasteTripStartStatisticsService::synchronizeTrip($pdo, $tripId);
            FinanceObligationService::syncWasteTrip($pdo, $tripId);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private static function writeLegacyMetadata(PDO $pdo, int $tripId, array $state): void
    {
        $values = WasteTripLegacyMetadataService::fromFlight($state);
        $columns = WasteTripLegacyMetadataService::COLUMNS;
        $assignments = implode(',', array_map(static fn(string $column): string => $column.'=?', $columns));
        $pdo->prepare('UPDATE waste_trips SET '.$assignments.' WHERE id=?')->execute(array_merge(array_map(static fn(string $column) => $values[$column], $columns), [$tripId]));
    }

    public static function markLegacySyncError(PDO $pdo,int $tripId,string $message): void
    { $message=self::nullableText($message,1000)??'Unknown legacy sync error';$stmt=$pdo->prepare("UPDATE waste_trips SET legacy_sync_error=?,legacy_last_checked_at=NOW() WHERE id=? AND source_system IN ('legacy_flights','legacy_sync')");$stmt->execute([$message,$tripId]); }

    public static function legacyRangeWarningForMax(int $legacyMax): ?string
    { return $legacyMax >= self::LEGACY_RANGE_WARNING_THRESHOLD ? self::LEGACY_RANGE_WARNING : null; }

    public static function legacyRangeDiagnostic(PDO $legacyPdo): array
    { $legacyMax=WasteTripLegacySourceService::maxLegacyId($legacyPdo);return['legacy_max_id'=>$legacyMax,'warning'=>self::legacyRangeWarningForMax($legacyMax)]; }

    public static function linkedTripsForSync(PDO $pdo,int $limit=500): array
    { $limit=max(1,min(500,$limit));return$pdo->query("SELECT id,business_id,legacy_flight_id FROM waste_trips WHERE source_system IN ('legacy_flights','legacy_sync') AND deleted_at IS NULL ORDER BY (legacy_last_checked_at IS NULL) DESC, legacy_last_checked_at ASC, id ASC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC); }

    public static function previewSelection(PDO $pdo,array $businessIds,bool $requireCurrentPlacement=true): array
    {
        $ids=array_values(array_unique(array_map('strval',$businessIds)));if($ids===[])return['input_count'=>0,'found_count'=>0,'missing'=>[],'occupied'=>[],'total_mass_kg'=>0.0,'requests'=>[]];$marks=implode(',',array_fill(0,count($ids),'?'));$join=$requireCurrentPlacement?' JOIN feo_placement_current pc ON pc.zayavka_id=fr.zayavka_id ': ' ';$stmt=$pdo->prepare("SELECT fr.id,fr.zayavka_id,fr.mass_netto,fr.naim_oo_gruzootpravitel,fr.mno_adres_pogruzki FROM feo_requests fr{$join}WHERE fr.zayavka_id IN ({$marks})");$stmt->execute($ids);$by=[];foreach($stmt->fetchAll(PDO::FETCH_ASSOC)as$row){$row['snapshot_mass_kg']=self::massKg($row['mass_netto']??null);$by[(string)$row['zayavka_id']]=$row;}$missing=[];$ordered=[];foreach($ids as$id){if(!isset($by[$id]))$missing[]=$id;else$ordered[]=$by[$id];}$claims=self::activeClaimMap($pdo,array_column($ordered,'id'));$occupied=[];foreach($ordered as$row)if(isset($claims[(int)$row['id']]))$occupied[(string)$row['zayavka_id']]=$claims[(int)$row['id']];return['input_count'=>count($ids),'found_count'=>count($ordered),'missing'=>$missing,'occupied'=>$occupied,'total_mass_kg'=>round(array_sum(array_map(static fn(array $r):float=>(float)$r['snapshot_mass_kg'],$ordered)),3),'requests'=>$ordered];
    }

    public static function fetchTrips(PDO $pdo,array $filters=[],bool $includeFinance=false): array
    {
        $access=RegulatedDataScopeService::condition('waste_trip','wt.id','wt.created_by_membership_id');$where=[$access['sql'],'wt.deleted_at IS NULL'];$params=$access['params'];$status=trim((string)($filters['status']??''));if($status!==''&&isset(self::STATUS_LABELS[$status])){if(in_array($status,[self::STATUS_PLANNED,self::STATUS_SEARCHING],true)){$where[]="wt.status IN ('planned_route','searching_executor')";}else{$where[]='wt.status=?';$params[]=$status;}}$company=(string)($filters['company']??'');if(in_array($company,['mg_logistic','planex'],true)){$where[]='wt.legal_entity_code=?';$params[]=$company;}
        $q=trim((string)($filters['q']??''));
        if($q!==''){
            // Match visible labels through the same PHP formatter; no newer SQL
            // functions or assumptions about DB Unicode/whitespace semantics.
            $managerIds=[];
            foreach(self::fetchRegistryManagers($pdo) as $option){
                if(mb_stripos(RegistryManagerPresentation::name((string)$option['full_name']),$q,0,'UTF-8')!==false)$managerIds[]=(int)$option['id'];
            }
            $managerMatch=$managerIds===[]?'1=0':'wt.responsible_user_id IN ('.implode(',',$managerIds).')';
            $term='%'.$q.'%';
            $where[]='(CAST(wt.business_id AS CHAR) LIKE ? OR wt.comment LIKE ? OR c.name LIKE ? OR u.full_name LIKE ? OR '.$managerMatch.' OR ct.name LIKE ? OR EXISTS (SELECT 1 FROM waste_trip_requests sqr JOIN feo_requests sqf ON sqf.id=sqr.feo_request_id WHERE sqr.waste_trip_id=wt.id AND sqr.removed_at IS NULL AND sqf.zayavka_id LIKE ?) OR wt.status LIKE ?)';
            for($i=0;$i<7;$i++)$params[]=$term;
        }
        $manager=(int)($filters['manager']??0);
        if($manager>0){$where[]='wt.responsible_user_id=?';$params[]=$manager;}
        $statusOrder="CASE wt.status WHEN 'planned_route' THEN 0 WHEN 'searching_executor' THEN 0 WHEN 'executor_assigned' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'completed' THEN 3 WHEN 'cancelled' THEN 4 ELSE 5 END";
        $orderBy=match ((string)($filters['sort']??'')) {
            'status' => $statusOrder.', wt.business_id DESC',
            'manager' => 'u.full_name ASC, u.membership_id ASC, '.$statusOrder.', wt.business_id DESC',
            'id' => 'wt.business_id DESC',
            default => $statusOrder.', wt.business_id DESC',
        };
        $sql="SELECT wt.*,c.name AS client_name,u.full_name AS responsible_name,u.membership_id AS responsible_membership_id,fu.short_name AS utilizer_short_name,fu.full_name AS utilizer_full_name,ct.name AS executor_contractor_name,GROUP_CONCAT(DISTINCT fr.zayavka_id ORDER BY wtr.position_no,wtr.id SEPARATOR ', ') AS request_ids,COUNT(DISTINCT wtr.id) AS request_count,COALESCE(SUM(wtr.snapshot_mass_kg),0) AS snapshot_total_mass_kg FROM waste_trips wt JOIN clients c ON c.id=wt.client_id JOIN users u ON u.id=wt.responsible_user_id LEFT JOIN feo_utilizers fu ON fu.id=wt.utilizer_id LEFT JOIN crews cr ON cr.id=wt.route_executor_id LEFT JOIN contractors ct ON ct.id=cr.contractor_id LEFT JOIN waste_trip_requests wtr ON wtr.waste_trip_id=wt.id AND wtr.removed_at IS NULL LEFT JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE ".implode(' AND ',$where).' GROUP BY wt.id,c.name,u.full_name,u.membership_id,fu.short_name,fu.full_name,ct.name ORDER BY '.$orderBy.' LIMIT 500';$stmt=$pdo->prepare($sql);$stmt->execute($params);$trips=$stmt->fetchAll(PDO::FETCH_ASSOC);self::enrichExecutorDisplay($pdo,$trips);if($includeFinance){$ids=array_column($trips,'id');$generalGrouped=WasteTripGeneralPaymentService::fetchForTrips($pdo,$ids);$customerGrouped=WasteTripCustomerPaymentService::fetchForTrips($pdo,$ids);$carrierGrouped=WasteTripCarrierPaymentService::fetchForTrips($pdo,$ids);foreach($trips as&$trip){$id=(int)$trip['id'];$trip['general_payments']=$generalGrouped[$id]??[];$trip['customer_payments']=$customerGrouped[$id]??[];$trip['carrier_payments']=$carrierGrouped[$id]??[];}unset($trip);}return$trips;
    }

    public static function fetchTrip(PDO $pdo,int $tripId,bool $includeFinance=false): ?array
    { $access=RegulatedDataScopeService::condition('waste_trip','wt.id','wt.created_by_membership_id');$stmt=$pdo->prepare('SELECT wt.*,c.name AS client_name,u.full_name AS responsible_name,u.membership_id AS responsible_membership_id,fu.short_name AS utilizer_short_name,fu.full_name AS utilizer_full_name,ct.name AS executor_contractor_name FROM waste_trips wt JOIN clients c ON c.id=wt.client_id JOIN users u ON u.id=wt.responsible_user_id LEFT JOIN feo_utilizers fu ON fu.id=wt.utilizer_id LEFT JOIN crews cr ON cr.id=wt.route_executor_id LEFT JOIN contractors ct ON ct.id=cr.contractor_id WHERE wt.id=? AND wt.deleted_at IS NULL AND '.$access['sql'].' LIMIT 1');$stmt->execute(array_merge([$tripId],$access['params']));$trip=$stmt->fetch(PDO::FETCH_ASSOC)?:null;if($trip===null)return null;$rows=[$trip];self::enrichExecutorDisplay($pdo,$rows);$trip=$rows[0];$trip['requests']=self::fetchRequestLinks($pdo,$tripId,false);if($includeFinance){$trip['customer_amount_sync_disabled']=WasteTripCustomerPaymentService::amountSyncDisabled($pdo,$tripId);$trip['general_payments']=WasteTripGeneralPaymentService::fetchForTrip($pdo,$tripId);$trip['customer_payments']=WasteTripCustomerPaymentService::fetchForTrip($pdo,$tripId);$trip['carrier_payments']=WasteTripCarrierPaymentService::fetchForTrip($pdo,$tripId);}return$trip; }

    public static function fetchActiveUsers(PDO $pdo): array { return$pdo->query("SELECT id,full_name,role_code FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC); }
    public static function fetchRegistryManagers(PDO $pdo): array
    {
        $access=RegulatedDataScopeService::condition('waste_trip','wt.id','wt.created_by_membership_id');
        $stmt=$pdo->prepare('SELECT DISTINCT u.id,u.full_name FROM waste_trips wt JOIN users u ON u.id=wt.responsible_user_id WHERE wt.deleted_at IS NULL AND '.$access['sql'].' ORDER BY u.full_name,u.id');
        $stmt->execute($access['params']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function fetchActiveUtilizers(PDO $pdo): array { return$pdo->query('SELECT id,short_name,full_name,address FROM feo_utilizers WHERE is_active=1 ORDER BY sort_order,short_name,id')->fetchAll(PDO::FETCH_ASSOC); }

    public static function canAccessTrip(PDO $pdo,array $trip,array $sessionUser): bool
    { return empty($trip['deleted_at']) && RegulatedDataScopeService::canAccess($pdo,'waste_trip','waste_trips',(int)($trip['id']??0)); }

    public static function cancel(PDO $pdo,int $tripId,int $expectedVersion,string $reason,array $sessionUser): void
    { $reason=self::nullableText($reason,500);if($reason===null)throw new RuntimeException('Укажите причину отмены рейса.');$pdo->beginTransaction();try{$trip=self::fetchTripForUpdate($pdo,$tripId);if($trip===null)throw new RuntimeException('Рейс не найден.');self::assertCanAccessTrip($pdo,$trip,$sessionUser);if((int)$trip['lock_version']!==$expectedVersion)throw new RuntimeException('Рейс уже был изменён. Обновите страницу.');self::assertTransition((string)$trip['status'],self::STATUS_CANCELLED);$actor=(int)($sessionUser['user_id']??0)?:null;$role=(string)($sessionUser['role_code']??'');$stmt=$pdo->prepare("UPDATE waste_trips SET status='cancelled',cancellation_reason=?,cancelled_at=NOW(),cancelled_by_user_id=?,cancelled_by_role=?,updated_by_user_id=?,updated_by_role=?,lock_version=lock_version+1 WHERE id=? AND lock_version=?");$stmt->execute([$reason,$actor,$role,$actor,$role,$tripId,$expectedVersion]);if($stmt->rowCount()!==1)throw new RuntimeException('Конфликт отмены рейса. Обновите страницу.');WasteTripStartStatisticsService::synchronizeTrip($pdo,$tripId);FinanceObligationService::syncWasteTrip($pdo,$tripId);self::logAudit($pdo,$tripId,'cancel',['trip'=>$trip],['status'=>self::STATUS_CANCELLED,'cancellation_reason'=>$reason],$sessionUser);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;} }

    private static function insertTrip(PDO $pdo,int $businessId,array $data,?string $sourceSystem,?int $legacyId,array $sessionUser): int
    { $stmt=$pdo->prepare('INSERT INTO waste_trips (business_id,client_id,route_executor_id,responsible_user_id,destination_type,utilizer_id,status,planned_start_mode,planned_start_date,planned_start_date_from,planned_start_date_to,planned_finish_date,actual_start_date,actual_end_date,comment,cancellation_reason,source_system,legacy_flight_id,created_by_user_id,created_by_membership_id,created_by_role,updated_by_user_id,updated_by_role) VALUES (:business_id,:client_id,:route_executor_id,:responsible_user_id,:destination_type,:utilizer_id,:status,:planned_start_mode,:planned_start_date,:planned_start_date_from,:planned_start_date_to,:planned_finish_date,:actual_start_date,:actual_end_date,:comment,:cancellation_reason,:source_system,:legacy_flight_id,:created_user_id,:created_membership_id,:created_role_code,:updated_user_id,:updated_role_code)');$actor=(int)($sessionUser['user_id']??0)?:null;$membershipId=AccessControlService::effectiveMembershipId();$role=(string)($sessionUser['role_code']??'');$stmt->execute(self::tripParams($data)+[':business_id'=>$businessId,':source_system'=>$sourceSystem,':legacy_flight_id'=>$legacyId,':created_user_id'=>$actor,':created_membership_id'=>$membershipId,':created_role_code'=>$role,':updated_user_id'=>$actor,':updated_role_code'=>$role]);return(int)$pdo->lastInsertId(); }

    private static function tripParams(array $data): array
    { return[':client_id'=>$data['client_id'],':route_executor_id'=>$data['route_executor_id'],':responsible_user_id'=>$data['responsible_user_id'],':destination_type'=>$data['destination_type'],':utilizer_id'=>$data['utilizer_id'],':status'=>$data['status'],':planned_start_mode'=>$data['planned_start_mode'],':planned_start_date'=>$data['planned_start_date'],':planned_start_date_from'=>$data['planned_start_date_from'],':planned_start_date_to'=>$data['planned_start_date_to'],':planned_finish_date'=>$data['planned_finish_date'],':actual_start_date'=>$data['actual_start_date'],':actual_end_date'=>$data['actual_end_date'],':comment'=>$data['comment'],':cancellation_reason'=>$data['cancellation_reason']]; }
    private static function tripUpdateParams(array $data,array $sessionUser): array { $actor=(int)($sessionUser['user_id']??0)?:null;$role=(string)($sessionUser['role_code']??'');return self::tripParams($data)+[':user_id'=>$actor,':role_code'=>$role]; }

    private static function allocateBusinessId(PDO $pdo,PDO $legacyPdo): int
    {
        $state=self::namespaceState($pdo,true);$previous=(int)$state['last_issued_business_id'];$next=max(self::ERP_BUSINESS_ID_START,$previous+1);
        $occupied=$pdo->prepare('SELECT id FROM waste_trips WHERE business_id=? LIMIT 1 FOR UPDATE');
        while(true){
            if(!WasteTripLegacySourceService::hasFlightId($legacyPdo,$next)){$occupied->execute([$next]);if($occupied->fetchColumn()===false)break;}
            $next++;
        }
        $stmt=$pdo->prepare('UPDATE waste_trip_namespace_state SET last_issued_business_id=?,updated_at=NOW() WHERE singleton_id=1 AND last_issued_business_id=?');$stmt->execute([$next,$previous]);if($stmt->rowCount()!==1)throw new RuntimeException('Конфликт выдачи business ID. Повторите операцию.');return$next;
    }
    private static function namespaceState(PDO $pdo,bool $forUpdate): array { $sql='SELECT legacy_high_water_at_cutover,last_issued_business_id,cutover_completed_at FROM waste_trip_namespace_state WHERE singleton_id=1'.($forUpdate?' FOR UPDATE':'');$row=$pdo->query($sql)->fetch(PDO::FETCH_ASSOC);if(!$row||empty($row['cutover_completed_at']))throw new RuntimeException('Waste Trips cutover не завершён.');return$row; }
    private static function isLegacyLinked(array $trip): bool { return in_array($trip['source_system']??null,[WasteTripLegacySourceService::SOURCE_SYSTEM,'legacy_sync'],true)&&(int)($trip['legacy_flight_id']??0)>0; }
    private static function assertLegacyOwnedMutationAbsent(array $input): void { $present=[];foreach(self::LEGACY_OWNED_INPUT_KEYS as$key)if(array_key_exists($key,$input))$present[]=$key;if($present!==[])throw new RuntimeException('Legacy-owned поля нельзя изменять из ERP: '.implode(', ',$present).'.'); }
    private static function assertCanAccessTrip(PDO $pdo,array $trip,array $sessionUser): void { if(!self::canAccessTrip($pdo,$trip,$sessionUser))throw new RuntimeException('У вас нет доступа к этому рейсу.'); }

    private static function assertEntitiesVisible(PDO $pdo,array $data,array $sessionUser,bool $preserveExecutor=false): void
    { if(!LinearRouteService::isVisibleEntity($pdo,'clients',(int)$data['client_id'],$sessionUser,'client'))throw new RuntimeException('Заказчик недоступен или неактивен.');if(!$preserveExecutor&&!empty($data['route_executor_id'])){$visibleIds=array_map(static fn(array $row):int=>(int)($row['id']??0),LinearRouteService::fetchVisibleRouteExecutors($pdo,$sessionUser));if(!in_array((int)$data['route_executor_id'],$visibleIds,true))throw new RuntimeException('Исполнитель рейса недоступен или неактивен.');}$user=$pdo->prepare("SELECT COUNT(*) FROM users WHERE id=? AND status='active' AND deleted_at IS NULL");$user->execute([(int)$data['responsible_user_id']]);if((int)$user->fetchColumn()!==1)throw new RuntimeException('Ответственный сотрудник недоступен или неактивен.');if(($data['destination_type']??'')==='utilizer'&&!empty($data['utilizer_id'])){$util=$pdo->prepare('SELECT COUNT(*) FROM feo_utilizers WHERE id=? AND is_active=1');$util->execute([(int)$data['utilizer_id']]);if((int)$util->fetchColumn()!==1)throw new RuntimeException('Утилизатор недоступен или неактивен.');} }

    private static function resolveRequestsForClaim(PDO $pdo,array $businessIds,bool $requireCurrentPlacement): array
    { $businessIds=array_values(array_unique(array_map('strval',$businessIds)));if($businessIds===[])return[];$sorted=$businessIds;sort($sorted,SORT_STRING);$marks=implode(',',array_fill(0,count($sorted),'?'));$join=$requireCurrentPlacement?' JOIN feo_placement_current pc ON pc.zayavka_id=fr.zayavka_id ':' ';$stmt=$pdo->prepare("SELECT fr.id,fr.zayavka_id,fr.mass_netto,fr.naim_oo_gruzootpravitel,fr.mno_adres_pogruzki FROM feo_requests fr{$join}WHERE fr.zayavka_id IN ({$marks}) ORDER BY fr.id FOR UPDATE");$stmt->execute($sorted);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);$map=[];foreach($rows as$row){$row['snapshot_mass_kg']=self::massKg($row['mass_netto']??null);$map[(string)$row['zayavka_id']]=$row;}$missing=array_values(array_filter($businessIds,static fn(string $id):bool=>!isset($map[$id])));if($missing!==[])throw new RuntimeException(($requireCurrentPlacement?'Заявки отсутствуют в текущем пуле ФЭО: ':'Заявки отсутствуют в canonical FEO: ').implode(', ',$missing).'.');return array_map(static fn(string $id):array=>$map[$id],$businessIds); }

    private static function activeClaimMap(PDO $pdo,array $requestIds,?int $ignoreTripId=null): array
    { $ids=array_values(array_unique(array_filter(array_map('intval',$requestIds))));if($ids===[])return[];$marks=implode(',',array_fill(0,count($ids),'?'));$sql="SELECT wtr.feo_request_id,wt.id AS waste_trip_id,wt.business_id FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE wtr.feo_request_id IN ({$marks}) AND wtr.removed_at IS NULL AND wt.status<>'cancelled'";$params=$ids;if($ignoreTripId!==null){$sql.=' AND wt.id<>?';$params[]=$ignoreTripId;}$sql.=' ORDER BY wtr.feo_request_id FOR UPDATE';$stmt=$pdo->prepare($sql);$stmt->execute($params);$map=[];foreach($stmt->fetchAll(PDO::FETCH_ASSOC)as$row)$map[(int)$row['feo_request_id']]=(int)$row['business_id'];return$map; }
    private static function assertNoActiveClaims(PDO $pdo,array $requestIds,?int $ignoreTripId=null): void { $claims=self::activeClaimMap($pdo,$requestIds,$ignoreTripId);if($claims===[])return;$labels=[];$marks=implode(',',array_fill(0,count($claims),'?'));$stmt=$pdo->prepare("SELECT id,zayavka_id FROM feo_requests WHERE id IN ({$marks})");$stmt->execute(array_keys($claims));foreach($stmt->fetchAll(PDO::FETCH_ASSOC)as$row)$labels[]=(string)$row['zayavka_id'].' (рейс #'.$claims[(int)$row['id']].')';throw new RuntimeException('Заявки уже включены в активный рейс: '.implode(', ',$labels).'.'); }
    private static function insertRequestLinks(PDO $pdo,int $tripId,array $resolvedRows): void { $stmt=$pdo->prepare('INSERT INTO waste_trip_requests (waste_trip_id,feo_request_id,snapshot_mass_kg,position_no) VALUES (?,?,?,?)');foreach(array_values($resolvedRows)as$position=>$row)$stmt->execute([$tripId,(int)$row['id'],number_format((float)$row['snapshot_mass_kg'],3,'.',''),$position]); }
    private static function reconcileRequestLinks(PDO $pdo,int $tripId,array $resolvedRows): void
    {
        $existing=[];
        foreach(self::fetchRequestLinks($pdo,$tripId,true,false) as $row)$existing[(int)$row['feo_request_id']]=['id'=>(int)$row['id'],'removed'=>$row['removed_at']!==null];
        $keep=[];
        $update=$pdo->prepare('UPDATE waste_trip_requests SET snapshot_mass_kg=?,position_no=?,removed_at=NULL WHERE id=? AND waste_trip_id=?');
        $insert=$pdo->prepare('INSERT INTO waste_trip_requests (waste_trip_id,feo_request_id,snapshot_mass_kg,position_no) VALUES (?,?,?,?)');
        foreach(array_values($resolvedRows) as $position=>$row){
            $requestId=(int)$row['id'];$mass=number_format((float)$row['snapshot_mass_kg'],3,'.','');$keep[$requestId]=true;
            if(isset($existing[$requestId]))$update->execute([$mass,$position,$existing[$requestId]['id'],$tripId]);
            else $insert->execute([$tripId,$requestId,$mass,$position]);
        }
        $archive=$pdo->prepare('UPDATE waste_trip_requests SET removed_at=NOW() WHERE id=? AND waste_trip_id=? AND removed_at IS NULL');
        foreach($existing as $requestId=>$link)if(!isset($keep[$requestId])&&!$link['removed'])$archive->execute([$link['id'],$tripId]);
    }
    private static function fetchTripForUpdate(PDO $pdo,int $tripId): ?array { $stmt=$pdo->prepare('SELECT * FROM waste_trips WHERE id=? FOR UPDATE');$stmt->execute([$tripId]);return$stmt->fetch(PDO::FETCH_ASSOC)?:null; }
    private static function legacySyncFailureDescription(Throwable $error,string $stage): string
    {
        if ($error instanceof \PDOException && (string)($error->errorInfo[1]??'')==='1451'
            && str_contains($error->getMessage(),'fk_registration_journal_request')) {
            return 'Нельзя убрать заявку из рейса: по ней уже есть записи в журнале регистрации.';
        }
        return match($stage) {
            'request_resolution','claim_check','request_links' => 'Не удалось обновить заявки рейса.',
            'source_amounts','finance_obligations' => 'Не удалось обновить суммы и расчёты по рейсу.',
            'audit' => 'Не удалось записать историю изменений рейса.',
            default => 'Не удалось сохранить данные рейса.',
        };
    }
    /** The latest ERP choice owns this field, including choices made before the explicit audit marker existed. */
    private static function manualExecutorChoice(PDO $pdo,int $tripId): ?array
    {
        $stmt=$pdo->prepare("SELECT before_json,after_json FROM waste_trip_audit_log WHERE waste_trip_id=? AND action='update' AND JSON_EXTRACT(after_json, '$.source') IS NULL ORDER BY id DESC");
        $stmt->execute([$tripId]);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            $after=json_decode((string)$row['after_json'],true);
            if(!is_array($after)||($after['source']??null)==='legacy_sync')continue;
            if(($after['executor_sync_disabled']??false)===true)return ['id'=>(int)($after['manual_route_executor_id']??0)?:null];
            $before=json_decode((string)$row['before_json'],true);
            if(!is_array($before)||!isset($before['trip'])||!is_array($after['trip']??null)||!array_key_exists('route_executor_id',$after['trip']))continue;
            $oldId=(int)($before['trip']['route_executor_id']??0);$newId=(int)($after['trip']['route_executor_id']??0);
            if($oldId!==$newId&&$newId>0)return ['id'=>$newId];
        }
        return null;
    }
    private static function wasManuallyDeleted(PDO $pdo,int $tripId): bool
    {
        $stmt=$pdo->prepare("SELECT after_json FROM waste_trip_audit_log WHERE waste_trip_id=? AND action='cancel' ORDER BY id DESC");
        $stmt->execute([$tripId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $event=json_decode((string)$json,true);
            if (($event['deleted']??false) === true) return ($event['reason']??'') === 'user_delete';
        }
        return false;
    }
    private static function fetchRequestLinks(PDO $pdo,int $tripId,bool $forUpdate,bool $activeOnly=true): array { $sql='SELECT wtr.id,wtr.feo_request_id,wtr.snapshot_mass_kg,wtr.position_no,wtr.removed_at,fr.zayavka_id,fr.mass_netto,fr.naim_oo_gruzootpravitel,fr.mno_adres_pogruzki,fr.naim_otkhoda_fkko,fr.nomer_opasnogo_gr FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wtr.waste_trip_id=?'.($activeOnly?' AND wtr.removed_at IS NULL':'').' ORDER BY wtr.position_no,wtr.id'.($forUpdate?' FOR UPDATE':'');$stmt=$pdo->prepare($sql);$stmt->execute([$tripId]);return$stmt->fetchAll(PDO::FETCH_ASSOC); }
    private static function logAudit(PDO $pdo,int $tripId,string $action,?array $before,?array $after,array $sessionUser): void { $encode=static fn(?array $v):?string=>$v===null?null:json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$stmt=$pdo->prepare('INSERT INTO waste_trip_audit_log (waste_trip_id,action,before_json,after_json,actor_user_id,actor_role) VALUES (?,?,?,?,?,?)');$stmt->execute([$tripId,$action,$encode($before),$encode($after),(int)($sessionUser['user_id']??0)?:null,(string)($sessionUser['role_code']??'')]); }

    private static function enrichExecutorDisplay(PDO $pdo,array &$trips): void
    { $crewIds=array_values(array_unique(array_filter(array_map(static fn(array $trip):int=>(int)($trip['route_executor_id']??0),$trips))));if($crewIds===[])return;$marks=implode(',',array_fill(0,count($crewIds),'?'));$drivers=$pdo->prepare("SELECT cd.crew_id,GROUP_CONCAT(d.full_name ORDER BY cd.position SEPARATOR ' / ') AS driver_names FROM crew_drivers cd JOIN drivers d ON d.id=cd.driver_id WHERE cd.crew_id IN ({$marks}) GROUP BY cd.crew_id");$drivers->execute($crewIds);$driverMap=[];foreach($drivers->fetchAll(PDO::FETCH_ASSOC)as$row)$driverMap[(int)$row['crew_id']]=trim((string)$row['driver_names']);$vehicles=$pdo->prepare("SELECT c.id AS crew_id,vu1.brand AS primary_brand,vu1.plate_number AS primary_plate,vu2.brand AS secondary_brand,vu2.plate_number AS secondary_plate FROM crews c LEFT JOIN driver_vehicle_blocks dvb ON dvb.id=c.driver_vehicle_block_id LEFT JOIN vehicle_sets vs ON vs.id=dvb.vehicle_set_id LEFT JOIN vehicle_units vu1 ON vu1.id=vs.primary_vehicle_unit_id LEFT JOIN vehicle_units vu2 ON vu2.id=vs.secondary_vehicle_unit_id WHERE c.id IN ({$marks})");$vehicles->execute($crewIds);$vehicleMap=[];foreach($vehicles->fetchAll(PDO::FETCH_ASSOC)as$row){$parts=[];foreach(['primary','secondary']as$p){$label=trim(implode(' ',array_filter([trim((string)($row[$p.'_brand']??'')),trim((string)($row[$p.'_plate']??''))])));if($label!=='')$parts[]=$label;}$vehicleMap[(int)$row['crew_id']]=implode(' + ',$parts);}foreach($trips as&$trip){$id=(int)($trip['route_executor_id']??0);$trip['executor_driver_names']=$driverMap[$id]??'';$trip['executor_vehicle_label']=$vehicleMap[$id]??'';}unset($trip); }

    private static function normalizeDate(mixed $value): ?string { $value=trim((string)$value);if($value==='')return null;if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)!==1)throw new RuntimeException('Некорректный формат даты.');[$y,$m,$d]=array_map('intval',explode('-',$value));if(!checkdate($m,$d,$y))throw new RuntimeException('Некорректная календарная дата.');return$value; }
    private static function nullableText(mixed $value,int $maxLength): ?string { $value=trim((string)$value);if($value==='')return null;return function_exists('mb_substr')?mb_substr($value,0,$maxLength,'UTF-8'):substr($value,0,$maxLength); }
    public static function massKg(mixed $massNetto): float { $value=str_replace(',','.',trim((string)$massNetto));if($value===''||!is_numeric($value))return 0.0;return round(max(0.0,(float)$value)*1000,3); }
    private static function assertTransition(string $from,string $to): void { if(!isset(self::TRANSITIONS[$from])||!in_array($to,self::TRANSITIONS[$from],true))throw new RuntimeException('Недопустимый переход статуса: '.self::statusLabel($from).' → '.self::statusLabel($to).'.'); }
}
