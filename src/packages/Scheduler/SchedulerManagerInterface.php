<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler;

use DateTimeInterface;
use NeoPHP\Component\Container\Exception\ContainerException;
use NeoPHP\Package\Scheduler\Exception\ConfigurationException;
use NeoPHP\Package\Scheduler\Exception\InvalidExpressionException;
use NeoPHP\Package\Scheduler\Exception\TaskNotFoundException;
use NeoPHP\Package\Scheduler\Schedule\Schedule;
use NeoPHP\Package\Scheduler\Schedule\Task;

interface SchedulerManagerInterface
{
    /**
     * Builds a new schedule from the tasks of scheduler.yaml, the #[AsScheduledTask] classes and the schedule providers.
     *
     * @return Schedule The schedule
     * @throws ConfigurationException When a task definition is invalid, a task name is used twice or a provider does not implement ScheduleProviderInterface
     * @throws InvalidExpressionException When a cron expression is invalid
     * @throws ContainerException When a schedule provider cannot be built by the container
     */
    public function getSchedule(): Schedule;

    /**
     * Returns the scheduled tasks, built once.
     *
     * @return array<string, Task> The tasks, by name
     * @throws ConfigurationException When a task definition is invalid, a task name is used twice or a provider does not implement ScheduleProviderInterface
     * @throws InvalidExpressionException When a cron expression is invalid
     */
    public function getTasks(): array;

    /**
     * Returns a scheduled task.
     *
     * @param string $name Name of the task
     * @return Task The task
     * @throws TaskNotFoundException When the task does not exist
     * @throws ConfigurationException When a task definition is invalid
     * @throws InvalidExpressionException When a cron expression is invalid
     */
    public function find(string $name): Task;

    /**
     * Returns the tasks due at a date, in the timezone of each task.
     *
     * @param DateTimeInterface $now The date, usually the current minute
     * @return array<string, Task> The due tasks, by name
     * @throws ConfigurationException When a task definition is invalid
     * @throws InvalidExpressionException When a cron expression is invalid
     */
    public function getDueTasks(DateTimeInterface $now): array;

    /**
     * Forgets the built tasks: they are built again on the next call.
     *
     * @return void
     */
    public function reset(): void;
}