<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Discovery;

use NeoPHP\Component\Kernel\Discovery\ClassFinder;
use NeoPHP\Package\Scheduler\Attribute\AsScheduledTask;
use NeoPHP\Package\Scheduler\Contract\ScheduleProviderInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use ReflectionClass;

class TaskDiscovery
{
    protected ClassFinder $finder;

    public function __construct(protected array $paths = [])
    {
        $this->finder = new ClassFinder();
    }

    public function discover(): array
    {
        $tasks = [];
        $providers = [];

        foreach ($this->paths as $path) {
            foreach ($this->finder->find((string) $path, ['AsScheduledTask', 'ScheduleProviderInterface']) as $class) {
                if (!class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if (!$reflection->isInstantiable()) {
                    continue;
                }

                if ($reflection->implementsInterface(ScheduleProviderInterface::class)) {
                    $providers[] = $class;
                }

                $command = $reflection->getAttributes(AsCommand::class);

                foreach ($reflection->getAttributes(AsScheduledTask::class) as $attribute) {
                    $task = $attribute->newInstance();
                    $tasks[] = [
                        'type' => $command !== [] ? 'command' : 'class',
                        'target' => $command !== [] ? $command[0]->newInstance()->name : $class,
                        'arguments' => $task->arguments,
                        'cron' => $task->cron,
                        'name' => $task->name,
                        'timezone' => $task->timezone,
                        'without_overlapping' => $task->withoutOverlapping,
                        'overlap_ttl' => $task->overlapTtl,
                        'description' => $task->description,
                    ];
                }
            }
        }

        return ['tasks' => $tasks, 'providers' => $providers];
    }

    public function getResources(): array
    {
        return $this->finder->getResources();
    }
}