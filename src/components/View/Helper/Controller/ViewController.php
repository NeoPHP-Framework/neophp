<?php

declare(strict_types=1);

namespace NeoPHP\Component\View\Helper\Controller;

use NeoPHP\Component\Http\HttpManagerInterface;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\View\ViewManagerInterface;

trait ViewController
{
    abstract protected function get(string $id): mixed;

    protected function render(string $template, array $parameters = [], int $status = 200, array $headers = []): Response
    {
        return $this->get(HttpManagerInterface::class)->createResponse($this->renderView($template, $parameters), $status, $headers);
    }

    protected function renderView(string $template, array $parameters = []): string
    {
        return $this->get(ViewManagerInterface::class)->render($template, $parameters);
    }
}