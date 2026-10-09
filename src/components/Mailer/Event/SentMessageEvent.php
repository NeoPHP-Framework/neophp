<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Event;

use NeoPHP\Component\Event\Contract\AbstractEvent;
use NeoPHP\Component\Mailer\Message\SentMessage;

class SentMessageEvent extends AbstractEvent
{
    public function __construct(protected SentMessage $message)
    {
    }

    public function getMessage(): SentMessage
    {
        return $this->message;
    }
}