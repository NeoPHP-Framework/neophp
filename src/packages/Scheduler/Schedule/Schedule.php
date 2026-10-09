<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Schedule;

use NeoPHP\Package\Scheduler\Exception\ConfigurationException;

class Schedule
{
    protected array $tasks = [];

    public function command(string $command, string $arguments = ''): Task
    {
        $command = trim($command);

        if ($arguments === '' && str_contains($command, ' ')) {
            [$command, $arguments] = explode(' ', $command, 2);
        }

        return $this->add(new Task(Task::TYPE_COMMAND, $command, trim($arguments)));
    }

    public function call(callable $callback): Task
    {
        return $this->add(new Task(Task::TYPE_CALL, $callback));
    }

    public function job(object|string $message): Task
    {
        return $this->add(new Task(Task::TYPE_MESSAGE, $message));
    }

    public function invoke(string $class): Task
    {
        return $this->add(new Task(Task::TYPE_CLASS, ltrim($class, '\\')));
    }

    public function add(Task $task): Task
    {
        $this->tasks[] = $task;

        return $task;
    }

    public function getTasks(): array
    {
        $tasks = [];

        foreach ($this->tasks as $task) {
            $name = $task->getName();

            if (isset($tasks[$name])) {
                throw new ConfigurationException('Two scheduled tasks are named "{name}": give them a distinct name with ->name().', 0, null, ['name' => $name]);
            }

            $tasks[$name] = $task;
        }

        return $tasks;
    }
}