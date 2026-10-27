<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier;

use NeoPHP\Package\Notifier\Contract\RecipientInterface;
use NeoPHP\Package\Notifier\Exception\NotifierException;
use NeoPHP\Package\Notifier\Model\DeliveryResult;
use NeoPHP\Package\Notifier\Model\Notification;
use NeoPHP\Package\Notifier\Model\StoredNotification;
use NeoPHP\Package\Notifier\WebPush\Subscription;

interface NotifierManagerInterface
{
    /**
     * Sends a notification now, on the channels of its importance (or the ones given with Notification::channels()).
     * A channel that fails does not stop the others: every outcome is returned and the failures are logged.
     *
     * @param RecipientInterface|iterable<RecipientInterface> $recipients The recipient or the recipients
     * @return list<DeliveryResult> One result per recipient and channel
     * @throws NotifierException When a channel of the notification does not exist
     */
    public function send(Notification $notification, RecipientInterface|iterable $recipients): array;

    /**
     * Sends a notification in the background: it is dispatched as a job of the Queue package, then sent by a worker.
     *
     * @param RecipientInterface|iterable<RecipientInterface> $recipients The recipient or the recipients (kept as identifier and email address)
     * @param array<string, mixed> $options The dispatch options of the Queue package (transport, queue, delay, priority)
     * @throws NotifierException When the Queue package is disabled
     */
    public function dispatch(Notification $notification, RecipientInterface|iterable $recipients, array $options = []): void;

    /**
     * Returns the channels used for a notification.
     *
     * @return list<string> The names of the channels
     */
    public function getChannelsFor(Notification $notification): array;

    /**
     * Returns the names of the registered channels.
     *
     * @return list<string> database, mercure, push, email and the custom channels
     */
    public function getChannelNames(): array;

    /**
     * Returns the notifications kept by the database channel, the most recent first.
     *
     * @param RecipientInterface|string $recipient The recipient or its identifier
     * @return list<StoredNotification> The notifications
     * @throws NotifierException When the database channel is not available
     */
    public function getNotifications(RecipientInterface|string $recipient, int $limit = 20, bool $unreadOnly = false, int $offset = 0): array;

    /**
     * Counts the unread notifications of a recipient.
     *
     * @throws NotifierException When the database channel is not available
     */
    public function countUnread(RecipientInterface|string $recipient): int;

    /**
     * Marks a notification (or all of them when the id is null) of a recipient as read.
     *
     * @return int The number of notifications marked as read
     * @throws NotifierException When the database channel is not available
     */
    public function markAsRead(RecipientInterface|string $recipient, ?int $id = null): int;

    /**
     * Deletes a notification (or all of them when the id is null) of a recipient.
     *
     * @return int The number of deleted notifications
     * @throws NotifierException When the database channel is not available
     */
    public function deleteNotifications(RecipientInterface|string $recipient, ?int $id = null): int;

    /**
     * Deletes the notifications older than a number of days.
     *
     * @param bool $readOnly Keeps the unread notifications
     * @return int The number of deleted notifications
     * @throws NotifierException When the database channel is not available
     */
    public function purge(int $days, bool $readOnly = false): int;

    /**
     * Saves the push subscription of a browser (sent by the JavaScript of the page) for a recipient.
     *
     * @param array<string, mixed>|Subscription $subscription The JSON of PushSubscription.toJSON(), or a Subscription
     * @throws NotifierException When the subscription is invalid or the database channel is not available
     */
    public function subscribe(RecipientInterface|string $recipient, array|Subscription $subscription): void;

    /**
     * Deletes a push subscription (the browser disabled the notifications, the user logged out).
     *
     * @param RecipientInterface|string|null $recipient Only when it belongs to this recipient
     * @return bool True when a subscription was deleted
     * @throws NotifierException When the database channel is not available
     */
    public function unsubscribe(string $endpoint, RecipientInterface|string|null $recipient = null): bool;

    /**
     * Returns the VAPID public key given to pushManager.subscribe() in the browser.
     *
     * @return string|null The key (base64url), or null when the push channel is not configured
     */
    public function getVapidPublicKey(): ?string;

    /**
     * Creates the tables of the notifications and of the push subscriptions (done automatically on first use unless database.auto_setup is false).
     *
     * @throws NotifierException When the database channel is not available
     */
    public function setup(): void;

    /**
     * Returns the Mercure topic of a recipient, to subscribe to with mercure(topic, { subscribe: true }).
     */
    public function getTopic(RecipientInterface|string $recipient): string;
}