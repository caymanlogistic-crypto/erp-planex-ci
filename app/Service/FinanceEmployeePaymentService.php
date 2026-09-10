<?php

namespace App\Service;

use PDO;

final class FinanceEmployeePaymentService
{
    public const IDENTITY_TENANT_USER = 'TENANT_USER';
    public const IDENTITY_COMPANY_USER = 'COMPANY_USER';
    public const IDENTITY_MEMBERSHIP = 'MEMBERSHIP';

    public static function fetchActiveEmployees(PDO $localPdo, PDO $centralPdo, int $companyId): array
    {
        $employees = [];
        $knownMemberships = [];
        $knownLogins = [];

        $ownerStmt = $centralPdo->prepare("SELECT id, full_name, login, role, status
                                             FROM company_users
                                            WHERE company_id = ? AND status = 'active'
                                            ORDER BY full_name ASC, id ASC");
        $ownerStmt->execute([$companyId]);
        foreach ($ownerStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employees[] = self::directoryRow(self::IDENTITY_COMPANY_USER, $row, 'role');
            $login = mb_strtolower(trim((string)($row['login'] ?? '')));
            if ($login !== '') $knownLogins[$login] = true;
        }

        $tenantStmt = $localPdo->query("SELECT id, membership_id, full_name, login, role_code, status
                                         FROM users
                                        WHERE status = 'active' AND deleted_at IS NULL
                                        ORDER BY full_name ASC, id ASC");
        foreach ($tenantStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employees[] = self::directoryRow(self::IDENTITY_TENANT_USER, $row, 'role_code');
            $membershipId = (int)($row['membership_id'] ?? 0);
            if ($membershipId > 0) $knownMemberships[$membershipId] = true;
            $login = mb_strtolower(trim((string)($row['login'] ?? '')));
            if ($login !== '') $knownLogins[$login] = true;
        }

        $membershipStmt = $centralPdo->prepare(
            "SELECT m.id AS membership_id, a.full_name, a.login, r.system_key AS role_code
               FROM company_memberships m
               JOIN user_accounts a ON a.id = m.account_id
               JOIN company_roles r ON r.id = m.role_id AND r.company_id = m.company_id
              WHERE m.company_id = ?
                AND m.status = 'active'
                AND a.status = 'active'
              ORDER BY a.full_name ASC, m.id ASC"
        );
        $membershipStmt->execute([$companyId]);
        foreach ($membershipStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $membershipId = (int)($row['membership_id'] ?? 0);
            $login = mb_strtolower(trim((string)($row['login'] ?? '')));
            if ($membershipId <= 0 || isset($knownMemberships[$membershipId]) || ($login !== '' && isset($knownLogins[$login]))) continue;
            $employees[] = [
                'ref' => self::makeEmployeeRef(self::IDENTITY_MEMBERSHIP, $membershipId),
                'identity_type' => self::IDENTITY_MEMBERSHIP,
                'identity_id' => $membershipId,
                'id' => $membershipId,
                'full_name' => (string)($row['full_name'] ?? ''),
                'login' => (string)($row['login'] ?? ''),
                'role_code' => (string)($row['role_code'] ?? ''),
                'status' => 'active',
            ];
        }

        usort($employees, static fn(array $a, array $b): int => [mb_strtolower($a['full_name']), $a['ref']] <=> [mb_strtolower($b['full_name']), $b['ref']]);
        return $employees;
    }

    public static function resolveActiveEmployee(PDO $localPdo, PDO $centralPdo, int $companyId, string $ref): array
    {
        [$type, $id] = self::parseEmployeeRef($ref);
        if ($type === self::IDENTITY_COMPANY_USER) {
            $stmt = $centralPdo->prepare("SELECT id, full_name, login, role, status
                                           FROM company_users
                                          WHERE id = ? AND company_id = ? AND status = 'active'");
            $stmt->execute([$id, $companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new \InvalidArgumentException('Сотрудник не найден или его аккаунт неактивен.');
            return self::directoryRow($type, $row, 'role');
        }

        if ($type === self::IDENTITY_MEMBERSHIP) {
            $stmt = $centralPdo->prepare(
                "SELECT m.id, a.full_name, a.login, r.system_key AS role_code, m.status
                   FROM company_memberships m
                   JOIN user_accounts a ON a.id = m.account_id
                   JOIN company_roles r ON r.id = m.role_id AND r.company_id = m.company_id
                  WHERE m.id = ?
                    AND m.company_id = ?
                    AND m.status = 'active'
                    AND a.status = 'active'"
            );
            $stmt->execute([$id, $companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new \InvalidArgumentException('Сотрудник не найден или его аккаунт неактивен.');
            return self::directoryRow($type, $row, 'role_code');
        }

        $stmt = $localPdo->prepare("SELECT id, full_name, login, role_code, status
                                     FROM users
                                    WHERE id = ? AND status = 'active' AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \InvalidArgumentException('Сотрудник не найден или его аккаунт неактивен.');
        return self::directoryRow($type, $row, 'role_code');
    }

    public static function fetchEmployeeSummaries(PDO $pdo, array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['employee_ref'])) {
            [$type, $id] = self::parseEmployeeRef((string)$filters['employee_ref']);
            $where[] = 'fem.employee_identity_type = :employee_identity_type';
            $where[] = 'fem.employee_identity_id = :employee_identity_id';
            $params[':employee_identity_type'] = $type;
            $params[':employee_identity_id'] = $id;
        }
        if (!empty($filters['source_type']) && in_array($filters['source_type'], ['BANK','CASH'], true)) {
            $where[] = 'fem.source_type = :source_type';
            $params[':source_type'] = $filters['source_type'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'fo.operation_date >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'fo.operation_date <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }
        $sql = "SELECT fem.employee_identity_type, fem.employee_identity_id,
                       MAX(fem.employee_name_snapshot) AS full_name,
                       MAX(fem.employee_role_snapshot) AS role_code,
                       SUM(CASE WHEN fo.status='POSTED' AND fem.movement_type='PAYMENT' THEN fo.amount ELSE 0 END) AS paid_amount,
                       SUM(CASE WHEN fo.status='POSTED' AND fem.movement_type='RETURN' THEN fo.amount ELSE 0 END) AS returned_amount,
                       SUM(CASE WHEN fo.status='POSTED' AND fem.movement_type='PAYMENT' THEN fo.amount
                                WHEN fo.status='POSTED' AND fem.movement_type='RETURN' THEN -fo.amount ELSE 0 END) AS balance_amount,
                       MAX(fo.operation_date) AS last_operation_date,
                       COUNT(*) AS movement_count,
                       SUM(CASE WHEN fo.status='POSTED' THEN 1 ELSE 0 END) AS posted_movement_count,
                       SUM(CASE WHEN fo.status='CANCELLED' THEN 1 ELSE 0 END) AS cancelled_movement_count
                  FROM finance_employee_movements fem
                  JOIN finance_operations fo ON fo.id = fem.finance_operation_id
                 WHERE ".implode(' AND ', $where)."
                 GROUP BY fem.employee_identity_type, fem.employee_identity_id
                 ORDER BY last_operation_date DESC, full_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['employee_ref'] = self::makeEmployeeRef((string)$row['employee_identity_type'], (int)$row['employee_identity_id']);
        }
        unset($row);
        return $rows;
    }

    public static function fetchEmployeeLedger(PDO $pdo, string $employeeRef): array
    {
        [$type, $id] = self::parseEmployeeRef($employeeRef);
        $stmt = $pdo->prepare("SELECT fem.id, fem.employee_user_id, fem.employee_identity_type, fem.employee_identity_id,
                                     fem.employee_name_snapshot AS full_name, fem.employee_role_snapshot AS role_code,
                                     fem.movement_type, fem.source_type, fem.note,
                                     fo.id AS finance_operation_id, fo.operation_date, fo.amount, fo.status,
                                     fo.purpose, fo.comment, fma.name AS money_account_name,
                                     bt.id AS bank_transaction_id, bt.counterparty_name, bt.document_number
                                FROM finance_employee_movements fem
                                JOIN finance_operations fo ON fo.id = fem.finance_operation_id
                           LEFT JOIN finance_money_accounts fma ON fma.id = fo.money_account_id
                           LEFT JOIN bank_transactions bt ON bt.id = fem.bank_transaction_id
                               WHERE fem.employee_identity_type = ? AND fem.employee_identity_id = ?
                            ORDER BY fo.operation_date ASC, fem.id ASC");
        $stmt->execute([$type, $id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $balanceCents = 0;
        foreach ($rows as &$row) {
            if (($row['status'] ?? '') === 'POSTED') {
                $amountCents = self::toCents((string)$row['amount']);
                $balanceCents += ($row['movement_type'] === 'PAYMENT') ? $amountCents : -$amountCents;
            }
            $row['running_balance'] = self::fromCents($balanceCents);
            $row['employee_ref'] = self::makeEmployeeRef((string)$row['employee_identity_type'], (int)$row['employee_identity_id']);
        }
        unset($row);
        return array_reverse($rows);
    }

    public static function fetchBankCandidates(PDO $pdo, string $movementType, int $limit = 200): array
    {
        self::assertMovementType($movementType);
        $amountCondition = $movementType === 'PAYMENT' ? 'bt.debit_amount > 0' : 'bt.credit_amount > 0';
        $stmt = $pdo->prepare("SELECT bt.id, bt.operation_date, bt.counterparty_name, bt.counterparty_inn,
                                     bt.debit_amount, bt.credit_amount, bt.purpose, ba.account_number,
                                     fo.id AS finance_operation_id
                                FROM bank_transactions bt
                                JOIN bank_accounts ba ON ba.id = bt.account_id
                                JOIN finance_operations fo ON fo.bank_transaction_id = bt.id AND fo.status = 'POSTED'
                           LEFT JOIN finance_employee_movements fem ON fem.bank_transaction_id = bt.id
                               WHERE {$amountCondition}
                                 AND COALESCE(bt.is_internal_transfer,0)=0
                                 AND fem.id IS NULL
                            ORDER BY bt.operation_date DESC, bt.id DESC
                               LIMIT :limit");
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function createCashMovement(PDO $pdo, array $data, array $user, array $employee): array
    {
        $movementType = strtoupper(trim((string)($data['movement_type'] ?? '')));
        self::assertMovementType($movementType);
        self::assertDirectoryEmployee($employee);
        $operationType = $movementType === 'PAYMENT' ? 'EXPENSE' : 'INCOME';
        $purpose = trim((string)($data['purpose'] ?? ''));
        if ($purpose === '') $purpose = ($movementType === 'PAYMENT' ? 'Выплата сотруднику: ' : 'Возврат от сотрудника: ') . $employee['full_name'];
        $started = !$pdo->inTransaction();
        if ($started) $pdo->beginTransaction();
        try {
            $operationId = FinanceCashService::createCashOperation($pdo, [
                'operation_type'=>$operationType,
                'money_account_id'=>(int)($data['money_account_id']??0),
                'amount'=>(string)($data['amount']??''),
                'operation_date'=>(string)($data['operation_date']??''),
                'purpose'=>$purpose,
                'comment'=>trim((string)($data['comment']??'')),
            ], $user);
            $movementId = self::insertMovement($pdo, $employee, $movementType, 'CASH', $operationId, null, $data['comment']??null, $user);
            if ($started) $pdo->commit();
            return ['movement_id'=>$movementId,'finance_operation_id'=>$operationId];
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function linkBankTransaction(PDO $pdo, array $data, array $user, array $employee): array
    {
        $movementType = strtoupper(trim((string)($data['movement_type'] ?? '')));
        self::assertMovementType($movementType);
        self::assertDirectoryEmployee($employee);
        $bankTransactionId = (int)($data['bank_transaction_id'] ?? 0);
        if ($bankTransactionId <= 0) throw new \InvalidArgumentException('Выберите банковскую операцию.');
        $started = !$pdo->inTransaction();
        if ($started) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT bt.*, fo.id AS finance_operation_id, fo.status AS finance_operation_status
                                     FROM bank_transactions bt JOIN finance_operations fo ON fo.bank_transaction_id=bt.id
                                    WHERE bt.id=? FOR UPDATE");
            $stmt->execute([$bankTransactionId]);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tx) throw new \RuntimeException('Банковская операция не найдена или не связана с финансовой операцией.');
            if (($tx['finance_operation_status']??'') !== 'POSTED') throw new \RuntimeException('Можно связать только проведённую банковскую операцию.');
            if (!empty($tx['is_internal_transfer'])) throw new \RuntimeException('Внутренний перевод нельзя оформить как выплату сотруднику.');
            $debitPositive = self::toCents((string)($tx['debit_amount']??'0.00')) > 0;
            $creditPositive = self::toCents((string)($tx['credit_amount']??'0.00')) > 0;
            $actualType = $debitPositive && !$creditPositive ? 'PAYMENT' : ($creditPositive && !$debitPositive ? 'RETURN' : null);
            if ($actualType !== $movementType) throw new \RuntimeException('Направление банковской операции не соответствует типу взаиморасчёта.');
            $check=$pdo->prepare('SELECT id FROM finance_employee_movements WHERE bank_transaction_id=? OR finance_operation_id=? LIMIT 1');
            $check->execute([$bankTransactionId,(int)$tx['finance_operation_id']]);
            if ($check->fetchColumn()) throw new \RuntimeException('Эта банковская операция уже связана с сотрудником.');
            $movementId=self::insertMovement($pdo,$employee,$movementType,'BANK',(int)$tx['finance_operation_id'],$bankTransactionId,$data['comment']??null,$user);
            if ($started) $pdo->commit();
            return ['movement_id'=>$movementId,'finance_operation_id'=>(int)$tx['finance_operation_id'],'bank_transaction_id'=>$bankTransactionId];
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function reassignMovement(PDO $pdo, int $movementId, array $employee, array $user): array
    {
        if ($movementId <= 0) throw new \InvalidArgumentException('Движение сотрудника не найдено.');
        self::assertDirectoryEmployee($employee);
        $started = !$pdo->inTransaction();
        if ($started) $pdo->beginTransaction();
        try {
            $stmt=$pdo->prepare('SELECT * FROM finance_employee_movements WHERE id=? FOR UPDATE');
            $stmt->execute([$movementId]);
            $movement=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$movement) throw new \InvalidArgumentException('Движение сотрудника не найдено.');
            $old=['employee_identity_type'=>$movement['employee_identity_type'],'employee_identity_id'=>(int)$movement['employee_identity_id']];
            $new=['employee_identity_type'=>$employee['identity_type'],'employee_identity_id'=>(int)$employee['identity_id']];
            if ($old !== $new) {
                $localUserId=$employee['identity_type']===self::IDENTITY_TENANT_USER?(int)$employee['identity_id']:null;
                $upd=$pdo->prepare('UPDATE finance_employee_movements SET employee_user_id=?, employee_identity_type=?, employee_identity_id=?, employee_name_snapshot=?, employee_role_snapshot=? WHERE id=?');
                $upd->execute([$localUserId,$employee['identity_type'],(int)$employee['identity_id'],$employee['full_name'],$employee['role_code'],$movementId]);
                FinanceAuditLogService::log($pdo,'finance_employee_movement',$movementId,'reassign_employee',$old,$new,(int)($user['id']??0),(string)($user['role']??'company_owner'));
            }
            if ($started) $pdo->commit();
            return ['movement_id'=>$movementId,'employee_ref'=>$employee['ref']];
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function findByBankTransaction(PDO $pdo, int $bankTransactionId): ?array
    {
        $stmt=$pdo->prepare("SELECT fem.*, fem.employee_name_snapshot AS full_name, fo.operation_date, fo.amount, fo.status
                              FROM finance_employee_movements fem JOIN finance_operations fo ON fo.id=fem.finance_operation_id
                             WHERE fem.bank_transaction_id=? LIMIT 1");
        $stmt->execute([$bankTransactionId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $row['employee_ref']=self::makeEmployeeRef((string)$row['employee_identity_type'],(int)$row['employee_identity_id']);
        return $row ?: null;
    }

    public static function unlinkBankTransaction(PDO $pdo, int $bankTransactionId, array $user): bool
    {
        $started=!$pdo->inTransaction(); if($started)$pdo->beginTransaction();
        try {
            $stmt=$pdo->prepare('SELECT * FROM finance_employee_movements WHERE bank_transaction_id=? FOR UPDATE');$stmt->execute([$bankTransactionId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$row){if($started)$pdo->commit();return false;}
            $pdo->prepare('DELETE FROM finance_employee_movements WHERE id=?')->execute([(int)$row['id']]);
            FinanceAuditLogService::log($pdo,'finance_employee_movement',(int)$row['id'],'unlink_bank',$row,['removed'=>true],(int)($user['id']??0),(string)($user['role']??'company_owner'));
            if($started)$pdo->commit(); return true;
        } catch(\Throwable $e){if($started&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function unlinkByBankTransactionIfExists(PDO $pdo,int $bankTransactionId,array $user):void{self::unlinkBankTransaction($pdo,$bankTransactionId,$user);}

    public static function formatMoney(mixed $value): string
    {
        $cents=self::toCents((string)($value??'0.00'));$negative=$cents<0;$abs=abs($cents);$rubles=intdiv($abs,100);$kopecks=$abs%100;
        return ($negative?'-':'').number_format($rubles,0,'',' ').','.str_pad((string)$kopecks,2,'0',STR_PAD_LEFT);
    }

    public static function moneySign(mixed $value):int{return self::toCents((string)($value??'0.00'))<=>0;}

    public static function makeEmployeeRef(string $type,int $id):string
    {
        if(!in_array($type,[self::IDENTITY_TENANT_USER,self::IDENTITY_COMPANY_USER,self::IDENTITY_MEMBERSHIP],true)||$id<=0)throw new \InvalidArgumentException('Некорректный идентификатор сотрудника.');
        return $type.':'.$id;
    }

    public static function parseEmployeeRef(string $ref):array
    {
        if(!preg_match('/^(TENANT_USER|COMPANY_USER|MEMBERSHIP):(\d+)$/D',trim($ref),$m)||(int)$m[2]<=0)throw new \InvalidArgumentException('Выберите сотрудника.');
        return [$m[1],(int)$m[2]];
    }

    private static function directoryRow(string $type,array $row,string $roleField):array
    {
        return ['ref'=>self::makeEmployeeRef($type,(int)$row['id']),'identity_type'=>$type,'identity_id'=>(int)$row['id'],'id'=>(int)$row['id'],'full_name'=>(string)$row['full_name'],'login'=>(string)($row['login']??''),'role_code'=>(string)($row[$roleField]??''),'status'=>(string)($row['status']??'active')];
    }

    private static function insertMovement(PDO $pdo,array $employee,string $movementType,string $sourceType,int $operationId,?int $bankTxId,mixed $note,array $user):int
    {
        $localUserId=$employee['identity_type']===self::IDENTITY_TENANT_USER?(int)$employee['identity_id']:null;
        $stmt=$pdo->prepare("INSERT INTO finance_employee_movements (employee_user_id,employee_identity_type,employee_identity_id,employee_name_snapshot,employee_role_snapshot,movement_type,source_type,finance_operation_id,bank_transaction_id,note,created_by_user_id,created_by_role) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$localUserId,$employee['identity_type'],(int)$employee['identity_id'],$employee['full_name'],$employee['role_code'],$movementType,$sourceType,$operationId,$bankTxId,self::nullableText($note),$user['id']??null,$user['role']??null]);
        $id=(int)$pdo->lastInsertId();
        FinanceAuditLogService::log($pdo,'finance_employee_movement',$id,'create',null,['employee_ref'=>$employee['ref'],'movement_type'=>$movementType,'source_type'=>$sourceType,'finance_operation_id'=>$operationId,'bank_transaction_id'=>$bankTxId],(int)($user['id']??0),(string)($user['role']??'company_owner'));
        return $id;
    }

    private static function assertDirectoryEmployee(array $employee):void
    {
        if(empty($employee['ref'])||empty($employee['full_name'])||!in_array($employee['identity_type']??'',[self::IDENTITY_TENANT_USER,self::IDENTITY_COMPANY_USER,self::IDENTITY_MEMBERSHIP],true)||(int)($employee['identity_id']??0)<=0)throw new \InvalidArgumentException('Сотрудник не найден или его аккаунт неактивен.');
    }

    private static function assertMovementType(string $movementType):void{if(!in_array($movementType,['PAYMENT','RETURN'],true))throw new \InvalidArgumentException('Тип операции должен быть PAYMENT или RETURN.');}
    private static function nullableText(mixed $value):?string{$value=trim((string)($value??''));return $value===''?null:mb_substr($value,0,1000);}
    private static function toCents(string $value):int{$normalized=str_replace([' ', ','],['','.'],trim($value));if($normalized===''||!preg_match('/^-?\d+(?:\.\d{1,2})?$/D',$normalized))return 0;$negative=str_starts_with($normalized,'-');if($negative)$normalized=substr($normalized,1);[$rubles,$kopecks]=array_pad(explode('.',$normalized,2),2,'');$kopecks=str_pad(substr($kopecks,0,2),2,'0');$cents=((int)$rubles*100)+(int)$kopecks;return $negative?-$cents:$cents;}
    private static function fromCents(int $cents):string{$negative=$cents<0;$abs=abs($cents);return ($negative?'-':'').intdiv($abs,100).'.'.str_pad((string)($abs%100),2,'0',STR_PAD_LEFT);}
}
