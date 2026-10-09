<?php

declare(strict_types=1);

namespace NeoPHP\Component\View\Engine;

use NeoPHP\Component\View\Exception\ViewException;
use NeoPHP\Component\View\Template\Sections;
use NeoPHP\Component\View\Template\Template;
use NeoPHP\Component\View\ViewManagerInterface;

class PhpEngine implements EngineInterface
{
    public const MAX_LAYOUT_DEPTH = 20;

    protected array $functions = [];

    protected array $filters = [];

    protected array $globals = [];

    protected ?ViewManagerInterface $view = null;

    public function setView(ViewManagerInterface $view): void
    {
        $this->view = $view;
    }

    public function getName(): string
    {
        return 'php';
    }

    public function getExtensions(): array
    {
        return ['.php'];
    }

    public function render(string $template, string $file, array $parameters): string
    {
        if ($this->view === null) {
            throw new ViewException('The PHP engine is not attached to a view.');
        }

        $sections = new Sections();
        $parameters = array_merge($this->globals, $parameters);
        $current = $file;

        for ($depth = 0; $depth < self::MAX_LAYOUT_DEPTH; $depth++) {
            $context = new Template($this->view, $sections, $this->functions, $this->filters, $parameters);
            $output = $context->renderFile($current, $parameters);
            $layout = $context->getLayout();

            if ($layout === null) {
                return $output;
            }

            if (!$sections->has('content') && trim($output) !== '') {
                $sections->set('content', $output);
            }

            $parameters = array_merge($parameters, $context->getLayoutParameters());
            $current = $this->view->locate($layout, $this->getName());
        }

        throw new ViewException(sprintf('Too many nested layouts while rendering "%s" (circular extend()?).', $template));
    }

    public function addFunction(string $name, callable $function, bool $safe = false): void
    {
        $this->functions[$name] = $function;
    }

    public function addFilter(string $name, callable $filter, bool $safe = false): void
    {
        $this->filters[$name] = $filter;
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->globals[$name] = $value;
    }

    public function addPath(string $path, ?string $namespace = null): void
    {
    }
}