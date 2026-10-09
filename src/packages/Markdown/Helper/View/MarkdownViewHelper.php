<?php

declare(strict_types=1);

namespace NeoPHP\Package\Markdown\Helper\View;

use NeoPHP\Component\View\Contract\ViewFilterInterface;
use NeoPHP\Component\View\Contract\ViewSafeHtmlInterface;
use NeoPHP\Package\Markdown\MarkdownManagerInterface;

/**
 * @internal
 */
class MarkdownViewHelper implements ViewFilterInterface, ViewSafeHtmlInterface
{
    public function __construct(protected MarkdownManagerInterface $markdown)
    {
    }

    public function getName(): string
    {
        return 'markdown';
    }

    public function __invoke(?string $text, bool $allowHtml = false): string
    {
        return $allowHtml ? $this->markdown->toHtml((string) $text) : $this->markdown->toSafeHtml((string) $text);
    }
}