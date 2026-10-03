<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Cron;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use NeoPHP\Package\Scheduler\Exception\InvalidExpressionException;
use NeoPHP\Package\Scheduler\Exception\SchedulerException;

class CronExpression
{
    public const MAX_ITERATIONS = 200000;

    public const ALIASES = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    public const FIELDS = [
        'minute' => [0, 59],
        'hour' => [0, 23],
        'day' => [1, 31],
        'month' => [1, 12],
        'weekday' => [0, 7],
    ];

    public const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    public const WEEKDAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    protected string $source;

    protected string $expression;

    protected array $values = [];

    protected array $restricted = [];

    protected DateTimeZone $timezone;

    public function __construct(string $expression, DateTimeZone|string|null $timezone = null)
    {
        $this->source = trim($expression);
        $this->expression = self::normalize($this->source);
        $this->timezone = $timezone instanceof DateTimeZone ? $timezone : new DateTimeZone($timezone ?? date_default_timezone_get());
        $parts = preg_split('/\s+/', $this->expression) ?: [];

        if (count($parts) !== 5) {
            throw new InvalidExpressionException('The cron expression "{expression}" must have 5 fields (minute hour day-of-month month day-of-week) or be an alias (@daily, @hourly, @every 5m...).', 0, null, ['expression' => $this->source]);
        }

        foreach (array_keys(self::FIELDS) as $index => $field) {
            $this->restricted[$field] = $parts[$index] !== '*' && $parts[$index] !== '?';
            $this->values[$field] = $this->parseField($field, $parts[$index]);
        }

        if (isset($this->values['weekday'][7])) {
            $this->values['weekday'][0] = true;
            unset($this->values['weekday'][7]);
        }
    }

    public static function isValid(string $expression): bool
    {
        try {
            new self($expression);
        } catch (InvalidExpressionException) {
            return false;
        }

        return true;
    }

    public static function normalize(string $expression): string
    {
        $expression = trim((string) preg_replace('/\s+/', ' ', $expression));
        $lower = strtolower($expression);

        if (isset(self::ALIASES[$lower])) {
            return self::ALIASES[$lower];
        }

        if (str_starts_with($lower, '@every')) {
            return self::every(trim(substr($lower, 6)), $expression);
        }

        if (str_starts_with($lower, '@')) {
            throw new InvalidExpressionException('The cron alias "{expression}" is not supported (supported: {aliases}, @every <n>m|h|d).', 0, null, ['expression' => $expression, 'aliases' => implode(', ', array_keys(self::ALIASES))]);
        }

        return $expression;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getTimezone(): DateTimeZone
    {
        return $this->timezone;
    }

    public function getFieldValues(string $field): array
    {
        return array_keys($this->values[$field] ?? []);
    }

    public function isDue(?DateTimeInterface $date = null): bool
    {
        $date = $this->localize($date);

        return isset($this->values['minute'][(int) $date->format('i')])
            && isset($this->values['hour'][(int) $date->format('G')])
            && isset($this->values['month'][(int) $date->format('n')])
            && $this->matchesDay($date);
    }

    public function getNextRunDate(?DateTimeInterface $from = null, int $skip = 0, bool $allowCurrent = false): DateTimeImmutable
    {
        $date = $this->localize($from);
        $date = $date->setTime((int) $date->format('G'), (int) $date->format('i'));

        if (!$allowCurrent) {
            $date = $date->modify('+1 minute');
        }

        for ($found = 0; ; $found++) {
            $date = $this->search($date, true);

            if ($found >= $skip) {
                return $date;
            }

            $date = $date->modify('+1 minute');
        }
    }

    public function getPreviousRunDate(?DateTimeInterface $from = null, int $skip = 0, bool $allowCurrent = false): DateTimeImmutable
    {
        $date = $this->localize($from);
        $date = $date->setTime((int) $date->format('G'), (int) $date->format('i'));

        if (!$allowCurrent) {
            $date = $date->modify('-1 minute');
        }

        for ($found = 0; ; $found++) {
            $date = $this->search($date, false);

            if ($found >= $skip) {
                return $date;
            }

            $date = $date->modify('-1 minute');
        }
    }

    public function getNextRunDates(int $count, ?DateTimeInterface $from = null, bool $allowCurrent = false): array
    {
        $dates = [];
        $date = $from;

        for ($i = 0; $i < max(0, $count); $i++) {
            $date = $this->getNextRunDate($date, 0, $allowCurrent && $i === 0);
            $dates[] = $date;
        }

        return $dates;
    }

    public function __toString(): string
    {
        return $this->expression;
    }

    protected function search(DateTimeImmutable $date, bool $forward): DateTimeImmutable
    {
        $limit = (int) $date->format('Y') + ($forward ? 6 : -6);

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $year = (int) $date->format('Y');

            if (($forward && $year > $limit) || (!$forward && $year < $limit)) {
                break;
            }

            if (!isset($this->values['month'][(int) $date->format('n')])) {
                $date = $forward
                    ? $date->modify('first day of next month')->setTime(0, 0)
                    : $date->modify('last day of previous month')->setTime(23, 59);
                continue;
            }

            if (!$this->matchesDay($date)) {
                $date = $forward ? $date->modify('+1 day')->setTime(0, 0) : $date->modify('-1 day')->setTime(23, 59);
                continue;
            }

            $hour = (int) $date->format('G');

            if (!isset($this->values['hour'][$hour])) {
                $next = $forward ? $date->setTime($hour, 0)->modify('+1 hour') : $date->setTime($hour, 0)->modify('-1 minute');
                $date = (int) $next->format('G') === $hour && $forward ? $next->modify('+1 hour') : $next;
                continue;
            }

            if (!isset($this->values['minute'][(int) $date->format('i')])) {
                $date = $date->modify($forward ? '+1 minute' : '-1 minute');
                continue;
            }

            return $date;
        }

        throw new SchedulerException('No run date found for the cron expression "{expression}".', 0, null, ['expression' => $this->source]);
    }

