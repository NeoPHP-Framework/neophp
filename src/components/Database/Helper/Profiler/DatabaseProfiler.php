<?php

declare(strict_types=1);

namespace NeoPHP\Component\Database\Helper\Profiler;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Database\Contract\QueryLoggerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\WebProfiler\Block\AlertBlock;
use NeoPHP\Package\WebProfiler\Block\MetricBlock;
use NeoPHP\Package\WebProfiler\Block\TableBlock;
use NeoPHP\Package\WebProfiler\Block\TabsBlock;
use NeoPHP\Package\WebProfiler\Contract\AbstractProfiler;
use NeoPHP\Package\WebProfiler\Contract\ProfilerInterface;
use NeoPHP\Package\WebProfiler\Contract\ToolbarInterface;
use NeoPHP\Package\WebProfiler\Model\Metric;
use NeoPHP\Package\WebProfiler\Model\Panel;
use NeoPHP\Package\WebProfiler\Model\Profile;
use NeoPHP\Package\WebProfiler\Model\Status;
use NeoPHP\Package\WebProfiler\Model\ToolbarItem;
use Throwable;

class DatabaseProfiler extends AbstractProfiler implements ToolbarInterface, ProfilerInterface
{
    public const PRIORITY = 80;

    public const SLOW_QUERY_MS = 100;

    public const MANY_QUERIES = 50;

    public function __construct(protected ContainerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        if (!$this->container->bound(QueryLoggerInterface::class) || !$this->container->resolved(QueryLoggerInterface::class)) {
            return [];
        }

        $logger = $this->container->get(QueryLoggerInterface::class);

        if (!$logger instanceof QueryLoggerInterface) {
            return [];
        }

        $queries = $logger->getQueries();
        $count = $logger->count();
        $dropped = $logger->getDropped();
        $time = $logger->getTotalTime();
        $logger->reset();
        $connections = [];
        $groups = [];
        $slow = [];
        $errors = [];

        foreach ($queries as $query) {
            $name = (string) $query['connection'];
            $connections[$name] ??= ['queries' => 0, 'time' => 0.0, 'errors' => 0, 'transactions' => 0];
            $connections[$name]['time'] = round($connections[$name]['time'] + (float) $query['duration'], 3);

            if ($query['type'] === 'transaction') {
                $connections[$name]['transactions']++;
            } else {
                $connections[$name]['queries']++;
                $key = $name . '|' . $this->normalize((string) $query['sql']);
                $groups[$key] ??= ['connection' => $name, 'sql' => (string) $query['sql'], 'count' => 0, 'time' => 0.0, 'indexes' => [], 'identical' => []];
                $groups[$key]['count']++;
                $groups[$key]['time'] += (float) $query['duration'];
                $groups[$key]['indexes'][] = $query['index'];
                $groups[$key]['identical'][md5(serialize($query['params']))] = true;
            }

            if ((float) $query['duration'] > self::SLOW_QUERY_MS) {
                $slow[] = $query['index'];
            }

            if ($query['error'] !== null) {
                $connections[$name]['errors']++;
                $errors[] = $query['index'];
            }
        }

        $duplicates = [];

        foreach ($groups as $group) {
            if ($group['count'] > 1) {
                $group['distinct_params'] = count($group['identical']);
                unset($group['identical']);
                $duplicates[] = $group;
            }
        }

        usort($duplicates, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'queries' => $queries,
            'count' => $count,
            'dropped' => $dropped,
            'time' => round($time, 3),
            'connections' => $connections,
            'duplicates' => $duplicates,
            'slow' => $slow,
            'errors' => $errors,
        ];
    }

