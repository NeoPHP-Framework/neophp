<?php

declare(strict_types=1);

namespace NeoPHP\Component\Csrf\Helper\View;

use NeoPHP\Component\Csrf\CsrfManagerInterface;
use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Component\View\Contract\ViewSafeHtmlInterface;

/**
 * @internal
 */
class CsrfFieldViewHelper implements ViewFunctionInterface, ViewSafeHtmlInterface
{
    public function __construct(protected CsrfManagerInterface $csrf)
    {
    }

    public function getName(): string
    {
        return 'csrf_field';
    }

    public function __invoke(string $id, ?string $field = null): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars($field ?? $this->csrf->getFieldName(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->csrf->getToken($id), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }
}