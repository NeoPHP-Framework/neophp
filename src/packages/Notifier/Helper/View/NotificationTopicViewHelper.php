<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Helper\View;

use NeoPHP\Component\View\Contract\ViewFunctionInterface;
use NeoPHP\Package\Notifier\Contract\RecipientInterface;
use NeoPHP\Package\Notifier\NotifierManagerInterface;

/**
 * @internal
 */
class NotificationTopicViewHelper implements ViewFunctionInterface
{
    public function __construct(protected NotifierManagerInterface $notifier)
    {
    }

    public function getName(): string
    {
        return 'notification_topic';
    }

    public function __invoke(RecipientInterface|string $recipient): string
    {
        return $this->notifier->getTopic($recipient);
    }
}