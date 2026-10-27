<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier;

use NeoPHP\Component\Kernel\Attribute\Package;
use NeoPHP\Component\Logger\Contract\LoggerInterface;
use NeoPHP\Package\Notifier\Channel\MercureChannel;
use NeoPHP\Package\Notifier\Contract\ChannelInterface;
use NeoPHP\Package\Notifier\Contract\RecipientInterface;
use NeoPHP\Package\Notifier\Exception\NotifierException;
use NeoPHP\Package\Notifier\Helper\Queue\SendNotificationJob;
use NeoPHP\Package\Notifier\Model\DeliveryResult;
use NeoPHP\Package\Notifier\Model\Notification;
use NeoPHP\Package\Notifier\Model\Recipient;
use NeoPHP\Package\Notifier\Provider\NotifierProvider;
use NeoPHP\Package\Notifier\Storage\NotificationStore;
use NeoPHP\Package\Notifier\WebPush\Subscription;
use NeoPHP\Package\Notifier\WebPush\Vapid;
use NeoPHP\Package\Queue\Contract\MessageBusInterface;
use Throwable;

#[Package(provider: NotifierProvider::class)]
final class NotifierManager implements NotifierManagerInterface
{
    public const CONFIG_FILE = 'config/packages/notifier.yaml';

    public const DEFAULT_POLICY = [
        Notification::IMPORTANCE_LOW => ['database'],
        Notification::IMPORTANCE_NORMAL => ['database', 'mercure'],
        Notification::IMPORTANCE_HIGH => ['database', 'mercure', 'push'],
        Notification::IMPORTANCE_URGENT => ['database', 'mercure', 'push', 'email'],
    ];

    /**
     * @var array<string, ChannelInterface>
     */
    protected array $channels = [];

    /**
     * @var array<string, list<string>>
     */
    protected array $policy;

    /**
     * @param iterable<ChannelInterface> $channels
     * @param array<string, mixed> $policy Channels by importance (lists of channel names, from the configuration)
     */
    public function __construct(
        iterable $channels,
        array $policy = [],
        protected ?NotificationStore $store = null,
        protected ?MessageBusInterface $bus = null,
        protected ?LoggerInterface $logger = null,
        protected ?Vapid $vapid = null,
        protected string $topic = 'notifications/{id}',
    ) {
        foreach ($channels as $channel) {
            $this->channels[$channel->getName()] = $channel;
        }

        $this->policy = array_map(static fn (mixed $names): array => array_values(array_filter((array) $names, 'is_string')), array_replace(self::DEFAULT_POLICY, $policy));
    }

    public function send(Notification $notification, RecipientInterface|iterable $recipients): array
    {
        $channels = [];

        foreach ($this->getChannelsFor($notification) as $name) {
            if (!isset($this->channels[$name])) {
                throw new NotifierException('The notification channel "{channel}" does not exist: use {channels}.', 0, null, ['channel' => $name, 'channels' => implode(', ', array_keys($this->channels))]);
            }

            $channels[] = $this->channels[$name];
        }

        $results = [];

        foreach ($recipients instanceof RecipientInterface ? [$recipients] : $recipients as $recipient) {
            foreach ($channels as $channel) {
                $results[] = $this->deliver($channel, $notification, $recipient);
            }
        }

        return $results;
    }

    public function dispatch(Notification $notification, RecipientInterface|iterable $recipients, array $options = []): void
    {
        if ($this->bus === null) {
            throw new NotifierException('The Queue package is disabled: send the notification with send() instead of dispatch().');
        }

        $snapshots = [];

        foreach ($recipients instanceof RecipientInterface ? [$recipients] : $recipients as $recipient) {
            $snapshots[] = Recipient::from($recipient);
        }

        $this->bus->dispatch(new SendNotificationJob($notification, $snapshots), $options);
    }

    public function getChannelsFor(Notification $notification): array
    {
        return $notification->getChannels() !== [] ? $notification->getChannels() : ($this->policy[$notification->getImportance()] ?? []);
    }

    public function getChannelNames(): array
    {
        return array_keys($this->channels);
    }

    public function getNotifications(RecipientInterface|string $recipient, int $limit = 20, bool $unreadOnly = false, int $offset = 0): array
    {
        return $this->store()->find($this->identifier($recipient), $limit, $unreadOnly, $offset);
    }

    public function countUnread(RecipientInterface|string $recipient): int
    {
        return $this->store()->countUnread($this->identifier($recipient));
    }

    public function markAsRead(RecipientInterface|string $recipient, ?int $id = null): int
    {
        return $this->store()->markAsRead($this->identifier($recipient), $id);
    }

    public function deleteNotifications(RecipientInterface|string $recipient, ?int $id = null): int
    {
        return $this->store()->delete($this->identifier($recipient), $id);
    }

    public function purge(int $days, bool $readOnly = false): int
    {
        return $this->store()->purge(time() - max(0, $days) * 86400, $readOnly);
    }

    public function subscribe(RecipientInterface|string $recipient, array|Subscription $subscription): void
    {
        $this->store()->saveSubscription($this->identifier($recipient), $subscription instanceof Subscription ? $subscription : Subscription::fromArray($subscription));
    }

    public function unsubscribe(string $endpoint, RecipientInterface|string|null $recipient = null): bool
    {
        return $this->store()->deleteSubscription($endpoint, $recipient === null ? null : $this->identifier($recipient));
    }

    public function setup(): void
    {
        $this->store()->setup();
    }

    public function getVapidPublicKey(): ?string
    {
        return $this->vapid?->getPublicKey();
    }

    public function getTopic(RecipientInterface|string $recipient): string
    {
        return (new MercureChannel(null, $this->topic))->topic($recipient);
    }

    protected function deliver(ChannelInterface $channel, Notification $notification, RecipientInterface $recipient): DeliveryResult
    {
        $identifier = $recipient->getNotificationIdentifier();

        try {
            $reason = $channel->unavailableReason($notification, $recipient);

            if ($reason !== null) {
                return new DeliveryResult($identifier, $channel->getName(), DeliveryResult::SKIPPED, $reason);
            }

            $channel->send($notification, $recipient);

            return new DeliveryResult($identifier, $channel->getName(), DeliveryResult::SENT);
        } catch (Throwable $exception) {
            $this->logger?->error('The notification "{subject}" cannot be sent to "{recipient}" on the channel {channel}: {error}', [
                'subject' => $notification->getSubject(),
                'recipient' => $identifier,
                'channel' => $channel->getName(),
                'error' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return new DeliveryResult($identifier, $channel->getName(), DeliveryResult::FAILED, $exception->getMessage());
        }
    }

    protected function identifier(RecipientInterface|string $recipient): string
    {
        return $recipient instanceof RecipientInterface ? $recipient->getNotificationIdentifier() : $recipient;
    }

    protected function store(): NotificationStore
    {
        if ($this->store === null) {
            throw new NotifierException('The notifications are not stored: the Database component is disabled or has no connection.');
        }

        return $this->store;
    }
}