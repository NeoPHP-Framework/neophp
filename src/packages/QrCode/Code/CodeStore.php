<?php

declare(strict_types=1);

namespace NeoPHP\Package\QrCode\Code;

use NeoPHP\Component\Cache\Contract\CacheInterface;
use NeoPHP\Package\QrCode\Exception\QrCodeException;
use NeoPHP\Package\QrCode\Signer\PayloadSigner;

/**
 * Short codes typed by hand when a QR code cannot be scanned (e.g. 7Q2K-4MXA).
 * A code is derived from its content with APP_SECRET (the same content always gives the same code) and kept in the cache.
 * Crockford base 32 alphabet: no I, L, O or U, and the typing mistakes O / I / L are corrected.
 *
 * @internal
 */
final class CodeStore
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const KEY_CODE = 'qr_code.code.';

    private const KEY_USED = 'qr_code.used.';

    public function __construct(private readonly ?CacheInterface $cache, private readonly PayloadSigner $signer, private readonly int $length = 8)
    {
    }

    /**
     * Registers the short code of a content.
     *
     * @param int|null $ttl Seconds during which the code can be typed, null = as long as the cache keeps it
     *
     * @return string The code, formatted by groups of 4 characters
     *
     * @throws QrCodeException When no cache pool is available or APP_SECRET is empty
     */
    public function register(string $content, ?int $ttl = null): string
    {
        $code = $this->codeFor($content);
        $this->cache()->set(self::KEY_CODE . $code, $content, $ttl !== null ? max(1, $ttl) : null);

        return self::format($code);
    }

    /**
     * Content of a typed code.
     *
     * @return string|null Null when the code is unknown or expired
     */
    public function resolve(string $code): ?string
    {
        $normalized = $this->normalize($code);

        if ($normalized === null) {
            return null;
        }

        $content = $this->cache()->get(self::KEY_CODE . $normalized);

        return is_string($content) ? $content : null;
    }

    /**
     * Upper case code without separators, typing mistakes corrected (O = 0, I and L = 1).
     *
     * @return string|null Null when the input does not look like a code of the configured length
     */
    public function normalize(string $input): ?string
    {
        $code = strtr(strtoupper((string) preg_replace('/[\s\-_.]+/', '', $input)), ['O' => '0', 'I' => '1', 'L' => '1']);

        if (strlen($code) !== $this->length || strspn($code, self::ALPHABET) !== $this->length) {
            return null;
        }

        return $code;
    }

    /**
     * Marks a single-use content as used.
     *
     * @return bool False when it was already used
     */
    public function consume(string $content, int $ttl): bool
    {
        $key = self::KEY_USED . bin2hex(substr($this->signer->hash($content), 0, 16));

        if ($this->cache()->has($key)) {
            return false;
        }

        return $this->cache()->set($key, time(), max(1, $ttl));
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public static function format(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    /**
     * Code of a content, not formatted (the same content always gives the same code).
     */
    public function codeFor(string $content): string
    {
        $hash = $this->signer->hash($content);
        $bits = '';

        foreach (str_split($hash) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $code = '';

        for ($i = 0; $i < $this->length; $i++) {
            $code .= self::ALPHABET[(int) bindec(substr($bits, $i * 5, 5))];
        }

        return $code;
    }

    /**
     * @throws QrCodeException When no cache pool is available
     */
    private function cache(): CacheInterface
    {
        if ($this->cache === null) {
            throw new QrCodeException('The short codes of the QR codes are kept in a cache pool: enable the Cache component and check cache_pool in {config}.', 0, null, ['config' => 'config/packages/qr_code.yaml']);
        }

        return $this->cache;
    }
}