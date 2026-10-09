<?php

declare(strict_types=1);

namespace NeoPHP\Component\Csrf;

use NeoPHP\Component\Csrf\Provider\CsrfProvider;
use NeoPHP\Component\Kernel\Attribute\Component;
use NeoPHP\Component\Session\SessionManager;
use NeoPHP\Component\Session\SessionManagerInterface;

#[Component(provider: CsrfProvider::class, requires: [SessionManager::class])]
final class CsrfManager implements CsrfManagerInterface
{
    public const DEFAULT_OPTIONS = [
        'field_name' => '_token',
        'header_name' => 'X-CSRF-TOKEN',
        'session_key' => '_csrf',
        'max_tokens' => 200,
    ];

    public const TOKEN_LENGTH = 32;

    protected SessionManagerInterface $session;

    protected array $options = self::DEFAULT_OPTIONS;

    public function __construct(SessionManagerInterface $session, array $options = [])
    {
        $this->session = $session;
        $this->options = array_replace(static::DEFAULT_OPTIONS, array_intersect_key($options, static::DEFAULT_OPTIONS));
    }

    public function getToken(string $id): string
    {
        $tokens = $this->tokens();

        if (!isset($tokens[$id]) || !is_string($tokens[$id])) {
            return $this->refreshToken($id);
        }

        $token = $tokens[$id];

        if (array_key_last($tokens) !== $id) {
            unset($tokens[$id]);
            $tokens[$id] = $token;
            $this->session->set((string) $this->options['session_key'], $tokens);
        }

        return $this->mask($token);
    }

    public function refreshToken(string $id): string
    {
        $tokens = $this->tokens();
        unset($tokens[$id]);
        $tokens[$id] = self::encode(random_bytes(static::TOKEN_LENGTH));
        $max = max(1, (int) $this->options['max_tokens']);

        if (count($tokens) > $max) {
            $tokens = array_slice($tokens, -$max, null, true);
        }

        $this->session->set((string) $this->options['session_key'], $tokens);

        return $this->mask($tokens[$id]);
    }

    public function removeToken(string $id): void
    {
        $tokens = $this->tokens();

        if (!isset($tokens[$id])) {
            return;
        }

        unset($tokens[$id]);
        $this->session->set((string) $this->options['session_key'], $tokens);
    }

    public function hasToken(string $id): bool
    {
        return isset($this->tokens()[$id]);
    }

    public function isTokenValid(string $id, ?string $token): bool
    {
        $stored = $this->tokens()[$id] ?? null;

        if (!is_string($stored) || $token === null || $token === '') {
            return false;
        }

        $unmasked = $this->unmask($token);

        return $unmasked !== null && hash_equals($stored, $unmasked);
    }

    public function getFieldName(): string
    {
        return (string) $this->options['field_name'];
    }

    public function getHeaderName(): string
    {
        return (string) $this->options['header_name'];
    }

    protected function tokens(): array
    {
        $tokens = $this->session->get((string) $this->options['session_key'], []);

        return is_array($tokens) ? $tokens : [];
    }

    protected function mask(string $token): string
    {
        $raw = self::decode($token);
        $key = random_bytes(strlen($raw));

        return self::encode($key) . '.' . self::encode($raw ^ $key);
    }

    protected function unmask(string $token): ?string
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            return null;
        }

        $key = self::decode($parts[0]);
        $masked = self::decode($parts[1]);

        if ($key === '' || strlen($key) !== strlen($masked)) {
            return null;
        }

        return self::encode($masked ^ $key);
    }

    protected static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}