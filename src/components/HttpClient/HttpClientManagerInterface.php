<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient;

use NeoPHP\Component\HttpClient\Contract\ResponseInterface;
use NeoPHP\Component\HttpClient\Contract\TransportInterface;

interface HttpClientManagerInterface
{
    public function request(string $method, string $url, array $options = []): ResponseInterface;

    public function get(string $url, array $options = []): ResponseInterface;

    public function post(string $url, array $options = []): ResponseInterface;

    public function put(string $url, array $options = []): ResponseInterface;

    public function patch(string $url, array $options = []): ResponseInterface;

    public function delete(string $url, array $options = []): ResponseInterface;

    public function head(string $url, array $options = []): ResponseInterface;

    public function requestMany(array $requests): array;

    public function download(string $url, string $target, array $options = []): ResponseInterface;

    public function withOptions(array $options): static;

    public function client(string $name): HttpClientManagerInterface;

    public function hasClient(string $name): bool;

    public function getOptions(): array;

    public function getTransport(): TransportInterface;
}