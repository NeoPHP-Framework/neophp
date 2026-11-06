<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\TwoFactor;

class Totp
{
    public const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const PERIOD = 30;

    public const DIGITS = 6;

    public function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public function getUri(string $secret, string $label, string $issuer): string
    {
        return sprintf('otpauth://totp/%s:%s?%s', rawurlencode($issuer), rawurlencode($label), http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986));
    }

    public function getCode(string $secret, ?int $time = null): string
    {
        return $this->code(self::base32Decode($secret), intdiv($time ?? time(), self::PERIOD));
    }

    public function verify(string $secret, string $code, int $window = 1, ?int $time = null): ?int
    {
        $code = (string) preg_replace('/\s+/', '', $code);

        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }

        $key = self::base32Decode($secret);
        $current = intdiv($time ?? time(), self::PERIOD);

        for ($step = $current - $window; $step <= $current + $window; $step++) {
            if (hash_equals($this->code($key, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public function generateBackupCodes(int $count = 8): array
    {
        $codes = [];

        for ($index = 0; $index < $count; $index++) {
            $codes[] = strtoupper(bin2hex(random_bytes(5)));
        }

        return $codes;
    }

    public function hashBackupCode(string $code): string
    {
        return hash('sha256', strtoupper(trim($code)));
    }

    protected function code(string $key, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    protected static function base32Encode(string $value): string
    {
        $bits = '';

        foreach (str_split($value) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    protected static function base32Decode(string $value): string
    {
        $bits = '';

        foreach (str_split(strtoupper(rtrim($value, '='))) as $character) {
            $position = strpos(self::ALPHABET, $character);

            if ($position !== false) {
                $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
            }
        }

        $decoded = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr((int) bindec($chunk));
            }
        }

        return $decoded;
    }
}