<?php

declare(strict_types=1);

namespace NeoPHP\Component\View\Engine;

use NeoPHP\Component\View\Exception\ViewException;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TwigEngine implements EngineInterface
{
    protected ?object $environment = null;

    protected ?object $loader = null;

    protected array $functions = [];

    protected array $filters = [];

    protected array $globals = [];

    protected array $paths = [];

    public function __construct(protected array $options = [])
    {
        if (!static::isAvailable()) {
            throw new ViewException('Twig is not installed. Run "composer require twig/twig" to render .twig templates.');
        }
    }

    public static function isAvailable(): bool
    {
        return class_exists(Environment::class);
    }

    public function getName(): string
    {
        return 'twig';
    }

    public function getExtensions(): array
    {
        return ['.html.twig', '.twig'];
    }

    public function render(string $template, string $file, array $parameters): string
    {
        try {
            return $this->getEnvironment()->render($template, $parameters);
        } catch (Error $exception) {
            throw new ViewException('{message}', 0, $exception, ['message' => $exception->getMessage()]);
        }
    }

    public function addFunction(string $name, callable $function, bool $safe = false): void
    {
        $this->functions[$name] = [$function, $safe];

        if ($this->environment !== null) {
            $this->environment->addFunction($this->createFunction($name, $function, $safe));
        }
    }

    public function addFilter(string $name, callable $filter, bool $safe = false): void
    {
        $this->filters[$name] = [$filter, $safe];

        if ($this->environment !== null) {
            $this->environment->addFilter($this->createFilter($name, $filter, $safe));
        }
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->globals[$name] = $value;
        $this->environment?->addGlobal($name, $value);
    }

    public function addPath(string $path, ?string $namespace = null): void
    {
        $this->paths[] = [$path, $namespace];

        if ($this->loader !== null && is_dir($path)) {
            $this->loader->addPath($path, $namespace ?? FilesystemLoader::MAIN_NAMESPACE);
        }
    }

    public function getEnvironment(): Environment
    {
        if ($this->environment !== null) {
            return $this->environment;
        }

        $this->loader = new FilesystemLoader();

        foreach ($this->paths as [$path, $namespace]) {
            if (is_dir($path)) {
                $this->loader->addPath($path, $namespace ?? FilesystemLoader::MAIN_NAMESPACE);
            }
        }

        $environment = new Environment($this->loader, [
            'cache' => $this->options['cache'] ?? false,
            'debug' => (bool) ($this->options['debug'] ?? false),
            'auto_reload' => (bool) ($this->options['auto_reload'] ?? true),
            'strict_variables' => (bool) ($this->options['strict_variables'] ?? false),
            'autoescape' => $this->options['autoescape'] ?? 'html',
            'charset' => $this->options['charset'] ?? 'UTF-8',
        ]);

        foreach ($this->functions as $name => [$function, $safe]) {
            $environment->addFunction($this->createFunction($name, $function, $safe));
        }

        foreach ($this->filters as $name => [$filter, $safe]) {
            $environment->addFilter($this->createFilter($name, $filter, $safe));
        }

        foreach ($this->globals as $name => $value) {
            $environment->addGlobal($name, $value);
        }

        return $this->environment = $environment;
    }

    protected function createFunction(string $name, callable $function, bool $safe): TwigFunction
    {
        return new TwigFunction($name, $function, $safe ? ['is_safe' => ['html']] : []);
    }

    protected function createFilter(string $name, callable $filter, bool $safe): TwigFilter
    {
        return new TwigFilter($name, $filter, $safe ? ['is_safe' => ['html']] : []);
    }
}