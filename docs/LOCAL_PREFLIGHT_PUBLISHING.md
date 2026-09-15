# Локальный preflight и штатная публикация: инструкция агенту

## Назначение и ограничения

Выполняйте разработку и все pre-push тесты в Windows/локальной WSL. После локального PASS используйте сервер только для переноса кандидата, проверки подписанного подтверждения и guarded push. GitHub CI, merge, semantic memory и применимый guarded deploy остаются обязательными. Production migration требует отдельного разрешения владельца.

Не запускайте серверный `preflight-task TASK CLASS` для локальной задачи. Не создавайте `.preflight.env` вручную и не копируйте старое подтверждение на новый SHA. Прямой push в обход `push-task` не нужен.

Для уведомлений MAX событие охватывает «Добавление» и «Исключение». Управление — внутри «MAX — интеграция» → «События». Это отдельный прикладной результат; успешный preflight сам по себе не означает выкладку или включение события.

## Как устроено подтверждение

`tools/runner/local_preflight.py run` запускает настоящий `tools/agent_preflight.py` на точном коммите. Он требует чистую task-ветку, полный локальный PASS без REMOTE_REQUIRED и неизменность checkout после тестов. Затем создаёт три файла:

- `receipt.json`: репозиторий, task ID, ветка, base/head/tree, blob preflight, класс, список проверок, время и SHA256 журнала;
- `preflight.log`: полный результат локального запуска;
- `receipt.sig`: RSA/SHA256 подпись receipt локальным приватным ключом.

Серверный import проверяет подпись заранее доверенным публичным ключом, журнал, актуальный план проверок, protected task metadata и checkout. Срок подтверждения — 24 часа от начала прогона. После проверки он архивирует доказательства и атомарно формирует совместимый `.preflight.env`. Обновлённый `push-task` повторно проверяет архив непосредственно перед отправкой и отправляет точный проверенный SHA.

Это аттестация доверенной локальной машины: подпись защищает перенос и происхождение данных, но не является sandbox или доказательством честности владельца приватного ключа. Приватный ключ не передаётся на сервер и не хранится в Git.

## 1. Подготовка

1. Прочитайте применимые AGENTS.md, LOCAL-DEVELOPMENT.md и START HERE. Для продолжения используйте существующую task-ветку и START_HEAD.
2. Завершите реализацию, необходимые тесты и двухфазные коммиты implementation → repository memory. Подписывайте финальный SHA, включая memory-коммит.
3. Для Linux-контрактов используйте локальную WSL. Проверки БД выполняйте в изолированной локальной БД; не расширяйте grants fixed slots.
4. Убедитесь, что `git rev-parse HEAD` одинаков в Windows и WSL. Для рабочей копии Windows допустима относительная локальная ссылка `.git`, например `gitdir: ../../source/.git/worktrees/TASK`. Не меняйте tracked source ради пути среды.
5. Установите Python 3.9+, Git, OpenSSL и необходимые runtimes/tests. Результат старого прогона нельзя подписать задним числом: producer сам запускает preflight.

## 2. Ключ машины — один раз

Создайте отдельный ключ в защищённой локальной папке вне репозитория. Пример для WSL:

```bash
umask 077
mkdir -p "$HOME/.config/planex-local-preflight"
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 \
  -out "$HOME/.config/planex-local-preflight/signing.pem"
openssl pkey -in "$HOME/.config/planex-local-preflight/signing.pem" -pubout \
  -out "$HOME/.config/planex-local-preflight/public.pem"
sha256sum "$HOME/.config/planex-local-preflight/public.pem"
```

После локального PASS при настройке публикации передайте **только public.pem** через разрешённый транспорт. Сравните SHA256 с локальным значением и зарегистрируйте его под стабильным key ID:
`~/erp-planex-test/state/local-preflight-trust/<key-id>.pem`, каталог 0700, файл 0600, владелец — runner user.
Присланный receipt не имеет права самостоятельно добавлять свой ключ в доверенные. Замена ключа — отдельная операция настройки доверия с проверкой fingerprint.

## 3. Подписанный локальный прогон

В локальной среде с уже настроенными тестовыми DB-параметрами:

```bash
python3 tools/runner/helpers/preflight-task --local run TASK_ID CRITICAL \
  --worktree "$PWD" \
  --base EXACT_40_HEX_START_HEAD \
  --output /absolute/local/evidence/NEW_DIRECTORY \
  --key "$HOME/.config/planex-local-preflight/signing.pem" \
  --key-id KEY_ID
```

