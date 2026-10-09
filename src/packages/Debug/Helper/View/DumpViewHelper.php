<?php

declare(strict_types=1);

namespace NeoPHP\Package\Debug\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Component\View\Contract\ViewSafeHtmlInterface;
use NeoPHP\Package\Debug\DebugManagerInterface;

/**
 * @internal
 */
class DumpViewHelper implements ViewFunctionInterface, ViewSafeHtmlInterface
{
    public function __construct(protected DebugManagerInterface $debug)
    {
    }

    public function getName(): string
    {
        return 'dump';
    }

    public function __invoke(mixed ...$values): string
    {
        if (!$this->debug->isEnabled()) {
            return '';
        }

        $output = '';

        foreach ($values as $value) {
            $output .= $this->debug->toHtml($value);
        }

        return $output;
    }
}