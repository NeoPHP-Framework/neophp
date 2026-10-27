<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Helper\Controller;

use NeoPHP\Package\Notifier\Contract\RecipientInterface;
use NeoPHP\Package\Notifier\Model\DeliveryResult;
use NeoPHP\Package\Notifier\Model\Notification;
use NeoPHP\Package\Notifier\NotifierManagerInterface;

trait NotifierController
{
    abstract protected function get(string $id): mixed;

    /**
     * @param Notification $notification
     * @param RecipientInterface|iterable $recipients
     * @return list<DeliveryResult>
     */
    protected function sendNotification(Notification $notification, RecipientInterface|iterable $recipients): array
    {
        return $this->get(NotifierManagerInterface::class)->send($notification, $recipients);
    }
}