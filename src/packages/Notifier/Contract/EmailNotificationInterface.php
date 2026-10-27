<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Contract;

use NeoPHP\Component\Mailer\Mime\Email;

interface EmailNotificationInterface
{
    /**
     * Returns the email to send to this recipient (the recipient address is added when the email has no recipient), or null to skip the email channel.
     */
    public function toEmail(RecipientInterface $recipient): ?Email;
}