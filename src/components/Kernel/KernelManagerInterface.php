<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;

interface KernelManagerInterface
{
    public function boot(): void;

    public function handle(Request $request): Response;

    public function run(): void;

    public function terminate(Request $request, Response $response): void;

    public function getContainer(): ContainerManagerInterface;

    public function getRootPath(): string;

    public function getConfigPath(): string;

    public function getPublicPath(): string;

    public function getTemplatesPath(): string;

    public function getCachePath(): string;

    public function getEnvironment(): string;

    public function isDebug(): bool;

    public function getVersion(): string;

    public function getParameters(): array;

    public function getModules(): array;

    public function isEnabled(string $class): bool;
}