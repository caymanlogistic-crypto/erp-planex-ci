#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 3 ]; then
  echo 'Usage: P07_deploy_erpv2.sh <artifact.tar.gz> <artifact_sha256> <source_sha>' >&2
  exit 64
fi

artifact="$1"
expected_sha="$2"
source_sha="$3"

if ! [[ "$expected_sha" =~ ^[0-9a-f]{64}$ ]]; then
  echo 'Invalid artifact SHA-256.' >&2
  exit 65
fi
if ! [[ "$source_sha" =~ ^[0-9a-f]{40}$ ]]; then
  echo 'Invalid source commit SHA.' >&2
  exit 65
fi
if [ ! -f "$artifact" ]; then
  echo 'Deployment artifact does not exist.' >&2
  exit 66
fi

ROOT=/home/s/spugovxsim/planexp
PHP_BIN=/usr/bin/php8.5
TEST_MODE=0
HEALTH_CMD=''
if [ "${PLANEX_P07_TEST_MODE:-0}" = '1' ]; then
  TEST_MODE=1
  test_root="${PLANEX_P07_ROOT_OVERRIDE:-}"
  case "$test_root" in
    /tmp/*) ROOT="$test_root" ;;
    *) echo 'Test root override must be under /tmp.' >&2; exit 65 ;;
  esac
  if [ -n "${PLANEX_P07_PHP_BIN:-}" ]; then
    PHP_BIN="$PLANEX_P07_PHP_BIN"
  fi
  HEALTH_CMD="${PLANEX_P07_HEALTH_CMD:-}"
  if [ -z "$HEALTH_CMD" ] || [ ! -x "$HEALTH_CMD" ]; then
    echo 'Test health command is required and must be executable.' >&2
    exit 65
  fi
elif [ -n "${PLANEX_P07_ROOT_OVERRIDE:-}" ] || [ -n "${PLANEX_P07_PHP_BIN:-}" ] || [ -n "${PLANEX_P07_HEALTH_CMD:-}" ]; then
  echo 'P07 overrides are forbidden outside test mode.' >&2
  exit 65
fi

if [ ! -x "$PHP_BIN" ]; then
  echo "Required ERPv2 PHP runtime missing or not executable: $PHP_BIN" >&2
  exit 69
fi
php_version="$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null)" || {
  echo "Unable to execute ERPv2 PHP runtime: $PHP_BIN" >&2
  exit 69
}
case "$php_version" in
  8.5.*) ;;
  *) echo "Required ERPv2 PHP 8.5 runtime, got: $php_version ($PHP_BIN)" >&2; exit 69 ;;
esac
printf 'P07_PHP_BIN=%s version=%s\n' "$PHP_BIN" "$php_version"

LIVE="$ROOT/public_html/erpv2"
DEPLOY="$ROOT/deploy"
BACKUPS="$ROOT/deploy_backups"
MARKER="$DEPLOY/P07_erpv2_deployed_commit.txt"
ALLOWED_ROOTS=(app bootstrap config database public scripts tools tests resources)
HOST_MANAGED_PUBLIC_FILES=(.htaccess .user.ini)

mkdir -p "$DEPLOY" "$BACKUPS"
test -d "$LIVE"
test -f "$MARKER"

actual_sha="$(sha256sum "$artifact" | awk '{print $1}')"
if [ "$actual_sha" != "$expected_sha" ]; then
  echo 'Deployment artifact SHA-256 mismatch.' >&2
  exit 65
fi

work="$(mktemp -d "$DEPLOY/P07_stage_${source_sha}_XXXXXX")"
stage="$work/stage"
rollback="$work/rollback"
mkdir -p "$stage" "$rollback"
committed=0
deployed_roots=()

rollback_live() {
  local rc=$?
  if [ "$committed" -eq 0 ] && [ "${#deployed_roots[@]}" -gt 0 ]; then
    set +e
    for ((i=${#deployed_roots[@]}-1; i>=0; i--)); do
      name="${deployed_roots[$i]}"
      rm -rf "$LIVE/$name"
      if [ -e "$rollback/$name" ]; then
        mv "$rollback/$name" "$LIVE/$name"
      fi
    done
  fi
  rm -rf "$work"
  exit "$rc"
}
trap rollback_live EXIT INT TERM

listing="$work/artifact_listing.txt"
tar -tzf "$artifact" > "$listing"
if [ ! -s "$listing" ]; then
  echo 'Deployment artifact is empty.' >&2
  exit 65
fi

forbidden='(^|/)(\.git|\.env([^/]*)?|storage|uploads|logs|tmp|screenshots|node_modules|vendor)(/|$)|(^|/)(cookies?|reports)(/|$)'
if grep -E "$forbidden" "$listing" >/dev/null; then
  echo 'Forbidden runtime or secret path in deployment artifact.' >&2
  exit 65
fi

while IFS= read -r entry; do
  [ -n "$entry" ] || continue
  entry="${entry#./}"
  top="${entry%%/*}"
  allowed=0
  for name in "${ALLOWED_ROOTS[@]}"; do
    if [ "$top" = "$name" ]; then allowed=1; break; fi
  done
  if [ "$allowed" -ne 1 ]; then
    echo "Unexpected top-level deployment root: $top" >&2
    exit 65
  fi
done < "$listing"

tar -xzf "$artifact" -C "$stage"
if find "$stage" -type l -print -quit | grep -q .; then
  echo 'Symlinks are forbidden in deployment artifact.' >&2
  exit 65
fi

for name in "${ALLOWED_ROOTS[@]}"; do
  if [ ! -d "$stage/$name" ]; then
    echo "Required deployment root missing: $name" >&2
    exit 65
  fi
  if [ -e "$LIVE/$name" ] && { [ ! -d "$LIVE/$name" ] || [ -L "$LIVE/$name" ]; }; then
    echo "Live deployment root is not a normal directory: $name" >&2
    exit 65
  fi
done

# The repository intentionally does not own hosting control files inside public/.
# Preserve a normal live copy. If a previous root swap already removed it, recover
# only that exact hosting-control file from the newest controlled backup that has it.
recover_public_control() {
  local control="$1" live_control="$LIVE/public/$control" stage_control="$stage/public/$control"
  local archive member meta restore_dir restored
  [ ! -e "$stage_control" ] || return 0
  if [ -f "$live_control" ] && [ ! -L "$live_control" ]; then
    cp -p "$live_control" "$stage_control"
    printf 'P07_PRESERVED_PUBLIC_CONTROL=%s\n' "$control"
    return 0
  fi
  while IFS= read -r archive; do
    [ -n "$archive" ] || continue
    for member in "./public/$control" "public/$control"; do
      meta="$(tar -tvzf "$archive" "$member" 2>/dev/null | head -n1 || true)"
      case "$meta" in
        -*) ;;
        *) continue ;;
      esac
      restore_dir="$work/host_control_restore_${control//./_}"
      rm -rf "$restore_dir"
      mkdir -p "$restore_dir"
      tar -xzf "$archive" -C "$restore_dir" "$member" 2>/dev/null || continue
      restored="$restore_dir/${member#./}"
      if [ -f "$restored" ] && [ ! -L "$restored" ]; then
        cp -p "$restored" "$stage_control"
        printf 'P07_RECOVERED_PUBLIC_CONTROL=%s backup=%s\n' "$control" "$archive"
        return 0
      fi
    done
  done < <(find "$BACKUPS" -mindepth 2 -maxdepth 2 -type f \( -name erpv2_current.tar.gz -o -name erpv2_before.tar.gz \) -printf '%T@ %p\n' 2>/dev/null | sort -nr | cut -d' ' -f2-)
  printf 'P07_PUBLIC_CONTROL_UNAVAILABLE=%s\n' "$control"
}
for control in "${HOST_MANAGED_PUBLIC_FILES[@]}"; do
  recover_public_control "$control"
done

while IFS= read -r -d '' file; do
  "$PHP_BIN" -l "$file" >/dev/null
done < <(find "$stage/app" "$stage/bootstrap" "$stage/config" "$stage/public" "$stage/scripts" "$stage/tools" "$stage/tests" -type f -name '*.php' -print0)

echo 'Preflight passed.'

ts="$(date -u +%Y%m%dT%H%M%SZ)"
backup="$BACKUPS/P07_${ts}_${source_sha}"
mkdir -p "$backup"
tar -C "$LIVE" -czf "$backup/erpv2_before.tar.gz" .
test -s "$backup/erpv2_before.tar.gz"

for name in "${ALLOWED_ROOTS[@]}"; do
  if [ -e "$LIVE/$name" ]; then
    mv "$LIVE/$name" "$rollback/$name"
  fi
  deployed_roots+=("$name")
  mv "$stage/$name" "$LIVE/$name"
done

for name in "${ALLOWED_ROOTS[@]}"; do
  test -d "$LIVE/$name"
done

health_http=''
if [ "$TEST_MODE" -eq 1 ]; then
  health_http="$("$HEALTH_CMD")"
else
  set +e
  health_http="$(curl -sS -L -o /dev/null -w '%{http_code}' --max-time 30 https://plan-ex.ru/erpv2/login)"
  health_rc=$?
  set -e
  if [ "$health_rc" -ne 0 ]; then health_http=000; fi
fi
printf 'P07_HEALTH_HTTP=%s\n' "$health_http"
if [ "$health_http" != 200 ]; then
  echo "ERPv2 post-swap health check failed: HTTP $health_http" >&2
  exit 70
fi

marker_tmp="$MARKER.tmp.$$"
printf '%s\n' "$source_sha" > "$marker_tmp"
mv "$marker_tmp" "$MARKER"
committed=1
rm -rf "$work"
trap - EXIT INT TERM
printf 'P07_DEPLOY_OK commit=%s backup=%s http=%s\n' "$source_sha" "$backup" "$health_http"
