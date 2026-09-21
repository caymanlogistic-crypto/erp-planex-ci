<?php

namespace App\Service;

use RuntimeException;

require_once __DIR__ . '/MaxFeoMessageTemplates.php';

/** Registered ERP events: adding an event does not require a new settings screen. */
final class MaxEventCatalog
{
    private const LEGACY_FEO_TEMPLATE = "Создан блок ФЭО №{{block_id}}: {{block_name}}\nТип: {{block_type}}\nЗаявок: {{request_count}}, масса: {{mass_kg}} кг\nОтветственный: {{author}}\nКомпания: {{company}}\n{{created_at}}";
    private const FEO_TEMPLATE = "{{action_icon}} **{{action_title}}**\n**КОЛИЧЕСТВО:** {{count_label}}\n**ОБЩАЯ МАССА:** {{mass_kg}} кг";

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
                    'mass_kg' => ['Масса блока, кг', '25 400'],
                    'author' => ['Ответственный', 'Владимир С.'],
                    'company' => ['Название компании', 'Пример компании'],
                    'created_at' => ['Дата и время создания', '15.09.2026 15:30'],
                    'current_count' => ['Заявок в актуальном составе', '681'],
                    'current_mass_kg' => ['Масса актуального состава, кг', '100 000,000'],
                ],
            ],
        ] + self::transferEvents() + self::documentEvents();
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
            'batch_id'=>['Номер пакета','18'], 'batch_name'=>['Название пакета','Перенос на склад'],
            'request_count'=>['Число заявок','12'], 'address'=>['Адрес назначения','Москва, улица Примерная, 1'],
            'quality'=>['Качество координат','Только улица, дом не определён'],
            'author'=>['Автор','Владимир С.'], 'company'=>['Компания','Пример компании'],
            'created_at'=>['Дата и время','18.09.2026 15:30'], 'comment'=>['Комментарий','Изменение места погрузки'],
        ];
        $events=[];
        foreach (['created'=>['ФЭО — создан пакет переноса','Создан пакет переноса'], 'reverted'=>['ФЭО — возвращён пакет переноса','Возвращён пакет переноса']] as $action=>$labels) {
            $events['feo.transfer.'.$action]=['title'=>$labels[0],
                'description'=>'После успешного '.($action==='created'?'переноса':'возврата').' всего пакета. Качество координат обязательно присутствует в сообщении.',
                'template'=>$labels[1]." №{{batch_id}}: {{batch_name}}\nЗаявок: {{request_count}}\nАдрес: {{address}}\nКачество координат: {{quality}}\nАвтор: {{author}}\n{{company}} · {{created_at}}\n{{comment}}",
                'variables'=>$variables];
        }
        return $events;
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
        if ($key === 'feo.placement.block_created') {
            if (str_starts_with(ltrim($stored), '{"feo_templates_version"')) return MaxFeoMessageTemplates::decode($stored)['main'];
            if ($stored === '' || $stored === self::LEGACY_FEO_TEMPLATE
                || $stored === "{{action_title}}:\n\nЗаявок: {{request_count}}, масса: {{mass_kg}} кг"
                || $stored === "{{action_icon}} **{{action_title}}**\n{{count_label}} · {{mass_kg}} кг") return self::FEO_TEMPLATE;
        }
        return $stored;
    }

    public static function isSystemRequired(string $key): bool
    {
        return !empty(self::event($key)['system_required']);
    }

    public static function systemFormat(string $key, string $stored): string
    {
        return self::isSystemRequired($key) ? 'markdown' : $stored;
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
        if (str_starts_with($key, 'feo.transfer.') && !preg_match('/\{\{\s*quality\s*\}\}/', $template)) throw new RuntimeException('Добавьте в шаблон качество координат: {{quality}}.');
        if (!in_array($format, ['plain', 'html', 'markdown'], true)) throw new RuntimeException('Неизвестный формат MAX.');
        if (trim($template) === '' || mb_strlen($template) > 4000) throw new RuntimeException('Шаблон должен содержать от 1 до 4000 символов.');
        $literal = preg_replace('/\{\{\s*([^{}]+?)\s*\}\}/u', '', $template);
        if ($literal === null || str_contains($literal, '{{') || str_contains($literal, '}}')) throw new RuntimeException('Проверьте скобки переменных: {{название}}.');
        $text = preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/u', static function (array $m) use ($event, $values, $format): string {
            $name = trim($m[1]);
            if (!isset($event['variables'][$name])) throw new RuntimeException('Неизвестная переменная: {{' . $name . '}}.');
            if (!array_key_exists($name, $values) || !is_scalar($values[$name])) throw new RuntimeException('Нет значения переменной: ' . $name . '.');
            $value = (string)$values[$name];
            if ($format === 'html') return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($format === 'markdown') return preg_replace('/([\\\\`*_{}\[\]()#+.!>~|=\-^])/u', '\\\\$1', $value);
            return $value;
        }, $template);
        if ($text === null || trim($text) === '' || mb_strlen($text) > 4000) throw new RuntimeException('Готовое сообщение должно содержать от 1 до 4000 символов. Сократите шаблон.');
        return $text;
    }
}
