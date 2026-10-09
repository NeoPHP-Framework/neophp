<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Schedule;

use Closure;
use DateTimeInterface;
use DateTimeZone;
use NeoPHP\Package\Scheduler\Cron\CronExpression;
use NeoPHP\Package\Scheduler\Exception\ConfigurationException;
use ReflectionFunction;

class Task
{
    public const TYPE_COMMAND = 'command';

    public const TYPE_CALL = 'call';

    public const TYPE_CLASS = 'class';

    public const TYPE_MESSAGE = 'message';

    public const FREQUENCIES = [
        'everyMinute', 'everyTwoMinutes', 'everyFiveMinutes', 'everyTenMinutes', 'everyFifteenMinutes', 'everyThirtyMinutes',
        'hourly', 'everyTwoHours', 'everySixHours', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'weekdays', 'weekends',
    ];

    protected array $fields = ['*', '*', '*', '*', '*'];

    protected ?string $timezone = null;

    protected ?string $name = null;

    protected string $description = '';

    protected bool $withoutOverlapping = false;

    protected int $overlapTtl = 1440;

    protected array $filters = [];

    protected string $source = 'schedule';

    protected ?CronExpression $cron = null;

    public function __construct(protected string $type, protected mixed $target, protected string $arguments = '')
    {
        if (!in_array($type, [self::TYPE_COMMAND, self::TYPE_CALL, self::TYPE_CLASS, self::TYPE_MESSAGE], true)) {
            throw new ConfigurationException('The task type "{type}" is not valid.', 0, null, ['type' => $type]);
        }
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTarget(): mixed
    {
        return $this->target;
    }

    public function getArguments(): string
    {
        return $this->arguments;
    }

    public function cron(string $expression): static
    {
        $cron = new CronExpression($expression, $this->timezone);
        $this->fields = preg_split('/\s+/', $cron->getExpression()) ?: [];

        return $this->reset();
    }

    public function getExpression(): string
    {
        return implode(' ', $this->fields);
    }

    public function getCron(): CronExpression
    {
        return $this->cron ??= new CronExpression($this->getExpression(), $this->timezone);
    }

    public function everyMinute(): static
    {
        return $this->spliceInto(0, '*');
    }

    public function everyTwoMinutes(): static
    {
        return $this->spliceInto(0, '*/2');
    }

    public function everyFiveMinutes(): static
    {
        return $this->spliceInto(0, '*/5');
    }

    public function everyTenMinutes(): static
    {
        return $this->spliceInto(0, '*/10');
    }

    public function everyFifteenMinutes(): static
    {
        return $this->spliceInto(0, '*/15');
    }

    public function everyThirtyMinutes(): static
    {
        return $this->spliceInto(0, '0,30');
    }

    public function hourly(): static
    {
        return $this->spliceInto(0, '0');
    }

    public function hourlyAt(int $minute): static
    {
        return $this->spliceInto(0, (string) $minute);
    }

    public function everyTwoHours(): static
    {
        return $this->spliceInto(0, '0')->spliceInto(1, '*/2');
    }

    public function everySixHours(): static
    {
        return $this->spliceInto(0, '0')->spliceInto(1, '*/6');
    }

    public function daily(): static
    {
        return $this->spliceInto(0, '0')->spliceInto(1, '0');
    }

    public function dailyAt(string $time): static
    {
        [$hour, $minute] = $this->parseTime($time);

        return $this->spliceInto(0, (string) $minute)->spliceInto(1, (string) $hour);
    }

    public function twiceDaily(int $first = 1, int $second = 13): static
    {
        return $this->spliceInto(0, '0')->spliceInto(1, $first . ',' . $second);
    }

    public function weekly(): static
    {
        return $this->spliceInto(0, '0')->spliceInto(1, '0')->spliceInto(4, '0');
    }

    public function weeklyOn(int|string $day, string $time = '00:00'): static
    {
        return $this->dailyAt($time)->spliceInto(4, (string) $day);
    }

    public function monthly(): static
    {
        return $this->spliceInto(0, '0')->spliceInto(1, '0')->spliceInto(2, '1');
    }

    public function monthlyOn(int $day = 1, string $time = '00:00'): static
    {
        return $this->dailyAt($time)->spliceInto(2, (string) $day);
    }

    public function quarterly(): static
    {
        return $this->spliceInto(0, '0')->spliceInto(1, '0')->spliceInto(2, '1')->spliceInto(3, '1,4,7,10');
    }

    public function yearly(): static
    {
        return $this->spliceInto(0, '0')->spliceInto(1, '0')->spliceInto(2, '1')->spliceInto(3, '1');
    }

    public function at(string $time): static
    {
        return $this->dailyAt($time);
    }

    public function weekdays(): static
    {
        return $this->spliceInto(4, '1-5');
    }

    public function weekends(): static
    {
        return $this->spliceInto(4, '0,6');
    }

    public function days(int|string ...$days): static
    {
        return $this->spliceInto(4, implode(',', array_map('strval', $days)));
    }

    public function between(string $start, string $end): static
    {
        $this->filters[] = function (DateTimeInterface $now) use ($start, $end): bool {
            $time = $now->format('H:i');
            [$from, $to] = [sprintf('%02d:%02d', ...$this->parseTime($start)), sprintf('%02d:%02d', ...$this->parseTime($end))];

            return $from <= $to ? $time >= $from && $time <= $to : $time >= $from || $time <= $to;
        };

        return $this;
    }

    public function timezone(DateTimeZone|string $timezone): static
    {
        $this->timezone = $timezone instanceof DateTimeZone ? $timezone->getName() : $timezone;

        return $this->reset();
    }

    public function hasTimezone(): bool
    {
        return $this->timezone !== null;
    }

    public function getTimezone(): string
    {
        return $this->timezone ?? date_default_timezone_get();
    }

    public function withoutOverlapping(int $ttlMinutes = 1440): static
    {
        $this->withoutOverlapping = true;
        $this->overlapTtl = max(1, $ttlMinutes);

        return $this;
    }

    public function isWithoutOverlapping(): bool
    {
        return $this->withoutOverlapping;
    }

    public function getOverlapTtl(): int
    {
        return $this->overlapTtl;
    }

    public function when(callable $callback): static
    {
        $this->filters[] = $callback instanceof Closure ? $callback : Closure::fromCallable($callback);

        return $this;
    }

    public function skip(callable $callback): static
    {
        $this->filters[] = static fn (DateTimeInterface $now): bool => !(bool) $callback($now);

        return $this;
    }

    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): string
    {
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        return match ($this->type) {
            self::TYPE_COMMAND => trim((string) $this->target . ' ' . $this->arguments),
            self::TYPE_CALL => $this->closureName(),
            self::TYPE_MESSAGE => 'message:' . (is_object($this->target) ? $this->target::class : (string) $this->target),
            default => (string) $this->target,
        };
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function isDue(DateTimeInterface $now): bool
    {
        if (!$this->getCron()->isDue($now)) {
            return false;
        }

        return $this->filtersPass($now);
    }

    public function filtersPass(DateTimeInterface $now): bool
    {
        foreach ($this->filters as $filter) {
            if (!(bool) $filter($now)) {
                return false;
            }
        }

        return true;
    }

    public function getTargetLabel(): string
    {
        return match ($this->type) {
            self::TYPE_COMMAND => 'php bin/neo ' . trim((string) $this->target . ' ' . $this->arguments),
            self::TYPE_CALL => 'closure',
            self::TYPE_MESSAGE => is_object($this->target) ? $this->target::class : (string) $this->target,
            default => (string) $this->target,
        };
    }

    protected function spliceInto(int $position, string $value): static
    {
        $this->fields[$position] = $value;

        return $this->reset();
    }

    protected function reset(): static
    {
        $this->cron = null;

        return $this;
    }

    protected function parseTime(string $time): array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $matches) !== 1 || (int) $matches[1] > 23 || (int) $matches[2] > 59) {
            throw new ConfigurationException('The time "{time}" is not valid: use "HH:MM".', 0, null, ['time' => $time]);
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    protected function closureName(): string
    {
        if ($this->target instanceof Closure) {
            $reflection = new ReflectionFunction($this->target);

            return sprintf('closure@%s:%d', basename((string) $reflection->getFileName()), (int) $reflection->getStartLine());
        }

        return is_string($this->target) ? $this->target : 'callable';
    }
}