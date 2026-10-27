<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Model;

class DeliveryResult
{
    public const SENT = 'sent';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public function __construct(
        protected string $recipient,
        protected string $channel,
        protected string $status,
        protected ?string $message = null,
    ) {
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Returns why the channel was skipped or failed.
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function isSent(): bool
    {
        return $this->status === self::SENT;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }
}