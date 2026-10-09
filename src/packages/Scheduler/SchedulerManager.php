<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler;

use DateTimeInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Kernel\Attribute\Package;
use NeoPHP\Package\Scheduler\Contract\ScheduleProviderInterface;
use NeoPHP\Package\Scheduler\Exception\ConfigurationException;
use NeoPHP\Package\Scheduler\Exception\TaskNotFoundException;
use NeoPHP\Package\Scheduler\Provider\SchedulerProvider;
use NeoPHP\Package\Scheduler\Schedule\Schedule;
use NeoPHP\Package\Scheduler\Schedule\Task;

#[Package(provider: SchedulerProvider::class)]
final class SchedulerManager implements SchedulerManagerInterface
{
    protected ?array $tasks = null;

    public function __construct(
        protected array $configTasks = [],
        protected array $discovered = [],
        protected array $providers = [],
        protected ?ContainerManagerInterface $container = null,
        protected ?string $timezone = null,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();

        foreach ($this->configTasks as $index => $definition) {
            if (!filter_var($definition['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $this->define($schedule, (array) $definition, 'config', (string) $index);
        }

        foreach ($this->discovered as $definition) {
            $this->define($schedule, (array) $definition, 'attribute', '');
        }

        foreach (array_unique($this->providers) as $class) {
            $provider = $this->container !== null ? $this->container->get((string) $class) : new $class();

            if (!$provider instanceof ScheduleProviderInterface) {
                throw new ConfigurationException('The schedule provider "{class}" must implement {interface}.', 0, null, ['class' => $class, 'interface' => ScheduleProviderInterface::class]);
            }

            $before = count($schedule->getTasks());
            $provider->schedule($schedule);
            $tasks = array_values($schedule->getTasks());

            for ($i = $before; $i < count($tasks); $i++) {
                if ($tasks[$i]->getSource() === 'schedule') {
                    $tasks[$i]->setSource('provider');
                }
            }
        }

        if ($this->timezone !== null && $this->timezone !== '') {
            foreach ($schedule->getTasks() as $task) {
                if (!$task->hasTimezone()) {
                    $task->timezone($this->timezone);
                }
            }
        }

        return $schedule;
    }

    public function getTasks(): array
    {
        return $this->tasks ??= $this->getSchedule()->getTasks();
    }

    public function find(string $name): Task
    {
        $tasks = $this->getTasks();

        if (!isset($tasks[$name])) {
            throw new TaskNotFoundException('The scheduled task "{name}" does not exist (see php bin/neo schedule:list).', 0, null, ['name' => $name]);
        }

        return $tasks[$name];
    }

    public function getDueTasks(DateTimeInterface $now): array
    {
        return array_filter($this->getTasks(), static fn (Task $task): bool => $task->isDue($now));
    }

    public function reset(): void
    {
        $this->tasks = null;
    }

    protected function define(Schedule $schedule, array $definition, string $source, string $index): Task
    {
        $task = match (true) {
            isset($definition['command']) => $schedule->command((string) $definition['command'], (string) ($definition['arguments'] ?? '')),
            isset($definition['message']) => $schedule->job((string) $definition['message']),
            isset($definition['class']) => $schedule->invoke((string) $definition['class']),
            ($definition['type'] ?? null) === Task::TYPE_COMMAND => $schedule->command((string) $definition['target'], (string) ($definition['arguments'] ?? '')),
            ($definition['type'] ?? null) === Task::TYPE_CLASS => $schedule->invoke((string) $definition['target']),
            default => throw new ConfigurationException('The scheduled task #{index} must define "command", "message" or "class".', 0, null, ['index' => $index]),
        };

        $task->setSource($source);

        if (isset($definition['timezone']) && $definition['timezone'] !== '') {
            $task->timezone((string) $definition['timezone']);
        }

        if (isset($definition['frequency']) && $definition['frequency'] !== '') {
            $frequency = (string) $definition['frequency'];

            if (!in_array($frequency, Task::FREQUENCIES, true)) {
                throw new ConfigurationException('The frequency "{frequency}" of the scheduled task #{index} is not valid (allowed: {allowed}).', 0, null, [
                    'frequency' => $frequency,
                    'index' => $index,
                    'allowed' => implode(', ', Task::FREQUENCIES),
                ]);
            }

            $task->{$frequency}();

            if (isset($definition['at']) && $definition['at'] !== '') {
                [$hour, $minute] = array_map('intval', explode(':', (string) $definition['at'], 2) + [1 => 0]);
                $task->cron(sprintf('%d %d %s', $minute, $hour, implode(' ', array_slice(explode(' ', $task->getExpression()), 2))));
            }
        } else {
            $task->cron((string) ($definition['cron'] ?? '* * * * *'));
        }

        if (isset($definition['name']) && $definition['name'] !== '') {
            $task->name((string) $definition['name']);
        }

        if (isset($definition['description']) && $definition['description'] !== '') {
            $task->description((string) $definition['description']);
        }

        $overlap = $definition['without_overlapping'] ?? false;

        if ($overlap === true || (is_numeric($overlap) && (int) $overlap > 0)) {
            $task->withoutOverlapping($overlap === true ? (int) ($definition['overlap_ttl'] ?? 1440) : (int) $overlap);
        }

        return $task;
    }
}