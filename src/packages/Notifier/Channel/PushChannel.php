<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\Channel;

use NeoPHP\Component\HttpClient\HttpClientManagerInterface;
use NeoPHP\Package\Notifier\Contract\ChannelInterface;
use NeoPHP\Package\Notifier\Contract\RecipientInterface;
use NeoPHP\Package\Notifier\Exception\NotifierException;
use NeoPHP\Package\Notifier\Model\Notification;
use NeoPHP\Package\Notifier\Storage\NotificationStore;
use NeoPHP\Package\Notifier\WebPush\Encryptor;
use NeoPHP\Package\Notifier\WebPush\Subscription;
use NeoPHP\Package\Notifier\WebPush\Vapid;
use Throwable;

/**
 * @internal
 */
class PushChannel implements ChannelInterface
{
    public const NAME = 'push';

    public const URGENCY = ['low' => 'low', 'normal' => 'normal', 'high' => 'high', 'urgent' => 'high'];

    public function __construct(
        protected ?NotificationStore $store,
        protected ?HttpClientManagerInterface $http,
        protected ?Vapid $vapid,
        protected int $ttl = 86400,
        protected Encryptor $encryptor = new Encryptor(),
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function unavailableReason(Notification $notification, RecipientInterface $recipient): ?string
    {
        return match (true) {
            $this->vapid === null => 'the VAPID keys are not configured (php bin/neo notifier:vapid)',
            $this->store === null => 'the Database component is disabled or has no connection',
            $this->http === null => 'the HttpClient component is disabled',
            $this->store->getSubscriptions($recipient->getNotificationIdentifier()) === [] => 'the recipient has no push subscription',
            default => null,
        };
    }

    public function send(Notification $notification, RecipientInterface $recipient): void
    {
        if ($this->store === null || $this->http === null || $this->vapid === null) {
            return;
        }

        $payload = (string) json_encode($notification->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $errors = [];
        $sent = 0;

        foreach ($this->store->getSubscriptions($recipient->getNotificationIdentifier()) as $subscription) {
            try {
                $sent += $this->push($subscription, $payload, $notification->getImportance()) ? 1 : 0;
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ($sent === 0) {
            throw $errors === []
                ? new NotifierException('Every push subscription of the recipient has expired: they were deleted.')
                : new NotifierException('The push notification failed on every subscription: {errors}', 0, null, ['errors' => implode(' | ', $errors)]);
        }
    }

    /**
     * Sends a payload to one subscription; an expired subscription (404, 410) is deleted.
     *
     * @return bool True when the push service accepted it
     * @throws NotifierException When the push service refuses the notification
     */
    public function push(Subscription $subscription, string $payload, string $importance = 'normal'): bool
    {
        if ($this->http === null || $this->vapid === null) {
            return false;
        }

        $response = $this->http->post($subscription->getEndpoint(), [
            'headers' => [
                'Authorization' => $this->vapid->authorization($subscription->getEndpoint()),
                'TTL' => (string) $this->ttl,
                'Urgency' => self::URGENCY[$importance] ?? 'normal',
                'Content-Type' => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
            ],
            'body' => $this->encryptor->encrypt($payload, $subscription),
        ]);
        $status = $response->getStatusCode();

        if ($status === 404 || $status === 410) {
            $this->store?->deleteSubscription($subscription->getEndpoint());

            return false;
        }

        if ($status < 200 || $status >= 300) {
            throw new NotifierException('The push service refused the notification (HTTP {status}): {content}', 0, null, ['status' => $status, 'content' => trim($response->getContent(false)) ?: 'empty response']);
        }

        return true;
    }
}