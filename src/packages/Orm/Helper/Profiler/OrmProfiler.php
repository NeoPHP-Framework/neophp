<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Helper\Profiler;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Orm\Contract\OrmInterface;
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

class OrmProfiler extends AbstractProfiler implements ToolbarInterface, ProfilerInterface
{
    public const PRIORITY = 75;

    public function __construct(protected ContainerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        if (!$this->container->bound(OrmInterface::class) || !$this->container->resolved(OrmInterface::class)) {
            return [];
        }

        $orm = $this->container->get(OrmInterface::class);

        if (!$orm instanceof OrmInterface) {
            return [];
        }

        $statistics = $orm->getUnitOfWork()->getStatistics();
        $managed = (array) $statistics['managed'];
        arsort($managed);

        return [
            'managed' => $managed,
            'managed_count' => array_sum($managed),
            'scheduled_inserts' => $statistics['scheduled_inserts'],
            'scheduled_removals' => $statistics['scheduled_removals'],
            'flush_count' => $statistics['flush_count'],
            'flushes' => $statistics['flushes'],
            'initialized_proxies' => $statistics['initialized_proxies'],
        ];
    }

    public function getToolbarItem(Profile $profile, array $data): ?ToolbarItem
    {
        $count = (int) ($data['managed_count'] ?? 0);

        if ($count === 0 && (int) ($data['flush_count'] ?? 0) === 0) {
            return null;
        }

        return new ToolbarItem('ORM', (string) $count, 'database', Status::DEFAULT, [
            'Managed entities' => $count,
            'Entity classes' => count((array) ($data['managed'] ?? [])),
            'Flushes' => (int) ($data['flush_count'] ?? 0),
            'Proxies initialized' => (int) ($data['initialized_proxies'] ?? 0),
        ]);
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        if (!isset($data['managed'])) {
            return null;
        }

        $managed = (array) $data['managed'];
        $flushes = (array) ($data['flushes'] ?? []);

        return new Panel('ORM', 'database', [
            new MetricBlock([
                new Metric('Managed entities', (int) ($data['managed_count'] ?? 0)),
                new Metric('Entity classes', count($managed)),
                new Metric('Flushes', (int) ($data['flush_count'] ?? 0)),
                new Metric('Proxies initialized', (int) ($data['initialized_proxies'] ?? 0), null, Status::DEFAULT, 'Lazy references loaded from the database.'),
            ]),
            new TabsBlock([
                sprintf('Entities (%d)', count($managed)) => [new TableBlock(['Class', 'Managed'], array_map(static fn (string $class, int $count): array => [$class, $count], array_keys($managed), $managed), null, 'No managed entity.')],
                sprintf('Flushes (%d)', count($flushes)) => [new TableBlock(['#', 'Inserts', 'Updates', 'Deletes', 'Collections', 'Time'], array_map(static fn (int $index, array $flush): array => [
                    $index + 1,
                    $flush['inserts'],
                    $flush['updates'],
                    $flush['deletes'],
                    $flush['collections'],
                    number_format((float) $flush['duration'], 2) . ' ms',
                ], array_keys($flushes), $flushes), null, 'No flush.')],
                'Unit of work' => [new TableBlock(['Scheduled', 'Count'], [
                    ['Pending inserts', (int) ($data['scheduled_inserts'] ?? 0)],
                    ['Pending removals', (int) ($data['scheduled_removals'] ?? 0)],
                ])],
            ]),
        ]);
    }
}