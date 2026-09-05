<?php

declare(strict_types=1);

use Planex\Tools\RolesAccess\MigrationProof;

require_once __DIR__ . '/../tools/roles_access/MigrationProof.php';

$expectThrows = static function (callable $fn, string $message): void {
    try {
        $fn();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
};
$member = static function (string $key = 'company:1:legacy-user:1', int $companyId = 1): array {
    $row = ['migration_key' => $key, 'company_id' => $companyId];
    foreach (MigrationProof::CATEGORIES as $category) {
        $row[$category] = [];
    }
    return $row;
};
$snapshot = static fn (string $phase, array $memberships): array => [
    'phase' => $phase,
    'source_head' => str_repeat('a', 40),
    'memberships' => $memberships,
];

$expectThrows(fn () => MigrationProof::canonicalSnapshot(['phase' => 'BEFORE']), 'incomplete snapshot accepted');
$incomplete = $member();
unset($incomplete['drivers']);
$expectThrows(fn () => MigrationProof::canonicalSnapshot($snapshot('BEFORE', [$incomplete])), 'missing category accepted');

$before = $snapshot('BEFORE', [$member()]);
$afterMissing = $snapshot('AFTER', [$member('company:2:legacy-user:2', 2)]);
$comparison = MigrationProof::compare($before, $afterMissing);
if ($comparison['accepted'] || count($comparison['unapproved']) !== 2) {
    throw new RuntimeException('membership disappearance/addition was not blocked');
}
$afterCompany = $snapshot('AFTER', [$member('company:1:legacy-user:1', 2)]);
if (MigrationProof::compare($before, $afterCompany)['accepted']) {
    throw new RuntimeException('company mismatch was not blocked');
}
$diff = MigrationProof::compare($before, $afterMissing)['diffs'][0];
$withoutEvidence = $diff + ['owner_approved' => true, 'reason' => 'accepted'];
if (MigrationProof::compare($before, $afterMissing, [$withoutEvidence])['accepted']) {
    throw new RuntimeException('exception without owner evidence was accepted');
}

$plan = MigrationProof::assignmentPlan(
    [
        ['entity_type' => 'client', 'entity_id' => 5, 'granted_to_user_id' => 9, 'is_live' => '1', 'revoked_at' => null],
        ['entity_type' => 'vehicle_set', 'entity_id' => 6, 'granted_to_user_id' => 9, 'is_live' => true, 'revoked_at' => null],
    ],
    [['entity_type' => 'driver', 'entity_id' => 7, 'created_by_user_id' => 404, 'proven' => 1]],
    [9 => 90]
);
if (!$plan['blocked'] || count($plan['assignments']) !== 1 || count($plan['conflicts']) !== 2) {
    throw new RuntimeException('strict assignment planning did not preserve/block correctly');
}
if (MigrationProof::inferTripScope([1, 2], [1], [1])['scope'] !== 'own'
    || MigrationProof::inferTripScope([1, 2], [1], [2])['blocked'] !== true
    || MigrationProof::inferTripScope([1, 2], [1], [])['scope'] !== 'none') {
    throw new RuntimeException('trip representability detection failed');
}


$duplicateMembership = MigrationProof::identityPlan([
    ['company_id' => 1, 'legacy_company_user_id' => 10, 'login' => 'same', 'full_name' => 'User', 'password_hash' => 'hash'],
    ['company_id' => 1, 'legacy_company_user_id' => 11, 'login' => ' SAME ', 'full_name' => 'User', 'password_hash' => 'hash'],
]);
if (!$duplicateMembership['blocked'] || !isset($duplicateMembership['conflicts'][0]['fields']['_company_membership'])) {
    throw new RuntimeException('duplicate membership for normalized login/company was accepted');
}
$conflictEvidence = MigrationProof::identityConflictEvidence($duplicateMembership['conflicts']);
if (count($conflictEvidence) !== 1
    || ($conflictEvidence[0]['fields'] ?? []) !== ['_company_membership']
    || ($conflictEvidence[0]['source_refs'] ?? []) !== ['1:central_company_user:10', '1:central_company_user:11']
    || !preg_match('/^[a-f0-9]{16}$/', (string) ($conflictEvidence[0]['login_fingerprint'] ?? ''))
    || str_contains(json_encode($conflictEvidence, JSON_THROW_ON_ERROR), 'same')
) {
    throw new RuntimeException('identity conflict evidence is not sanitized/deterministic');
}
$missingIdentity = MigrationProof::identityPlan([
    ['company_id' => 1, 'legacy_company_user_id' => 12, 'login' => 'missing', 'full_name' => '', 'password_hash' => ''],
]);
if (!$missingIdentity['blocked']) {
    throw new RuntimeException('missing required identity fields were accepted');
}
$sharedIdentity = MigrationProof::identityPlan([
    ['company_id' => 1, 'legacy_company_user_id' => 13, 'login' => 'shared', 'full_name' => 'Shared', 'password_hash' => 'hash'],
    ['company_id' => 2, 'legacy_company_user_id' => 14, 'login' => 'SHARED', 'full_name' => 'Shared', 'password_hash' => 'hash'],
]);
if ($sharedIdentity['blocked'] || count($sharedIdentity['accounts']) !== 1 || count($sharedIdentity['memberships']) !== 2) {
    throw new RuntimeException('valid cross-company shared account was not planned');
}

$passGates = array_fill_keys(
    ['central_schema', 'tenant_schema', 'identity_conflicts', 'before_snapshot', 'migration', 'after_snapshot', 'parity', 'independent_regression'],
    'PASS',
);
$readyManifest = [
    'gates' => $passGates,
    'source_head' => str_repeat('b', 40),
    'company_id' => 1,
    'membership_count' => 1,
    'tenant_count' => 1,
    'artifact_hashes_verified' => true,
    'comparison_accepted' => true,
    'identity_conflict_count' => 0,
    'unapproved_diff_count' => 0,
    'owner_exception_count' => 0,
    'owner_exceptions' => [],
    'before_sha256' => str_repeat('1', 64),
    'after_sha256' => str_repeat('2', 64),
    'comparison_sha256' => str_repeat('3', 64),
    'central_migration_sha256' => str_repeat('4', 64),
    'tenant_migration_sha256' => str_repeat('5', 64),
];
if (!MigrationProof::readiness($readyManifest)['ready']) {
    throw new RuntimeException('complete readiness evidence was rejected');
}
$unverifiedManifest = $readyManifest;
$unverifiedManifest['artifact_hashes_verified'] = false;
if (MigrationProof::readiness($unverifiedManifest)['ready']) {
    throw new RuntimeException('unverified artifact hashes were accepted');
}

echo "roles access migration proof: PASS\n";
