<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Contract;

use NeoPHP\Package\Notifier\Exception\NotifierException;
use NeoPHP\Package\Notifier\Model\Notification;

interface ChannelInterface
{
    /**
     * Returns the name of the channel used in the channel policy and in Notification::channels().
     */
    public function getName(): string;

    /**
     * Returns why the channel cannot deliver this notification to this recipient (module disabled, no email address...), or null.
     */
    public function unavailableReason(Notification $notification, RecipientInterface $recipient): ?string;

    /**
     * Delivers the notification.
     *
     * @throws NotifierException When the delivery fails
     */
    public function send(Notification $notification, RecipientInterface $recipient): void;
}