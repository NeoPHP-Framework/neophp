<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Component\View\Contract\ViewSafeHtmlInterface;
use NeoPHP\Package\Security\SecurityManagerInterface;

/**
 * @internal
 */
class LogoutFormViewHelper implements ViewFunctionInterface, ViewSafeHtmlInterface
{
    public function __construct(protected SecurityManagerInterface $security)
    {
    }

    public function getName(): string
    {
        return 'logout_form';
    }

    public function __invoke(string $label = 'Logout', string $class = '', ?string $firewall = null): string
    {
        $path = $this->security->getLogoutPath($firewall);

        if ($path === null) {
            return '';
        }

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<form method="post" action="' . $escape($path) . '" style="display:inline">'
            . '<button type="submit"' . ($class !== '' ? ' class="' . $escape($class) . '"' : '') . '>' . $escape($label) . '</button>'
            . '</form>';
    }
}