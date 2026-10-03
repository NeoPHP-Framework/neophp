<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Runner;

use DateTimeImmutable;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Package\Queue\Contract\MessageBusInterface;
use NeoPHP\Package\Scheduler\Exception\ConfigurationException;
use NeoPHP\Package\Scheduler\Exception\SchedulerException;
use NeoPHP\Package\Scheduler\History\HistoryStore;
use NeoPHP\Package\Scheduler\Lock\LockStore;
use NeoPHP\Package\Scheduler\Task;
use Throwable;

class TaskRunner
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public function __construct(
        protected LockStore $locks,
        protected HistoryStore $history,
        protected ?ContainerInterface $container = null,
        protected string $rootPath = '',
        protected string $console = 'bin/neo',
        protected ?string $phpBinary = null,
    ) {
    }

    public function run(Task $task): array
    {
        $name = $task->getName();
        $start = microtime(true);
        $record = [
            'name' => $name,
            'type' => $task->getType(),
            'started_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'timestamp' => time(),
            'duration' => 0.0,
            'status' => self::STATUS_SUCCESS,
            'exit_code' => 0,
            'output' => '',
            'error' => null,
        ];

        if ($task->isWithoutOverlapping() && !$this->locks->acquire($name, $task->getOverlapTtl() * 60)) {
            $record['status'] = self::STATUS_SKIPPED;
            $record['error'] = 'The previous run is still in progress (without overlapping).';
            $this->history->append($record);

            return $record;
        }

        try {
            [$code, $output] = $this->execute($task);
            $record['exit_code'] = $code;
            $record['output'] = $output;
            $record['status'] = $code === 0 ? self::STATUS_SUCCESS : self::STATUS_FAILED;
            $record['error'] = $code === 0 ? null : sprintf('Exit code %d', $code);
        } catch (Throwable $exception) {
            $record['status'] = self::STATUS_FAILED;
            $record['exit_code'] = 1;
            $record['error'] = sprintf('%s: %s', $exception::class, $exception->getMessage());
        } finally {
            if ($task->isWithoutOverlapping()) {
                $this->locks->release($name);
            }
        }

        $record['duration'] = round((microtime(true) - $start) * 1000, 2);
        $this->history->append($record);

        return $record;
    }

    public function getHistory(): HistoryStore
    {
        return $this->history;
    }

    public function getLocks(): LockStore
    {
        return $this->locks;
    }

    protected function execute(Task $task): array
    {
        return match ($task->getType()) {
            Task::TYPE_COMMAND => $this->runCommand((string) $task->getTarget(), $task->getArguments()),
            Task::TYPE_MESSAGE => $this->dispatchMessage($task->getTarget()),
            default => $this->runCallable($task),
        };
    }

    protected function runCommand(string $command, string $arguments): array
    {
        $console = preg_match('#^([A-Za-z]:)?[/\\\\]#', $this->console) === 1 ? $this->console : rtrim($this->rootPath, '/\\') . DIRECTORY_SEPARATOR . $this->console;

        if (!is_file($console)) {
            throw new ConfigurationException('The console script "{console}" does not exist (scheduler "console" option).', 0, null, ['console' => $console]);
        }

        if (!function_exists('proc_open')) {
            throw new SchedulerException('proc_open() is disabled: the scheduler cannot run the command "{command}".', 0, null, ['command' => $command]);
        }

        $argv = [$this->phpBinary ?? PHP_BINARY, $console, $command, ...self::tokenize($arguments), '--no-interaction', '--no-ansi'];
        $errors = (string) tempnam(sys_get_temp_dir(), 'neo_task_');
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']], $pipes, $this->rootPath !== '' ? $this->rootPath : null);

        if (!is_resource($process)) {
            @unlink($errors);

            throw new SchedulerException('Unable to start the command "{command}".', 0, null, ['command' => $command]);
        }

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);
        $output .= (string) @file_get_contents($errors);
        @unlink($errors);

        return [$code, $output];
    }

    protected function dispatchMessage(mixed $message): array
    {
        if ($this->container === null || !$this->container->has(MessageBusInterface::class)) {
            throw new ConfigurationException('A scheduled message requires the Queue package (MessageBusInterface).');
        }

        $message = is_object($message) ? $message : new $message();
        $envelope = $this->container->get(MessageBusInterface::class)->dispatch($message);

        return [0, sprintf('%s dispatched to %s/%s (#%s)', $message::class, $envelope->getTransport(), $envelope->getQueue(), (string) $envelope->getId())];
    }

    protected function runCallable(Task $task): array
    {
        $target = $task->getTarget();

        if ($task->getType() === Task::TYPE_CLASS) {
            $instance = $this->container !== null ? $this->container->get((string) $target) : new $target();

            if (!is_object($instance) || !is_callable($instance)) {
                throw new ConfigurationException('The scheduled task class "{class}" must be invokable (__invoke).', 0, null, ['class' => (string) $target]);
            }

            $target = [$instance, '__invoke'];
        }

        if (!is_callable($target)) {
            throw new ConfigurationException('The scheduled task "{name}" is not callable.', 0, null, ['name' => $task->getName()]);
        }

        ob_start();

        try {
            $result = $this->container !== null ? $this->container->call($target) : $target();
        } finally {
            $output = (string) ob_get_clean();
        }

        if (is_string($result) || is_float($result)) {
            $output .= (string) $result;
        }

        return [$result === false ? 1 : (is_int($result) ? $result : 0), $output];
    }

    public static function tokenize(string $arguments): array
    {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\'|(\S+)/', $arguments, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $tokens = [];

        foreach ($matches as $match) {
            $tokens[] = $match[3] ?? $match[2] ?? stripcslashes((string) $match[1]);
        }

        return $tokens;
    }
}