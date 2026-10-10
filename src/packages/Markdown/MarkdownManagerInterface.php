<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown;

use NeoPHP\Package\Markdown\Converter\MarkdownConversion;
use NeoPHP\Package\Markdown\Document\MarkdownDocument;
use NeoPHP\Package\Markdown\Exception\MarkdownException;

interface MarkdownManagerInterface
{
    /**
     * Reads a Markdown file into a document.
     *
     * @param string $file Path of the file, relative to the project or absolute
     * @return MarkdownDocument The document
     * @throws MarkdownException When the file does not exist or cannot be read
     */
    public function get(string $file): MarkdownDocument;

    /**
     * Creates a document from Markdown.
     *
     * @param string $markdown The Markdown
     * @return MarkdownDocument The document
     */
    public function fromString(string $markdown): MarkdownDocument;

    /**
     * Renders Markdown into HTML, keeping the raw HTML it contains.
     *
     * @param string $markdown The Markdown
     * @return string The HTML
     */
    public function toHtml(string $markdown): string;

    /**
     * Renders Markdown into HTML, escaping the raw HTML it contains.
     *
     * @param string $markdown The Markdown
     * @return string The HTML
     */
    public function toSafeHtml(string $markdown): string;

    /**
     * Converts a file into Markdown: a template (.twig, .php) is rendered first, an HTML file is converted, a text or Markdown file is read.
     *
     * @param string $file Path of the file, relative to the project or absolute
     * @param array<string, mixed> $parameters Variables of the template
     * @return MarkdownConversion The conversion, which can be saved to a file
     * @throws MarkdownException When the file does not exist, its extension is not supported, the template is outside the templates directory, the View component is missing or the template cannot be rendered
     */
    public function parse(string $file, array $parameters = []): MarkdownConversion;

    /**
     * Converts HTML into Markdown.
     *
     * @param string $html The HTML
     * @return string The Markdown
     */
    public function convert(string $html): string;
}