<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Signer;

use JsonException;
use NeoPHP\Package\QrCode\Exception\QrCodeException;

/**
 * @internal
 */
class PayloadSigner
{
    public const PREFIX = 'NQR1';

    private const SIGNATURE_BYTES = 16;

    public function __construct(private string $secret)
    {
    }

    /**
     * @throws QrCodeException When APP_SECRET is empty or the data cannot be encoded in JSON
     */
    public function sign(mixed $data, ?int $expiresAt = null, bool $once = false): string
    {
        $this->assertSecret();
        $payload = ['d' => $data];

        if ($expiresAt !== null) {
            $payload['e'] = $expiresAt;
        }

        if ($once) {
            $payload['o'] = 1;
        }

        try {
            $body = self::PREFIX . '.' . self::base64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new QrCodeException('The data of the signed QR code cannot be encoded in JSON: {error}', 0, $exception, ['error' => $exception->getMessage()]);
        }

        return $body . '.' . $this->signature($body);
    }

    public function isSigned(string $content): bool
    {
        return str_starts_with($content, self::PREFIX . '.');
    }

    /**
     * Reads a signed content.
     *
     * @return array{data: mixed, expires_at: int|null, once: bool}|null The payload, null when the content or its signature is invalid
     *
     * @throws QrCodeException When APP_SECRET is empty
     */
    public function verify(string $content): ?array
    {
        $this->assertSecret();
        $parts = explode('.', $content);

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }

        if (!hash_equals($this->signature($parts[0] . '.' . $parts[1]), $parts[2])) {
            return null;
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $payload = $json !== false ? json_decode($json, true) : null;

        if (!is_array($payload) || !array_key_exists('d', $payload)) {
            return null;
        }

        return [
            'data' => $payload['d'],
            'expires_at' => isset($payload['e']) ? (int) $payload['e'] : null,
            'once' => (bool) ($payload['o'] ?? false),
        ];
    }

    /**
     * Keyed hash of a value, used to derive the short codes.
     */
    public function hash(string $value): string
    {
        $this->assertSecret();

        return hash_hmac('sha256', 'qr_code:' . $value, $this->secret, true);
    }

    private function signature(string $body): string
    {
        return self::base64(substr(hash_hmac('sha256', $body, $this->secret, true), 0, self::SIGNATURE_BYTES));
    }

    private function assertSecret(): void
    {
        if ($this->secret === '') {
            throw new QrCodeException('The signed QR codes and the short codes need a secret: define APP_SECRET in .env ({key}).', 0, null, ['key' => 'framework.app.secret']);
        }
    }

    private static function base64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}