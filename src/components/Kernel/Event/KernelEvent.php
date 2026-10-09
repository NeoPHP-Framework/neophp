<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Kernel\KernelManagerInterface;

abstract class KernelEvent extends AbstractEvent
{
    public function __construct(protected KernelManagerInterface $kernel, protected Request $request)
    {
    }

    public function getKernel(): KernelManagerInterface
    {
        return $this->kernel;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }
}