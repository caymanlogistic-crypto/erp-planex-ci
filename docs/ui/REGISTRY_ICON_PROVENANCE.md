# Иконки заголовков реестров перевозчиков и транспорта

Обе страницы используют именованные SVG из официального пакета [`@tabler/icons` 3.48.0](https://github.com/tabler/tabler-icons/tree/v3.48.0/icons/outline). Исходники находятся в `app/View/components/tabler/outline/`, уведомление MIT — в `app/View/components/tabler/LICENSE.txt`. Общий вывод выполняет `app/View/components/registry_tabler_icons.php`; геометрия SVG сохраняется, к корню добавляются только класс утверждённого размера и атрибуты доступности.

| Страница | Именованный исходник | SHA-256 исходного SVG |
| --- | --- | --- |
| `/erpv2/company/contractors` | `outline/truck-delivery.svg` | `a774d0b45ef54c002a9ff3fb35510ed824b61c26c197d564dabb8862ca3412a5` |
| `/erpv2/company/vehicle-sets` | `outline/truck.svg` | `e00548eaa071c83635175ba38510b352834672364ef62c00fd5c1de2ec21d81e` |

Свойства в шапке: 30 × 30 px, обводка 2, цвет `#7C4718`. `tests/helpers/registry_tabler_icon_contract.mjs` сравнивает имя, хеш файла, полную SVG-геометрию и вычисленные свойства в браузере. Контракт выполняется для обеих страниц на ширинах 390/1280/1920/3840 px. Негативная проверка заменяет путь SVG произвольной геометрией, сохраняя класс Tabler, и требует отказа контракта. Отдельный `tests/company_registry_runtime_browser_test.mjs` проверяет страницы через реальные маршруты, контроллеры и сервисы для OWNER и ограниченного пользователя, действия, права и неизменность бизнес-таблиц.

Пакет: `@tabler/icons@3.48.0`; архив, использованный для извлечения SVG, имеет SHA-256 `28447DCF6F0BB2B8D92C59A1B3D30900A180DE2E97D7DB4D267E6320DC68F449`.
