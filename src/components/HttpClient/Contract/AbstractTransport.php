<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Contract;

use NeoPHP\Component\HttpClient\Exception\TransportException;
use NeoPHP\Component\HttpClient\Request\Request;
use NeoPHP\Component\HttpClient\Response\Response;
use Stringable;

abstract class AbstractTransport implements TransportInterface, Stringable
{
    public function sendMany(array $requests): array
    {
        $results = [];

        foreach ($requests as $key => $request) {
            try {
                $results[$key] = $this->send($request);
            } catch (TransportException $exception) {
                $results[$key] = $exception;
            }
        }

        return $results;
    }

    public function __toString(): string
    {
        return $this->getName();
    }

    protected function openSink(Request $request): mixed
    {
        $sink = $request->getOption('sink');

        if (!is_string($sink) || $sink === '') {
            return null;
        }

        $directory = dirname($sink);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new TransportException('The directory "{directory}" cannot be created.', 0, null, ['directory' => $directory]);
        }

        $handle = @fopen($sink, 'wb');

        if ($handle === false) {
            throw new TransportException('The file "{file}" cannot be opened for writing.', 0, null, ['file' => $sink]);
        }

        return $handle;
    }

    protected function progress(Request $request, int $downloaded, ?int $total): void
    {
        $callback = $request->getOption('on_progress');

        if (is_callable($callback)) {
            $callback($downloaded, $total !== null && $total > 0 ? $total : null);
        }
    }

    protected function info(Request $request, float $start, array $info = []): array
    {
        $sink = $request->getOption('sink');

        return array_replace([
            'url' => $request->getUrl(),
            'http_method' => $request->getMethod(),
            'total_time' => microtime(true) - $start,
            'primary_ip' => null,
            'sink' => is_string($sink) && $sink !== '' ? $sink : null,
        ], $info);
    }

    protected function timeout(Request $request): ?float
    {
        $timeout = $request->getOption('timeout');

        return is_numeric($timeout) && (float) $timeout > 0 ? (float) $timeout : null;
    }
}