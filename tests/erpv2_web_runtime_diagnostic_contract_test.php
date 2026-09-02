<?php

declare(strict_types=1);

$path = __DIR__ . '/../.github/workflows/erpv2_web_runtime_diagnostic.yml';
$yaml = (string) file_get_contents($path);

foreach ([
    'qa/erpv2-web-runtime-diagnostic-20260902',
    'CANONICAL_BRANCH: chatgpt/production-stabilization-20260802',
    'SERVER_ROOT: /home/s/spugovxsim/planexp',
    'test "$(git rev-parse HEAD)" = "$(git rev-parse FETCH_HEAD)"',
    'ERPV2_SSH_PRIVATE_KEY',
    'ERPV2_SSH_KNOWN_HOSTS',
    'PROBE_NAME="_p07_runtime_${GITHUB_RUN_ID}.php"',
    'REMOTE_PROBE="$SERVER_ROOT/${LIVE_DIR}/erpv2/public/$PROBE_NAME"',
    'chmod 0644 "$LOCAL_PROBE"',
    'chmod 0644 \'$REMOTE_PROBE\'',
    'PROBE_MODE=%a',
    'PHP_VERSION=',
    'PHP_SAPI=',
    'OPCACHE_VALIDATE_TIMESTAMPS=',
    'OPCACHE_RESET=',
    "function_exists('opcache_reset') && opcache_reset()",
    'https://plan-ex.ru/erpv2/${PROBE_NAME}?token=${TOKEN}',
    'LOGIN_HTTP_AFTER_WEB_OPCACHE_RESET=',
    'ERPV2_WEB_SERVER_DIAGNOSTIC_BEGIN',
    'ERPV2_WEB_SERVER_DIAGNOSTIC_END',
    'case "$WEB_VERSION" in 8.5.*)',
    'test ! -e',
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
if (!str_contains($yaml, 'trap cleanup EXIT') || !str_contains($yaml, 'rm -f \'$REMOTE_PROBE\'')) {
    throw new RuntimeException('Transient public runtime probe must be fail-safe cleaned up.');
}
if (!str_contains($yaml, "printf '::add-mask::%s\\n' \"\$TOKEN\"")) {
    throw new RuntimeException('Transient runtime probe token must be masked in Actions logs.');
}
if (!str_contains($yaml, "grep -Ein 'php|handler|cgi|fcgi|rewrite|error|index|options|require|allow|deny'")) {
    throw new RuntimeException('Failure diagnostics must remain bounded to handler/rewrite/access directives.');
}
if (str_contains($yaml, 'cat "$SERVER_ROOT/$LIVE_DIR/.htaccess"') || str_contains($yaml, 'cat "$LIVE/.htaccess"')) {
    throw new RuntimeException('Runtime diagnostic must not dump complete server configuration files.');
}
if (str_contains($yaml, 'workflow_dispatch:')) {
    throw new RuntimeException('Runtime diagnostic must run only from the exact-canonical guarded QA ref, not arbitrary manual source input.');
}

echo "erpv2_web_runtime_diagnostic_contract_test: PASS\n";
