<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\WebPush;

use NeoPHP\Package\Notifier\Exception\NotifierException;

/**
 * @internal
 */
class Vapid
{
    public const LIFETIME = 43200;

    public function __construct(protected string $publicKey, protected string $privateKey, protected string $subject)
    {
        if (strlen(Keys::decode($publicKey)) !== 65 || strlen(Keys::decode($privateKey)) !== 32) {
            throw new NotifierException('The VAPID keys are invalid: run php bin/neo notifier:vapid and copy them into VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY.');
        }

        if (!str_starts_with($subject, 'mailto:') && !str_starts_with($subject, 'https://')) {
            throw new NotifierException('The VAPID subject "{subject}" must be a mailto: or https:// URL (VAPID_SUBJECT).', 0, null, ['subject' => $subject]);
        }
    }

    /**
     * Returns the Authorization header of a push request to this endpoint.
     */
    public function authorization(string $endpoint, ?int $now = null): string
    {
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $segments = [
            Keys::encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256'])),
            Keys::encode((string) json_encode(['aud' => $audience, 'exp' => ($now ?? time()) + self::LIFETIME, 'sub' => $this->subject], JSON_UNESCAPED_SLASHES)),
        ];
        $key = Keys::privateKey(Keys::decode($this->privateKey), Keys::decode($this->publicKey));

        if (!openssl_sign(implode('.', $segments), $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new NotifierException('The VAPID JWT cannot be signed: {error}', 0, null, ['error' => (string) openssl_error_string()]);
        }

        $segments[] = Keys::encode(static::derToRaw($der));

        return 'vapid t=' . implode('.', $segments) . ', k=' . $this->publicKey;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Turns an ECDSA signature of OpenSSL (DER sequence of two integers) into the R || S form of JWS (64 bytes).
     */
    public static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $raw = '';

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $integer = ltrim(substr($der, $offset + 2, $length), "\0");
            $raw .= str_pad($integer, 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }
}