    public function getToolbarItem(Profile $profile, array $data): ?ToolbarItem
    {
        if (!isset($data['count'])) {
            return null;
        }

        $count = (int) $data['count'];
        $duplicates = count((array) ($data['duplicates'] ?? []));
        $status = match (true) {
            ($data['errors'] ?? []) !== [] => Status::DANGER,
            $duplicates > 0 || $count > self::MANY_QUERIES => Status::WARNING,
            default => Status::DEFAULT,
        };

        return new ToolbarItem('Database', (string) $count, 'database', $status, [
            'Queries' => $count,
            'Query time' => $this->ms((float) ($data['time'] ?? 0)),
            'Duplicated queries' => $duplicates,
            'Errors' => count((array) ($data['errors'] ?? [])),
            'Connections' => implode(', ', array_keys((array) ($data['connections'] ?? []))) ?: 'none',
        ]);
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        if (!isset($data['count'])) {
            return null;
        }

        $queries = (array) ($data['queries'] ?? []);
        $duplicates = (array) ($data['duplicates'] ?? []);
        $errors = (array) ($data['errors'] ?? []);
        $slow = (array) ($data['slow'] ?? []);
        $connections = (array) ($data['connections'] ?? []);
        $blocks = [
            new MetricBlock([
                new Metric('Queries', (int) $data['count'], null, (int) $data['count'] > self::MANY_QUERIES ? Status::WARNING : Status::DEFAULT),
                new Metric('Query time', round((float) ($data['time'] ?? 0), 2), 'ms'),
                new Metric('Duplicates', count($duplicates), null, $duplicates === [] ? Status::DEFAULT : Status::WARNING, 'Same SQL executed several times: possible N+1.'),
                new Metric('Connections', count($connections)),
            ]),
        ];

        foreach ($errors as $index) {
            $query = $queries[$index] ?? [];
            $blocks[] = new AlertBlock(sprintf('%s — %s', (string) ($query['error'] ?? ''), (string) ($query['sql'] ?? '')), Status::DANGER, sprintf('Query #%d failed', (int) $index));
        }

        if ((int) ($data['dropped'] ?? 0) > 0) {
            $blocks[] = new AlertBlock(sprintf('%d queries were not recorded (limit reached).', (int) $data['dropped']), Status::WARNING);
        }

        $blocks[] = new TabsBlock([
            sprintf('Queries (%d)', count($queries)) => [new TableBlock(['#', 'Time', 'SQL', 'Parameters', 'Rows', 'Caller'], array_map(fn (array $query): array => $this->row($query), $queries), null, 'No query executed.')],
            sprintf('Duplicates (%d)', count($duplicates)) => [new TableBlock(['Count', 'Distinct params', 'Total time', 'Connection', 'SQL', 'Queries'], array_map(fn (array $group): array => [
                $group['count'],
                $group['distinct_params'],
                $this->ms((float) $group['time']),
                $group['connection'],
                $group['sql'],
                '#' . implode(', #', $group['indexes']),
            ], $duplicates), null, 'No duplicated query.')],
            sprintf('Slow (%d)', count($slow)) => [new TableBlock(['#', 'Time', 'SQL', 'Parameters', 'Rows', 'Caller'], array_map(fn (int $index): array => $this->row((array) ($queries[$index] ?? [])), $slow), null, sprintf('No query slower than %d ms.', self::SLOW_QUERY_MS))],
            sprintf('Connections (%d)', count($connections)) => [new TableBlock(['Connection', 'Queries', 'Transactions', 'Time', 'Errors'], array_map(fn (string $name, array $stats): array => [
                $name,
                $stats['queries'],
                $stats['transactions'],
                $this->ms((float) $stats['time']),
                $stats['errors'],
            ], array_keys($connections), $connections), null, 'No connection used.')],
        ]);

        return new Panel('Database', 'database', $blocks);
    }

    protected function row(array $query): array
    {
        return [
            $query['index'] ?? '',
            $this->ms((float) ($query['duration'] ?? 0)),
            ($query['connection'] ?? '') === '' ? (string) ($query['sql'] ?? '') : sprintf('[%s] %s', $query['connection'], $query['sql'] ?? ''),
            ($query['params'] ?? []) === [] ? '' : (string) json_encode($query['params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            $query['rows'] ?? '',
            $query['caller'] ?? '',
        ];
    }

    protected function normalize(string $sql): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $sql)));
    }

    protected function ms(float $value): string
    {
        return number_format($value, 2) . ' ms';
    }
}