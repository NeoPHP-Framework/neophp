<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\Translation\TranslationManagerInterface;

/**
 * @internal
 */
class LocalesViewHelper implements ViewFunctionInterface
{
    public function __construct(protected TranslationManagerInterface $translator)
    {
    }

    public function getName(): string
    {
        return 'locales';
    }

    public function __invoke(): array
    {
        return $this->translator->getLocales();
    }
}