#!/usr/bin/env bash
set +e
for p in /home/s/spugovxsim/planexp/logs /home/s/spugovxsim/planexp/public_html/erpv2/logs /home/s/spugovxsim/logs; do
  if [ -d "$p" ]; then
    find "$p" -maxdepth 2 -type f -mmin -10 -print 2>/dev/null | head -n 12 | while IFS= read -r f; do
      echo "LOG=$f"
      tail -n 240 "$f" 2>/dev/null | grep -Eai 'fatal|uncaught|exception|typeerror|pdo|clients|roles|access|navigationcounter|migration' | tail -n 100 || true
    done
  fi
done
log_path="$(/usr/bin/php8.5 -r 'echo ini_get("error_log");' 2>/dev/null)"
if [ -n "$log_path" ] && [ "$log_path" != syslog ] && [ -r "$log_path" ]; then
  echo "PHP_ERROR_LOG=$log_path"
  tail -n 300 "$log_path" 2>/dev/null | grep -Eai 'fatal|uncaught|exception|typeerror|pdo|clients|roles|access|navigationcounter|migration' | tail -n 120 || true
fi
