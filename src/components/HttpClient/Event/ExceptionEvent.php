<?php

declare(strict_types=1);

namespace NeoPHP\Component\HttpClient\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Component\HttpClient\Contract\ResponseInterface;
use NeoPHP\Component\HttpClient\Exception\HttpClientException;
use NeoPHP\Component\HttpClient\Exception\HttpException;

class ExceptionEvent extends AbstractEvent
{
    public function __construct(protected string $method, protected string $url, protected HttpClientException $exception)
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

    public function getException(): HttpClientException
    {
        return $this->exception;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->exception instanceof HttpException ? $this->exception->getResponse() : null;
    }
}