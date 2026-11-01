# ERP PLANEX — финальный архив для внешней ревизии

> Исторический архив. Не используйте описанные здесь доступы и команды для текущей ERP; действующий порядок работы указан в `AGENTS.md` и `C:\plan-ex\ERP-PLANEX\LOCAL-DEVELOPMENT.md`.

## Назначение

Финальный handoff-архив ERP PLANEX. Передан на внешнюю ревью архитектуры, кода и документации.

## Распаковка

Распаковать в любую папку, например:

```
C:\Projects\erp-planex-review
```

## Состав архива

| Путь | Назначение |
|------|-----------|
| `public/` | Точка входа, CSS, JS |
| `bootstrap/` | Загрузчик приложения |
| `config/` | Конфигурация |
| `app/` | Исходный код (Controllers, Services, Routes, Views, Core) |
| `database/` | Миграции (центральные + локальные для компаний) |
| `docs/` | Архитектурная и проектная документация |
| `tools/` | Architecture guard, утилиты |
| `.env.example` | Шаблон конфигурации (скопировать → `.env`) |

## Что исключено из архива

- `.git/` — история версий
- `.env` — локальные credentials
- `.kilo/node_modules/` — зависимости агентов (3458 файлов)
- `vendor/`, `node_modules/` — пакетные зависимости
- `storage/` — пользовательские документы (требуют БД). Из архива исключается, но в рабочей среде НЕ удалять — иначе документы в БД станут осиротевшими, просмотр/скачивание будут возвращать 404.
- `logs/` — временные логи
- `tmp/`, `*.log`, backup `*.sql`, `*.zip`, `*.gz`

## Запуск

```bash
cp .env.example .env
# настроить .env: БД, пароли
php -S localhost:8017 -t public public/index.php
```

## Что читать первым

| Файл | Назначение |
|------|-----------|
| `docs/ai/HANDOFF_FOR_NEW_CHAT.md` | Главный контекст проекта |
| `docs/ai/FINAL_HANDOFF_PACKAGE_REPORT.md` | Отчёт о состоянии проекта и проверках |
| `docs/ai/ROUTE_MAP_AFTER_E7.md` | Карта маршрутов |
| `docs/ai/MODULAR_DEVELOPMENT_RULES.md` | Правила разработки |
| `tools/architecture_guard.php` | Автоматическая проверка архитектуры |

## Роли для входа (runtime)

| Роль | Логин | Пароль |
|------|-------|--------|
| Owner | `owner_test_runtime` | `[удалено]` |
| Senior Logist | `senior_runtime_1` | `senior1234` |
| Logist | `logist_runtime_1` | `[удалено]` |
| Superadmin | `admin@planex.local` | `[удалено]` |
