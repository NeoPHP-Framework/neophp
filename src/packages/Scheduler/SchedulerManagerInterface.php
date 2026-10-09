<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler;

use DateTimeInterface;
use NeoPHP\Package\Scheduler\Schedule\Schedule;
use NeoPHP\Package\Scheduler\Schedule\Task;

interface SchedulerManagerInterface
{
    public function getSchedule(): Schedule;

    public function getTasks(): array;

    public function find(string $name): Task;

    public function getDueTasks(DateTimeInterface $now): array;

    public function reset(): void;
}