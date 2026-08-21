<?php

namespace App\Service;

use DateTimeImmutable;

final class DateCalculationService
{
    public const CONDITION_PREPAYMENT = 'prepayment';
    public const CONDITION_START_DAY = 'start_day';
    public const CONDITION_AFTER_START = 'after_start';
    public const CONDITION_END_DAY = 'end_day';
    public const CONDITION_AFTER_END = 'after_end';
    public const CONDITION_AFTER_DOCUMENTS = 'after_documents';
    public const CONDITION_SPECIFIC_DATE = 'specific_date';

    public const DAYS_KIND_CALENDAR = 'calendar';
    public const DAYS_KIND_WORKING = 'working';

    public const CONDITION_LABELS = [
        self::CONDITION_PREPAYMENT => 'Предоплата',
        self::CONDITION_START_DAY => 'На загрузке',
        self::CONDITION_AFTER_START => 'После загрузки',
        self::CONDITION_END_DAY => 'На выгрузке',
        self::CONDITION_AFTER_END => 'После выгрузки',
        self::CONDITION_AFTER_DOCUMENTS => 'Документы получены',
        self::CONDITION_SPECIFIC_DATE => 'Конкретная дата',
    ];

    public const CONDITION_LABELS_RU = self::CONDITION_LABELS;

    public static function isValidDate(?string $date): bool
    {
        if ($date === null || $date === '' || trim($date) !== $date) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false) {
            return false;
        }

