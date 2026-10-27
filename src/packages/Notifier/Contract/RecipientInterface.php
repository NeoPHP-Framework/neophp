<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Contract;

interface RecipientInterface
{
    /**
     * Returns the identifier of the recipient: the key of its notifications and of its push subscriptions, the {id} of its Mercure topic.
     */
    public function getNotificationIdentifier(): string;

    /**
     * Returns the email address of the email channel, or null when the recipient has none.
     */
    public function getNotificationEmail(): ?string;
}