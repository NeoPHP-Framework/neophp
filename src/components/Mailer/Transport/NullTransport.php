<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Transport;

use NeoPHP\Component\Mailer\Contract\AbstractTransport;
use NeoPHP\Component\Mailer\Message\SentMessage;

class NullTransport extends AbstractTransport
{
    public function __toString(): string
    {
        return 'null://null';
    }

    protected function doSend(SentMessage $message): void
    {
    }
}