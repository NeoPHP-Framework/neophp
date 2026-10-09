<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\Translation\TranslationManagerInterface;

/**
 * @internal
 */
class TranslateViewHelper implements ViewFunctionInterface
{
    public function __construct(protected TranslationManagerInterface $translator)
    {
    }

    public function getName(): string
    {
        return 'translate';
    }

    public function __invoke(?string $key, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->translator->translate((string) $key, $parameters, $domain, $locale);
    }
}