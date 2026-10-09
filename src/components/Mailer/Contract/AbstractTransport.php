<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Contract;

use NeoPHP\Component\Mailer\Message\Envelope;
use NeoPHP\Component\Mailer\Message\SentMessage;
use NeoPHP\Component\Mailer\Mime\Email;
use NeoPHP\Component\Mailer\Mime\MimeRenderer;

abstract class AbstractTransport implements TransportInterface
{
    protected ?MimeRenderer $renderer = null;

    public function send(Email $email, ?Envelope $envelope = null): SentMessage
    {
        $email->validate();
        $renderer = $this->getRenderer();
        $messageId = $renderer->generateMessageId($email);
        $message = new SentMessage($email, $envelope ?? Envelope::create($email), $messageId, $renderer->render($email, $messageId), (string) $this);

        $this->doSend($message);

        return $message;
    }

    public function getRenderer(): MimeRenderer
    {
        return $this->renderer ??= new MimeRenderer();
    }

    public function setRenderer(MimeRenderer $renderer): static
    {
        $this->renderer = $renderer;

        return $this;
    }

    abstract protected function doSend(SentMessage $message): void;
}