# AGENT 01 TASK — Discovery / Architecture Contract

## Цель
Провести read-only discovery текущей реализации ERP PLANEX перед изменением auth/access/schema и сформировать архитектурный контракт для остальных потоков.

## Контекст
Source of truth: `../00_IMPLEMENTATION_CONTRACT.md`.
Особенно разделы 3–4, 17–32, 35–60, 67, 87–96, 97–99.

## START_HEAD / baseline
`8b1b4a027eadbe41626f28fcf3c12db338a31b0a`

## Разрешённый scope
Read-only анализ релевантных:
- auth/session/controllers;
- role/access services;
- routes/menu/layout;
- Client/Contractor/Vehicle/Driver/Linear/Waste canonical entities;
- finance gates;
- ownership/grants;
- migrations/tests.

## Запрещённый scope
Любые code/schema/DB/production mutations; `/erp`; broad audit вне задачи.

## Обязательный результат
Однозначно определить:
- identity/membership model;
- hard-coded role checks;
- page/menu/endpoint модель;
- canonical entities;
- Vehicle technical mapping;
- existing assignments/ownership;
- finance gates;
- session dependencies;
- migration risks;
- безопасные ownership seams для потоков 02–07.

## Обязательные тесты
Read-only evidence inspection; никаких runtime mutations.

## Критерии готовности
Discovery достаточен для фиксации `OWNERSHIP.md` и `DECISIONS.md`; Vehicle mapping доказан до schema changes.

## Формат отчёта
`../reports/agent-01.md` по общему REPORT contract.

## Что агент НЕ должен делать
Не расширять ТЗ; не писать код; не менять production; не трогать `/erp`; не создавать параллельные ACL/entity mechanisms; не принимать продуктовые решения вместо Tech Lead.