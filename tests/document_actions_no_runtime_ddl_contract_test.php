<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$actions = $root.'/app/Http/Controllers/Company/DocumentActions';
$files = ['index.php', 'doc_types_index.php', 'upload_form.php', 'upload_submit.php'];
$checked = 0;
foreach ($files as $file) {
    $source = file_get_contents($actions.'/'.$file);
    if ($source === false) {
        throw new RuntimeException("Cannot read DocumentActions/$file");
    }
    if (preg_match('/(?:->exec\s*\(|\b(?:ALTER|CREATE|DROP)\s+TABLE\b|migrations-local\/)/i', $source)) {
        throw new RuntimeException("Request-time DDL remains in DocumentActions/$file");
    }
    $checked++;
}

$submit = file_get_contents($actions.'/upload_submit.php');
$schemaCheck = strpos($submit, "SELECT created_by_user_id, created_by_role FROM documents LIMIT 0");
$typeCheck = strpos($submit, "SELECT id FROM document_types LIMIT 0");
$fileMove = strpos($submit, 'move_uploaded_file(');
if ($schemaCheck === false || $typeCheck === false || $fileMove === false
    || $schemaCheck >= $fileMove || $typeCheck >= $fileMove) {
    throw new RuntimeException('Upload must verify numbered-migration schema before moving a file');
}

$policy = file_get_contents($root.'/app/Support/runtime_migration_policy.php');
if ($policy === false || !str_contains($policy, 'if (!$isCli && !$isExplicitTenantProvisioning)')) {
    throw new RuntimeException('Ordinary HTTP must remain outside the migration path');
}

echo "DOCUMENT_ACTIONS_NO_RUNTIME_DDL=PASS actions=$checked upload_schema_before_move=PASS\n";
