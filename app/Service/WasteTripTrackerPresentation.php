<?php

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;

final class WasteTripTrackerPresentation
{
    /** @return array{text:string,title:string,late:bool}|null */
    public static function fromUtc(?string $lastPointUtc, DateTimeImmutable $now): ?array
    {
        if ($lastPointUtc === null || $lastPointUtc === '') return null;
        $point = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $lastPointUtc, new DateTimeZone('UTC'));
        if ($point === false || $point->format('Y-m-d H:i:s') !== $lastPointUtc) return null;
        $seconds = $now->getTimestamp() - $point->getTimestamp();
        if ($seconds < 0) return null;
        $minutes = intdiv($seconds, 60);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $parts = [];
        if ($days > 0) $parts[] = $days.'д';
        if ($hours > 0) $parts[] = $hours.'ч';
        $parts[] = ($minutes % 60).'м';
        return ['text'=>implode(' ', $parts),
            'title'=>'Последняя связь: '.$point->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i').' МСК',
            'late'=>$seconds > 3 * 3600];
    }
}
