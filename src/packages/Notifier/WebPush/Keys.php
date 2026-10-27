<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\WebPush;

use NeoPHP\Package\Notifier\Exception\NotifierException;
use OpenSSLAsymmetricKey;

/**
 * @internal
 */
class Keys
{
    // DER prefixes of a P-256 SubjectPublicKeyInfo and of a SEC1 ECPrivateKey (RFC 5480, RFC 5915)
    protected const PUBLIC_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    protected const PRIVATE_PREFIX = '30770201010420';

    protected const PRIVATE_MIDDLE = 'a00a06082a8648ce3d030107a144034200';

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);

        return $bytes === false ? '' : $bytes;
    }

    /**
     * @param string $point Uncompressed point (0x04 || X || Y, 65 bytes)
     * @throws NotifierException
     */
    public static function publicKey(string $point): OpenSSLAsymmetricKey
    {
        static::assertExtension();

        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new NotifierException('A P-256 public key must be an uncompressed point of 65 bytes.');
        }

        $key = openssl_pkey_get_public(static::pem(hex2bin(self::PUBLIC_PREFIX) . $point, 'PUBLIC KEY'));

        if ($key === false) {
            throw new NotifierException('The P-256 public key cannot be read by OpenSSL.');
        }

        return $key;
    }

    /**
     * @param string $secret Private scalar (32 bytes)
     * @param string $point Uncompressed public point (65 bytes)
     * @throws NotifierException
     */
    public static function privateKey(string $secret, string $point): OpenSSLAsymmetricKey
    {
        static::assertExtension();

        if (strlen($secret) !== 32 || strlen($point) !== 65) {
            throw new NotifierException('A P-256 private key needs a 32 bytes secret and a 65 bytes public point.');
        }

        $der = hex2bin(self::PRIVATE_PREFIX) . $secret . hex2bin(self::PRIVATE_MIDDLE) . $point;
        $key = openssl_pkey_get_private(static::pem($der, 'EC PRIVATE KEY'));

        if ($key === false) {
            throw new NotifierException('The P-256 private key cannot be read by OpenSSL.');
        }

        return $key;
    }

    /**
     * Creates a new P-256 key pair.
     *
     * @return array{0: OpenSSLAsymmetricKey, 1: string, 2: string} The key, its public point (65 bytes) and its private scalar (32 bytes)
     * @throws NotifierException
     */
    public static function generate(): array
    {
        static::assertExtension();
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if ($key === false || !is_array($details) || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            throw new NotifierException('OpenSSL cannot create a P-256 key: {error}', 0, null, ['error' => (string) openssl_error_string()]);
        }

        $point = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        return [$key, $point, str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT)];
    }

    protected static function pem(string $der, string $label): string
    {
        return "-----BEGIN {$label}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$label}-----\n";
    }

    protected static function assertExtension(): void
    {
        if (!extension_loaded('openssl')) {
            throw new NotifierException('The push channel requires the PHP extension openssl (ext-openssl).');
        }
    }
}