<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Transport;

use NeoPHP\Component\Logger\Contract\LoggerInterface;
use NeoPHP\Component\Mailer\Contract\AbstractTransport;
use NeoPHP\Component\Mailer\Message\SentMessage;
use NeoPHP\Component\Mailer\Mime\Address;

class LogTransport extends AbstractTransport
{
    public function __construct(protected LoggerInterface $logger, protected string $level = 'info')
    {
    }

    public function __toString(): string
    {
        return 'log://' . $this->level;
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getEmail();

        $this->logger->log($this->level, 'Email "{subject}" sent to {recipients} (transport: log).', [
            'subject' => $email->getSubject(),
            'from' => implode(', ', array_map(static fn (Address $address): string => $address->toString(), $email->getFrom())),
            'recipients' => implode(', ', array_map(static fn (Address $address): string => $address->getAddress(), $message->getEnvelope()->getRecipients())),
            'message_id' => $message->getMessageId(),
            'size' => strlen($message->toString()),
        ]);

        $message->setTransportId($message->getMessageId());
    }
}