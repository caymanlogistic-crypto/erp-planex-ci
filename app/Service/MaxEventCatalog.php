<?php

namespace App\Service;

use RuntimeException;

require_once __DIR__ . '/MaxFeoMessageTemplates.php';

/** Registered ERP events: adding an event does not require a new settings screen. */
final class MaxEventCatalog
{
    private const LEGACY_FEO_TEMPLATE = "Создан блок ФЭО №{{block_id}}: {{block_name}}\nТип: {{block_type}}\nЗаявок: {{request_count}}, масса: {{mass_kg}} кг\nОтветственный: {{author}}\nКомпания: {{company}}\n{{created_at}}";
    private const FEO_TEMPLATE = "{{action_icon}} **{{action_title}}**\n**КОЛИЧЕСТВО:** {{count_label}}\n**ОБЩАЯ МАССА:** {{mass_kg}} кг\n{{request_ids}}";
    private const LEGACY_TRANSFER_CREATED_TEMPLATE = "Создан пакет переноса №{{batch_id}}: {{batch_name}}\nЗаявок: {{request_count}}\nАдрес: {{address}}\nКачество координат: {{quality}}\nАвтор: {{author}}\n{{company}} · {{created_at}}\n{{comment}}";
    private const LEGACY_TRANSFER_REVERTED_TEMPLATE = "Возвращён пакет переноса №{{batch_id}}: {{batch_name}}\nЗаявок: {{request_count}}\nАдрес: {{address}}\nКачество координат: {{quality}}\nАвтор: {{author}}\n{{company}} · {{created_at}}\n{{comment}}";
    private const PREVIOUS_TRANSFER_CREATED_TEMPLATE = "Создан пакет перемещения №{{batch_id}}: {{batch_name}}\nЗаявок: {{request_count}}\nНомера заявок: {{request_ids}}\nМесто назначения: {{address}}\nКачество координат: {{quality}}\nИзменения:\n{{changes}}\nАвтор: {{author}}\n{{company}} · {{created_at}}\n{{comment}}";
    private const PREVIOUS_TRANSFER_REVERTED_TEMPLATE = "Возвращён пакет перемещения №{{batch_id}}: {{batch_name}}\nЗаявок: {{request_count}}\nНомера заявок: {{request_ids}}\nМесто назначения: {{address}}\nКачество координат: {{quality}}\nИзменения:\n{{changes}}\nАвтор: {{author}}\n{{company}} · {{created_at}}\n{{comment}}";
    private const CURRENT_TRANSFER_CREATED_TEMPLATE = "Создан блок перемещения №{{batch_id}}: {{batch_name}}\nЗаявок: {{request_count}}\nНомера заявок: {{request_ids}}\nМесто назначения: {{address}}\nКачество координат: {{quality}}\nИзменения:\n{{changes}}\nАвтор: {{author}}\n{{company}} · {{created_at}}\n{{comment}}";
    private const CURRENT_TRANSFER_REVERTED_TEMPLATE = "Возвращён блок перемещения №{{batch_id}}: {{batch_name}}\nЗаявок: {{request_count}}\nНомера заявок: {{request_ids}}\nМесто назначения: {{address}}\nКачество координат: {{quality}}\nИзменения:\n{{changes}}\nАвтор: {{author}}\n{{company}} · {{created_at}}\n{{comment}}";

    public static function all(): array
    {
        return [
            'feo.placement.block_created' => [
                'title' => 'ФЭО — создан блок размещения',
                'description' => 'После сохранения нового блока «Добавление» или «Исключение». Системное обязательное сообщение.',
                'system_required' => true,
                'template_editable' => true,
                'format_editable' => false,
                'template' => self::FEO_TEMPLATE,
                'feo_template_definitions' => MaxFeoMessageTemplates::definitions(),
                'feo_templates' => MaxFeoMessageTemplates::defaults(),
                'variables' => [
                    'action_title' => ['Заголовок действия', 'ДОБАВЛЕНО НА КАРТУ'],
                    'action_icon' => ['Знак действия', '✅'],
                    'request_word' => ['Склонение количества', 'заявок'],
                    'count_label' => ['Количество заявок', '12 заявок'],
                    'block_id' => ['Номер блока', '42'],
                    'block_name' => ['Название блока', 'Размещение 15 сентября'],
                    'block_type' => ['Тип блока', 'Добавление'],
                    'request_count' => ['Число заявок в блоке', '12'],
                    'request_ids' => ['Номера заявок блока', '123456, 123457, 456789'],
                    'mass_kg' => ['Масса блока, кг', '25 400'],
                    'author' => ['Ответственный', 'Владимир С.'],
                    'company' => ['Название компании', 'Пример компании'],
                    'created_at' => ['Дата и время создания', '15.09.2026 15:30'],
                    'current_count' => ['Заявок в актуальном составе', '681'],
                    'current_mass_kg' => ['Масса актуального состава, кг', '100 000,000'],
                ],
            ],
        ] + self::transferEvents() + self::weightEvents() + self::documentEvents() + self::routeExecutorEvents();
    }

