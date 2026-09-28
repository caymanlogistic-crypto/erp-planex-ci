<?php
declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/** Mail summary uses the same ten business columns as the downloadable Excel. */
final class TripReviewEmail
{
    private const HEADERS = ['Заявка', 'ОО', 'Дата', 'Прибытие', 'Убытие', 'Скан ТН',
        'Подпись ОО', 'Подпись водителя', 'Дата/время', 'Восстановление'];

    /** @return array{text:string,html:string} */
    public static function build(array $trip, string $url): array
    {
        $number = (string)$trip['business_id'];
        $rows = array_map(self::row(...), $trip['requests']);
        $title = 'Рейс №'.$number.' передан на проверку';
        $intro = 'Сводные данные по заявкам приведены ниже. Полный комплект документов доступен по ссылке.';
        $plain = [$title, $intro, '', implode(' | ', self::HEADERS)];
        foreach ($rows as $row) $plain[] = implode(' | ', $row);
        $plain[] = '';
        $plain[] = 'Скачать полный комплект (хранится 30 дней): '.$url;
        $plain[] = 'Передавайте ссылку только тем, кому разрешён доступ к документам рейса.';

        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $head = implode('', array_map(static fn(string $cell): string => '<th style="padding:8px 6px;border:1px solid #d8d3ca;background:#eeeae3;text-align:left">'.$escape($cell).'</th>', self::HEADERS));
        $body = '';
        foreach ($rows as $index=>$row) {
            $cells = implode('', array_map(static fn(string $cell): string => '<td style="padding:7px 6px;border:1px solid #dedad2;vertical-align:top">'.$escape($cell === '' ? '—' : $cell).'</td>', $row));
            $body .= '<tr style="background:'.($index % 2 === 0 ? '#fff' : '#f8f6f2').'">'.$cells.'</tr>';
        }
        $safeUrl = $escape($url);
        $html = '<!doctype html><html lang="ru"><head><meta charset="UTF-8"></head>'
            .'<body style="margin:0;padding:24px;background:#f2f0eb;color:#202020;font:13px Arial,sans-serif">'
            .'<div style="max-width:1100px;margin:auto;background:#fff;border:1px solid #d2ccc2;padding:20px">'
            .'<h2 style="margin:0 0 8px;font-size:18px">'.$escape($title).'</h2>'
            .'<p style="margin:0 0 16px">'.$escape($intro).'</p>'
            .'<p style="margin:0 0 18px"><a href="'.$safeUrl.'" style="display:inline-block;background:#87511f;color:#fff;text-decoration:none;padding:10px 14px">Скачать полный комплект</a>'
            .'<span style="display:inline-block;margin-left:10px;color:#665f56">Хранится 30 дней</span></p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:11px"><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table>'
            .'<p style="margin:16px 0 0;color:#665f56">Передавайте ссылку только тем, кому разрешён доступ к документам рейса.</p>'
            .'</div></body></html>';
        return ['text'=>implode("\n", $plain), 'html'=>$html];
    }

    /** @return list<string> */
    private static function row(array $request): array
    {
        $arrival = self::time((string)($request['arrived_at'] ?? ''));
        $departure = self::time((string)($request['departed_at'] ?? ''));
        $date = $arrival[0] !== '' ? $arrival[0] : $departure[0];
        $scan = (int)($request['tn_absent'] ?? 0) === 1 ? 'Нет ТН' :
            (!empty($request['documents']) ? 'Есть' : 'Нет скана');
        $dateState = ['full'=>'полностью', 'partial'=>'частично', 'no'=>'нет'];
        $recovery = ['tracker'=>'по трекеру', 'driver'=>'со слов вод./мен'];
        return [(string)$request['zayavka_id'], (string)$request['shipper_name'], $date,
            $arrival[1], $departure[1], $scan,
            self::mark((string)($request['sender_signature_status'] ?? '')),
            self::mark((string)($request['driver_signature_status'] ?? '')),
            $dateState[(string)($request['tn_datetime_status'] ?? '')] ?? '',
            $recovery[(string)($request['recovery_source'] ?? '')] ?? ''];
    }

    /** @return array{string,string} */
    private static function time(string $value): array
    {
        if ($value === '') return ['', ''];
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $date === false ? ['', ''] : [$date->format('d.m.Y'), $date->format('H:i')];
    }

    private static function mark(string $value): string
    {
        return $value === 'yes' ? 'V' : ($value === 'no' ? 'X' : '');
    }
}
