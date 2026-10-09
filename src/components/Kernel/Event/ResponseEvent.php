<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Event;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Kernel\KernelManagerInterface;

class ResponseEvent extends KernelEvent
{
    public function __construct(KernelManagerInterface $kernel, Request $request, protected Response $response)
    {
        parent::__construct($kernel, $request);
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }
}