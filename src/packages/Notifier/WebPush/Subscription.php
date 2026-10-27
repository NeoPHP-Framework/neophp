<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\WebPush;

use NeoPHP\Package\Notifier\Exception\NotifierException;

class Subscription
{
    public function __construct(protected string $endpoint, protected string $publicKey, protected string $authSecret)
    {
        if (!str_starts_with($endpoint, 'https://')) {
            throw new NotifierException('The push endpoint "{endpoint}" must be an https:// URL.', 0, null, ['endpoint' => $endpoint]);
        }

        if (strlen(Keys::decode($publicKey)) !== 65 || strlen(Keys::decode($authSecret)) !== 16) {
            throw new NotifierException('The keys of the push subscription are invalid (p256dh: 65 bytes, auth: 16 bytes, base64url).');
        }
    }

    /**
     * Creates a subscription from the JSON of PushSubscription.toJSON() in the browser: {endpoint, keys: {p256dh, auth}}.
     *
     * @throws NotifierException When the endpoint or the keys are missing or invalid
     */
    public static function fromArray(array $subscription): static
    {
        $keys = (array) ($subscription['keys'] ?? []);

        if (!is_string($subscription['endpoint'] ?? null) || !is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null)) {
            throw new NotifierException('A push subscription needs an endpoint and the keys p256dh and auth.');
        }

        return new static($subscription['endpoint'], $keys['p256dh'], $keys['auth']);
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Returns the public key of the browser (p256dh), base64url.
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Returns the authentication secret of the browser (auth), base64url.
     */
    public function getAuthSecret(): string
    {
        return $this->authSecret;
    }

    public function toArray(): array
    {
        return ['endpoint' => $this->endpoint, 'keys' => ['p256dh' => $this->publicKey, 'auth' => $this->authSecret]];
    }
}