Выберите фактический класс задачи, не понижайте его ради прохождения. Каталог output должен быть новым и находиться вне checkout. DB credentials передавайте окружением через локальный wrapper, не аргументами команд и не в receipt.

Успех: exit 0 и `LOCAL_SIGNED_PREFLIGHT_PASS=<точный SHA>`. Если тесты изменили checkout или остался незакоммиченный файл, подтверждение не выдаётся. Служебный Python bytecode направляется во временную папку вне checkout.

## 4. Перенос и import — после локального PASS

1. Перенесите Git-кандидат обычным task-scoped bundle/transport без checkout в canonical и без принудительного сброса чужих изменений.
2. Серверная task metadata должна соответствовать TASK_ID, ветке и START_HEAD; task checkout должен быть чистым и иметь SHA receipt.
3. Перенесите три файла evidence в `~/erp-planex-test/state/local-preflight-inbox/TASK_ID/<head>/`.
4. Выполните:

```bash
~/erp-planex-test/bin/preflight-task --local import TASK_ID \
  ~/erp-planex-test/state/local-preflight-inbox/TASK_ID/EXACT_HEAD
~/erp-planex-test/bin/push-task TASK_ID
```

Import выполняет только криптографическую проверку, сверку Git и вычисление плана по changed paths. Он не запускает application tests или DB capability probes на сервере. Успех import: `LOCAL_PREFLIGHT_IMPORTED=<SHA>`.

Далее дождитесь полного CI aggregate на этом SHA, выполните guarded merge, обновите затронутую Notion-память и применимый deploy. Не сообщайте владельцу «задеплоено» до подтверждённого deploy.

## 5. Первый переход со старого helper

Старая версия `2026-09-09.1` не понимает `--local`. Обновление постоянных helpers допускается только из merged canonical; изменять `bin` с task-ветки нельзя.

Для первоначального перехода, санкционированного владельцем как доработка локальной публикации:

1. Полностью проверьте новый механизм локально, включая `tests/local_preflight_evidence_test.py`, и получите подписанный PASS финального кандидата.
2. Передайте ровно этот кандидат и evidence в его существующий серверный task checkout. Проверьте SHA checkout, SHA256 `tools/runner/local_preflight.py` и pinned hash в `helpers/preflight-task` по проверенному локальному кандидату.
3. Выполните **task-scoped** `python3 tools/runner/helpers/preflight-task --local import TASK_ID EVIDENCE_DIR` из этого checkout. Это реальный подписанный import, не ручная генерация PASS.
4. Немедленно выполните установленный `~/erp-planex-test/bin/push-task TASK_ID`. Старый helper проверяет совместимые task/base/branch/head/result поля; новый importer уже проверил подпись, срок и полный журнал. Если checkout изменился — остановитесь.
5. После обязательных CI/merge синхронизируйте persistent canonical checkout и выполните штатный `python3 tools/runner/sync_runner_helpers.py install`, затем `verify-live`. Никаких копирований task-helper непосредственно в `bin`.
6. Дальнейшие задачи используют обычный путь из раздела 4, включая повторную проверку подписи перед push.

## Отказы и восстановление

| Отказ | Действие |
|---|---|
| `DIRTY_WORKTREE` / `WORKTREE_CHANGED_DURING_RUN` | Разберите изменения, завершите commit и заново запустите локальный producer. |
| `FULL_LOCAL_PASS_REQUIRED` / `LOCAL_RUN_FAILED` | Исправьте локальную причину; удалённый запуск не заменяет проверку. |
| `INVALID_SIGNATURE` / неизвестный key ID | Проверьте передачу и регистрацию ключа; не доверяйте ключу из самого receipt. |
| `LOG_DIGEST_MISMATCH` / `CHECK_PLAN_MISMATCH` | Передайте исходный комплект файлов либо выполните новый локальный прогон. |
| `CANDIDATE_MISMATCH` / `BASE_MISMATCH` | Сверьте task metadata, ветку и SHA. Не исправляйте receipt руками. |
| `EXPIRED_OR_INVALID_TIME` | Проверьте часы и выполните новый локальный прогон. |
| `LOCAL_EVIDENCE_MODULE_HASH_MISMATCH` | Синхронизируйте canonical source и установленные helpers штатным installer. |
| `CANONICAL_DRIFT` | Обновите task base по принятому workflow, разрешите конфликты и повторите локальные проверки. |
| Нет места / WSL read-only | Освободите место, восстановите локальную среду и повторите незавершённые проверки. |

Для новой задачи не используйте SHA и журналы MAX как собственное доказательство. Сохраняйте свой task ID, base/head, команды, exit codes, evidence и результат публикации.
