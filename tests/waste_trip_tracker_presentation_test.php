<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/app/Service/WasteTripTrackerPresentation.php';

use App\Service\WasteTripTrackerPresentation as Tracker;

$now = new DateTimeImmutable('2026-10-22 12:00:00', new DateTimeZone('UTC'));
$recent = Tracker::fromUtc('2026-10-22 11:58:00', $now);
$boundary = Tracker::fromUtc('2026-10-22 09:00:00', $now);
$late = Tracker::fromUtc('2026-10-21 22:58:00', $now);
$long = Tracker::fromUtc('2026-10-09 22:58:00', $now);
if ($recent['text'] !== '2м' || $recent['late']) throw new RuntimeException('Recent tracker format');
if ($boundary['text'] !== '3ч 0м' || $boundary['late']) throw new RuntimeException('Three hour boundary');
if ($late['text'] !== '13ч 2м' || !$late['late']) throw new RuntimeException('Late tracker format');
if ($long['text'] !== '12д 13ч 2м' || !$long['late']) throw new RuntimeException('Long tracker format');
if (Tracker::fromUtc(null, $now) !== null || Tracker::fromUtc('2026-10-22 12:01:00', $now) !== null) throw new RuntimeException('Invalid tracker point');
echo "WASTE_TRIP_TRACKER_PRESENTATION_PASS\n";
