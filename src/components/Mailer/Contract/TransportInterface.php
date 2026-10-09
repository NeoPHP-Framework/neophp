<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Contract;

use NeoPHP\Component\Mailer\Message\Envelope;
use NeoPHP\Component\Mailer\Message\SentMessage;
use NeoPHP\Component\Mailer\Mime\Email;
use Stringable;

interface TransportInterface extends Stringable
{
    public function send(Email $email, ?Envelope $envelope = null): SentMessage;
}