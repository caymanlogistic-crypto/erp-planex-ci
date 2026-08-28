<?php

declare(strict_types=1);

// Canonical Stage 1 import source: legacy map_test/map_files/admin/setup_utilizers_table.php
// Colors come from legacy map_test/map_files/admin/apply_utilizer_palette.php (color_main).
$utilizers = [
    [
        'full_name' => 'АО "НПП "КУБАНЬЦВЕТМЕТ"',
        'short_name' => 'КУБАНЬЦВЕТМЕТ',
        'address' => 'Краснодарский край, Абинский район, ст. Холмская, территория металлургического завода',
        'fkko_classification' => 'бой стеклянный ртутных ламп и термометров с остатками ртути',
        'adr' => 0,
        'latitude' => 44.8480460,
        'longitude' => 38.3892340,
        'color_hex' => '#8D6E63',
        'sort_order' => 10,
    ],
    [
        'full_name' => 'ООО "ЛИМПЭК"',
        'short_name' => 'ЛИМПЭК',
        'address' => 'Ярославская область, Тутаевский район, п. Чебаково, ул. Дорожная, д.3',
        'fkko_classification' => 'отходы растворителей на основе тетрахлорметана',
        'adr' => 1,
        'latitude' => 57.7560540,
        'longitude' => 39.5370390,
        'color_hex' => '#F9A825',
        'sort_order' => 20,
    ],
    [
        'full_name' => 'ООО "МЕРКОМ"',
        'short_name' => 'МЕРКОМ',
        'address' => 'Московская обл, г Лыткарино, тер промзона Тураево, стр 8, НИИП',
        'fkko_classification' => 'отходы термометров ртутных',
        'adr' => 0,
        'latitude' => 55.5712500,
        'longitude' => 37.9470120,
        'color_hex' => '#43A047',
        'sort_order' => 30,
    ],
    [
        'full_name' => 'ООО "НЭК - ИННОВАЦИИ"',
        'short_name' => 'НЭК',
        'address' => 'Ярославская область, г.Ярославль, пр-кт Октября, д. 78, 1 этаж № 49-51, 54-57; 2 этаж № 32-49, кадастровый номер 76:23:050102:1402',
        'fkko_classification' => 'одиночные гальванические элементы (батарейки) никель-кадмиевые неповрежденные отработанные',
        'adr' => 0,
        'latitude' => 57.6533530,
        'longitude' => 39.8623450,
        'color_hex' => '#8E24AA',
        'sort_order' => 40,
    ],
    [
        'full_name' => 'ООО "ПК "ЭКО+"',
        'short_name' => 'ЭКО+',
        'address' => 'Астраханская область, Икрянинский Муниципальный Район, Городское Поселение Рабочий Поселок Ильинка, Ильинка рабочий поселок, территория Промышленный участок 3',
        'fkko_classification' => 'смесь галогенсодержащих органических веществ',
        'adr' => 1,
        'latitude' => 46.2351810,
        'longitude' => 47.9019440,
        'color_hex' => '#00ACC1',
        'sort_order' => 50,
    ],
    [
        'full_name' => 'ООО "ПРОМХИМЛАБ"',
        'short_name' => 'ПРОМХИМЛАБ',
        'address' => '656904, Алтайский край, г. Барнаул, Южный проезд, д. 21А',
        'fkko_classification' => 'жидкие отходы, содержащие хлорорганические соединения',
        'adr' => 1,
        'latitude' => 53.3122970,
        'longitude' => 83.6433310,
        'color_hex' => '#1E88E5',
        'sort_order' => 60,
    ],
    [
        'full_name' => 'ООО "ФИРМА ЛИПЕЦКЭКОХИМ"',
        'short_name' => 'ЛИПЕЦКЭКОХИМ',
        'address' => '398041, Липецкая область, г. Липецк, ул. Передельческая, д.5, литер "И"',
        'fkko_classification' => 'кислота аккумуляторная серная отработанная',
        'adr' => 1,
        'latitude' => 52.5690240,
        'longitude' => 39.6787750,
        'color_hex' => '#D81B60',
        'sort_order' => 70,
    ],
    [
        'full_name' => 'ООО "ФРЕГАТ"',
        'short_name' => 'ФРЕГАТ',
        'address' => 'Московская обл, г. Воскресенск, ул. Кирова, д. 3 в',
        'fkko_classification' => 'аккумуляторы',
        'adr' => 0,
        'latitude' => 55.2954730,
        'longitude' => 38.7050730,
        'color_hex' => '#546E7A',
        'sort_order' => 80,
    ],
];

$rules = [];
$safeShortContains = ['КУБАНЬЦВЕТМЕТ', 'ЛИМПЭК', 'МЕРКОМ', 'ПРОМХИМЛАБ', 'ЛИПЕЦКЭКОХИМ', 'ФРЕГАТ'];
foreach ($utilizers as $u) {
    $short = $u['short_name'];
    $rules[] = ['utilizer' => $short, 'source_field' => 'recipient', 'match_type' => 'exact', 'pattern' => $u['full_name'], 'priority' => 10];
    $rules[] = ['utilizer' => $short, 'source_field' => 'recipient', 'match_type' => 'exact', 'pattern' => $short, 'priority' => 20];
    $rules[] = ['utilizer' => $short, 'source_field' => 'delivery_address', 'match_type' => 'exact', 'pattern' => $u['address'], 'priority' => 30];
    $rules[] = ['utilizer' => $short, 'source_field' => 'recipient', 'match_type' => 'contains', 'pattern' => $u['full_name'], 'priority' => 40];
    if (in_array($short, $safeShortContains, true)) {
        $rules[] = ['utilizer' => $short, 'source_field' => 'recipient', 'match_type' => 'contains', 'pattern' => $short, 'priority' => 50];
    }
}

return ['utilizers' => $utilizers, 'rules' => $rules];
