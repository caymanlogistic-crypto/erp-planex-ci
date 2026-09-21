<?php

namespace App\Service;

use PDO;
use RuntimeException;

/** Editable FEO text, stored with the existing event rule; no second delivery pipeline. */
final class MaxFeoMessageTemplates
{
    private const VERSION = 3;

    public static function definitions(): array
    {
        $route = ['manager'=>'Упоминание и разделитель (пусто без MAX ID)', 'route'=>'Номер и название рейса',
            'items'=>'Сгруппированные заявки', 'count_label'=>'Количество заявок', 'mass_kg'=>'Общая масса, кг'];
        $row = ['id'=>'Номер заявки','mass_kg'=>'Масса заявки, кг'];
        return [
            'main'=>['title'=>'Общее сообщение', 'variables'=>['action_icon'=>'✅ / ❌','action_title'=>'Добавлено / снято с карты','count_label'=>'Количество заявок','mass_kg'=>'Общая масса, кг','request_ids'=>'Номера заявок блока'],
                'template'=>"{{action_icon}} **{{action_title}}**\n**КОЛИЧЕСТВО:** {{count_label}}\n**ОБЩАЯ МАССА:** {{mass_kg}} кг\n{{request_ids}}"],
            'same_point'=>['title'=>'Добавление в активные точки','variables'=>$route,
                'template'=>"‼️ {{manager}}**НЕОБХОДИМО ДОБАВИТЬ В РЕЙС** ‼️\n**{{route}}**\n\n{{items}}\n\n**ИТОГО ДОБАВИТЬ:** {{count_label}} · {{mass_kg}} кг"],
            'nearby'=>['title'=>'Заявки рядом с рейсом','variables'=>$route,
                'template'=>"⚠️ {{manager}}**НОВЫЕ ЗАЯВКИ РЯДОМ С РЕЙСОМ, ДО 5 КМ** ⚠️\n**{{route}}**\n\n**НОВЫЕ ЗАЯВКИ:**\n{{items}}\n\n**ИТОГО:** {{count_label}} · {{mass_kg}} кг\n⚠️ **ПРОВЕРЬТЕ ВОЗМОЖНОСТЬ ДОБАВЛЕНИЯ В РЕЙС** ⚠️"],
            'active_exclusion'=>['title'=>'Снятие активных заявок','variables'=>$route,
                'template'=>"‼️ {{manager}}**С КАРТЫ СНЯТЫ ЗАЯВКИ, УЖЕ ВКЛЮЧЁННЫЕ В РЕЙС** ‼️\n**{{route}}**\n\n**СНЯТЫЕ ЗАЯВКИ:**\n{{items}}\n\n**ИТОГО СНЯТО:** {{count_label}} · {{mass_kg}} кг\n‼️ **ПРОВЕРЬТЕ И СКОРРЕКТИРУЙТЕ СОСТАВ РЕЙСА** ‼️"],
            'point'=>['title'=>'Группа одной точки','variables'=>['active_ids'=>'Все активные заявки в точке','requests'=>'Новые заявки в точке'],
                'template'=>"**НОВЫЕ ЗАЯВКИ:**\n{{requests}}\n**НАХОДЯТСЯ В ТОМ ЖЕ МЕСТЕ, ЧТО И ЗАЯВКИ, УЖЕ ВКЛЮЧЁННЫЕ В РЕЙС:**\n**{{active_ids}}**"],
            'addition_row'=>['title'=>'Строка новой заявки в точке','variables'=>$row,'template'=>'{{id}} — {{mass_kg}} кг'],
            'nearby_row'=>['title'=>'Строка заявки рядом','variables'=>$row,'template'=>'{{id}} — {{mass_kg}} кг'],
            'exclusion_row'=>['title'=>'Строка снятой заявки','variables'=>$row,'template'=>'**{{id}} — {{mass_kg}} кг**'],
        ];
    }

    public static function defaults(): array { return array_map(static fn(array $d): string => $d['template'], self::definitions()); }

    public static function decode(string $stored): array
    {
        $data = json_decode($stored, true);
        if (!is_array($data) || !in_array($data['feo_templates_version'] ?? null, [1,2,self::VERSION], true)) return self::defaults();
        $version = (int)$data['feo_templates_version'];
        $templates = $data['templates'] ?? [];
        if (!is_array($templates)) return self::defaults();
        if (($version === 1 && $templates === self::legacyDefaultsV1())
            || ($version === 2 && $templates === self::legacyDefaultsV2())) return self::defaults();
        if ($version < self::VERSION && isset($templates['main']) && is_string($templates['main'])
            && !preg_match('/\{\{\s*request_ids\s*\}\}/u', $templates['main'])) {
            $templates['main'] = rtrim($templates['main']) . "\n{{request_ids}}";
        }
        return self::validate($templates);
    }

    public static function fromInput(mixed $input): array
    {
        $templates = is_string($input) ? json_decode($input, true) : $input;
        if (!is_array($templates)) throw new RuntimeException('Не удалось прочитать шаблоны ФЭО.');
        return self::validate($templates);
    }

