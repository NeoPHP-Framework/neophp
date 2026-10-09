<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Helper\View;

use NeoPHP\Component\View\Contract\ViewFilterInterface;
use NeoPHP\Package\Translation\TranslationManagerInterface;

/**
 * @internal
 */
class TransViewHelper implements ViewFilterInterface
{
    public function __construct(protected TranslationManagerInterface $translator)
    {
    }

    public function getName(): string
    {
        return 'trans';
    }

    public function __invoke(?string $key, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->translator->translate((string) $key, $parameters, $domain, $locale);
    }
}