    private static function routeExecutorEvents(): array
    {
        $variables = [
            'trip'=>['Номер и наименование рейса','#123 Перевозка отходов'],
            'vehicle_driver'=>['Госномер и фамилия водителя','А123ВС77 (Петров)'],
            'responsible'=>['Имя логиста','Карина'],
            'missing'=>['Недостающие данные по группам',"**• Водитель:** СНИЛС\n**• Файлы:** Паспорт водителя"],
        ];
        return [
            'route_executor.partial'=>[
                'title'=>'Исполнитель рейса — первичные данные',
                'description'=>'После первой передачи неполных данных исполнителя рейса. Получатели письма задаются в «Ключи и сервисы».',
                'template'=>"🚚 Внесены частичные данные\n**{{trip}}**\n{{vehicle_driver}}\n\n**Не хватает для полного комплекта**\n{{missing}}\nЛогист: {{responsible}}",
                'variables'=>$variables,
            ],
            'route_executor.full'=>[
                'title'=>'Исполнитель рейса — полный комплект',
                'description'=>'После передачи полного комплекта данных исполнителя рейса.',
                'template'=>"🚛 ПОЛНЫЙ КОМПЛЕКТ\n**{{trip}}**\n{{vehicle_driver}}\nЛогист: {{responsible}}",
                'variables'=>$variables,
            ],
        ];
    }

    private static function documentEvents(): array
    {
        $variables=['trip'=>['Рейс','123'],'loading_dates'=>['Даты загрузки','21–22.09.2026'],
            'responsible'=>['Ответственный логист','Иванов Иван'],'action'=>['Следующее действие','Проверьте ответ перевозчика'],
            'url'=>['Запрос в ERP','https://plan-ex.ru/erpv2/company/documents/collection/123']];
        $events=[];
        foreach (['requested'=>'Отправлен запрос документов','reply'=>'Получен ответ перевозчика','reminder'=>'Пора проверить документы','mail_error'=>'Ошибка отправки письма','minimum'=>'Минимальный пакет собран','full'=>'Полный пакет собран'] as $key=>$title) {
            $events['documents.'.$key]=['title'=>'Документы — '.$title,
                'description'=>in_array($key,['minimum','full'],true)?'Общее уведомление о пакете документов на рейс.':'Уведомление помощнику логиста. Выберите группу ПЛ.',
                'template'=>$title."\nРейс #{{trip}} · загрузка: {{loading_dates}}\nЛогист: {{responsible}}\n{{action}}\n{{url}}",
                'variables'=>$variables];
        }
        return $events;
    }

    private static function transferEvents(): array
    {
        $variables = [
            'batch_id'=>['Номер блока','18'], 'batch_name'=>['Название блока','Перемещение на склад'],
            'request_count'=>['Число заявок','12'], 'address'=>['Адрес назначения','Москва, улица Примерная, 1'],
            'quality'=>['Качество координат','Только улица, дом не определён'],
            'request_ids'=>['Номера заявок','123456, 123457, 456789'],
            'changes'=>['Изменения места','№123456: Старый адрес → Новый адрес'],
            'author'=>['Автор','Владимир С.'], 'company'=>['Компания','Пример компании'],
            'created_at'=>['Дата и время','18.09.2026 15:30'], 'comment'=>['Комментарий','Изменение места погрузки'],
            'destination_intro'=>['Подпись адреса назначения','Перемещены на новый адрес:'],
            'source_request_id'=>['Заявка с адресом назначения','345356'],
            'returned_requests'=>['Заявки и восстановленные адреса','310500: Липецкая область, Липецк, улица Передельческая, 5Б'],
        ];
        $events=[];
        foreach (['created'=>['ФЭО — создан блок перемещения','Создан блок перемещения'], 'reverted'=>['ФЭО — возвращён блок перемещения','Возвращён блок перемещения']] as $action=>$labels) {
            $events['feo.transfer.'.$action]=['title'=>$labels[0],
                 'description'=>'После успешного '.($action==='created'?'перемещения':'возврата').' всего блока.',
                 'template'=>$action==='created'
                     ? "ПЕРЕМЕЩЕНИЕ ЗАЯВОК\nЗАЯВКИ\n**{{request_ids}}**\n{{destination_intro}}\n**{{address}}**"
                     : "ВОЗВРАТ ЗАЯВОК НА ИЗНАЧАЛЬНЫЕ АДРЕСА\nЗаявки возвращены на изначальные адреса\n{{returned_requests}}",
                'variables'=>$variables];
        }
        return $events;
    }

