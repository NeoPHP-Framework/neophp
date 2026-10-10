<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown;

use Closure;
use NeoPHP\Component\Kernel\Attribute\Package;
use NeoPHP\Component\View\ViewManagerInterface;
use NeoPHP\Package\Markdown\Converter\HtmlConverter;
use NeoPHP\Package\Markdown\Converter\MarkdownConversion;
use NeoPHP\Package\Markdown\Document\MarkdownDocument;
use NeoPHP\Package\Markdown\Exception\MarkdownException;
use NeoPHP\Package\Markdown\Parser\HtmlRenderer;
use NeoPHP\Package\Markdown\Provider\MarkdownProvider;
use Throwable;

#[Package(provider: MarkdownProvider::class)]
final class MarkdownManager implements MarkdownManagerInterface
{
    public const TEMPLATE_EXTENSIONS = ['.html.twig', '.twig', '.php'];

    public const HTML_EXTENSIONS = ['.html', '.htm'];

    public const TEXT_EXTENSIONS = ['.txt', '.md', '.markdown'];

    protected string $rootPath;

    protected string $templatesPath;

    protected ?Closure $viewResolver = null;

    protected ?HtmlRenderer $renderer = null;

    protected ?HtmlRenderer $safeRenderer = null;

    protected ?HtmlConverter $converter = null;

    public function __construct(string $rootPath, ?string $templatesPath = null, ?Closure $viewResolver = null)
    {
        $this->rootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
        $this->templatesPath = rtrim(str_replace('\\', '/', $templatesPath ?? $this->rootPath . '/templates'), '/');
        $this->viewResolver = $viewResolver;
    }

    public function get(string $file): MarkdownDocument
    {
        return $this->fromString($this->read($this->absolute($file)));
    }

    public function fromString(string $markdown): MarkdownDocument
    {
        return new MarkdownDocument($markdown, $this->renderer());
    }

    public function toHtml(string $markdown): string
    {
        return $this->renderer()->render($markdown)['html'];
    }

    public function toSafeHtml(string $markdown): string
    {
        $this->safeRenderer ??= (new HtmlRenderer())->setEscapeHtml(true);

        return $this->safeRenderer->render($markdown)['html'];
    }

    public function parse(string $file, array $parameters = []): MarkdownConversion
    {
        $path = $this->absolute($file);
        $lower = strtolower($path);

        if (!is_file($path)) {
            throw new MarkdownException('The file "{file}" does not exist.', 0, null, ['file' => $path]);
        }

        foreach (self::TEMPLATE_EXTENSIONS as $extension) {
            if (str_ends_with($lower, $extension)) {
                return $this->conversion($this->convert($this->renderTemplate($path, $parameters)), $path);
            }
        }

        foreach (self::HTML_EXTENSIONS as $extension) {
            if (str_ends_with($lower, $extension)) {
                return $this->conversion($this->convert($this->read($path)), $path);
            }
        }

        foreach (self::TEXT_EXTENSIONS as $extension) {
            if (str_ends_with($lower, $extension)) {
                $text = trim(str_replace(["\r\n", "\r"], "\n", $this->read($path)));

                return $this->conversion($text === '' ? '' : $text . "\n", $path);
            }
        }

        throw new MarkdownException('The file "{file}" can not be converted into Markdown: use a .html, .htm, .txt file or a .twig / .php template.', 0, null, ['file' => $path]);
    }

    public function convert(string $html): string
    {
        $this->converter ??= new HtmlConverter();

        return $this->converter->convert($html);
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    public function getTemplatesPath(): string
    {
        return $this->templatesPath;
    }

    protected function renderer(): HtmlRenderer
    {
        return $this->renderer ??= new HtmlRenderer();
    }

    protected function conversion(string $markdown, string $source): MarkdownConversion
    {
        return new MarkdownConversion($markdown, $source, $this->rootPath, $this);
    }

    protected function renderTemplate(string $path, array $parameters): string
    {
        $templates = realpath($this->templatesPath);
        $real = realpath($path);
        $templates = $templates === false ? $this->templatesPath : str_replace('\\', '/', $templates);
        $real = $real === false ? $path : str_replace('\\', '/', $real);

        if (!str_starts_with($real, rtrim($templates, '/') . '/')) {
            throw new MarkdownException('The template "{file}" must be inside the templates directory "{templates}".', 0, null, ['file' => $real, 'templates' => $templates]);
        }

        $view = $this->viewResolver !== null ? ($this->viewResolver)() : null;

        if (!$view instanceof ViewManagerInterface) {
            throw new MarkdownException('The View component is required to convert the template "{file}".', 0, null, ['file' => $real]);
        }

        $name = substr($real, strlen(rtrim($templates, '/')) + 1);

        try {
            return $view->render($name, $parameters);
        } catch (Throwable $exception) {
            if ($exception instanceof MarkdownException) {
                throw $exception;
            }

            throw new MarkdownException('Unable to render the template "{template}": {error}', 0, $exception, ['template' => $name, 'error' => $exception->getMessage()]);
        }
    }

    protected function absolute(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (preg_match('#^([a-zA-Z]:)?/#', $path) === 1) {
            return $path;
        }

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return $this->rootPath . '/' . $path;
    }

    protected function read(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new MarkdownException('The file "{file}" does not exist.', 0, null, ['file' => $path]);
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new MarkdownException('Unable to read the file "{file}".', 0, null, ['file' => $path]);
        }

        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }
}