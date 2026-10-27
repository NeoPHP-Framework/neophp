<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\Notifier\NotifierManagerInterface;

/**
 * @internal
 */
class VapidPublicKeyViewHelper implements ViewFunctionInterface
{
    public function __construct(protected NotifierManagerInterface $notifier)
    {
    }

    public function getName(): string
    {
        return 'vapid_public_key';
    }

    public function __invoke(): string
    {
        return (string) $this->notifier->getVapidPublicKey();
    }
}