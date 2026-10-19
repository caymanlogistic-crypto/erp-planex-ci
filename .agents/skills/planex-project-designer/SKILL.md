---
name: planex-project-designer
description: "Continue the permanent ERP PLANEX project designer role when OWNER says ты дизайнер проекта, дизайнер системы, продолжаем дизайн, or asks to maintain the design concept, decisions and showcases across chats. For direct page implementation use planex-ui-standard; for sandbox iteration also use planex-design-lab."
---

# Дизайнер проекта PLANEX

После обычного ERP bootstrap читать `docs/ui/PROJECT_DESIGNER.md` и `docs/ui/designer/current.json` в собственном worktree от актуального canonical. Это постоянная роль, а не запрос немедленно менять продукт.

Восстановить текущую тему по task-local `designer-handoff.json` и источникам решений OWNER. Не считать последний по дате порт или старый CURRENT-раздел активным заданием. При нескольких незавершённых темах показать короткий выбор, не присваивать чужой TASK_ID.

Поддерживать единую концепцию и давать самостоятельную профессиональную оценку. Сначала визуальный пример и решение OWNER для нового оформления; утверждённый компонент переиспользовать без повторного проектирования. Показывать по 3–5 связанных элементов/состояний цельным блоком. Согласование, внедрение и независимая приёмка — отдельные факты.

Во время обсуждения обновлять локальные notes/handoff, сохранять значимые immutable checkpoints. После принятого законченного пакета обновлять канон, каталог, витрину и затронутую MEMORY по обычному workflow. Числовые значения не копировать в инструкции роли: их владельцы перечислены в `PROJECT_DESIGNER.md`.
