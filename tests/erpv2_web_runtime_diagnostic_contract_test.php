<?php

declare(strict_types=1);

$path = __DIR__ . '/../.github/workflows/erpv2_web_runtime_diagnostic.yml';
$yaml = (string) file_get_contents($path);

foreach ([
    'qa/erpv2-web-runtime-diagnostic-20260902',
    'CANONICAL_BRANCH: chatgpt/production-stabilization-20260802',
    'SERVER_ROOT: /home/s/spugovxsim/planexp',
    'TASK_DIAGNOSTIC_BASE_SHA: a211573f385d1ac19bdebef0bc1e807fc8132187',
    'git merge-base --is-ancestor "$TASK_DIAGNOSTIC_BASE_SHA" "$head"',
    'ERPV2_SSH_PRIVATE_KEY',
    'ERPV2_SSH_KNOWN_HOSTS',
    'PROBE_NAME="_p07_runtime_${GITHUB_RUN_ID}.php"',
    'STATIC_NAME="_p07_static_${GITHUB_RUN_ID}.txt"',
    'REMOTE_PROBE="$SERVER_ROOT/${LIVE_DIR}/erpv2/public/$PROBE_NAME"',
    'REMOTE_STATIC="$SERVER_ROOT/${LIVE_DIR}/erpv2/public/$STATIC_NAME"',
    'chmod 0644 "$LOCAL_PROBE" "$LOCAL_STATIC"',
    'PROBE_MODE=%a',
    'STATIC_PROBE_HTTP=',
    'ERPV2_ASSET_HTTP=',
    'LEGACY_ERP_HTTP=',
    'ROOT_SITE_HTTP=',
    'PHP_VERSION=',
    'PHP_SAPI=',
    'OPCACHE_VALIDATE_TIMESTAMPS=',
    'OPCACHE_RESET=',
    'WEB_ERROR_LOG=',
    'WEB_INI_LOADED=',
    'erpv2_waste_log_probe_${GITHUB_RUN_ID}',
    'WT-260909-173446-777F2F',
    "function_exists('opcache_reset') && opcache_reset()",
    'https://plan-ex.ru/erpv2/${PROBE_NAME}?token=${TOKEN}',
    'LOGIN_HTTP_AFTER_WEB_OPCACHE_RESET=',
    'ERPV2_WEB_SERVER_DIAGNOSTIC_BEGIN',
    'PHP_WEB_CONFIG_BEGIN',
    'RECENT_SERVER_ERROR_EVIDENCE_BEGIN',
    'ERPV2_WEB_SERVER_DIAGNOSTIC_END',
    'test "$STATIC_HTTP" = 200',
    'test "$ASSET_HTTP" = 200',
    'test "$PROBE_HTTP" = 200',
    'test "$LOGIN_HTTP" = 200',
    'test -n "$WEB_VERSION"',
    'test -n "$WEB_SAPI"',
    'ERPv2 observed web runtime: PHP $WEB_VERSION ($WEB_SAPI)',
    'ERPV2_WEB_RUNTIME_DIAGNOSTIC=PASS',
] as $needle) {
    if (!str_contains($yaml, $needle)) {
        throw new RuntimeException('Missing ERPv2 web runtime diagnostic contract marker: ' . $needle);
    }
}

if (str_contains($yaml, 'REMOTE_PROBE="$HOME/planexp/')) {
    throw new RuntimeException('Remote probe path must never be derived from GitHub runner $HOME.');
}
if (str_contains($yaml, 'mysql ') || str_contains($yaml, 'UPDATE ') || str_contains($yaml, 'INSERT ') || str_contains($yaml, 'DELETE FROM ')) {
    throw new RuntimeException('Web runtime diagnostic must not perform production business/DB writes.');
}
if (!str_contains($yaml, 'trap cleanup EXIT') || !str_contains($yaml, "rm -f '\$REMOTE_PROBE' '\$REMOTE_STATIC'")) {
    throw new RuntimeException('Transient public PHP/static probes must be fail-safe cleaned up.');
}
if (!str_contains($yaml, "printf '::add-mask::%s\\n' \"\$TOKEN\"")) {
    throw new RuntimeException('Transient runtime probe token must be masked in Actions logs.');
}
if (!str_contains($yaml, "grep -Ein 'php|handler|cgi|fcgi|rewrite|error|index|options|require|allow|deny'")) {
    throw new RuntimeException('Failure diagnostics must remain bounded to handler/rewrite/access directives.');
}
if (!str_contains($yaml, "grep -Ein 'php|version|handler|cgi|fcgi|auto_prepend|auto_append|error_log|opcache|session|extension|zend'")) {
    throw new RuntimeException('PHP web config evidence must remain bounded to runtime-relevant directives.');
}
if (!str_contains($yaml, "grep -Ei 'erpv2|_p07_runtime|_p07_static|internal redirect|AH[0-9]+|premature|script headers|permission denied|primary script|php|cgi|fastcgi|fcgi'")) {
    throw new RuntimeException('Server error evidence must remain bounded to ERPv2/PHP/CGI failures.');
}
if (str_contains($yaml, 'cat "$SERVER_ROOT/$LIVE_DIR/.htaccess"') || str_contains($yaml, 'cat "$LIVE/.htaccess"') || str_contains($yaml, 'cat "$cfg"')) {
    throw new RuntimeException('Runtime diagnostic must not dump complete server/PHP configuration files.');
}
if (str_contains($yaml, 'case "$WEB_VERSION" in 8.5.*)') || str_contains($yaml, 'ERPv2 web runtime is not PHP 8.5')) {
    throw new RuntimeException('Web runtime diagnostic must validate executable health without hard-pinning the hosting PHP minor version.');
}
if (str_contains($yaml, 'workflow_dispatch:')) {
    throw new RuntimeException('Runtime diagnostic must run only from the exact-canonical guarded QA ref, not arbitrary manual source input.');
}

echo "erpv2_web_runtime_diagnostic_contract_test: PASS\n";
