<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Event;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Kernel\KernelManagerInterface;
use Throwable;

class ExceptionEvent extends KernelEvent
{
    protected ?Response $response = null;

    public function __construct(KernelManagerInterface $kernel, Request $request, protected Throwable $throwable)
    {
        parent::__construct($kernel, $request);
    }

    public function getThrowable(): Throwable
    {
        return $this->throwable;
    }

    public function setThrowable(Throwable $throwable): void
    {
        $this->throwable = $throwable;
    }

    public function getResponse(): ?Response
    {
        return $this->response;
    }

    public function setResponse(Response $response): void
    {
        $this->response = $response;
        $this->stopPropagation();
    }

    public function hasResponse(): bool
    {
        return $this->response !== null;
    }
}