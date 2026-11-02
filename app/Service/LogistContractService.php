<?php

namespace App\Service;

use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/DocumentGeneratorService.php';
require_once __DIR__ . '/LogistContractPdfService.php';
require_once __DIR__ . '/MaxMessagingService.php';
require_once __DIR__ . '/MaxEventCatalog.php';

final class LogistContractService
{
    public static function schemaReady(PDO $pdo): bool
    {
        $q = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='logist_contracts'");
        return (int)$q->fetchColumn() === 1;
    }

    public static function list(PDO $pdo, int $userId, bool $owner): array
    {
        $where = $owner ? '' : 'WHERE created_by_user_id=' . (int)$userId;
        $sql = "SELECT c.*,u.full_name AS logist_name FROM logist_contracts c LEFT JOIN users u ON u.id=c.created_by_user_id WHERE c.id IN
            (SELECT MAX(id) FROM logist_contracts {$where} GROUP BY trip_id,contract_type" . ($owner ? '' : ',created_by_user_id') . ") ORDER BY c.id DESC LIMIT 500";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['snapshot'] = self::decodeSnapshot($row);
        return $rows;
    }

    public static function pendingCount(PDO $pdo): int
    {
        $sql = "SELECT COUNT(*) FROM logist_contracts c JOIN
            (SELECT trip_id,contract_type,MAX(id) id FROM logist_contracts GROUP BY trip_id,contract_type) latest ON latest.id=c.id
            WHERE c.status='pending'";
        return (int)$pdo->query($sql)->fetchColumn();
    }

    public static function trips(PDO $pdo, array $actor): array
    {
        $role = (string)($actor['role_code'] ?? '');
        $userId = (int)($actor['user_id'] ?? 0);
        self::assertCurrentLogist($pdo, $userId, $role);
        $trips = self::withTripReadScope($role, static fn(): array => DocumentGeneratorService::listDocumentTrips($pdo));
        return array_values(array_filter($trips, static function(array $trip) use ($pdo, $actor, $role, $userId): bool {
            return self::isLogist($role)
                ? (int)($trip['responsible_user_id'] ?? 0) === $userId
                : WasteTripService::canAccessTrip($pdo, $trip, $actor);
        }));
    }

    public static function executors(PDO $pdo, int $userId, string $role): array
    {
        return DocumentGeneratorService::listAccessibleExecutors($pdo, $userId, $role);
    }

