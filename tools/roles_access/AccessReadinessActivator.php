<?php

declare(strict_types=1);

namespace Planex\Tools\RolesAccess;

use PDO;
use RuntimeException;

/**
 * Persists the activation gate only after recomputing every proof artifact.
 * This class never performs deployment or changes the feature flag.
 */
final class AccessReadinessActivator
{
    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param list<array<string,mixed>> $exceptions
     * @param array<string,mixed> $manifest
     * @return array{ready:true,manifest_sha256:string}
     */
    public static function verifyAndMarkReady(
        PDO $central,
        int $companyId,
        array $before,
        array $after,
        array $exceptions,
        array $manifest,
    ): array {
        $before = MigrationProof::canonicalSnapshot($before);
        $after = MigrationProof::canonicalSnapshot($after);
        $comparison = MigrationProof::compare($before, $after, $exceptions);

        $expectedBefore = MigrationProof::hash($before);
        $expectedAfter = MigrationProof::hash($after);
        $expectedComparison = (string) ($comparison['comparison_sha256'] ?? '');

        if (($manifest['company_id'] ?? null) !== $companyId
            || ($manifest['before_sha256'] ?? null) !== $expectedBefore
            || ($manifest['after_sha256'] ?? null) !== $expectedAfter
            || ($manifest['comparison_sha256'] ?? null) !== $expectedComparison
            || ($manifest['comparison_accepted'] ?? false) !== ($comparison['accepted'] ?? false)
            || ($manifest['unapproved_diff_count'] ?? null) !== count($comparison['unapproved'] ?? [])) {
            throw new RuntimeException('Readiness manifest does not match recomputed proof artifacts.');
        }

        $readiness = MigrationProof::readiness($manifest);
        if (!$readiness['ready']) {
            throw new RuntimeException(
                'Readiness gates failed: ' . implode(',', $readiness['failed_gates'])
            );
        }

        $lookup = $central->prepare(
            'SELECT state,migration_key FROM company_access_readiness WHERE company_id=? FOR UPDATE'
        );

        $central->beginTransaction();
        try {
            $lookup->execute([$companyId]);
            $row = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!$row || !in_array((string) $row['state'], ['migrated', 'verified'], true)) {
                throw new RuntimeException("Company {$companyId} must be migrated before activation.");
            }
            if ((string) ($row['migration_key'] ?? '') !== 'roles-access:' . (string) $manifest['source_head']) {
                throw new RuntimeException("Company {$companyId} migration key does not match source_head.");
            }

            $update = $central->prepare(
                "UPDATE company_access_readiness
                 SET state='ready',before_sha256=?,after_sha256=?,comparison_sha256=?,
                     verified_at=NOW(),activated_at=NOW()
                 WHERE company_id=? AND state IN ('migrated','verified')"
            );
            $update->execute([$expectedBefore, $expectedAfter, $expectedComparison, $companyId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException("Company {$companyId} readiness transition was not applied.");
            }
            $central->commit();
        } catch (\Throwable $e) {
            if ($central->inTransaction()) {
                $central->rollBack();
            }
            throw $e;
        }

        return ['ready' => true, 'manifest_sha256' => $readiness['manifest_sha256']];
    }
}
