#!/usr/bin/env bash
# Disposable runner-local DB only. LOCAL uses Invoke-LocalBrowserTest.ps1 -Database.
set -euo pipefail
test "${GITHUB_ACTIONS:-}" = true
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ && "${GITHUB_RUN_ATTEMPT:-}" =~ ^[0-9]+$ ]]
schema="planexui${GITHUB_RUN_ID}a${GITHUB_RUN_ATTEMPT}"
user="ui${GITHUB_RUN_ID}a${GITHUB_RUN_ATTEMPT}"
test "${#user}" -le 32
sudo systemctl start mysql
cleanup() { mysql -uroot -proot -e "DROP DATABASE IF EXISTS \`$schema\`; DROP USER IF EXISTS '$user'@'127.0.0.1';"; }
trap cleanup EXIT
mysql -uroot -proot -e "CREATE DATABASE \`$schema\` CHARACTER SET utf8mb4; CREATE USER '$user'@'127.0.0.1' IDENTIFIED BY 'synthetic-ui-test'; GRANT ALL ON \`$schema\`.* TO '$user'@'127.0.0.1';"
export APP_ENV=test PLANEX_TEST_FIXED_DB="$schema" PLANEX_TEST_FIXED_DB_ALLOWLIST="$schema"
export PLANEX_SCHEMA_DB_HOST=127.0.0.1 PLANEX_SCHEMA_DB_PORT=3306 PLANEX_SCHEMA_DB_USER="$user" PLANEX_SCHEMA_DB_PASSWORD=synthetic-ui-test
node tests/registry_runtime_gate_test.mjs
node tools/registry_runtime_gate.mjs
