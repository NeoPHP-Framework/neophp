<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\Notifier\Contract\RecipientInterface;
use NeoPHP\Package\Notifier\NotifierManagerInterface;

/**
 * @internal
 */
class UnreadNotificationsViewHelper implements ViewFunctionInterface
{
    public function __construct(protected NotifierManagerInterface $notifier)
    {
    }

    public function getName(): string
    {
        return 'unread_notifications';
    }

    public function __invoke(RecipientInterface|string|null $recipient): int
    {
        return $recipient === null ? 0 : $this->notifier->countUnread($recipient);
    }
}