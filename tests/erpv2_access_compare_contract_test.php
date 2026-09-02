<?php

declare(strict_types=1);

$path = __DIR__ . '/../.github/workflows/erpv2_access_compare.yml';
$yaml = (string) file_get_contents($path);

foreach ([
    'qa/erpv2-access-compare-20260902',
    'CANONICAL_BRANCH: chatgpt/production-stabilization-20260802',
    'SERVER_ROOT: /home/s/spugovxsim/planexp',
    'LAST_GOOD_SHA: 001f9f49134e42819a4645610d5df2e78d733133',
    'test "$(git rev-parse HEAD)" = "$(git rev-parse FETCH_HEAD)"',
    'ERPV2_SSH_PRIVATE_KEY',
    'Compare current ERPv2 legacy ERP and last-good snapshot',
    'P21_*_${LAST_GOOD_SHA}',
    'LAST_GOOD_HTACCESS_SHA256=',
    'LAST_GOOD_PUBLIC_HTACCESS=',
    'ROUTING_FILE=',
    'ACCESS_MODE=',
    "'ERPV2_LOGIN|https://plan-ex.ru/erpv2/login'",
    "'ERPV2_ASSET|https://plan-ex.ru/erpv2/assets/css/app.css'",
    "'LEGACY_ERP|https://plan-ex.ru/erp/'",
    "'ROOT_SITE|https://plan-ex.ru/'",
    "printf '%s_HTTP=%s\\n' \"\$name\" \"\$code\"",
] as $needle) {
    if (!str_contains($yaml, $needle)) {
        throw new RuntimeException('Missing ERPv2 access compare contract marker: ' . $needle);
    }
}

if (str_contains($yaml, 'workflow_dispatch:')) {
    throw new RuntimeException('ERPv2 access compare must run only from the exact-canonical QA ref.');
}
foreach (['scp ', 'rsync ', 'file_put_contents', 'sed -i', 'mysql ', 'UPDATE ', 'INSERT ', 'DELETE FROM '] as $forbidden) {
    if (str_contains($yaml, $forbidden)) {
        throw new RuntimeException('ERPv2 access compare must stay read-only on production: ' . $forbidden);
    }
}
if (preg_match('/(?:chmod|chown|mv|cp|rm\s+-[rf]+)[^\n]*(?:\$SERVER_ROOT|\$LIVE|\$OLD|\$BACKUPS)/i', $yaml)) {
    throw new RuntimeException('ERPv2 access compare must not mutate production paths.');
}
if (!str_contains($yaml, 'chmod 600 "$HOME/.ssh/erpv2_access_compare_key" "$HOME/.ssh/known_hosts"')) {
    throw new RuntimeException('Local SSH key permissions must remain restricted.');
}
if (!str_contains($yaml, "grep -Ein 'options|directoryindex|rewrite|fallbackresource|errordocument|redirect|alias|php|handler|cgi|fcgi|require|allow|deny|setenv'")) {
    throw new RuntimeException('Routing evidence must stay bounded to relevant directives.');
}
if (str_contains($yaml, 'cat "$LIVE/.htaccess"') || str_contains($yaml, 'cat "$OLD/.htaccess"')) {
    throw new RuntimeException('ERPv2 access compare must not dump full routing files.');
}

echo "erpv2_access_compare_contract_test: PASS\n";
