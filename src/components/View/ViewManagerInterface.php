<?php

declare(strict_types=1);

namespace NeoPHP\Component\View;

use NeoPHP\Component\View\Contract\ViewHelperInterface;
use NeoPHP\Component\View\Engine\EngineInterface;

interface ViewManagerInterface
{
    public function render(string $template, array $parameters = []): string;

    public function exists(string $template): bool;

    public function locate(string $template, ?string $engine = null): string;

    public function addPath(string $path, ?string $namespace = null): static;

    public function getPaths(): array;

    public function addGlobal(string $name, mixed $value): static;

    public function addHelper(string $name, callable $helper, bool $safe = false): static;

    public function addFilter(string $name, callable $filter, bool $safe = false): static;

    public function addExtension(ViewHelperInterface $extension): static;

    public function addEngine(EngineInterface $engine): static;

    public function getEngines(): array;
}