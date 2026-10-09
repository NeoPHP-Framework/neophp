<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Helper\Controller;

use NeoPHP\Component\Mailer\MailerManagerInterface;
use NeoPHP\Component\Mailer\Message\SentMessage;
use NeoPHP\Component\Mailer\Mime\Email;

trait MailerController
{
    abstract protected function get(string $id): mixed;

    protected function sendEmail(Email $email): ?SentMessage
    {
        return $this->get(MailerManagerInterface::class)->send($email);
    }
}