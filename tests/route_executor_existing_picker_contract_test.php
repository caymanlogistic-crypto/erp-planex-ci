<?php
$root = dirname(__DIR__);
$form = file_get_contents($root . '/app/View/partials/company_route_executor_create_form.php');
$submit = file_get_contents($root . '/app/Http/Controllers/Company/RouteExecutorActions/create_submit.php');
$page = file_get_contents($root . '/app/View/pages/company_route_executors.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($page, 'data-route-executor-existing-create'), 'visible existing-data action is missing');
$assert(str_contains($form, 'name="responsible_logist_membership_id"'), 'responsible logist selector is missing');
$assert(str_contains($form, "responsible_logist_membership_id'] ?? '') == \$logist['membership_id']"), 'responsible logist selection is not retained after validation');
$assert(str_contains($submit, "eligibleRecipientMembershipId"), 'responsible logist is not validated against eligible company memberships');
$assert(str_contains($submit, "INSERT INTO entity_responsibilities"), 'route executor responsibility is not persisted');
$assert(strpos($submit, 'INSERT INTO entity_responsibilities') < strpos($submit, '$localPdo->commit()'), 'responsibility must be saved inside the crew transaction');

echo "ROUTE_EXECUTOR_EXISTING_PICKER_CONTRACT=PASS\n";
