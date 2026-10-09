<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\RememberMe;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Package\Security\Authentication\Passport;
use NeoPHP\Package\Security\Contract\PasswordAuthenticatedUserInterface;
use NeoPHP\Package\Security\Contract\UserInterface;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\Firewall\HttpUtils;
use NeoPHP\Package\Security\User\UserClass;

class RememberMeHandler
{
    public const DEFAULT_OPTIONS = [
        'name' => 'REMEMBERME',
        'lifetime' => 604800,
        'path' => '/',
        'domain' => null,
        'secure' => 'auto',
        'httponly' => true,
        'samesite' => 'Lax',
        'parameter' => '_remember_me',
        'always' => false,
        'storage' => 'signature',
        'connection' => null,
        'table' => DatabaseTokenProvider::DEFAULT_TABLE,
    ];

    public const PERSISTENT_PREFIX = 'p';

    protected array $options;

    public function __construct(protected HttpUtils $http, protected string $secret, array $options = [], protected ?TokenProviderInterface $provider = null)
    {
        if ($secret === '') {
            throw new SecurityException('The remember-me feature needs a secret: define APP_SECRET in .env.');
        }

        $this->options = array_replace(static::DEFAULT_OPTIONS, array_intersect_key($options, static::DEFAULT_OPTIONS));
    }

    public function getOption(string $name): mixed
    {
        return $this->options[$name] ?? null;
    }

    public function getProvider(): ?TokenProviderInterface
    {
        return $this->provider;
    }

    public function isRequested(Passport $passport): bool
    {
        return (bool) $this->options['always'] || $passport->isRememberMe();
    }

    public function createCookie(UserInterface $user): void
    {
        $expires = time() + (int) $this->options['lifetime'];
        $identifier = $user->getUserIdentifier();

        if ($this->provider !== null) {
            $series = self::encode(random_bytes(24));
            $secret = self::encode(random_bytes(32));
            $request = $this->http->getRequest();
            $userAgent = $request?->headers->get('User-Agent');

            $this->provider->createToken(new PersistentToken(
                $series,
                hash('sha256', $secret),
                UserClass::of($user),
                $identifier,
                $this->fingerprint($user),
                time(),
                time(),
                $expires,
                is_string($userAgent) ? $userAgent : null,
                $request?->getClientIp(),
            ));
            $value = self::PERSISTENT_PREFIX . ':' . $series . ':' . $secret;
        } else {
            $value = self::encode($identifier) . ':' . $expires . ':' . $this->signature($identifier, $expires, $user);
        }

        $this->http->getCookies()->set((string) $this->options['name'], $value, $this->cookieOptions() + ['lifetime' => (int) $this->options['lifetime']]);
    }

    public function clearCookie(): void
    {
        $request = $this->http->getRequest();
        $series = $request === null ? null : $this->getSeries($request);

        if ($series !== null) {
            $this->provider?->deleteToken($series);
        }

        $this->http->getCookies()->remove((string) $this->options['name'], $this->cookieOptions());
    }

    public function getSeries(Request $request): ?string
    {
        $value = $request->cookies->get((string) $this->options['name']);

        if (!is_string($value) || !str_starts_with($value, self::PERSISTENT_PREFIX . ':') || substr_count($value, ':') !== 2) {
            return null;
        }

        return explode(':', $value)[1];
    }

    public function hasCookie(Request $request): bool
    {
        return is_string($request->cookies->get((string) $this->options['name']));
    }

    public function parse(Request $request): ?array
    {
        $value = $request->cookies->get((string) $this->options['name']);

        if (!is_string($value) || substr_count($value, ':') !== 2) {
            return null;
        }

        if (str_starts_with($value, self::PERSISTENT_PREFIX . ':')) {
            return $this->parsePersistent($value);
        }

        [$identifier, $expires, $signature] = explode(':', $value);
        $identifier = self::decode($identifier);

        if ($identifier === null || $identifier === '' || !ctype_digit($expires) || $signature === '') {
            return null;
        }

        return ['identifier' => $identifier, 'expires' => (int) $expires, 'signature' => $signature];
    }

    public function isValid(UserInterface $user, int $expires, string $signature): bool
    {
        return $expires > time() && hash_equals($this->signature($user->getUserIdentifier(), $expires, $user), $signature);
    }

    public function validate(UserInterface $user, array $cookie): bool
    {
        if (!isset($cookie['series'])) {
            return $this->isValid($user, (int) $cookie['expires'], (string) $cookie['signature']);
        }

        if ($cookie['expires'] <= time() || $cookie['class'] !== UserClass::of($user) || !hash_equals($cookie['fingerprint'], $this->fingerprint($user))) {
            $this->provider?->deleteToken((string) $cookie['series']);

            return false;
        }

        $this->provider?->touchToken((string) $cookie['series'], time());

        return true;
    }

    protected function parsePersistent(string $value): ?array
    {
        [, $series, $secret] = explode(':', $value);

        if ($this->provider === null || $series === '' || $secret === '') {
            return null;
        }

        $token = $this->provider->loadToken($series);

        if ($token === null || !hash_equals($token->tokenHash, hash('sha256', $secret))) {
            return null;
        }

        return [
            'identifier' => $token->identifier,
            'expires' => $token->expiresAt,
            'signature' => '',
            'series' => $token->series,
            'class' => $token->class,
            'fingerprint' => $token->fingerprint,
        ];
    }

    protected function fingerprint(UserInterface $user): string
    {
        $password = $user instanceof PasswordAuthenticatedUserInterface ? (string) $user->getPassword() : '';

        return hash_hmac('sha256', UserClass::of($user) . '|' . $user->getUserIdentifier() . '|' . $password, $this->secret);
    }

    protected function signature(string $identifier, int $expires, UserInterface $user): string
    {
        $password = $user instanceof PasswordAuthenticatedUserInterface ? (string) $user->getPassword() : '';

        return hash_hmac('sha256', UserClass::of($user) . '|' . $identifier . '|' . $expires . '|' . $password, $this->secret);
    }

    protected function cookieOptions(): array
    {
        return [
            'path' => (string) $this->options['path'],
            'domain' => $this->options['domain'],
            'secure' => $this->options['secure'],
            'httponly' => (bool) $this->options['httponly'],
            'samesite' => (string) $this->options['samesite'],
        ];
    }

    protected static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected static function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}