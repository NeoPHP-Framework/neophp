<?php

declare(strict_types=1);

namespace NeoPHP\Package\Notifier\WebPush;

use NeoPHP\Package\Notifier\Exception\NotifierException;
use OpenSSLAsymmetricKey;

/**
 * @internal
 */
class Encryptor
{
    public const RECORD_SIZE = 4096;

    public const MAX_PAYLOAD = 3993;

    /**
     * @param array{0: OpenSSLAsymmetricKey, 1: string}|null $ephemeral Key pair of the application server (key, public point), a new one when null
     * @param string|null $salt 16 random bytes, new ones when null
     * @return string The body of the push request
     * @throws NotifierException When the payload is too large or the encryption fails
     */
    public function encrypt(string $payload, Subscription $subscription, ?array $ephemeral = null, ?string $salt = null): string
    {
        if (strlen($payload) > self::MAX_PAYLOAD) {
            throw new NotifierException('The push payload is {size} bytes long, {max} bytes allowed.', 0, null, ['size' => strlen($payload), 'max' => self::MAX_PAYLOAD]);
        }

        $userPublic = Keys::decode($subscription->getPublicKey());
        $authSecret = Keys::decode($subscription->getAuthSecret());
        [$serverKey, $serverPublic] = $ephemeral ?? Keys::generate();
        $salt ??= random_bytes(16);

        $sharedSecret = openssl_pkey_derive(Keys::publicKey($userPublic), $serverKey, 32);

        if ($sharedSecret === false || strlen($sharedSecret) !== 32) {
            throw new NotifierException('The ECDH agreement of the push payload failed: {error}', 0, null, ['error' => (string) openssl_error_string()]);
        }

        $ikm = hash_hkdf('sha256', $sharedSecret, 32, "WebPush: info\0" . $userPublic . $serverPublic, $authSecret);
        $key = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false) {
            throw new NotifierException('The push payload cannot be encrypted: {error}', 0, null, ['error' => (string) openssl_error_string()]);
        }

        return $salt . pack('N', self::RECORD_SIZE) . chr(strlen($serverPublic)) . $serverPublic . $ciphertext . $tag;
    }
}