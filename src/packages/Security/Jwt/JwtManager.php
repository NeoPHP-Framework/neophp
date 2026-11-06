<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Jwt;

use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\BadCredentialsException;
use NeoPHP\Package\Security\Exception\SecurityException;

class JwtManager
{
    public const ALGORITHMS = [
        'HS256' => 'sha256',
        'HS384' => 'sha384',
        'HS512' => 'sha512'
    ];

    public const MINIMUM_SECRET_LENGTH = 32;

    public const DEFAULT_LIFETIME = 3600;

    protected string $algorithm;

    public function __construct(protected string $secret, string $algorithm = 'HS256', protected int $lifetime = self::DEFAULT_LIFETIME, protected ?string $issuer = null, protected ?string $audience = null, protected int $leeway = 0)
    {
        $this->algorithm = strtoupper($algorithm);

        if (!isset(self::ALGORITHMS[$this->algorithm])) {
            throw new SecurityException('The JWT algorithm "{algorithm}" is not supported: use {allowed}.', 0, null, ['algorithm' => $algorithm, 'allowed' => implode(', ', array_keys(self::ALGORITHMS))]);
        }

        if (strlen($secret) < self::MINIMUM_SECRET_LENGTH) {
            throw new SecurityException('The JWT secret must have at least {length} characters.', 0, null, ['length' => self::MINIMUM_SECRET_LENGTH]);
        }
    }

    public function create(UserInterface $user, array $claims = [], ?int $lifetime = null): string
    {
        $now = time();
        $payload = ['sub' => $user->getUserIdentifier(), 'iat' => $now, 'nbf' => $now, 'exp' => $now + max(1, $lifetime ?? $this->lifetime)] + $claims;

        if ($this->issuer !== null) {
            $payload['iss'] = $this->issuer;
        }

        if ($this->audience !== null) {
            $payload['aud'] = $this->audience;
        }

        $body = self::encode((string) json_encode(['alg' => $this->algorithm, 'typ' => 'JWT'])) . '.' . self::encode((string) json_encode($payload));

        return $body . '.' . $this->signature($body);
    }

    public function decode(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new BadCredentialsException('Invalid access token.');
        }

        $header = self::decodeJson($parts[0]);
        $claims = self::decodeJson($parts[1]);
        $now = time();

        if (($header['alg'] ?? null) !== $this->algorithm || !hash_equals($this->signature($parts[0] . '.' . $parts[1]), $parts[2])) {
            throw new BadCredentialsException('Invalid access token.');
        }

        if (!is_int($claims['exp'] ?? null) || $claims['exp'] + $this->leeway <= $now || (is_int($claims['nbf'] ?? null) && $claims['nbf'] - $this->leeway > $now)) {
            throw new BadCredentialsException('Invalid access token.');
        }

        if (($this->issuer !== null && ($claims['iss'] ?? null) !== $this->issuer) || ($this->audience !== null && !in_array($this->audience, (array) ($claims['aud'] ?? []), true))) {
            throw new BadCredentialsException('Invalid access token.');
        }

        return $claims;
    }

    protected function signature(string $body): string
    {
        return self::encode(hash_hmac(self::ALGORITHMS[$this->algorithm], $body, $this->secret, true));
    }

    protected static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected static function decodeJson(string $segment): array
    {
        $json = base64_decode(strtr($segment, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : [];
    }
}