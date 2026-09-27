<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Helper\Controller;

use NeoPHP\Component\HttpClient\Contract\HttpClientInterface;

trait HttpClientController
{
    abstract protected function get(string $id): mixed;

    protected function httpClient(?string $name = null): HttpClientInterface
    {
        $client = $this->get(HttpClientInterface::class);

        return $name === null || $name === '' ? $client : $client->client($name);
    }
}