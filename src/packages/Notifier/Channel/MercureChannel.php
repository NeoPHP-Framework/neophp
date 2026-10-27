<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Channel;

use NeoPHP\Package\Mercure\MercureManagerInterface;
use NeoPHP\Package\Mercure\Model\Update;
use NeoPHP\Package\Notifier\Contract\ChannelInterface;
use NeoPHP\Package\Notifier\Contract\RecipientInterface;
use NeoPHP\Package\Notifier\Exception\NotifierException;
use NeoPHP\Package\Notifier\Model\Notification;
use Throwable;

/**
 * @internal
 */
class MercureChannel implements ChannelInterface
{
    public const NAME = 'mercure';

    public const EVENT_TYPE = 'notification';

    public function __construct(protected ?MercureManagerInterface $mercure, protected string $topic = 'notifications/{id}')
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function topic(RecipientInterface|string $recipient): string
    {
        $identifier = $recipient instanceof RecipientInterface ? $recipient->getNotificationIdentifier() : $recipient;

        return str_replace('{id}', rawurlencode($identifier), $this->topic);
    }

    public function unavailableReason(Notification $notification, RecipientInterface $recipient): ?string
    {
        return $this->mercure === null ? 'the Mercure package is disabled' : null;
    }

    public function send(Notification $notification, RecipientInterface $recipient): void
    {
        if ($this->mercure === null) {
            return;
        }

        try {
            $this->mercure->publish(Update::json($this->topic($recipient), $notification->toArray(), true, null, self::EVENT_TYPE));
        } catch (Throwable $exception) {
            throw new NotifierException('The notification cannot be published to Mercure: {error}', 0, $exception, ['error' => $exception->getMessage()]);
        }
    }
}