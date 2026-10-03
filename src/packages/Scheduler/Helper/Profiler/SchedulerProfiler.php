<?php

declare(strict_types=1);

namespace NeoPHP\Package\Scheduler\Helper\Profiler;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Scheduler\History\HistoryStore;
use NeoPHP\Package\Scheduler\Provider\SchedulerProvider;
use NeoPHP\Package\Scheduler\Scheduler;
use NeoPHP\Package\WebProfiler\Block\AlertBlock;
use NeoPHP\Package\WebProfiler\Block\MetricBlock;
use NeoPHP\Package\WebProfiler\Block\TableBlock;
use NeoPHP\Package\WebProfiler\Block\TabsBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Model\Metric;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\Status;
use Throwable;

class SchedulerProfiler extends AbstractProfiler implements ProfilerInterface
{
    public const PRIORITY = 35;

    public const HEARTBEAT_DELAY = 120;

    public const RECENT_RUNS = 20;

    public function __construct(protected ContainerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        if (!$this->container->bound(SchedulerProvider::CONFIG_ID)) {
            return [];
        }

        $history = $this->container->get(HistoryStore::class);

        if (!$history instanceof HistoryStore) {
            return [];
        }

        $last = $history->lastRuns();
        $tasks = [];
        $error = null;

        try {
            $scheduler = $this->container->get(Scheduler::class);

            foreach ($scheduler instanceof Scheduler ? $scheduler->getTasks() : [] as $name => $task) {
                try {
                    $next = $task->getCron()->getNextRunDate()->format('Y-m-d H:i T');
                } catch (Throwable $exception) {
                    $next = 'error: ' . $exception->getMessage();
                }

                $run = $last[$name] ?? null;
                $tasks[] = [
                    'name' => (string) $name,
                    'type' => $task->getType(),
                    'target' => $task->getTargetLabel(),
                    'cron' => $task->getExpression(),
                    'timezone' => $task->getTimezone(),
                    'next' => $next,
                    'source' => $task->getSource(),
                    'overlap' => $task->isWithoutOverlapping(),
                    'last_status' => $run !== null ? (string) ($run['status'] ?? '') : null,
                    'last_time' => $run !== null ? (int) ($run['timestamp'] ?? 0) : null,
                    'last_duration' => $run !== null ? (float) ($run['duration'] ?? 0) : null,
                    'last_error' => $run !== null && isset($run['error']) ? (string) $run['error'] : null,
                ];
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        return [
            'tasks' => $tasks,
            'recent' => $history->recent(self::RECENT_RUNS),
            'heartbeat' => $history->getLastBeat(),
            'now' => time(),
            'error' => $error,
        ];
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        if (!isset($data['tasks'])) {
            return null;
        }

        $tasks = (array) $data['tasks'];
        $recent = (array) ($data['recent'] ?? []);
        $heartbeat = isset($data['heartbeat']) ? (int) $data['heartbeat'] : null;
        $running = $heartbeat !== null && (int) ($data['now'] ?? time()) - $heartbeat <= self::HEARTBEAT_DELAY;
        $failed = array_values(array_filter($recent, static fn (mixed $run): bool => is_array($run) && ($run['status'] ?? null) === 'failed'));
        $blocks = [
            new MetricBlock([
                new Metric('Tasks', count($tasks)),
                new Metric('Recent failures', count($failed), null, $failed !== [] ? Status::DANGER : Status::DEFAULT),
                new Metric('schedule:run', $running ? 'running' : 'not running', null, $running ? Status::SUCCESS : Status::WARNING, $heartbeat !== null ? 'Last run: ' . date('Y-m-d H:i:s', $heartbeat) : 'Never ran'),
            ]),
        ];

        if (!$running && $tasks !== []) {
            $blocks[] = new AlertBlock('schedule:run did not run in the last 2 minutes: the system cron is not configured. Add "* * * * * cd /path/to/project && php bin/neo schedule:run" to the crontab, use the Windows Task Scheduler, or run "php bin/neo schedule:work" in development.', Status::WARNING, 'Scheduler not running');
        }

        if (($data['error'] ?? null) !== null) {
            $blocks[] = new AlertBlock((string) $data['error'], Status::DANGER, 'Unable to load the schedule');
        }

        $blocks[] = new TabsBlock([
            sprintf('Tasks (%d)', count($tasks)) => [new TableBlock(['Task', 'Type', 'Cron', 'Next run', 'Last run', 'Status', 'Source'], array_map(static fn (mixed $task): array => is_array($task) ? [
                (string) $task['name'] . ((bool) ($task['overlap'] ?? false) ? ' (no overlap)' : ''),
                (string) $task['type'] . ': ' . (string) $task['target'],
                (string) $task['cron'] . ' ' . (string) $task['timezone'],
                (string) $task['next'],
                $task['last_time'] !== null ? date('Y-m-d H:i:s', (int) $task['last_time']) . ' (' . number_format((float) $task['last_duration'], 0) . ' ms)' : 'never',
                (string) ($task['last_status'] ?? '') . ($task['last_error'] !== null ? ': ' . (string) $task['last_error'] : ''),
                (string) $task['source'],
            ] : [], $tasks), null, 'No scheduled task.')],
            sprintf('Recent runs (%d)', count($recent)) => [new TableBlock(['Started', 'Task', 'Status', 'Duration', 'Error', 'Output'], array_map(static fn (mixed $run): array => is_array($run) ? [
                (string) ($run['started_at'] ?? ''),
                (string) ($run['name'] ?? ''),
                (string) ($run['status'] ?? ''),
                number_format((float) ($run['duration'] ?? 0), 2) . ' ms',
                (string) ($run['error'] ?? ''),
                mb_substr(trim((string) ($run['output'] ?? '')), -300),
            ] : [], $recent), null, 'No run recorded.')],
            sprintf('Failures (%d)', count($failed)) => [new TableBlock(['Started', 'Task', 'Error'], array_map(static fn (array $run): array => [
                (string) ($run['started_at'] ?? ''),
                (string) ($run['name'] ?? ''),
                (string) ($run['error'] ?? ''),
            ], $failed), null, 'No recent failure.')],
        ]);

        $status = $failed !== [] ? Status::DANGER : (!$running && $tasks !== [] ? Status::WARNING : Status::DEFAULT);

        return new Panel('Scheduler', 'time', $blocks, $tasks !== [] ? count($tasks) : null, $status);
    }
}