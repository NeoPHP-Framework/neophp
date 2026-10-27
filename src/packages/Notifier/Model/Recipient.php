<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Model;

use NeoPHP\Package\Notifier\Contract\RecipientInterface;

class Recipient implements RecipientInterface
{
    public function __construct(protected string|int $identifier, protected ?string $email = null)
    {
    }

    public static function from(RecipientInterface $recipient): static
    {
        return new static($recipient->getNotificationIdentifier(), $recipient->getNotificationEmail());
    }

    public function getNotificationIdentifier(): string
    {
        return (string) $this->identifier;
    }

    public function getNotificationEmail(): ?string
    {
        return $this->email;
    }
}