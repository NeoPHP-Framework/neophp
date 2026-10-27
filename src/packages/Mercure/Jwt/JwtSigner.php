<?php

declare(strict_types=1);

namespace NeoPHP\Package\Mercure\Jwt;

use JsonException;
use NeoPHP\Package\Mercure\Exception\MercureException;
use stdClass;

/**
 * @internal
 */
class JwtSigner
{
    public const ALGORITHMS = [
        'HS256' => 'sha256',
        'HS384' => 'sha384',
        'HS512' => 'sha512',
    ];

    protected string $algorithm;

    public function __construct(protected string $secret, string $algorithm = 'HS256')
    {
        $this->algorithm = strtoupper($algorithm);

        if ($secret === '') {
            throw new MercureException('The Mercure JWT secret is empty: set MERCURE_JWT_SECRET (jwt.secret in config/packages/mercure.yaml).');
        }

        if (!isset(self::ALGORITHMS[$this->algorithm])) {
            throw new MercureException('The Mercure JWT algorithm "{algorithm}" is not supported: use {allowed}.', 0, null, [
                'algorithm' => $algorithm,
                'allowed' => implode(', ', array_keys(self::ALGORITHMS)),
            ]);
        }
    }

    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function sign(array $claims): string
    {
        $segments = [
            self::encode(['alg' => $this->algorithm, 'typ' => 'JWT']),
            self::encode($claims === [] ? new stdClass() : $claims),
        ];
        $segments[] = self::base64UrlEncode(hash_hmac(self::ALGORITHMS[$this->algorithm], implode('.', $segments), $this->secret, true));

        return implode('.', $segments);
    }

    /**
     * Returns the claims of a token signed with the secret, after checking its algorithm, its signature and its expiration.
     *
     * @return array<string, mixed>
     */
    public function verify(string $token, ?int $now = null): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new MercureException('The Mercure JWT is malformed: 3 segments expected.');
        }

        [$header, $claims] = [self::decode($segments[0]), self::decode($segments[1])];

        if (($header['alg'] ?? null) !== $this->algorithm) {
            throw new MercureException('The Mercure JWT is signed with "{algorithm}", "{expected}" expected.', 0, null, [
                'algorithm' => is_string($header['alg'] ?? null) ? $header['alg'] : 'none',
                'expected' => $this->algorithm,
            ]);
        }

        $signature = self::base64UrlEncode(hash_hmac(self::ALGORITHMS[$this->algorithm], $segments[0] . '.' . $segments[1], $this->secret, true));

        if (!hash_equals($signature, $segments[2])) {
            throw new MercureException('The signature of the Mercure JWT is invalid.');
        }

        if (isset($claims['exp']) && is_numeric($claims['exp']) && (int) $claims['exp'] <= ($now ?? time())) {
            throw new MercureException('The Mercure JWT has expired.');
        }

        return $claims;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected static function encode(array|object $data): string
    {
        try {
            return self::base64UrlEncode(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $exception) {
            throw new MercureException('The claims of the Mercure JWT cannot be encoded as JSON: {error}.', 0, $exception, ['error' => $exception->getMessage()]);
        }
    }

    protected static function decode(string $segment): array
    {
        $json = base64_decode(strtr($segment, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        if (!is_array($data)) {
            throw new MercureException('The Mercure JWT is malformed: a segment is not base64url encoded JSON.');
        }

        return $data;
    }
}