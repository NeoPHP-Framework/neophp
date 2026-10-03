<?php

declare(strict_types=1);

namespace NeoPHP\Package\Queue\Serializer;

use NeoPHP\Package\Queue\Exception\ConfigurationException;
use NeoPHP\Package\Queue\Exception\SerializationException;
use Throwable;

class MessageSerializer
{
    public const ALGORITHM = 'sha256';

    public function __construct(protected ?string $secret = null)
    {
    }

    public function encode(object $message): string
    {
        try {
            $payload = base64_encode(serialize($message));
        } catch (Throwable $exception) {
            throw new SerializationException('The message "{class}" cannot be serialized: {error}', 0, $exception, [
                'class' => $message::class,
                'error' => $exception->getMessage(),
            ]);
        }

        return $payload . '.' . $this->sign($payload);
    }

    public function decode(string $body): object
    {
        $position = strrpos($body, '.');

        if ($position === false) {
            throw new SerializationException('The message payload is not signed.');
        }

        $payload = substr($body, 0, $position);
        $signature = substr($body, $position + 1);

        if (!hash_equals($this->sign($payload), $signature)) {
            throw new SerializationException('The message payload signature is invalid: the payload was tampered with or APP_SECRET changed.');
        }

        $data = base64_decode($payload, true);
        $message = $data === false ? false : @unserialize($data);

        if (!is_object($message)) {
            throw new SerializationException('The message payload cannot be unserialized.');
        }

        return $message;
    }

    protected function sign(string $payload): string
    {
        if ($this->secret === null || $this->secret === '') {
            throw new ConfigurationException('The queue messages are signed with "{key}": define APP_SECRET in .env.', 0, null, ['key' => 'framework.app.secret']);
        }

        return hash_hmac(self::ALGORITHM, $payload, $this->secret);
    }
}