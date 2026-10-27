<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Helper\Queue;

use NeoPHP\Package\Notifier\Model\Notification;
use NeoPHP\Package\Notifier\Model\Recipient;
use NeoPHP\Package\Notifier\NotifierManagerInterface;
use NeoPHP\Package\Queue\Contract\JobInterface;

/**
 * @internal
 */
class SendNotificationJob implements JobInterface
{
    /**
     * @param list<Recipient> $recipients
     */
    public function __construct(protected Notification $notification, protected array $recipients)
    {
    }

    public function getNotification(): Notification
    {
        return $this->notification;
    }

    /**
     * @return list<Recipient>
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function handle(NotifierManagerInterface $notifier): void
    {
        $notifier->send($this->notification, $this->recipients);
    }
}