    protected function matchesDay(DateTimeInterface $date): bool
    {
        $day = isset($this->values['day'][(int) $date->format('j')]);
        $weekday = isset($this->values['weekday'][(int) $date->format('w')]);

        if ($this->restricted['day'] && $this->restricted['weekday']) {
            return $day || $weekday;
        }

        return $day && $weekday;
    }

    protected function localize(?DateTimeInterface $date): DateTimeImmutable
    {
        $date = $date === null ? new DateTimeImmutable('now', $this->timezone) : DateTimeImmutable::createFromInterface($date);

        return $date->setTimezone($this->timezone);
    }

    protected function parseField(string $field, string $value): array
    {
        [$min, $max] = self::FIELDS[$field];
        $values = [];

        foreach (explode(',', strtolower($value)) as $part) {
            if (preg_match('#^(\*|\?|[a-z0-9]+(?:-[a-z0-9]+)?)(?:/(\d+))?$#', $part, $matches) !== 1) {
                throw $this->invalid($field, $value);
            }

            $step = isset($matches[2]) ? (int) $matches[2] : 1;

            if ($step < 1) {
                throw $this->invalid($field, $value);
            }

            if ($matches[1] === '*' || $matches[1] === '?') {
                [$start, $end] = [$min, $field === 'weekday' ? 6 : $max];
            } elseif (str_contains($matches[1], '-')) {
                [$start, $end] = array_map(fn (string $item): int => $this->toNumber($field, $item, $value), explode('-', $matches[1], 2));
            } else {
                $start = $this->toNumber($field, $matches[1], $value);
                $end = isset($matches[2]) ? ($field === 'weekday' ? 6 : $max) : $start;
            }

            if ($start < $min || $end > $max || $start > $end) {
                throw $this->invalid($field, $value);
            }

            for ($i = $start; $i <= $end; $i += $step) {
                $values[$i] = true;
            }
        }

        ksort($values);

        return $values;
    }

    protected function toNumber(string $field, string $item, string $value): int
    {
        if (ctype_digit($item)) {
            return (int) $item;
        }

        $names = match ($field) {
            'month' => self::MONTHS,
            'weekday' => self::WEEKDAYS,
            default => [],
        };

        if (!isset($names[$item])) {
            throw $this->invalid($field, $value);
        }

        return $names[$item];
    }

    protected function invalid(string $field, string $value): InvalidExpressionException
    {
        [$min, $max] = self::FIELDS[$field];

        return new InvalidExpressionException('Invalid {field} field "{value}" in the cron expression "{expression}" (allowed: {min}-{max}, "*", lists, ranges, steps{names}).', 0, null, [
            'field' => $field,
            'value' => $value,
            'expression' => $this->source,
            'min' => $min,
            'max' => $max,
            'names' => $field === 'month' ? ', jan-dec' : ($field === 'weekday' ? ', sun-sat' : ''),
        ]);
    }

    protected static function every(string $interval, string $expression): string
    {
        if (preg_match('/^(\d+)\s*(m|min|minutes?|h|hours?|d|days?)$/', $interval, $matches) !== 1 || (int) $matches[1] < 1) {
            throw new InvalidExpressionException('The interval of "{expression}" is not valid: use @every <n>m, <n>h or 1d.', 0, null, ['expression' => $expression]);
        }

        $count = (int) $matches[1];

        return match ($matches[2][0]) {
            'm' => $count < 60 && 60 % $count === 0 ? ($count === 1 ? '* * * * *' : sprintf('*/%d * * * *', $count)) : ($count % 60 === 0 ? self::every(($count / 60) . 'h', $expression) : throw new InvalidExpressionException('"{expression}": the minutes must divide 60 (1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30) or be a multiple of 60.', 0, null, ['expression' => $expression])),
            'h' => $count < 24 && 24 % $count === 0 ? ($count === 1 ? '0 * * * *' : sprintf('0 */%d * * *', $count)) : ($count === 24 ? '0 0 * * *' : throw new InvalidExpressionException('"{expression}": the hours must divide 24 (1, 2, 3, 4, 6, 8, 12).', 0, null, ['expression' => $expression])),
            default => $count === 1 ? '0 0 * * *' : throw new InvalidExpressionException('"{expression}": only "@every 1d" is supported for days, use a cron expression instead.', 0, null, ['expression' => $expression]),
        };
    }
}