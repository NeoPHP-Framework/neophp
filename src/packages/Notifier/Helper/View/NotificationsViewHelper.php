<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\Notifier\Contract\RecipientInterface;
use NeoPHP\Package\Notifier\Exception\NotifierException;
use NeoPHP\Package\Notifier\Model\StoredNotification;
use NeoPHP\Package\Notifier\NotifierManagerInterface;

/**
 * @internal
 */
class NotificationsViewHelper implements ViewFunctionInterface
{
    public function __construct(protected NotifierManagerInterface $notifier)
    {
    }

    public function getName(): string
    {
        return 'notifications';
    }

    /**
     * @return list<StoredNotification>
     * @throws NotifierException
     */
    public function __invoke(RecipientInterface|string|null $recipient, int $limit = 10, bool $unreadOnly = false): array
    {
        return $recipient === null ? [] : $this->notifier->getNotifications($recipient, $limit, $unreadOnly);
    }
}