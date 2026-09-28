<?php

declare(strict_types=1);

namespace NeoPHP\Component\View\Template;

use NeoPHP\Component\View\Contract\ViewInterface;
use NeoPHP\Component\View\Exception\ViewException;

class Template
{
    private ?string $layout = null;

    private array $layoutParameters = [];

    private array $openSections = [];

    public function __construct(
        protected ViewInterface $view,
        protected Sections $sections,
        protected array $functions = [],
        protected array $filters = [],
        protected array $parameters = [],
    ) {
    }

    public function renderFile(string $file, array $parameters): string
    {
        $level = ob_get_level();
        ob_start();

        try {
            (function (string $__template, array $__parameters): void {
                extract(array_diff_key($__parameters, ['this' => true, '__template' => true, '__parameters' => true]), EXTR_SKIP);
                unset($__parameters);
                include $__template;
            })->call($this, $file, $parameters);

            if ($this->openSections !== []) {
                throw new ViewException(sprintf('Section "%s" was started but never stopped in "%s".', end($this->openSections)['name'], $file));
            }

            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $exception;
        }
    }

    public function extend(string $layout, array $parameters = []): void
    {
        $this->layout = $layout;
        $this->layoutParameters = $parameters;
    }

    public function getLayout(): ?string
    {
        return $this->layout;
    }

    public function getLayoutParameters(): array
    {
        return $this->layoutParameters;
    }

    public function start(string $name, bool $append = false): void
    {
        $this->openSections[] = ['name' => $name, 'append' => $append];
        ob_start();
    }

    public function stop(): void
    {
        $section = array_pop($this->openSections);

        if ($section === null) {
            throw new ViewException('stop() called without a matching start().');
        }

        $content = (string) ob_get_clean();

        if ($section['append']) {
            $this->sections->append($section['name'], $content);
        } elseif (!$this->sections->has($section['name'])) {
            $this->sections->set($section['name'], $content);
        }
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections->get($name, $default);
    }

    public function hasSection(string $name): bool
    {
        return $this->sections->has($name);
    }

    public function include(string $template, array $parameters = []): string
    {
        return $this->view->render($template, array_merge($this->parameters, $parameters));
    }

    public function e(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if (is_bool($value)) {
            return '1';
        }

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new ViewException(sprintf('Cannot escape a value of type "%s".', get_debug_type($value)));
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function filter(string $name, mixed $value, mixed ...$arguments): mixed
    {
        if (!isset($this->filters[$name])) {
            throw new ViewException(sprintf('Unknown view filter "%s". Register it with ViewInterface::addFilter() or a ViewFilterInterface helper.', $name));
        }

        return ($this->filters[$name])($value, ...$arguments);
    }

    public function __call(string $name, array $arguments): mixed
    {
        if (!isset($this->functions[$name])) {
            throw new ViewException(sprintf('Unknown view function "%s()". Register it with ViewInterface::addHelper() or a ViewFunctionInterface helper.', $name));
        }

        return ($this->functions[$name])(...$arguments);
    }
}