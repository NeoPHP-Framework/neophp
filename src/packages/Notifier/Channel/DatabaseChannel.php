<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Channel;

use NeoPHP\Package\Notifier\Contract\ChannelInterface;
use NeoPHP\Package\Notifier\Contract\RecipientInterface;
use NeoPHP\Package\Notifier\Model\Notification;
use NeoPHP\Package\Notifier\Storage\NotificationStore;

/**
 * @internal
 */
class DatabaseChannel implements ChannelInterface
{
    public const NAME = 'database';

    public function __construct(protected ?NotificationStore $store)
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function unavailableReason(Notification $notification, RecipientInterface $recipient): ?string
    {
        return $this->store === null ? 'the Database component is disabled or has no connection' : null;
    }

    public function send(Notification $notification, RecipientInterface $recipient): void
    {
        $this->store?->add($recipient->getNotificationIdentifier(), $notification);
    }
}