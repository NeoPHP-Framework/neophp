<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Helper\Profiler;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Package\Queue\Contract\QueueInterface;
use NeoPHP\Package\Queue\Provider\QueueProvider;
use NeoPHP\Package\Queue\Trace\QueueTrace;
use NeoPHP\Package\Queue\Transport\SyncTransport;
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

class QueueProfiler extends AbstractProfiler implements ProfilerInterface
{
    public const PRIORITY = 40;

    public function __construct(protected ContainerInterface $container)
    {
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): array
    {
        if (!$this->container->bound(QueueProvider::CONFIG_ID)) {
            return [];
        }

        $messages = [];
        $dropped = 0;

        if ($this->container->resolved(QueueTrace::class)) {
            $trace = $this->container->get(QueueTrace::class);

            if ($trace instanceof QueueTrace) {
                $messages = $trace->getMessages();
                $dropped = $trace->getDropped();
                $trace->reset();
            }
        }

        $config = (array) $this->container->get(QueueProvider::CONFIG_ID);
        $transports = [];

        if ((bool) ($config['profiler_stats'] ?? true)) {
            $transports = $this->stats();
        }

        return [
            'messages' => $messages,
            'dropped' => $dropped,
            'transports' => $transports,
            'default_transport' => (string) ($config['default_transport'] ?? ''),
        ];
    }

    public function getPanel(Profile $profile, array $data): ?Panel
    {
        if (!isset($data['messages'])) {
            return null;
        }

        $messages = (array) $data['messages'];
        $transports = (array) ($data['transports'] ?? []);
        $errors = count(array_filter($messages, static fn (mixed $message): bool => is_array($message) && ($message['error'] ?? null) !== null));
        $sync = count(array_filter($messages, static fn (mixed $message): bool => is_array($message) && (bool) ($message['sync'] ?? false)));
        $failed = array_sum(array_map(static fn (mixed $transport): int => is_array($transport) ? (int) ($transport['failed'] ?? 0) : 0, $transports));
        $blocks = [
            new MetricBlock([
                new Metric('Dispatched', count($messages), null, $errors > 0 ? Status::DANGER : Status::DEFAULT),
                new Metric('Handled sync', $sync),
                new Metric('Sent', count($messages) - $sync),
                new Metric('Failed (all transports)', $failed, null, $failed > 0 ? Status::WARNING : Status::DEFAULT, 'Messages in the failed storage: php bin/neo queue:failed'),
            ]),
        ];

        if ((int) ($data['dropped'] ?? 0) > 0) {
            $blocks[] = new AlertBlock(sprintf('%d message(s) not recorded (limit %d per request).', (int) $data['dropped'], QueueTrace::MAX_MESSAGES), Status::WARNING);
        }

        $blocks[] = new TabsBlock([
            sprintf('Messages (%d)', count($messages)) => [new TableBlock(['#', 'Message', 'Transport', 'Queue', 'Delay', 'Priority', 'Mode', 'Time', 'Error'], array_map(static fn (int $index, mixed $message): array => is_array($message) ? [
                $index + 1,
                (string) ($message['class'] ?? ''),
                (string) ($message['transport'] ?? ''),
                (string) ($message['queue'] ?? ''),
                (int) ($message['delay'] ?? 0) > 0 ? (int) $message['delay'] . ' s' : '',
                (int) ($message['priority'] ?? 0),
                (bool) ($message['sync'] ?? false) ? 'handled sync' : 'sent #' . (string) ($message['id'] ?? ''),
                number_format((float) ($message['duration'] ?? 0), 2) . ' ms',
                (string) ($message['error'] ?? ''),
            ] : [], array_keys($messages), $messages), null, 'No message dispatched during this request.')],
            sprintf('Transports (%d)', count($transports)) => [new TableBlock(['Transport', 'DSN', 'Ready', 'Delayed', 'Reserved', 'Failed', 'Status'], array_map(static fn (string $name, mixed $transport): array => is_array($transport) ? [
                $name . ($name === (string) ($data['default_transport'] ?? '') ? ' (default)' : ''),
                (string) ($transport['dsn'] ?? ''),
                $transport['ready'] ?? '',
                $transport['delayed'] ?? '',
                $transport['reserved'] ?? '',
                $transport['failed'] ?? '',
                (string) ($transport['status'] ?? ''),
            ] : [], array_map('strval', array_keys($transports)), $transports), null, 'No transport.')],
        ]);

        return new Panel('Queue', 'list', $blocks, count($messages) > 0 ? count($messages) : null, $errors > 0 ? Status::DANGER : ($failed > 0 ? Status::WARNING : Status::DEFAULT));
    }

    protected function stats(): array
    {
        $stats = [];

        try {
            $queue = $this->container->get(QueueInterface::class);
        } catch (Throwable $exception) {
            return ['-' => ['status' => $exception->getMessage()]];
        }

        if (!$queue instanceof QueueInterface) {
            return [];
        }

        foreach ($queue->getTransportNames() as $name) {
            $dsn = method_exists($queue, 'getDsn') ? (string) $queue->getDsn($name) : '';
            $dsn = (string) preg_replace('#//([^:@/]*):([^@/]*)@#', '//$1:***@', $dsn);

            try {
                $transport = $queue->transport($name);

                if ($transport instanceof SyncTransport) {
                    $stats[$name] = ['dsn' => $dsn, 'status' => 'synchronous'];
                    continue;
                }

                $stats[$name] = ['dsn' => $dsn, ...$transport->count(), 'status' => 'ok'];
            } catch (Throwable $exception) {
                $stats[$name] = ['dsn' => $dsn, 'status' => 'error: ' . $exception->getMessage()];
            }
        }

        return $stats;
    }
}