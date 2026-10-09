<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel\Event;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Kernel\KernelManagerInterface;

class ControllerEvent extends KernelEvent
{
    public function __construct(KernelManagerInterface $kernel, Request $request, protected mixed $controller, protected array $parameters = [])
    {
        parent::__construct($kernel, $request);
    }

    public function getController(): mixed
    {
        return $this->controller;
    }

    public function setController(mixed $controller): void
    {
        $this->controller = $controller;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function setParameters(array $parameters): void
    {
        $this->parameters = $parameters;
    }
}