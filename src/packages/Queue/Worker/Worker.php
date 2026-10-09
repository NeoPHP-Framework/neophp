<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Worker;

use NeoPHP\Component\Event\EventManagerInterface;
use NeoPHP\Package\Queue\Contract\TransportInterface;
use NeoPHP\Package\Queue\Contract\UnrecoverableExceptionInterface;
use NeoPHP\Package\Queue\Event\WorkerMessageFailedEvent;
use NeoPHP\Package\Queue\Event\WorkerMessageHandledEvent;
use NeoPHP\Package\Queue\Event\WorkerMessageReceivedEvent;
use NeoPHP\Package\Queue\Message\Envelope;
use NeoPHP\Package\Queue\QueueManagerInterface;
use NeoPHP\Package\Queue\Retry\RetryStrategy;
use Throwable;

class Worker
{
    public const DEFAULTS = [
        'queues' => [],
        'limit' => 0,
        'time_limit' => 0,
        'memory_limit' => 128,
        'sleep' => 1.0,
        'stop_when_empty' => false,
        'once' => false,
    ];

    public const STATUS_HANDLED = 'handled';

    public const STATUS_RETRY = 'retry';

    public const STATUS_FAILED = 'failed';

    protected bool $shouldStop = false;

    protected string $stopReason = '';

    protected array $stats = ['handled' => 0, 'retried' => 0, 'failed' => 0];

    public function __construct(
        protected QueueManagerInterface $queue,
        protected RetryStrategy $retry,
        protected ?RestartSignal $restart = null,
        protected ?EventManagerInterface $events = null,
    ) {
    }

    public function run(TransportInterface $transport, array $options = [], ?callable $listener = null): array
    {
        $options = [...self::DEFAULTS, ...$options];
        $start = microtime(true);
        $this->shouldStop = false;
        $this->stopReason = '';
        $this->stats = ['handled' => 0, 'retried' => 0, 'failed' => 0];
        $this->registerSignals();
        $queues = array_values(array_filter(array_map('strval', (array) $options['queues'])));

        while (!$this->shouldStop) {
            $envelopes = $transport->get($queues, 1);

            if ($envelopes === []) {
                if ($options['stop_when_empty'] || $options['once']) {
                    $this->stop('empty');
                    break;
                }

                $this->sleep((float) $options['sleep']);
            }

            foreach ($envelopes as $envelope) {
                $this->process($transport, $envelope, $listener);
            }

            $processed = $this->stats['handled'] + $this->stats['retried'] + $this->stats['failed'];

            if ($options['once'] && $processed > 0) {
                $this->stop('once');
            } elseif ((int) $options['limit'] > 0 && $processed >= (int) $options['limit']) {
                $this->stop('limit');
            } elseif ((int) $options['time_limit'] > 0 && microtime(true) - $start >= (int) $options['time_limit']) {
                $this->stop('time limit');
            } elseif ((int) $options['memory_limit'] > 0 && memory_get_usage(true) >= (int) $options['memory_limit'] * 1024 * 1024) {
                $this->stop('memory limit');
            } elseif ($this->restart !== null && ($signal = $this->restart->get()) !== null && $signal >= $start) {
                $this->stop('restart signal');
            }
        }

        return [...$this->stats, 'reason' => $this->stopReason, 'duration' => round(microtime(true) - $start, 3)];
    }

    public function process(TransportInterface $transport, Envelope $envelope, ?callable $listener = null): string
    {
        $start = microtime(true);
        $this->events?->dispatch(new WorkerMessageReceivedEvent($envelope));

        try {
            $error = $envelope->getMeta('decode_error');

            if ($error instanceof Throwable) {
                throw $error;
            }

            $this->queue->handle($envelope);
        } catch (Throwable $exception) {
            $duration = round((microtime(true) - $start) * 1000, 2);
            $message = sprintf('%s: %s', $exception::class, $exception->getMessage());
            $retry = !$exception instanceof UnrecoverableExceptionInterface && $envelope->hasAttemptsLeft();
            $delay = $retry ? $this->retry->getDelay($envelope->getAttempts()) : 0;

            if ($retry) {
                $transport->release($envelope, $delay, $message);
                $this->stats['retried']++;
            } else {
                $transport->reject($envelope, $message);
                $this->stats['failed']++;
            }

            $envelope->setLastError($message);
            $this->events?->dispatch(new WorkerMessageFailedEvent($envelope, $exception, $retry, $delay));
            $status = $retry ? self::STATUS_RETRY : self::STATUS_FAILED;

            if ($listener !== null) {
                $listener($status, $envelope, $duration, $exception, $delay);
            }

            return $status;
        }

        $transport->ack($envelope);
        $duration = round((microtime(true) - $start) * 1000, 2);
        $this->stats['handled']++;
        $this->events?->dispatch(new WorkerMessageHandledEvent($envelope, $duration));

        if ($listener !== null) {
            $listener(self::STATUS_HANDLED, $envelope, $duration, null, 0);
        }

        return self::STATUS_HANDLED;
    }

    public function stop(string $reason = 'stopped'): void
    {
        $this->shouldStop = true;
        $this->stopReason = $this->stopReason === '' ? $reason : $this->stopReason;
    }

    public function isStopping(): bool
    {
        return $this->shouldStop;
    }

    protected function sleep(float $seconds): void
    {
        $end = microtime(true) + max(0.0, $seconds);

        while (!$this->shouldStop && microtime(true) < $end) {
            usleep((int) min(200000, max(0.0, $end - microtime(true)) * 1000000));

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    protected function registerSignals(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, function () use ($signal): void {
                $this->stop($signal === SIGINT ? 'interrupted' : 'terminated');
            });
        }
    }
}