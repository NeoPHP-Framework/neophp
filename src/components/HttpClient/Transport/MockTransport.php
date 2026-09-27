<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Transport;

use Closure;
use NeoPHP\Component\HttpClient\Contract\AbstractTransport;
use NeoPHP\Component\HttpClient\Exception\TransportException;
use NeoPHP\Component\HttpClient\Request\Request;
use NeoPHP\Component\HttpClient\Response\Response;

class MockTransport extends AbstractTransport
{
    protected ?Closure $factory = null;

    protected array $responses = [];

    protected array $requests = [];

    public function __construct(callable|array|MockResponse $responses = [])
    {
        if (is_callable($responses)) {
            $this->factory = Closure::fromCallable($responses);
        } else {
            $this->responses = is_array($responses) ? array_values($responses) : [$responses];
        }
    }

    public function getName(): string
    {
        return 'mock';
    }

    public function send(Request $request): Response
    {
        $start = microtime(true);
        $this->requests[] = $request;

        if ($this->factory !== null) {
            $mock = ($this->factory)($request->getMethod(), $request->getUrl(), $request->getOptions() + ['headers' => $request->getHeaders(), 'body' => $request->getBody()]);
        } elseif ($this->responses !== []) {
            $mock = array_shift($this->responses);
        } else {
            throw new TransportException('No mock response left for {method} "{url}".', 0, null, ['method' => $request->getMethod(), 'url' => $request->getUrl()]);
        }

        if ($mock instanceof TransportException) {
            throw $mock;
        }

        if (is_string($mock)) {
            $mock = new MockResponse($mock);
        }

        if (!$mock instanceof MockResponse) {
            throw new TransportException('The mock factory must return a MockResponse, {type} returned.', 0, null, ['type' => get_debug_type($mock)]);
        }

        $body = $request->getMethod() === 'HEAD' ? '' : $mock->getBody();
        $sink = $this->openSink($request);

        if ($sink !== null) {
            fwrite($sink, $body);
            fclose($sink);
        }

        if ($body !== '') {
            $this->progress($request, strlen($body), strlen($body));
        }

        return new Response($mock->getStatusCode(), $mock->getHeaders(), $sink !== null ? null : $body, $this->info($request, $start, $mock->getInfo()));
    }

    public function getRequests(): array
    {
        return $this->requests;
    }

    public function getRequestCount(): int
    {
        return count($this->requests);
    }

    public function getLastRequest(): ?Request
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }

    public function addResponse(MockResponse|TransportException $response): static
    {
        $this->responses[] = $response;

        return $this;
    }

    public function reset(): static
    {
        $this->requests = [];

        return $this;
    }
}