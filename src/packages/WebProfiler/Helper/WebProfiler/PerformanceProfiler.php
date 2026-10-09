<?php

declare(strict_types=1);

namespace NeoPHP\Package\WebProfiler\Helper\WebProfiler;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\WebProfiler\Block\MetricBlock;
use NeoPHP\Package\WebProfiler\Block\TableBlock;
use NeoPHP\Package\WebProfiler\Block\TimelineBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Contract\ToolbarInterface;
use NeoPHP\Package\WebProfiler\Model\Metric;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\Status;
use NeoPHP\Package\WebProfiler\Model\ToolbarItem;
use NeoPHP\Package\WebProfiler\Stopwatch\Stopwatch;
use NeoPHP\Package\WebProfiler\Util\ValueExporter;
use Throwable;

/**
 * @internal
 */
class PerformanceProfiler extends AbstractProfiler implements ToolbarInterface, ProfilerInterface
{
    public const PRIORITY = 200;

    public const SLOW = 500.0;

    public const VERY_SLOW = 1500.0;

    public function __construct(protected Stopwatch $stopwatch)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        $limit = $this->memoryLimit();

        return [
            'duration' => round($this->stopwatch->getElapsed(), 2),
            'memory' => memory_get_peak_usage(true),
            'memory_limit' => $limit,
            'events' => $this->stopwatch->toArray(),
            'included_files' => count(get_included_files()),
        ];
    }

    public function getToolbarItem(Profile $profile, array $data): ?ToolbarItem
    {
        $duration = (float) ($data['duration'] ?? $profile->getDuration());
        $memory = (int) ($data['memory'] ?? $profile->getMemory());

        return new ToolbarItem('Time', ValueExporter::formatDuration($duration), 'time', $this->durationStatus($duration), [
            'Total time' => ValueExporter::formatDuration($duration),
            'Peak memory' => ValueExporter::formatBytes($memory),
            'Memory limit' => ($data['memory_limit'] ?? -1) < 0 ? 'unlimited' : ValueExporter::formatBytes((int) $data['memory_limit']),
            'Included files' => (int) ($data['included_files'] ?? 0),
        ]);
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        $duration = (float) ($data['duration'] ?? $profile->getDuration());
        $memory = (int) ($data['memory'] ?? $profile->getMemory());
        $limit = (int) ($data['memory_limit'] ?? -1);
        $events = (array) ($data['events'] ?? []);
        $rows = array_map(static fn (array $event): array => [
            $event['name'] ?? '',
            $event['category'] ?? '',
            ValueExporter::formatDuration((float) ($event['start'] ?? 0)),
            ValueExporter::formatDuration((float) ($event['duration'] ?? 0)),
            ValueExporter::formatBytes((int) ($event['memory'] ?? 0)),
        ], $events);

        return new Panel('Performance', 'time', [
            new MetricBlock([
                new Metric('Total time', round($duration, 1), 'ms', $this->durationStatus($duration)),
                new Metric('Peak memory', round($memory / 1048576, 1), 'MiB', $limit > 0 && $memory > $limit * 0.8 ? Status::WARNING : Status::DEFAULT),
                new Metric('Memory limit', $limit < 0 ? 'unlimited' : round($limit / 1048576), $limit < 0 ? null : 'MiB'),
                new Metric('Included files', (int) ($data['included_files'] ?? 0)),
            ]),
            new TimelineBlock($events, $duration, 'Timeline'),
            new TableBlock(['Event', 'Category', 'Start', 'Duration', 'Memory'], $rows, 'Events', 'No stopwatch event.'),
        ], ValueExporter::formatDuration($duration), $this->durationStatus($duration));
    }

    protected function durationStatus(float $duration): string
    {
        return match (true) {
            $duration >= self::VERY_SLOW => Status::DANGER,
            $duration >= self::SLOW => Status::WARNING,
            default => Status::SUCCESS,
        };
    }

    protected function memoryLimit(): int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}