        // getLastErrors() returns false when there are no warnings/errors.
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return false;
        }

        return $parsed->format('Y-m-d') === $date;
    }

    public static function isConditionEventDay(string $conditionType): bool
    {
        return in_array($conditionType, [
            self::CONDITION_START_DAY,
            self::CONDITION_END_DAY,
            self::CONDITION_PREPAYMENT,
            self::CONDITION_SPECIFIC_DATE,
        ], true);
    }

    public static function isConditionRequiresDays(string $conditionType): bool
    {
        return in_array($conditionType, [
            self::CONDITION_AFTER_START,
            self::CONDITION_AFTER_END,
            self::CONDITION_AFTER_DOCUMENTS,
        ], true);
    }

    public static function addWorkingDays(string $dateString, int $days): ?string
    {
        if ($days < 0 || !self::isValidDate($dateString)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateString);
        if ($date === false || $days === 0) {
            return $date?->format('Y-m-d');
        }

        $added = 0;
        while ($added < $days) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') <= 5) {
                $added++;
            }
        }

        return $date->format('Y-m-d');
    }

    public static function addCalendarDays(string $dateString, int $days): ?string
    {
        if ($days < 0 || !self::isValidDate($dateString)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateString);
        if ($date === false || $days === 0) {
            return $date?->format('Y-m-d');
        }

        return $date->modify('+' . $days . ' days')->format('Y-m-d');
    }

    public static function nextWorkingDay(string $dateString): ?string
    {
        if (!self::isValidDate($dateString)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateString);
        if ($date === false) {
            return null;
        }
        while ((int) $date->format('N') > 5) {
            $date = $date->modify('+1 day');
        }
        return $date->format('Y-m-d');
    }

    public static function addDays(string $dateString, int $days, string $daysKind = self::DAYS_KIND_CALENDAR): ?string
    {
        if ($daysKind === self::DAYS_KIND_WORKING) {
            return self::addWorkingDays($dateString, $days);
        }
        if ($daysKind === self::DAYS_KIND_CALENDAR) {
            return self::addCalendarDays($dateString, $days);
        }
        return null;
    }

    public static function calculateForecastDueDate(
        ?string $conditionType,
        ?string $plannedStartDate,
        ?string $plannedEndDate,
        ?int $daysCount,
        string $daysKind = self::DAYS_KIND_CALENDAR,
        ?string $specificDueDate = null
    ): ?string {
        return self::calculateDueDate(
            $conditionType,
            $plannedStartDate,
            $plannedEndDate,
            null,
            $daysCount,
            $daysKind,
            $specificDueDate,
            true
        );
    }

    public static function calculateFinalDueDate(
        ?string $conditionType,
        ?string $actualStartDate,
        ?string $actualEndDate,
        ?string $closingDocumentsReceivedDate,
        ?int $daysCount,
        string $daysKind = self::DAYS_KIND_CALENDAR,
        ?string $specificDueDate = null
    ): ?string {
        return self::calculateDueDate(
            $conditionType,
            $actualStartDate,
            $actualEndDate,
            $closingDocumentsReceivedDate,
            $daysCount,
            $daysKind,
            $specificDueDate,
            false
        );
    }

    private static function calculateDueDate(
        ?string $conditionType,
        ?string $startDate,
        ?string $endDate,
        ?string $documentsDate,
        ?int $daysCount,
        string $daysKind,
        ?string $specificDueDate,
        bool $forecast
    ): ?string {
        $conditionType = trim((string) $conditionType);
        if (!array_key_exists($conditionType, self::CONDITION_LABELS)) {
            return null;
        }
        if (!in_array($daysKind, [self::DAYS_KIND_CALENDAR, self::DAYS_KIND_WORKING], true)) {
            return null;
        }

        if (in_array($conditionType, [self::CONDITION_PREPAYMENT, self::CONDITION_SPECIFIC_DATE], true)) {
            return self::isValidDate($specificDueDate) ? $specificDueDate : null;
        }

        if ($conditionType === self::CONDITION_START_DAY) {
            return self::isValidDate($startDate) ? $startDate : null;
        }
        if ($conditionType === self::CONDITION_END_DAY) {
            return self::isValidDate($endDate) ? $endDate : null;
        }

        if ($daysCount === null || $daysCount < 1) {
            return null;
        }

        if ($conditionType === self::CONDITION_AFTER_START) {
            return self::isValidDate($startDate) ? self::addDays($startDate, $daysCount, $daysKind) : null;
        }
        if ($conditionType === self::CONDITION_AFTER_END) {
            return self::isValidDate($endDate) ? self::addDays($endDate, $daysCount, $daysKind) : null;
        }
        if ($conditionType === self::CONDITION_AFTER_DOCUMENTS) {
            if ($forecast || !self::isValidDate($documentsDate)) {
                return null;
            }
            return self::addDays($documentsDate, $daysCount, $daysKind);
        }

        return null;
    }

    public static function validateCondition(
        ?string $conditionType,
        ?int $daysCount,
        ?string $specificDueDate,
        string $daysKind = self::DAYS_KIND_CALENDAR
    ): ?string {
        $conditionType = trim((string) $conditionType);
        if (!array_key_exists($conditionType, self::CONDITION_LABELS)) {
            return 'Неизвестное условие оплаты.';
        }
        if (!in_array($daysKind, [self::DAYS_KIND_CALENDAR, self::DAYS_KIND_WORKING], true)) {
            return 'Неизвестный тип подсчёта дней.';
        }

        if (in_array($conditionType, [self::CONDITION_PREPAYMENT, self::CONDITION_SPECIFIC_DATE], true)) {
            if (!self::isValidDate($specificDueDate)) {
                return 'Укажите корректную конкретную дату в формате ГГГГ-ММ-ДД.';
            }
            if ($daysCount !== null && $daysCount !== 0) {
                return 'Для конкретной даты смещение в днях не используется.';
            }
            return null;
        }

        if (self::isConditionRequiresDays($conditionType)) {
            if ($daysCount === null || $daysCount < 1) {
                return 'Для условия после события укажите не менее одного дня.';
            }
            return null;
        }

        if (in_array($conditionType, [self::CONDITION_START_DAY, self::CONDITION_END_DAY], true)) {
            if ($daysCount !== null && $daysCount !== 0) {
                return 'Для оплаты в день события смещение должно быть равно нулю.';
            }
            return null;
        }

        return null;
    }
}
