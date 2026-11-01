<?php

namespace App\Service;

use PDO;
use RuntimeException;

/** Durable central evidence; company_id and backup_id are each single-use. */
final class CompanyDeletionJournal
{
    public static function begin(PDO $pdo, array $company, int $actorUserId, array $backup): int
    {
        if ($actorUserId <= 0) {
            throw new RuntimeException('COMPANY_DELETE_ACTOR_MISSING');
        }
        $stmt = $pdo->prepare(
            "INSERT INTO company_deletion_runs
             (company_id, company_name, db_identifier, actor_user_id, actor_role, backup_id,
              backup_manifest_sha256, database_archive_sha256, storage_archive_sha256, restore_receipt_sha256,
              backup_created_at, backup_expires_at, status, stage)
             VALUES (?, ?, ?, ?, 'superadmin', ?, ?, ?, ?, ?, ?, ?, 'in_progress', 'backup_verified')"
        );
        try {
            $stmt->execute([
                (int)$company['id'], (string)$company['name'], (string)$company['db_identifier'],
                $actorUserId, $backup['id'], $backup['manifest_sha256'], $backup['database_sha256'],
                $backup['storage_sha256'], $backup['restore_receipt_sha256'], $backup['created_at'], $backup['expires_at'],
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new RuntimeException('COMPANY_DELETE_ALREADY_STARTED', 0, $e);
            }
            throw $e;
        }
        return (int)$pdo->lastInsertId();
    }

    public static function advance(PDO $pdo, int $runId, string $from, string $to): void
    {
        $allowed = [
            'backup_verified' => 'tenant_deleted',
            'tenant_deleted' => 'storage_deleted',
            'storage_deleted' => 'central_deleted',
            'central_deleted' => 'completed',
        ];
        if (($allowed[$from] ?? null) !== $to) {
            throw new RuntimeException('COMPANY_DELETE_JOURNAL_STAGE_INVALID');
        }
        $complete = $to === 'completed';
        $stmt = $pdo->prepare(
            "UPDATE company_deletion_runs SET stage=?, status=?, completed_at=" . ($complete ? 'NOW()' : 'NULL')
            . " WHERE id=? AND stage=? AND status='in_progress'"
        );
        $stmt->execute([$to, $complete ? 'completed' : 'in_progress', $runId, $from]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('COMPANY_DELETE_JOURNAL_STAGE_CONFLICT');
        }
    }

    public static function fail(PDO $pdo, int $runId, string $stage, string $code): void
    {
        if (!preg_match('/^[A-Z0-9_]{1,64}$/D', $code)) {
            $code = 'COMPANY_DELETE_UNCLASSIFIED_FAILURE';
        }
        $stmt = $pdo->prepare(
            "UPDATE company_deletion_runs SET status='failed', error_code=?
             WHERE id=? AND stage=? AND status='in_progress'"
        );
        $stmt->execute([$code, $runId, $stage]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('COMPANY_DELETE_JOURNAL_FAILURE_CONFLICT');
        }
    }
}
