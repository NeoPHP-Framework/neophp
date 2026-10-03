<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Contract;

use NeoPHP\Package\Scheduler\Schedule;

interface ScheduleProviderInterface
{
    public function schedule(Schedule $schedule): void;
}