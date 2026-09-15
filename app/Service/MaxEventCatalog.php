<?php

namespace App\Service;

use RuntimeException;

/** Registered ERP events: adding an event does not require a new settings screen. */
final class MaxEventCatalog
{
    public static function all(): array
    {
        return [
            'feo.placement.block_created' => [
                'title' => 'ФЭО — создан блок размещения',
                'description' => 'После сохранения нового блока «Добавление» или «Исключение».',
                'template' => "Создан блок ФЭО №{{block_id}}: {{block_name}}\nТип: {{block_type}}\nЗаявок: {{request_count}}, масса: {{mass_kg}} кг\nОтветственный: {{author}}\nКомпания: {{company}}\n{{created_at}}",
                'variables' => [
                    'block_id' => ['Номер блока', '42'],
                    'block_name' => ['Название блока', 'Размещение 15 сентября'],
                    'block_type' => ['Тип блока', 'Добавление'],
                    'request_count' => ['Число заявок в блоке', '12'],
                    'mass_kg' => ['Масса блока, кг', '25 400,000'],
                    'author' => ['Ответственный', 'Владимир С.'],
                    'company' => ['Название компании', 'Пример компании'],
                    'created_at' => ['Дата и время создания', '15.09.2026 15:30'],
                    'current_count' => ['Заявок в актуальном составе', '681'],
                    'current_mass_kg' => ['Масса актуального состава, кг', '100 000,000'],
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

    public static function render(string $key, string $template, string $format, array $values): string
    {
        $event = self::event($key);
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
