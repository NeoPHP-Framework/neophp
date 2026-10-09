<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Helper\Controller;

use NeoPHP\Component\HttpClient\HttpClientManagerInterface;

trait HttpClientController
{
    abstract protected function get(string $id): mixed;

    protected function httpClient(?string $name = null): HttpClientManagerInterface
    {
        $client = $this->get(HttpClientManagerInterface::class);

        return $name === null || $name === '' ? $client : $client->client($name);
    }
}