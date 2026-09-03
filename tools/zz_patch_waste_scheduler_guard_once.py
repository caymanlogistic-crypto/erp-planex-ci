from pathlib import Path

ci = Path('.github/workflows/ci.yml')
text = ci.read_text(encoding='utf-8')
start = text.index('      - name: Control-plane guard\n')
end = text.index('\n      - name: Canonical manual deploy policy', start)
block = '''      - name: Control-plane guard
        shell: bash
        run: |
          set -euo pipefail
          python3 - <<'PY'
          from pathlib import Path
          deploy_engine = 'P07_' + 'deploy_erpv2.sh'
          violations = []
          production_write = []
          approved_automatic = {'waste_trips_sync_schedule.yml'}
          for p in Path('.github/workflows').glob('*.y*ml'):
              if p.name == 'ci.yml': continue
              text = p.read_text(encoding='utf-8')
              mutating = any(token in text for token in [deploy_engine,'public_html/erpv2','deploy_backups','git push origin'])
              if not mutating: continue
              production_write.append(str(p))
              manual = '\\n  workflow_dispatch:' in text
              automatic = any(trigger in text for trigger in ['\\n  workflow_run:','\\n  push:','\\n  pull_request:','\\n  schedule:'])
              if automatic and p.name in approved_automatic:
                  required = [
                      "cron: '*/5 * * * *'",
                      'workflow_dispatch:',
                      'contents: read',
                      'group: waste-trips-legacy-sync-production',
                      'cancel-in-progress: false',
                      'CANONICAL_BRANCH: chatgpt/production-stabilization-20260802',
                      'P07_erpv2_deployed_commit.txt',
                      'test "$canonical" = "$SOURCE_SHA"',
                      'test "$marker" = "$SOURCE_SHA"',
                      'scripts/waste_trip_sync.php',
                      "grep -Fqx 'WASTE_TRIP_SYNC_OK' waste_trip_sync_schedule.txt",
                  ]
                  forbidden = ['\\n  workflow_run:', '\\n  push:', '\\n  pull_request:', 'Slitex2DB.php', '/fregat/', 'crontab ']
                  if (not manual or any(token not in text for token in required)
                          or any(token in text for token in forbidden)
                          or text.count("cron: '*/5 * * * *'") != 1):
                      violations.append(str(p))
                  continue
              if not manual or automatic:
                  violations.append(str(p))
          print('production-write workflows=', production_write)
          print('approved automatic production-write workflows=', sorted(approved_automatic))
          print('automatic production-write violations=', violations)
          raise SystemExit(1 if violations else 0)
          PY
'''
ci.write_text(text[:start] + block + text[end:], encoding='utf-8')
Path('.github/workflows/zz_patch_waste_scheduler_guard_once.yml').unlink(missing_ok=True)
Path(__file__).unlink()
