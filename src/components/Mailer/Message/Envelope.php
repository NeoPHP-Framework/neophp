<?php

declare(strict_types=1);

namespace NeoPHP\Component\Mailer\Message;

use NeoPHP\Component\Mailer\Exception\MailerException;
use NeoPHP\Component\Mailer\Mime\Address;
use NeoPHP\Component\Mailer\Mime\Email;

class Envelope
{
    protected Address $sender;

    protected array $recipients = [];

    public function __construct(Address|string $sender, array $recipients)
    {
        $this->setSender($sender);
        $this->setRecipients($recipients);
    }

    public static function create(Email $email): self
    {
        $sender = $email->getReturnPath() ?? $email->getSender() ?? ($email->getFrom()[0] ?? null);

        if ($sender === null) {
            throw new MailerException('Unable to build the envelope: the email has no sender.');
        }

        return new self(new Address($sender->getAddress()), $email->getRecipients());
    }

    public function getSender(): Address
    {
        return $this->sender;
    }

    public function setSender(Address|string $sender): static
    {
        $this->sender = new Address(Address::create($sender)->getAddress());

        return $this;
    }

    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function setRecipients(array $recipients): static
    {
        $unique = [];

        foreach (Address::createArray($recipients) as $recipient) {
            $unique[strtolower($recipient->getAddress())] = new Address($recipient->getAddress());
        }

        if ($unique === []) {
            throw new MailerException('The envelope must have at least one recipient.');
        }

        $this->recipients = array_values($unique);

        return $this;
    }
}