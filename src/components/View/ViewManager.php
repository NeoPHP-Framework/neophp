<?php

declare(strict_types=1);

namespace NeoPHP\Component\View;

use NeoPHP\Component\Config\ConfigManager;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\View\Contract\ViewFilterInterface;
use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Component\View\Contract\ViewGlobalInterface;
use NeoPHP\Component\View\Contract\ViewHelperInterface;
use NeoPHP\Component\View\Contract\ViewSafeHtmlInterface;
use NeoPHP\Component\View\Engine\EngineInterface;
use NeoPHP\Component\View\Engine\PhpEngine;
use NeoPHP\Component\View\Exception\TemplateNotFoundException;
use NeoPHP\Component\View\Exception\ViewException;
use NeoPHP\Component\View\Provider\ViewProvider;

#[Component(provider: ViewProvider::class, requires: [ConfigManager::class])]
final class ViewManager implements ViewManagerInterface
{
    public const MISSING_ENGINES = [
        '.html.twig' => 'Twig is not installed: run "composer require twig/twig" to render .twig templates.',
        '.twig' => 'Twig is not installed: run "composer require twig/twig" to render .twig templates.',
    ];

    protected array $paths = [];

    protected array $engines = [];

    public function __construct(array $paths = [], ?array $engines = null)
    {
        foreach ($engines ?? [new PhpEngine()] as $engine) {
            if ($engine instanceof EngineInterface) {
                $this->addEngine($engine);
            }
        }

        foreach ($paths as $path) {
            $this->addPath($path);
        }
    }

    public function render(string $template, array $parameters = []): string
    {
        [$name, $file, $engine] = $this->find($template);

        return $engine->render($name, $file, $parameters);
    }

    public function exists(string $template): bool
    {
        try {
            $this->find($template);

            return true;
        } catch (TemplateNotFoundException) {
            return false;
        }
    }

    public function locate(string $template, ?string $engine = null): string
    {
        return $this->find($template, $engine)[1];
    }

    public function addPath(string $path, ?string $namespace = null): static
    {
        $path = rtrim($path, '/\\');
        $this->paths[$namespace ?? ''][] = $path;

        foreach ($this->engines as $engine) {
            $engine->addPath($path, $namespace);
        }

        return $this;
    }

    public function getPaths(): array
    {
        return $this->paths;
    }

    public function addGlobal(string $name, mixed $value): static
    {
        foreach ($this->engines as $engine) {
            $engine->addGlobal($name, $value);
        }

        return $this;
    }

    public function addHelper(string $name, callable $helper, bool $safe = false): static
    {
        foreach ($this->engines as $engine) {
            $engine->addFunction($name, $helper, $safe);
        }

        return $this;
    }

    public function addFilter(string $name, callable $filter, bool $safe = false): static
    {
        foreach ($this->engines as $engine) {
            $engine->addFilter($name, $filter, $safe);
        }

        return $this;
    }

    public function addExtension(ViewHelperInterface $extension): static
    {
        $name = $extension->getName();
        $safe = $extension instanceof ViewSafeHtmlInterface;
        $registered = false;

        if ($extension instanceof ViewFunctionInterface || $extension instanceof ViewFilterInterface) {
            if (!is_callable($extension)) {
                throw new ViewException('The view helper "{class}" must define an __invoke() method.', 0, null, ['class' => $extension::class]);
            }
        }

        if ($extension instanceof ViewFunctionInterface) {
            $this->addHelper($name, $extension, $safe);
            $registered = true;
        }

        if ($extension instanceof ViewFilterInterface) {
            $this->addFilter($name, $extension, $safe);
            $registered = true;
        }

        if ($extension instanceof ViewGlobalInterface) {
            $this->addGlobal($name, $extension->getValue());
            $registered = true;
        }

        if (!$registered) {
            throw new ViewException('The view helper "{class}" must implement ViewFunctionInterface, ViewFilterInterface or ViewGlobalInterface.', 0, null, ['class' => $extension::class]);
        }

        return $this;
    }

    public function addEngine(EngineInterface $engine): static
    {
        if ($engine instanceof PhpEngine) {
            $engine->setView($this);
        }

        foreach ($this->paths as $namespace => $paths) {
            foreach ($paths as $path) {
                $engine->addPath($path, $namespace === '' ? null : $namespace);
            }
        }

        $this->engines[$engine->getName()] = $engine;

        return $this;
    }

    public function getEngines(): array
    {
        return $this->engines;
    }

    protected function find(string $template, ?string $only = null): array
    {
        $namespace = '';
        $name = $template;

        if (str_starts_with($template, '@')) {
            $parts = explode('/', substr($template, 1), 2);
            $namespace = $parts[0];
            $name = $parts[1] ?? '';
        }

        $name = ltrim(str_replace('\\', '/', $name), '/');

        if ($name === '' || str_contains('/' . $name . '/', '/../')) {
            throw new ViewException(sprintf('Invalid template name "%s".', $template));
        }

        $engines = $only !== null ? array_filter([$this->engines[$only] ?? null]) : $this->engines;
        $candidates = [];

        foreach ($engines as $engine) {
            $hasExtension = false;

            foreach ($engine->getExtensions() as $extension) {
                if (str_ends_with($name, $extension)) {
                    $hasExtension = true;
                    $candidates[] = [$name, $engine];
                    break;
                }
            }

            if (!$hasExtension && !$this->hasKnownExtension($name)) {
                foreach ($engine->getExtensions() as $extension) {
                    $candidates[] = [$name . $extension, $engine];
                }
            }
        }

        $searched = [];

        foreach ($candidates as [$candidate, $engine]) {
            foreach ($this->paths[$namespace] ?? [] as $directory) {
                $file = $directory . '/' . $candidate;
                $searched[] = $file;

                if (is_file($file)) {
                    return [($namespace !== '' ? '@' . $namespace . '/' : '') . $candidate, $file, $engine];
                }
            }
        }

        $this->assertEngineAvailable($namespace, $name);

        throw TemplateNotFoundException::create($template, $searched);
    }

    protected function assertEngineAvailable(string $namespace, string $name): void
    {
        foreach (static::MISSING_ENGINES as $extension => $message) {
            if ($this->hasKnownExtension($extension)) {
                continue;
            }

            $candidate = str_ends_with($name, $extension) ? $name : $name . $extension;

            foreach ($this->paths[$namespace] ?? [] as $directory) {
                if (is_file($directory . '/' . $candidate)) {
                    throw new ViewException('Unable to render "{template}": {reason}', 0, null, ['template' => $candidate, 'reason' => $message]);
                }
            }
        }
    }

    protected function hasKnownExtension(string $name): bool
    {
        foreach ($this->engines as $engine) {
            foreach ($engine->getExtensions() as $extension) {
                if (str_ends_with($name, $extension)) {
                    return true;
                }
            }
        }

        return false;
    }
}