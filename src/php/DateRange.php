<?php

declare(strict_types=1);

namespace PaymentCsv;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class DateRange
{
    public const MAX_DAYS = 92;

    private function __construct(
        private readonly DateTimeImmutable $start,
        private readonly DateTimeImmutable $end,
        private readonly DateTimeZone $timezone,
    ) {
    }

    public static function fromInput(string $from, string $to): self
    {
        $timezone = new DateTimeZone('Europe/Madrid');
        $start = self::parseDate($from, $timezone)->setTime(0, 0, 0);
        $end = self::parseDate($to, $timezone)->setTime(23, 59, 59);

        if ($end < $start) {
            throw new InvalidArgumentException('La fecha final debe ser igual o posterior a la fecha inicial.');
        }

        $days = (int) $start->diff($end)->days + 1;

        if ($days > self::MAX_DAYS) {
            throw new InvalidArgumentException('El periodo seleccionado no puede superar los 92 días.');
        }

        return new self($start, $end, $timezone);
    }

    public function contains(string $timestamp): bool
    {
        try {
            $date = new DateTimeImmutable($timestamp);
        } catch (Throwable) {
            return false;
        }

        $date = $date->setTimezone($this->timezone);

        return $date >= $this->start && $date <= $this->end;
    }

    public function candidateSearchQuery(): string
    {
        $utc = new DateTimeZone('UTC');
        $start = $this->start->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $end = $this->end->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');

        return sprintf('created_at:<=%s updated_at:>=%s', $end, $start);
    }

    public function formatInTimezone(string $timestamp): string
    {
        return (new DateTimeImmutable($timestamp))
            ->setTimezone($this->timezone)
            ->format(DateTimeInterface::ATOM);
    }

    public function fromInputValue(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function toInputValue(): string
    {
        return $this->end->format('Y-m-d');
    }

    private static function parseDate(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException('Las fechas deben utilizar el formato AAAA-MM-DD.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException('Selecciona una fecha de calendario válida.');
        }

        return $date;
    }
}
