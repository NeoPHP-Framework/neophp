<?php

declare(strict_types=1);

namespace NeoPHP\Component\View;

use NeoPHP\Component\View\Contract\ViewHelperInterface;
use NeoPHP\Component\View\Engine\EngineInterface;
use NeoPHP\Component\View\Exception\TemplateNotFoundException;
use NeoPHP\Component\View\Exception\ViewException;

interface ViewManagerInterface
{
    /**
     * Renders a template with the engine of its extension (.php, .html.twig).
     *
     * @param string $template Name of the template, with or without extension, @namespace/name for a namespaced template
     * @param array<string, mixed> $parameters Variables of the template
     * @return string The rendered content
     * @throws TemplateNotFoundException When the template does not exist
     * @throws ViewException When the name is invalid or the template needs an engine that is not installed (Twig)
     */
    public function render(string $template, array $parameters = []): string;

    /**
     * Tells whether a template exists.
     *
     * @param string $template Name of the template
     * @return bool True when the template exists
     * @throws ViewException When the name is invalid or the template needs an engine that is not installed
     */
    public function exists(string $template): bool;

    /**
     * Returns the file of a template.
     *
     * @param string $template Name of the template
     * @param string|null $engine Name of the engine to use (php, twig), every engine when null
     * @return string The path of the template file
     * @throws TemplateNotFoundException When the template does not exist
     * @throws ViewException When the name is invalid or the template needs an engine that is not installed
     */
    public function locate(string $template, ?string $engine = null): string;

    /**
     * Adds a templates directory, searched after the previous ones.
     *
     * @param string $path The directory
     * @param string|null $namespace Namespace of the directory (@namespace/name), or null for the main namespace
     * @return static The view manager
     */
    public function addPath(string $path, ?string $namespace = null): static;

    /**
     * Returns the templates directories.
     *
     * @return array<string, list<string>> The directories, by namespace (an empty string for the main namespace)
     */
    public function getPaths(): array;

    /**
     * Adds a global variable to every template of every engine.
     *
     * @param string $name Name of the variable
     * @param mixed $value The value
     * @return static The view manager
     */
    public function addGlobal(string $name, mixed $value): static;

    /**
     * Adds a function to every engine.
     *
     * @param string $name Name of the function
     * @param callable $helper The function
     * @param bool $safe Its output is HTML and is not escaped
     * @return static The view manager
     */
    public function addHelper(string $name, callable $helper, bool $safe = false): static;

    /**
     * Adds a filter to every engine.
     *
     * @param string $name Name of the filter
     * @param callable $filter The filter, receiving the value then the arguments
     * @param bool $safe Its output is HTML and is not escaped
     * @return static The view manager
     */
    public function addFilter(string $name, callable $filter, bool $safe = false): static;

    /**
     * Registers a view helper as a function, a filter and/or a global variable, according to the interfaces it implements.
     *
     * @param ViewHelperInterface $extension The view helper
     * @return static The view manager
     * @throws ViewException When the helper is not invokable or implements neither ViewFunctionInterface, ViewFilterInterface nor ViewGlobalInterface
     */
    public function addExtension(ViewHelperInterface $extension): static;

    /**
     * Registers a template engine with the existing directories; an engine of the same name is replaced.
     *
     * @param EngineInterface $engine The engine
     * @return static The view manager
     */
    public function addEngine(EngineInterface $engine): static;

    /**
     * Returns the template engines.
     *
     * @return array<string, EngineInterface> The engines, by name
     */
    public function getEngines(): array;
}