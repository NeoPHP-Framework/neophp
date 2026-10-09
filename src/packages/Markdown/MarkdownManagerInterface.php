<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown;

use NeoPHP\Package\Markdown\Converter\MarkdownConversion;
use NeoPHP\Package\Markdown\Document\MarkdownDocument;

interface MarkdownManagerInterface
{
    public function get(string $file): MarkdownDocument;

    public function fromString(string $markdown): MarkdownDocument;

    public function toHtml(string $markdown): string;

    public function toSafeHtml(string $markdown): string;

    public function parse(string $file, array $parameters = []): MarkdownConversion;

    public function convert(string $html): string;
}