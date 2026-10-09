<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Event;

use NeoPHP\Component\Http\Response\Response;

class RequestEvent extends KernelEvent
{
    protected ?Response $response = null;

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