    public static function encode(array $templates): string
    {
        $encoded = json_encode(['feo_templates_version'=>self::VERSION,'templates'=>self::validate($templates)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 65535) throw new RuntimeException('Общий размер шаблонов слишком большой. Сократите текст.');
        return $encoded;
    }

    public static function validate(array $templates): array
    {
        $definitions = self::definitions();
        if (array_diff(array_keys($templates), array_keys($definitions))) throw new RuntimeException('Неизвестный шаблон ФЭО.');
        foreach ($definitions as $kind=>$definition) {
            $text = $templates[$kind] ?? null;
            if (!is_string($text) || trim($text)==='' || mb_strlen($text,'UTF-8')>4000) throw new RuntimeException($definition['title'].': нужен шаблон до 4000 символов.');
            preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/u', $text, $matches);
            $keys = array_map('trim',$matches[1]);
            if (array_diff($keys,array_keys($definition['variables']))) throw new RuntimeException($definition['title'].': неизвестная переменная.');
            $literal=preg_replace('/\{\{\s*([^{}]+?)\s*\}\}/u','',$text);
            if (str_contains($literal,'{{') || str_contains($literal,'}}')) throw new RuntimeException($definition['title'].': проверьте скобки переменных.');
            foreach ($definition['variables'] as $key=>$label) {
                if (!in_array($key,$keys,true)) throw new RuntimeException($definition['title'].': сохраните переменную {{'.$key.'}} — '.$label.'.');
                if (array_count_values($keys)[$key]>1 && in_array($key,['items','requests','active_ids'],true)) throw new RuntimeException($definition['title'].': список '.$key.' должен быть указан один раз.');
            }
        }
        return array_replace(self::defaults(),$templates);
    }

    public static function load(PDO $pdo): array
    {
        if (!(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='max_event_rules'")->fetchColumn()) return self::defaults();
        $q=$pdo->prepare('SELECT message_template FROM max_event_rules WHERE event_key=?');
        $q->execute(['feo.placement.block_created']);
        return self::decode((string)$q->fetchColumn());
    }

    private static function legacyDefaultsV1(): array
    {
        return [
            'main'=>"{{action_icon}} **{{action_title}}**\n{{count_label}} · {{mass_kg}} кг",
            'same_point'=>"‼️ {{manager}}**ДОБАВИТЬ В РЕЙС** ‼️\n**{{route}}**\n{{items}}\n**Итого добавить: {{count_label}} · {{mass_kg}} кг**",
            'nearby'=>"⚠️ {{manager}}**РЯДОМ, ДО 5 КМ** ⚠️\n**{{route}}**\n{{items}}\n**{{count_label}} · {{mass_kg}} кг**\n⚠️ Рассмотрите добавление в рейс ⚠️",
            'active_exclusion'=>"‼️ {{manager}}**СНЯТЫ С КАРТЫ** ‼️\n**{{route}}**\n{{items}}\n**{{count_label}} · {{mass_kg}} кг**\n‼️ Скорректируйте состав рейса ‼️",
            'point'=>"В точке: **{{active_ids}}**\n{{requests}}",
            'addition_row'=>'\\+ {{id}} — {{mass_kg}} кг',
            'nearby_row'=>'{{id}} — {{mass_kg}} кг',
            'exclusion_row'=>'− **{{id}} — {{mass_kg}} кг**',
        ];
    }

    private static function legacyDefaultsV2(): array
    {
        $defaults = self::defaults();
        $defaults['main'] = "{{action_icon}} **{{action_title}}**\n**КОЛИЧЕСТВО:** {{count_label}}\n**ОБЩАЯ МАССА:** {{mass_kg}} кг";
        return $defaults;
    }

    public static function preview(array $templates, string $kind): string
    {
        $templates=self::validate($templates);
        $values=['action_icon'=>'✅','action_title'=>'ДОБАВЛЕНО НА КАРТУ','count_label'=>'2 заявки','mass_kg'=>'200','request_ids'=>'248516, 900002',
            'manager'=>'[Михаил](max://user/123456) · ','route'=>'\\#253 Новороссийск',
            'id'=>'248516','active_ids'=>'247370, 248948'];
        $lineKind=match($kind){'nearby'=>'nearby_row','active_exclusion'=>'exclusion_row',default=>'addition_row'};
        $values['requests']=self::render($templates,$lineKind,array_replace($values,['mass_kg'=>'60']));
        $values['requests'].="\n".self::render($templates,$lineKind,array_replace($values,['id'=>'900002','mass_kg'=>'140']));
        $values['items']=$kind==='same_point'?self::render($templates,'point',$values):$values['requests'];
        $text=self::render($templates,$kind,$values);
        if(mb_strlen($text,'UTF-8')>4000)throw new RuntimeException('Пример сообщения превышает 4000 символов. Сократите шаблон.');
        return $text;
    }

    /** Values come only from escaped business fields or the formatter's own Markdown. */
    public static function render(array $templates, string $kind, array $values): string
    {
        if (!isset($templates[$kind])) throw new RuntimeException('Неизвестный шаблон ФЭО.');
        return preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/u', static function(array $m) use($values): string {
            $key=trim($m[1]);
            if (!array_key_exists($key,$values)) throw new RuntimeException('Нет значения переменной '.$key.'.');
            return (string)$values[$key];
        },$templates[$kind]);
    }
}
