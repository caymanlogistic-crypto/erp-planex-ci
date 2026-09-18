<?php

namespace App\Service;

use RuntimeException;

/** Geographic values here always use their actual meaning, independently of FEO storage. */
final class YandexGeocodingService
{
    public function __construct(private readonly string $apiKey, private readonly ?\Closure $transport = null) {}

    public static function precisionLabel(string $precision): string
    {
        return match ($precision) {
            'exact' => 'Точное совпадение дома',
            'number' => 'Номер дома совпал, корпус или строение отличается',
            'near' => 'Найден соседний дом',
            'range' => 'Приблизительное положение дома',
            'street' => 'Только улица, дом не определён',
            'other' => 'Населённый пункт или район, адрес не уточнён',
            default => 'Точность не подтверждена',
        };
    }

    public function geocode(string $address): array
    {
        $address = trim($address);
        if ($address === '' || mb_strlen($address) > 1000) throw new RuntimeException('Введите адрес длиной до 1000 символов.');
        if ($this->apiKey === '') throw new RuntimeException('Ключ Яндекс Геокодера ещё не настроен. Обратитесь к руководителю.');
        $url = 'https://geocode-maps.yandex.ru/1.x/?' . http_build_query(['format'=>'json','apikey'=>$this->apiKey,'geocode'=>$address,'lang'=>'ru_RU','results'=>1]);
        if ($this->transport !== null) {
            $body = ($this->transport)($url);
        } else {
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
            $body = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($body === false || $status !== 200) throw new RuntimeException('Яндекс Геокодер недоступен. Проверьте ключ и лимиты запросов или повторите позже.');
        }
        try { $data = json_decode((string)$body, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('Некорректный ответ Яндекс Геокодера.'); }
        $object = $data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject'] ?? null;
        if (!is_array($object)) throw new RuntimeException('Адрес не найден. Уточните населённый пункт, улицу и дом.');
        $pos = preg_split('/\s+/', trim((string)($object['Point']['pos'] ?? '')));
        if (count($pos) !== 2 || !is_numeric($pos[0]) || !is_numeric($pos[1]) || abs((float)$pos[0]) > 180 || abs((float)$pos[1]) > 90) throw new RuntimeException('Яндекс вернул некорректные координаты.');
        $meta = $object['metaDataProperty']['GeocoderMetaData'] ?? [];
        $precision = (string)($meta['precision'] ?? 'unknown');
        return ['address'=>(string)($meta['text'] ?? $address),'query'=>$address,'latitude'=>$pos[1],'longitude'=>$pos[0],
            'precision'=>$precision,'quality'=>self::precisionLabel($precision),'kind'=>(string)($meta['kind'] ?? ''),'provider'=>'yandex','checked_at'=>date(DATE_ATOM)];
    }
}