    public static function create(PDO $pdo, array $company, int $companyId, int $userId, string $role, array $actor, array $input): array
    {
        $tripId = (int)($input['trip_id'] ?? 0);
        self::assertCurrentLogist($pdo, $userId, $role);
        $trip = self::withTripReadScope($role, static fn(): ?array => WasteTripService::fetchTrip($pdo, $tripId, false));
        if (!$trip || (self::isLogist($role)
            ? (int)($trip['responsible_user_id'] ?? 0) !== $userId
            : !WasteTripService::canAccessTrip($pdo, $trip, $actor))) {
            throw new RuntimeException('Рейс не найден или недоступен.');
        }
        $type = (string)($input['contract_type'] ?? '');
        $templateType = match ($type) {
            'lease' => 'vehicle_lease_crew',
            'sublease' => 'carrier_sublease_crew',
            default => throw new RuntimeException('Выберите вид договора.'),
        };
        $crew = (int)($trip['route_executor_id'] ?? 0);
        $chosen = (int)($input['route_executor_id'] ?? 0);
        if ($crew > 0 && $chosen !== $crew) throw new RuntimeException('Исполнитель рейса изменился. Откройте форму заново.');
        if ($crew <= 0) $crew = $chosen;
        if ($crew <= 0 || DocumentGeneratorService::loadAccessibleExecutor($pdo, $crew, $userId, $role) === null) {
            throw new RuntimeException('Выберите действующего исполнителя рейса.');
        }
        $start = self::startDate($trip);
        if ($start === '') throw new RuntimeException('У рейса отсутствует дата начала.');
        if ((string)($input['start_date'] ?? '') !== $start) throw new RuntimeException('Начало рейса изменилось. Откройте форму заново.');
        $finish = (new \DateTimeImmutable($start))->modify('+7 days')->format('Y-m-d');
        if ((string)($input['finish_date'] ?? '') !== $finish) throw new RuntimeException('Дата окончания должна быть через 7 дней после начала рейса.');
        $price = str_replace([' ', "\u{00a0}", ','], ['', '', '.'], trim((string)($input['price'] ?? '')));
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $price) || (float)$price <= 0) throw new RuntimeException('Укажите положительную ставку.');
        $vat = (string)($input['vat_mode'] ?? '');
        if (!in_array($vat, ['Без НДС', 'С НДС'], true)) throw new RuntimeException('Выберите режим НДС.');
        $template = null;
        foreach (DocumentGeneratorService::listTemplates($pdo, true) as $item) {
            if ((string)$item['document_type'] !== $templateType) continue;
            if ($template !== null) throw new RuntimeException('Для вида договора найдено несколько активных шаблонов.');
            $template = $item;
        }
        if (!$template) throw new RuntimeException('Актуальный шаблон договора не найден.');
        $params = ['contract_date'=>$start,'start_date'=>$start,'finish_date'=>$finish,'price'=>$price,'vat_mode'=>$vat];
        $prepared = self::withTripReadScope($role, static fn(): array => DocumentGeneratorService::prepareFromTrip($pdo, $company, $companyId, $userId, $role, (int)$template['id'], $tripId, $params, null, $crew));
        if (!($prepared['ok'] ?? false)) throw new RuntimeException(implode(' ', $prepared['errors'] ?? ['Договор не прошёл проверку.']));
        $active = $prepared['template'];
        $templatePath = DocumentService::resolveStoredPath($companyId, (string)$active['stored_path']);
        if (!$templatePath || !hash_equals((string)$active['sha256'], hash_file('sha256', $templatePath) ?: '')) {
            throw new RuntimeException('Контрольная сумма актуального шаблона не совпадает.');
        }
        $businessId = (int)($trip['business_id'] ?? 0);
        if ($businessId <= 0) throw new RuntimeException('Не найден ID рейса.');
        $lockName = 'erp_logist_contract_' . substr(hash('sha256', $companyId . ':' . $tripId . ':' . $type), 0, 24);
        $lock = $pdo->prepare('SELECT GET_LOCK(?,10)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Договор этого рейса уже формируется. Повторите позже.');
        $path = null;
        try {
            $v = $pdo->prepare('SELECT COALESCE(MAX(version_no),0)+1 FROM logist_contracts WHERE trip_id=? AND contract_type=?');
            $v->execute([$tripId,$type]);
            $version = (int)$v->fetchColumn();
            $relativeDir = sprintf('companies/%d/logist-contracts/%s', $companyId, date('Y/m'));
            $absoluteDir = storage_path($relativeDir);
            if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) throw new RuntimeException('Не удалось создать каталог договора.');
            $relativePath = $relativeDir . '/' . bin2hex(random_bytes(16)) . '.pdf';
            $path = storage_path($relativePath);
            LogistContractPdfService::render($templatePath, $prepared['values'], $prepared['request_values'], $path);
            $sha = hash_file('sha256', $path) ?: '';
            if ($sha === '') throw new RuntimeException('Не удалось проверить PDF договора.');
            $snapshot = $prepared['snapshot'];
            $snapshot['logist_contract'] = ['type'=>$type,'version'=>$version,'trip_id'=>$tripId,'business_id'=>$businessId];
            $name = DocumentService::safeDownloadName('Рейс ' . $businessId . ($type === 'sublease' ? ' Субаренда' : ' Аренда') . ' версия ' . $version . '.pdf');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO logist_contracts(trip_id,business_id,contract_type,version_no,template_id,template_version_id,route_executor_id,snapshot_json,pdf_path,pdf_name,pdf_sha256,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$tripId,$businessId,$type,$version,(int)$active['template_id'],(int)$active['version_id'],$crew,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$relativePath,$name,$sha,$userId]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
            $notificationWarning = null;
            try { self::notifyTestGroup($pdo, $id, $businessId, $version, $type, $userId); }
            catch (Throwable $error) {
                $notificationWarning = 'Договор сохранён, но уведомление в MAX не отправлено: ' . $error->getMessage();
                error_log('Logist contract MAX notification failed for #' . $id . ': ' . $error->getMessage());
            }
            $created = self::get($pdo, $id, $userId, false);
            $created['notification_warning'] = $notificationWarning;
            return $created;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($path && is_file($path)) @unlink($path);
            throw $error;
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    public static function get(PDO $pdo, int $id, int $userId, bool $owner): array
    {
        $stmt = $pdo->prepare('SELECT c.*,u.full_name AS logist_name FROM logist_contracts c LEFT JOIN users u ON u.id=c.created_by_user_id WHERE c.id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (!$owner && (int)$row['created_by_user_id'] !== $userId)) throw new RuntimeException('Договор не найден.');
        $row['snapshot'] = self::decodeSnapshot($row);
        return $row;
    }

    public static function versions(PDO $pdo, array $row, int $userId, bool $owner): array
    {
        $sql = 'SELECT id,version_no,status,review_reason,created_at FROM logist_contracts WHERE trip_id=? AND contract_type=?';
        if (!$owner) $sql .= ' AND created_by_user_id=' . (int)$userId;
        $sql .= ' ORDER BY version_no DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([(int)$row['trip_id'],(string)$row['contract_type']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function review(PDO $pdo, int $id, int $reviewer, string $decision, string $reason): void
    {
        if (!in_array($decision, ['approved','rejected'], true)) throw new RuntimeException('Выберите решение.');
        $reason = trim($reason);
        if ($decision === 'rejected' && ($reason === '' || mb_strlen($reason) > 1000)) throw new RuntimeException('Укажите причину отказа до 1000 символов.');
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT trip_id,contract_type,status FROM logist_contracts WHERE id=? FOR UPDATE');
            $q->execute([$id]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['status'] !== 'pending') throw new RuntimeException('Договор уже рассмотрен или не найден.');
            $latest = $pdo->prepare('SELECT MAX(id) FROM logist_contracts WHERE trip_id=? AND contract_type=?');
            $latest->execute([(int)$row['trip_id'],(string)$row['contract_type']]);
            if ((int)$latest->fetchColumn() !== $id) throw new RuntimeException('Этот договор заменён новой версией.');
            $pdo->prepare('UPDATE logist_contracts SET status=?,review_reason=?,reviewed_by_user_id=?,reviewed_at=NOW() WHERE id=?')
                ->execute([$decision,$decision === 'rejected' ? $reason : null,$reviewer,$id]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public static function pdfPath(array $row, int $companyId): string
    {
        $path = DocumentService::resolveStoredPath($companyId, (string)$row['pdf_path']);
        if (!$path || !hash_equals((string)$row['pdf_sha256'], hash_file('sha256', $path) ?: '')) {
            throw new RuntimeException('PDF договора недоступен или повреждён.');
        }
        return $path;
    }

    public static function startDate(array $trip): string
    {
        foreach (['actual_start_date','planned_start_date','planned_start_date_from'] as $key) {
            $value = substr((string)($trip[$key] ?? ''),0,10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)) return $value;
        }
        return '';
    }

    private static function decodeSnapshot(array $row): array
    {
        $snapshot = json_decode((string)$row['snapshot_json'], true);
        return is_array($snapshot) ? $snapshot : [];
    }

    private static function isLogist(string $role): bool
    {
        return in_array($role, ['logist', 'senior_logist', 'custom_role'], true);
    }

    private static function assertCurrentLogist(PDO $pdo, int $userId, string $role): void
    {
        if (!self::isLogist($role)) return;
        $access = AccessRuntime::current();
        if (!$access->isActive() || !$access->canAccessPage('company.logistics.contracts')
            || $access->membershipId() === null
            || DocumentGeneratorService::resolveActorTenantUserId($pdo, $access->membershipId(), $userId) !== $userId) {
            throw new RuntimeException('Рейсы недоступны текущему логисту.');
        }
    }

    private static function withTripReadScope(string $role, callable $read): mixed
    {
        if (!self::isLogist($role)) return $read();
        // Legacy sync rows may have been created by the owner. Read them here,
        // then expose only trips assigned to the authenticated tenant user.
        $access = AccessRuntime::current();
        $scopes = [];
        foreach (['client','contractor','vehicle_unit','driver','linear_trip','waste_trip'] as $entity) {
            $scopes[$entity] = $entity === 'waste_trip' ? EffectiveAccess::SCOPE_ALL : $access->dataScope($entity);
        }
        AccessRuntime::setCurrent(EffectiveAccess::member(
            (int)$access->membershipId(), (int)$access->companyId(),
            ['company.logistics.contracts'=>true], $scopes, $role
        ));
        try {
            return $read();
        } finally {
            AccessRuntime::setCurrent($access);
        }
    }

    private static function notifyTestGroup(PDO $pdo, int $id, int $businessId, int $version, string $type, int $userId): void
    {
        $q = $pdo->query("SELECT id FROM max_chats WHERE (name='тестовая' OR max_title='тестовая') AND is_active=1 LIMIT 2");
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) throw new RuntimeException('Группа MAX «тестовая» не найдена однозначно.');
        $name = $pdo->prepare('SELECT full_name FROM users WHERE id=?');
        $name->execute([$userId]);
        $rule = $pdo->prepare("SELECT message_template FROM max_event_rules WHERE event_key='logist.contract.created' LIMIT 1");
        $rule->execute();
        $event = MaxEventCatalog::event('logist.contract.created');
        $template = (string)($rule->fetchColumn() ?: $event['template']);
        $message = MaxEventCatalog::render('logist.contract.created', $template, 'plain', [
            'trip'=>(string)$businessId,
            'version'=>(string)$version,
            'contract_type'=>$type === 'lease' ? 'Аренда' : 'Субаренда',
            'logist'=>(string)($name->fetchColumn() ?: '—'),
            'url'=>'https://plan-ex.ru' . app_url('/company/document-generator/logist-contracts?open=' . $id),
        ]);
        (new MaxMessagingService($pdo))->sendToChat((int)$ids[0], $message, 'plain', true, 'event', $userId);
    }
}
