# Реестр вложений: диагностика только на чтение

`registry_document_inventory_readonly_diagnostic.yml` — отдельный control-plane инструмент для OWNER-authorized расследования утраченных вложений. Он не исправляет приложение, не восстанавливает документы и не запускает миграции. Application deploy не требуется.

## Запуск

После точного LOCAL PASS, PR/CI/merge запускать только canonical ref. Передать `company_scope=discover`, ранее проверенный production marker SHA и публичный X509 recipient certificate (base64 PEM и SHA256 PEM). Private key остаётся на Windows в task-owned защищённом хранилище; не передавать его в Actions. Discovery возвращает только список company ID/name/status с выделенной БД. Затем явно выбрать нужные ID; не угадывать tenant scope по тестовым fixtures. Для нескольких компаний перечислить все ID через запятую. Проектная полнота требует явно перечисленного scope.

Поток JSONL с сервера сразу шифруется CMS AES256 на runner, plaintext артефакта/лога нет. Скачать `inventory.cms`, расшифровать локально, проверить `start`, `end.completed`, каждый manifest/table/page/count/hash. Retention encrypted artifact — один день. Не публиковать персональные metadata в PR/Notion/логи. В общую память записывать только scope, counts, verdict и закрытые evidence references.

## Доказательства и ограничения

- Tenant SELECT в одном InnoDB REPEATABLE READ consistent read-only snapshot, включая soft-deleted cards/documents; per-table keyset с явной пустой конечной страницей и COUNT reconciliation. Отсутствующая обязательная таблица/поле, JSON error, лимит или timeout означают INCOMPLETE.
- Все document entity types учитываются в counts; полные metadata и bytes inspection только contractor/driver/vehicle_unit/vehicle_set. Иные типы не входят в расследование этих справочников.
- Сохраняются порядок и повторные source occurrences, реальные document IDs, selected entity IDs и типовые document controls. Для проверки исторической связи также экспортируются только `name` формы исполнителя, `full_name` формы водителя и primary/secondary `plate_number` формы ТС. Значения из других форм, паспортные номера/телефоны не экспортируются. Историческая идентичность может отличаться от текущей после исправления карточки; это не разрешает менять карточку или ослаблять source/reference SHA и привязку к entity ID.
- Source и destination читаются только внутри exact company/card/entity storage prefix. Traversal/symlink/необычные пути не читаются. Stable size/hash наблюдается на открытом файле с повторными stat checks; БД и filesystem не составляют атомарного общего snapshot. Это forensic observation, не гарантия отсутствия внешнего конкурентного изменения.
- Код не доказывает новую entity связь по одному ФИО/времени. `selected_entities.vehicle` — vehicle_set ID, не vehicle_unit ID; текущая связь с unit может отличаться от исторической. Использовать surviving hashes/структурные связи, сохранять ambiguity и multiset. Inherited library docs не считать новыми uploads.
- Текущая сохранённая история не содержит ранее перезаписанных files_json. Исчезнувшие historical versions требуют OWNER/mail/backup copies. Коллизия PHP slot — риск/условное shadowing, не доказательство физического удаления. Raw technical label — metadata defect signal отдельно от недостающего файла.

Сначала OWNER получает список для сверки: владелец/ID, документ, original_name, source/destination evidence, причина и уверенность, сохранившаяся копия, неизвестные поля. Actual business-data/file restoration требует отдельного поручения; этот workflow такого действия не имеет.

## Локальная проверка

`local/Invoke-LocalTests.ps1 -TaskId <task> -Worktree <worktree> -Tests @('tests/document_inventory_readonly_mysql_test.php')` проверяет реальный disposable MySQL read-only запрет UPDATE, pagination, soft-deleted карточку с двумя source файлами, hashes, missing bytes, privacy projection, containment и отсутствие обязательного schema field. Не использовать production для разработки/проб теста.
