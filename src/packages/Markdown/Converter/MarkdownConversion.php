<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown\Converter;

use NeoPHP\Package\Markdown\Document\MarkdownDocument;
use NeoPHP\Package\Markdown\Exception\MarkdownException;
use NeoPHP\Package\Markdown\MarkdownManagerInterface;
use Stringable;

class MarkdownConversion implements Stringable
{
    protected ?MarkdownDocument $document = null;

    public function __construct(protected string $markdown, protected string $source, protected string $rootPath, protected MarkdownManagerInterface $parser)
    {
    }

    public function getMarkdown(): string
    {
        return $this->markdown;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getDocument(): MarkdownDocument
    {
        return $this->document ??= $this->parser->fromString($this->markdown);
    }

    public function to(string $target): string
    {
        $target = str_replace('\\', '/', trim($target));

        if ($target === '') {
            throw new MarkdownException('The target file of the Markdown conversion is empty.');
        }

        if (preg_match('#^([a-zA-Z]:)?/#', $target) !== 1) {
            while (str_starts_with($target, './')) {
                $target = substr($target, 2);
            }

            $target = rtrim($this->rootPath, '/') . '/' . $target;
        }

        $directory = dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new MarkdownException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        if (@file_put_contents($target, $this->markdown) === false) {
            throw new MarkdownException('Unable to write the file "{file}".', 0, null, ['file' => $target]);
        }

        return $target;
    }

    public function __toString(): string
    {
        return $this->markdown;
    }
}