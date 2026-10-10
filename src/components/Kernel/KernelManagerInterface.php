<?php

declare(strict_types=1);

namespace NeoPHP\Component\Kernel;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Kernel\Exception\KernelException;

interface KernelManagerInterface
{
    /**
     * Registers the error handler, discovers the modules, builds the container, then registers and boots the providers; only the first call has an effect.
     *
     * @return void
     * @throws KernelException When a module, a provider or config/config.php is invalid, or a required module is disabled
     */
    public function boot(): void;

    /**
     * Boots the kernel and returns the response of a request; every error is turned into an error response.
     *
     * @param Request $request The request
     * @return Response The response, prepared for the request
     */
    public function handle(Request $request): Response;

    /**
     * Builds the request from the PHP globals, handles it, sends the response and calls terminate().
     *
     * @return void
     */
    public function run(): void;

    /**
     * Dispatches the TerminateEvent after the response is sent; an error of a listener is logged.
     *
     * @param Request $request The request
     * @param Response $response The sent response
     * @return void
     */
    public function terminate(Request $request, Response $response): void;

    /**
     * Returns the container of the application.
     *
     * @return ContainerManagerInterface The container
     * @throws KernelException When the kernel is not booted
     */
    public function getContainer(): ContainerManagerInterface;

    /**
     * Returns the root directory of the project.
     *
     * @return string The root directory
     */
    public function getRootPath(): string;

    /**
     * Returns the configuration directory.
     *
     * @return string The <root>/config directory
     */
    public function getConfigPath(): string;

    /**
     * Returns the public directory.
     *
     * @return string The <root>/public directory
     */
    public function getPublicPath(): string;

    /**
     * Returns the templates directory.
     *
     * @return string The <root>/templates directory
     */
    public function getTemplatesPath(): string;

    /**
     * Returns the cache directory.
     *
     * @return string The <root>/var/cache directory
     */
    public function getCachePath(): string;

    /**
     * Returns the environment.
     *
     * @return string The environment (dev, prod, test...)
     */
    public function getEnvironment(): string;

    /**
     * Tells whether the kernel runs in debug mode.
     *
     * @return bool True in debug
     */
    public function isDebug(): bool;

    /**
     * Returns the installed version of neophp/framework.
     *
     * @return string The Composer version, or AbstractKernel::VERSION when Composer does not know it
     */
    public function getVersion(): string;

    /**
     * Returns the kernel parameters registered in the container.
     *
     * @return array{'kernel.root_path': string, 'kernel.config_path': string, 'kernel.public_path': string, 'kernel.templates_path': string, 'kernel.cache_path': string, 'kernel.environment': string, 'kernel.debug': bool, 'kernel.version': string} The parameters, by name
     */
    public function getParameters(): array;

    /**
     * Returns the enabled modules, in their loading order.
     *
     * @return array<string, array{type: string, provider: string, requires: list<string>, namespace: string}> The definition of every module, by manager class
     * @throws KernelException When a module or config/config.php is invalid, or a required module is disabled
     */
    public function getModules(): array;

    /**
     * Tells whether the module a class belongs to is enabled; a class of the application or of no module is always enabled.
     *
     * @param string $class Name of the class
     * @return bool True when the module of the class is enabled
     * @throws KernelException When a module or config/config.php is invalid, or a required module is disabled
     */
    public function isEnabled(string $class): bool;
}