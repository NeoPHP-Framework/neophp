<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown\Contract;

use NeoPHP\Package\Markdown\Document\MarkdownDocument;
use NeoPHP\Package\Markdown\MarkdownConversion;

interface MarkdownParserInterface
{
    public function get(string $file): MarkdownDocument;

    public function fromString(string $markdown): MarkdownDocument;

    public function toHtml(string $markdown): string;

    public function toSafeHtml(string $markdown): string;

    public function parse(string $file, array $parameters = []): MarkdownConversion;

    public function convert(string $html): string;
}