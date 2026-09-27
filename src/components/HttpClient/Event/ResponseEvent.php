<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Component\HttpClient\Contract\ResponseInterface;

class ResponseEvent extends AbstractEvent
{
    public function __construct(protected string $method, protected string $url, protected ResponseInterface $response)
    {
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    public function setResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }
}