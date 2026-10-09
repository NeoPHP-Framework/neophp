<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Transport;

use NeoPHP\Component\HttpClient\Contract\TransportInterface;
use NeoPHP\Component\HttpClient\Exception\InvalidOptionException;

class TransportFactory
{
    public function create(string $name = 'auto'): TransportInterface
    {
        return match (strtolower(trim($name))) {
            '', 'auto' => extension_loaded('curl') ? new CurlTransport() : new StreamTransport(),
            'curl' => new CurlTransport(),
            'stream' => new StreamTransport(),
            'mock' => new MockTransport(),
            default => throw new InvalidOptionException('The HTTP client transport "{transport}" is not supported (auto, curl, stream or mock).', 0, null, ['transport' => $name]),
        };
    }
}