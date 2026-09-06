<?php

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$service = (string)file_get_contents($root . '/app/Service/ResponsibleAssignmentService.php');
$get = (string)file_get_contents($root . '/app/Http/Controllers/Company/ResponsibleAssignmentActions/assignments.php');
$explicit = (string)file_get_contents($root . '/app/Http/Controllers/Company/ResponsibleAssignmentActions/explicit_assignment.php');
$responsibility = (string)file_get_contents($root . '/app/Http/Controllers/Company/ResponsibleAssignmentActions/reassign.php');
$routes = (string)file_get_contents($root . '/app/Http/Routes/company_responsible_assignments.php');

$assert(str_contains($routes, "'/company/roles-access/assignments'"), 'GET assignment endpoint must be registered.');
$assert(str_contains($routes, "'/company/roles-access/assignments/reassign'"), 'Explicit assignment POST must have dedicated endpoint.');
$assert(str_contains($routes, "'/company/responsible-assignments/reassign'"), 'Responsibility transfer POST must remain registered.');
$assert(str_contains($routes, '$rolesAccessPage') && str_contains($routes, "'company.roles_access'"), 'Explicit assignment endpoints must use roles-access page key.');
$assert(str_contains($routes, '$responsibilityPage') && str_contains($routes, "'company.responsible_assignments'"), 'Responsibility tool must use separate canonical owner-only key.');

$assert(substr_count($get, 'isEffectiveSystemOwner()') === 1, 'GET must be owner-only.');
$assert(substr_count($explicit, 'isEffectiveSystemOwner()') === 1, 'Explicit POST must be owner-only.');
$assert(substr_count($responsibility, 'isEffectiveSystemOwner()') === 1, 'Responsibility POST must be owner-only.');
$assert(str_contains($get, "'error' => 'forbidden'") && str_contains($get, '], 403)'), 'GET must define JSON 403.');
$assert(str_contains($get, "'error' => 'invalid_membership_id'") && str_contains($get, '], 422)'), 'GET must define JSON 422.');
$assert(str_contains($get, "'error' => 'membership_not_found'") && str_contains($get, '], 404)'), 'GET must define JSON 404.');
$assert(str_contains($get, "'error' => 'assignment_load_failed'") && str_contains($get, '], 500)'), 'GET must define bounded JSON 500.');

$assert(str_contains($service, 'WHERE m.company_id=?'), 'Memberships must be isolated by current company.');
$assert(str_contains($service, 'company_memberships') && str_contains($service, 'user_accounts'), 'Payload must use stable central memberships.');
foreach (['client','contractor','vehicle_unit','driver'] as $entityType) {
    $assert(str_contains($service, "'{$entityType}'=>["), "Payload must include {$entityType}.");
}
$assert(str_contains($service, 'entity_assignments ea'), 'Assignment payload must read canonical M:N storage.');
$assert(str_contains($service, 'ea.membership_id=?'), 'Assignment flags must use stable membership id.');
$assert(str_contains($service, "e.status<>'archived'") && str_contains($service, 'e.deleted_at IS NULL'), 'Archived/deleted objects must be excluded.');
$assert(!str_contains($service, "'vehicle_set'=>[") && !str_contains($service, "'linear_trip'=>[") && !str_contains($service, "'waste_trip'=>["), 'Assignment tab must remain four reference entity types only.');

$assert(str_contains($explicit, 'WHERE company_id = ? AND id IN'), 'Explicit POST membership ids must be isolated by company.');
$assert(str_contains($explicit, 'array_replace($_POST, requestJsonBody())'), 'Explicit POST must accept JSON/full-set payload.');
$assert(str_contains($explicit, 'EntityAssignmentService::replace'), 'Explicit POST must mutate only M:N assignment service.');
$assert(!str_contains($explicit, 'EntityResponsibilityService::transfer'), 'Explicit POST must not transfer base responsibility.');
$assert(str_contains($explicit, "'error' => 'assignment_update_failed'") && str_contains($explicit, '], 500)'), 'Explicit failures must be bounded JSON 500.');
$assert(str_contains($explicit, "'error' => 'invalid_assignment_membership'") && str_contains($explicit, '], 422)'), 'Invalid membership set must be 422.');
$assert(str_contains($explicit, "'error' => 'assignment_target_not_found'") && str_contains($explicit, '], 422)'), 'Missing explicit target must be 422.');
$assert(str_contains($explicit, "'membership_ids'=>\$normalized") || str_contains($explicit, "'membership_ids' => \$normalized"), 'Explicit POST must return normalized full set.');

$assert(str_contains($responsibility, 'EntityResponsibilityService::transfer'), 'Responsibility POST must mutate responsibility projection.');
$assert(!str_contains($responsibility, 'EntityAssignmentService::replace'), 'Responsibility transfer must not rewrite explicit assignments.');
$assert(!str_contains($responsibility, 'UPDATE contractors SET created_by_user_id') && !str_contains($responsibility, 'UPDATE contractors SET created_by_membership_id'), 'Responsibility transfer must not rewrite creator history.');
$assert(str_contains($responsibility, 'WHERE id=? AND company_id=?') || str_contains($responsibility, 'WHERE id=? AND company_id=? AND'), 'Responsibility target membership must be company-isolated.');

$entrypoint = (string)file_get_contents($root . '/app/Support/entrypoint_dependencies.php');
foreach (['EntityAssignmentService.php','EntityResponsibilityService.php','ResponsibleAssignmentService.php'] as $dependency) {
    $assert(str_contains($entrypoint, $dependency), "Runtime dependency missing {$dependency}.");
}

if ($failures !== []) {
    fwrite(STDERR, "roles_access_assignment_endpoint_test: FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "roles_access_assignment_endpoint_test: PASS\n";
echo "EXPLICIT_ASSIGNMENT_ENDPOINT=SEPARATE\n";
echo "RESPONSIBILITY_TRANSFER_ENDPOINT=SEPARATE\n";
