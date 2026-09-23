# PLANEX DESIGN LAB — локальная песочница

DESIGN LAB ускоряет последовательные правки внешнего вида и поведения одной страницы. Агент создаёт отдельную локальную копию, OWNER смотрит её в браузере, изменения делаются по одной и сохраняются версиями. Только после команды `ВНЕДРЯЙ` выбранный вариант переносится в ERP и проходит один обычный цикл публикации.

## Границы безопасности

- Сервер слушает только loopback (`127.0.0.1` или `::1`) на динамическом task-local порту.
- HTTP-методы записи запрещены, формы и уход со страницы блокируются, внешние подключения запрещены CSP.
- Сессия хранится вне Git: `C:\plan-ex\ERP-PLANEX\local\design-lab\<TASK_ID>`.
- По умолчанию используются синтетические fixture-данные. Режим `snapshot` принимает только локальный HTML-файл, удаляет `<base>`, редактирует CSRF и отклоняет известные секретные маркеры.
- Прямое подключение к production DB/API отсутствует. Production DB, schema и business-data writes требуют отдельного разрешения OWNER и не выполняются через DESIGN LAB.
- Один агент владеет только своим TASK_ID, сессией, портом и процессом. Остановка разрешена только после проверки PID, времени запуска, executable, arguments, worktree и session.

## Быстрый цикл

```powershell
# создать или продолжить песочницу статистики менеджеров
& C:\plan-ex\ERP-PLANEX\local\New-DesignLab.ps1 `
  -TaskId manager-stats-design `
  -Worktree C:\plan-ex\ERP-PLANEX\worktrees\<task-worktree> `
  -Preset manager-statistics

# запустить и получить URL
& C:\plan-ex\ERP-PLANEX\local\Start-DesignLab.ps1 -TaskId manager-stats-design

# сохранить значимый вариант
& C:\plan-ex\ERP-PLANEX\local\Save-DesignLabVersion.ps1 `
  -TaskId manager-stats-design -Note 'Компактная верхняя панель'

# зафиксировать выбранный OWNER вариант перед «ВНЕДРЯЙ»
& C:\plan-ex\ERP-PLANEX\local\Approve-DesignLabVersion.ps1 `
  -TaskId manager-stats-design -Version v001

# остановить только свой сервер
& C:\plan-ex\ERP-PLANEX\local\Stop-DesignLab.ps1 -TaskId manager-stats-design
```

Рабочие файлы одной сессии:

- `workspace/overrides.css` — визуальные изменения;
- `workspace/overrides.js` — локальное интерактивное поведение;
- `workspace/notes.md` — решения и требования;
- `versions/vNNN/` — неизменяемые контрольные точки;
- `acceptance.json` — точный вариант, принятый командой `ВНЕДРЯЙ`.

Изменение уже сохранённой версии не допускается: следующая итерация получает новый номер. Песочница не является доказательством корректности ERP — после принятия агент переносит результат в реальные PHP/CSS/JS, запускает релевантные тесты, MEMORY drift check и один итоговый signed local preflight.

## Добавление нового preset

Preset добавляется в `PRESETS` файла `tools/design_lab.py` и обязан иметь детерминированный test renderer, route и список исходных файлов. Он не должен читать production-секреты или выполнять сетевые запросы. Изменение обязательно покрывается `tests/design_lab_contract_test.py`.
