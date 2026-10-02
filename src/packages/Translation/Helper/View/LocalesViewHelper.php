<?php

namespace NeoPHP\Package\Translation\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\Translation\Contract\TranslatorInterface;

class LocalesViewHelper implements ViewFunctionInterface
{
    public function __construct(protected TranslatorInterface $translator)
    {
    }

    public function getName(): string
    {
        return 'locales';
    }

    public function __invoke()
    {
        return $this->translator->getLocales();
    }
}