# Происхождение иконок заголовков реестров

Для каждого нового или изменённого реестра `approved-components.json` требует `header_icon` с ролью `registry.page-head`, пакетом `@tabler/icons`, версией `3.48.0`, вариантом `outline`, именем и точным путём `app/View/components/tabler/outline/<name>.svg`. Каталог сверяет исходный SVG с закреплённым перечнем SHA-256 всех 5166 официальных контурных иконок в `tabler-outline-3.48.0.json` (SHA-256 перечня `effa1ed6d92b1d4fddec70099375fb0105fcd2e9741860b10c26315594e2a079`). Новые файлы извлекаются из официального архива пакета; произвольная геометрия и метаданные иного пака отклоняются.

Общий `assertRegistryVisualContract` сравнивает отрисованную геометрию с точным именованным исходником, размер, цвет и обводку. `registry_visual_gate.mjs` запускает зарегистрированные браузерные тесты и сверяет фактически выполненные ID страниц, поэтому путь к чужому тесту не даёт покрытие новой странице. Gate обязателен в LOCAL preflight и обоих CI маршрутах.

**Исключений по происхождению иконок больше нет.** OWNER 19.10.2026 поручил закрыть пять прежних исключений. Все зарегистрированные шапки используют именованные SVG через общий renderer. Каталог и общий browser gate требуют provenance для каждого реестра, включая прежде исторические ID; витрина проверяет те же исходники в каждой из четырёх сцен. Исторический долг runtime fixtures — отдельная тема и не освобождает иконки от проверки.

Все зарегистрированные страницы используют именованные SVG из официального пакета [`@tabler/icons` 3.48.0](https://github.com/tabler/tabler-icons/tree/v3.48.0/icons/outline). Исходники находятся в `app/View/components/tabler/outline/`, уведомление MIT — в `app/View/components/tabler/LICENSE.txt`. Общий вывод выполняет `app/View/components/registry_tabler_icons.php`; геометрия SVG сохраняется, к корню добавляются только класс утверждённого размера и атрибуты доступности.

| Страница | Именованный исходник | SHA-256 исходного SVG |
| --- | --- | --- |
| `/erpv2/company/route-executors` | `outline/users-group.svg` | `257522e6da5bc1e67efda5de77595340fdfea780d6bfb8d5f8d594687a3e623c` |
| `/erpv2/company/trips/departures` | `outline/truck-delivery.svg` | `a774d0b45ef54c002a9ff3fb35510ed824b61c26c197d564dabb8862ca3412a5` |
| `/erpv2/company/finance/received-paid` | `outline/arrows-exchange.svg` | `7d1a55056bf11ae753ba7400ac986c4c7fcdc23ffc7a3192da41654d77ecc357` |
| `/erpv2/company/logistics/closing-documents` | `outline/file-text.svg` | `12e8c1a05fe3c14fb9f44e98145afa96c14ee0d9dddfdd69b75c53c31486cae8` |
| `/erpv2/company/drivers` | `outline/user.svg` | `89eb991dd629ba6ad556608bf70528de1e1ca7801a3c9d5566d4f1506e4404c6` |
| `/erpv2/company/contractors` | `outline/truck-delivery.svg` | `a774d0b45ef54c002a9ff3fb35510ed824b61c26c197d564dabb8862ca3412a5` |
| `/erpv2/company/vehicle-sets` | `outline/truck.svg` | `e00548eaa071c83635175ba38510b352834672364ef62c00fd5c1de2ec21d81e` |
| `/erpv2/company/feo/requests/map-placement` | `outline/map-pin.svg` | `4ca0c252b79ea1dd51d6ad73e4187427a32db8b5d8b5225b40c3b5e132c4824c` |

Свойства в шапке: 30 × 30 px, обводка 2, цвет `#7C4718`. `tests/helpers/registry_tabler_icon_contract.mjs` сравнивает имя, хеш файла, полную SVG-геометрию и вычисленные свойства в браузере. Контракт выполняется для каждой зарегистрированной шапки и четырёх сцен витрины на ширинах 390/1280/1920/3840 px. Негативная проверка заменяет путь SVG произвольной геометрией, сохраняя класс Tabler, и требует отказа контракта. Предметные runtime browser tests проверяют страницы через реальные маршруты, контроллеры и сервисы с допустимыми и ограниченными правами и неизменностью бизнес-таблиц.

Пакет: `@tabler/icons@3.48.0`; архив, использованный для извлечения SVG, имеет SHA-256 `28447DCF6F0BB2B8D92C59A1B3D30900A180DE2E97D7DB4D267E6320DC68F449`.
