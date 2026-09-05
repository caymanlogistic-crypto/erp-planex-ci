<?php

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$service = file_get_contents($root . '/app/Service/ResponsibleAssignmentService.php');
$get = file_get_contents($root . '/app/Http/Controllers/Company/ResponsibleAssignmentActions/assignments.php');
$post = file_get_contents($root . '/app/Http/Controllers/Company/ResponsibleAssignmentActions/reassign.php');
$routes = file_get_contents($root . '/app/Http/Routes/company_responsible_assignments.php');

$assert(str_contains($routes, "'/company/roles-access/assignments'"), 'GET assignment endpoint must be registered.');
$assert(substr_count($routes, '$rolesAccessPage') >= 3, 'GET and POST must be server-mapped to company.roles_access.');
$assert(str_contains($routes, "'company.roles_access'"), 'Assignment endpoints must use canonical roles access page key.');

$assert(substr_count($get, 'isEffectiveSystemOwner()') === 1, 'GET must be owner-only.');
$assert(substr_count($post, 'isEffectiveSystemOwner()') === 1, 'POST must be owner-only.');
$assert(str_contains($get, "'error' => 'forbidden'") && str_contains($get, '], 403)'), 'GET must define JSON 403.');
$assert(str_contains($get, "'error' => 'invalid_membership_id'") && str_contains($get, '], 422)'), 'GET must define JSON 422.');
$assert(str_contains($get, "'error' => 'membership_not_found'") && str_contains($get, '], 404)'), 'GET must define JSON 404.');
$assert(str_contains($get, "'error' => 'assignment_load_failed'") && str_contains($get, '], 500)'), 'GET must define bounded JSON 500.');

$assert(str_contains($service, 'WHERE m.company_id = ?'), 'Memberships must be isolated by current company.');
$assert(str_contains($service, 'company_memberships') && str_contains($service, 'user_accounts'), 'Payload must use stable central memberships.');
$assert(str_contains($post, 'WHERE company_id = ? AND id IN'), 'POST membership ids must be isolated by current company.');
$assert(str_contains($post, 'array_replace($_POST, requestJsonBody())'), 'POST must accept form and JSON full-set payloads.');
$assert(!str_contains($post, 'FROM users WHERE membership_id') && !str_contains($post, "membership_id IN ({\$marks}) AND status = 'active'"), 'Blocked memberships must remain assignable for redistribution.');

foreach (['client', 'contractor', 'vehicle_unit', 'driver'] as $entityType) {
    $assert(str_contains($service, "'{$entityType}' => ["), "Payload must include {$entityType}.");
}
$assert(str_contains($service, 'entity_assignments ea'), 'Assigned flags must come from canonical M:N assignments.');
$assert(str_contains($service, 'ea.membership_id = ?'), 'Assigned flags must use stable membership_id.');
$assert(str_contains($service, "e.status <> 'archived'") && str_contains($service, 'e.deleted_at IS NULL'), 'Archived/deleted objects must be excluded.');
$assert(!str_contains($service, 'vehicle_sets') && !str_contains($service, "'vehicle_set'"), 'VehicleSet must not be an assignment target.');
$assert(!str_contains($service, 'linear_route') && !str_contains($service, 'waste_trip'), 'Trips must not be assignment targets.');
$assert(!str_contains($service, 'created_by_user_id') && !str_contains($service, 'cascade'), 'Payload must not revive legacy creator/cascade semantics.');

foreach (['memberships', 'selected_membership_id', 'entities', 'assigned'] as $key) {
    $assert(str_contains($service, "'{$key}'"), "Response contract must contain {$key}.");
}
$assert(str_contains($post, "'legacy_assignment_payload_retired'") && str_contains($post, '], 410)'), 'Legacy transfer/cascade payload must return JSON 410.');

$entrypoint = file_get_contents($root . '/app/Support/entrypoint_dependencies.php');
$assert(str_contains($entrypoint, "app/Service/EntityAssignmentService.php"), 'EntityAssignmentService must be loaded by the public entrypoint.');
$assert(strpos($entrypoint, "app/Service/EntityAssignmentService.php") < strpos($entrypoint, "app/Service/ResponsibleAssignmentService.php"), 'EntityAssignmentService must load before ResponsibleAssignmentService consumers.');
$assert(str_contains($post, "'error' => 'assignment_update_failed'") && str_contains($post, '], 500)'), 'Unexpected assignment backend failures must be bounded JSON 500.');
$assert(str_contains($post, "'message' => 'Не удалось сохранить закрепление. Повторите позже.'"), 'Unexpected assignment failures must expose only a safe user message.');
$assert(!str_contains($post, "'message' => \$e->getMessage()"), 'Unexpected backend exception text must never be returned to the user.');
$assert(str_contains($post, "'error' => 'invalid_assignment_membership'") && str_contains($post, '], 422)'), 'Invalid membership set must return JSON 422.');
$assert(str_contains($post, "'error' => 'assignment_target_not_found'") && str_contains($post, '], 422)'), 'Missing assignment target must return JSON 422.');
$assert(str_contains($post, "'membership_ids' => \$normalized") || str_contains($post, "'membership_ids'=>\$normalized"), 'POST must return normalized full membership set.');

if ($failures !== []) {
    fwrite(STDERR, "roles_access_assignment_endpoint_test: FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "roles_access_assignment_endpoint_test: PASS\n";