    private static function weightEvents(): array
    {
        return [
            'feo.request.weight_changed' => [
                'title' => 'ФЭО — изменён вес заявок',
                'description' => 'После фактического изменения массы нетто или брутто существующих заявок вручную, блоком либо через применение, откат, возврат или удаление импорта.',
                'template' => "ФЭО — ИЗМЕНЁН ВЕС ЗАЯВОК\n{{part}}\nИсточник: {{source}}\nЗаявок: {{request_count}}\nНетто: {{old_mass_kg}} → {{new_mass_kg}} кг ({{difference_kg}} кг)\nБрутто: {{old_gross_kg}} → {{new_gross_kg}} кг ({{gross_difference_kg}} кг)\n{{changes}}\nОтветственный: {{author}}\n{{company}} · {{created_at}}",
                'variables' => [
                    'source'=>['Источник изменения','Импорт ФЭО #42'],
                    'part'=>['Часть сообщения','Часть 1 из 2'],
                    'request_count'=>['Число заявок','12'],
                    'old_mass_kg'=>['Общая масса до изменения, кг','25 400'],
                    'new_mass_kg'=>['Общая масса после изменения, кг','26 100'],
                    'difference_kg'=>['Изменение общей массы, кг','+700'],
                    'old_gross_kg'=>['Общая масса брутто до изменения, кг','25 900'],
                    'new_gross_kg'=>['Общая масса брутто после изменения, кг','26 600'],
                    'gross_difference_kg'=>['Изменение общей массы брутто, кг','+700'],
                    'changes'=>['Заявки и изменение веса','№123456: 1 000 → 1 200 кг (+200 кг)'],
                    'author'=>['Ответственный','Владимир С.'],
                    'company'=>['Компания','Пример компании'],
                    'created_at'=>['Дата и время','21.09.2026 15:30'],
                ],
            ],
        ];
    }

    public static function event(string $key): array
    {
        return self::all()[$key] ?? throw new RuntimeException('Неизвестное событие MAX.');
    }

    public static function example(string $key): array
    {
        return array_map(static fn(array $v): string => $v[1], self::event($key)['variables']);
    }

    public static function currentTemplate(string $key, string $stored): string
    {
        if (in_array($key,['route_executor.partial','route_executor.full'],true)) {
            if ($stored==='' || !str_contains($stored,'{{vehicle_driver}}') || str_contains($stored,'{{contractor}}') || str_contains($stored,'{{ready}}')) return self::event($key)['template'];
            return $stored;
        }
        if ($key === 'feo.placement.block_created') {
            if (str_starts_with(ltrim($stored), '{"feo_templates_version"')) return MaxFeoMessageTemplates::decode($stored)['main'];
            if ($stored === '' || $stored === self::LEGACY_FEO_TEMPLATE
                || $stored === "{{action_title}}:\n\nЗаявок: {{request_count}}, масса: {{mass_kg}} кг"
                || $stored === "{{action_icon}} **{{action_title}}**\n{{count_label}} · {{mass_kg}} кг"
                || $stored === "{{action_icon}} **{{action_title}}**\n**КОЛИЧЕСТВО:** {{count_label}}\n**ОБЩАЯ МАССА:** {{mass_kg}} кг") return self::FEO_TEMPLATE;
        }
        // Existing standard transfer rules can be stored with Windows CRLF. Normalize only for
        // exact comparison with known defaults; preserve every owner-customized template verbatim.
        if ($key === 'feo.transfer.created' || $key === 'feo.transfer.reverted') {
            $knownDefault = str_replace("\r\n", "\n", $stored);
            $knownTemplates = $key === 'feo.transfer.created'
                ? [self::LEGACY_TRANSFER_CREATED_TEMPLATE, self::PREVIOUS_TRANSFER_CREATED_TEMPLATE, self::CURRENT_TRANSFER_CREATED_TEMPLATE]
                : [self::LEGACY_TRANSFER_REVERTED_TEMPLATE, self::PREVIOUS_TRANSFER_REVERTED_TEMPLATE, self::CURRENT_TRANSFER_REVERTED_TEMPLATE];
            if ($knownDefault === '' || in_array($knownDefault, $knownTemplates, true)) return self::event($key)['template'];
        }
        if ($key === 'feo.request.weight_changed' && ($stored === '' || $stored === "ФЭО — ИЗМЕНЁН ВЕС ЗАЯВОК\n{{part}}\nИсточник: {{source}}\nЗаявок: {{request_count}}\nОбщая масса: {{old_mass_kg}} → {{new_mass_kg}} кг ({{difference_kg}} кг)\n{{changes}}\nОтветственный: {{author}}\n{{company}} · {{created_at}}")) return self::event($key)['template'];
        return $stored;
    }

    public static function isSystemRequired(string $key): bool
    {
        return !empty(self::event($key)['system_required']);
    }

    public static function systemFormat(string $key, string $stored): string
    {
        return self::isSystemRequired($key) || in_array($key,['route_executor.partial','route_executor.full'],true) ? 'markdown' : $stored;
    }

    public static function formatForTemplate(string $key, string $stored, string $template): string
    {
        if (in_array($key, ['feo.transfer.created', 'feo.transfer.reverted'], true)
            && self::currentTemplate($key, $template) === self::event($key)['template']) return 'markdown';
        return self::systemFormat($key, $stored);
    }

    public static function requestCountText(int $count): string
    {
        $tail = $count % 100;
        $word = $tail >= 11 && $tail <= 14 ? 'заявок'
            : match ($count % 10) { 1 => 'заявка', 2, 3, 4 => 'заявки', default => 'заявок' };
        return $count . ' ' . $word;
    }

    public static function render(string $key, string $template, string $format, array $values): string
    {
        $event = self::event($key);
        $template = self::currentTemplate($key, $template);
        $format = self::systemFormat($key, $format);
        if ($key === 'feo.placement.block_created') {
            $exclusion = in_array((string)($values['action_title'] ?? ''), ['СНЯТИЕ ЗАЯВОК', 'СНЯТО С КАРТЫ'], true);
            $values['action_icon'] = $exclusion ? '❌' : '✅';
            $values['action_title'] = $exclusion ? 'СНЯТО С КАРТЫ' : 'ДОБАВЛЕНО НА КАРТУ';
            $values['request_word'] = explode(' ', self::requestCountText((int)($values['request_count'] ?? 0)), 2)[1];
            $values['count_label'] = self::requestCountText((int)($values['request_count'] ?? 0));
        }
        if (!in_array($format, ['plain', 'html', 'markdown'], true)) throw new RuntimeException('Неизвестный формат MAX.');
        if (trim($template) === '' || mb_strlen($template) > 4000) throw new RuntimeException('Шаблон должен содержать от 1 до 4000 символов.');
        $literal = preg_replace('/\{\{\s*([^{}]+?)\s*\}\}/u', '', $template);
        if ($literal === null || str_contains($literal, '{{') || str_contains($literal, '}}')) throw new RuntimeException('Проверьте скобки переменных: {{название}}.');
        $text = preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/u', static function (array $m) use ($event, $values, $format, $key): string {
            $name = trim($m[1]);
            if (!isset($event['variables'][$name])) throw new RuntimeException('Неизвестная переменная: {{' . $name . '}}.');
            if (!array_key_exists($name, $values) || !is_scalar($values[$name])) throw new RuntimeException('Нет значения переменной: ' . $name . '.');
            $value = (string)$values[$name];
            if ($key === 'feo.transfer.created' && $name === 'destination_intro') {
                $sourceId = (string)($values['source_request_id'] ?? '');
                if ($sourceId !== '' && !preg_match('/^[0-9]{6}$/D', $sourceId)) throw new RuntimeException('Некорректный номер заявки назначения.');
                return $sourceId === '' ? 'Перемещены на новый адрес:'
                    : 'Перемещены на адрес заявки ' . ($format === 'markdown' ? '**'.$sourceId.'**' : $sourceId) . ':';
            }
            if ($key === 'feo.transfer.reverted' && $name === 'returned_requests' && $format === 'markdown') {
                return implode("\n", array_map(static fn(string $line): string => '**' . preg_replace('/([\\\\`*_{}\[\]()#+.!>~|=\-^])/u', '\\\\$1', $line) . '**', explode("\n", $value)));
            }
            if (in_array($key,['route_executor.partial','route_executor.full'],true) && $name==='missing') return $value;
            if ($format === 'html') return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($format === 'markdown') return preg_replace('/([\\\\`*_{}\[\]()#+.!>~|=\-^])/u', '\\\\$1', $value);
            return $value;
        }, $template);
        if ($text === null || trim($text) === '' || mb_strlen($text) > 4000) throw new RuntimeException('Готовое сообщение должно содержать от 1 до 4000 символов. Сократите шаблон.');
        return $text;
    }
}
