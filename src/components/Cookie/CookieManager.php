<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cookie;

use NeoPHP\Component\Cookie\Exception\CookieException;
use NeoPHP\Component\Cookie\Provider\CookieProvider;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Kernel\Attribute\Component;

#[Component(provider: CookieProvider::class)]
final class CookieManager implements CookieManagerInterface
{
    public const DEFAULT_OPTIONS = [
        'lifetime' => 0,
        'path' => '/',
        'domain' => null,
        'secure' => 'auto',
        'httponly' => true,
        'samesite' => 'Lax',
        'signed' => false,
    ];

    public const SAMESITE = ['Lax', 'Strict', 'None', ''];

    protected array $cookies = [];

    protected array $defaults = self::DEFAULT_OPTIONS;

    protected ?string $secret = null;

    protected bool $secureRequest = false;

    protected array $queue = [];

    public function __construct(array $cookies = [], array $defaults = [], ?string $secret = null, bool $secureRequest = false)
    {
        $this->cookies = $cookies;
        $this->defaults = array_replace(static::DEFAULT_OPTIONS, array_intersect_key($defaults, static::DEFAULT_OPTIONS));
        $this->secret = $secret;
        $this->secureRequest = $secureRequest;
    }

    public function get(string $name, mixed $default = null): mixed
    {
        if (array_key_exists($name, $this->queue)) {
            return $this->queue[$name]['value'] ?? $default;
        }

        return $this->cookies[$name] ?? $default;
    }

    public function getSigned(string $name, mixed $default = null): mixed
    {
        if (array_key_exists($name, $this->queue)) {
            return $this->queue[$name]['options']['signed'] ? ($this->queue[$name]['value'] ?? $default) : $default;
        }

        if (!isset($this->cookies[$name]) || !is_string($this->cookies[$name])) {
            return $default;
        }

        $position = strrpos($this->cookies[$name], '.');

        if ($position === false) {
            return $default;
        }

        $value = substr($this->cookies[$name], 0, $position);
        $signature = substr($this->cookies[$name], $position + 1);

        return hash_equals($this->signature($name, $value), $signature) ? $value : $default;
    }

    public function has(string $name): bool
    {
        if (array_key_exists($name, $this->queue)) {
            return $this->queue[$name]['value'] !== null;
        }

        return array_key_exists($name, $this->cookies);
    }

    public function all(): array
    {
        $cookies = $this->cookies;

        foreach ($this->queue as $name => $cookie) {
            if ($cookie['value'] === null) {
                unset($cookies[$name]);
            } else {
                $cookies[$name] = $cookie['value'];
            }
        }

        return $cookies;
    }

    public function set(string $name, string $value, array $options = []): static
    {
        $this->queue[$name] = ['value' => $value, 'options' => $this->options($name, $options)];

        return $this;
    }

    public function remove(string $name, array $options = []): static
    {
        $this->queue[$name] = ['value' => null, 'options' => $this->options($name, $options)];

        return $this;
    }

    public function getQueued(): array
    {
        return $this->queue;
    }

    public function apply(Response $response): Response
    {
        foreach ($this->queue as $name => $cookie) {
            $options = $cookie['options'];

            if ($cookie['value'] === null) {
                $response->setCookie((string) $name, '', 1, $options['path'], $options['domain'], $options['secure'], $options['httponly'], $options['samesite']);
                continue;
            }

            $value = $options['signed'] ? $this->sign((string) $name, $cookie['value']) : $cookie['value'];
            $expires = $options['lifetime'] > 0 ? time() + $options['lifetime'] : 0;

            $response->setCookie((string) $name, $value, $expires, $options['path'], $options['domain'], $options['secure'], $options['httponly'], $options['samesite']);
        }

        $this->queue = [];

        return $response;
    }

    protected function options(string $name, array $options): array
    {
        $unknown = array_diff(array_keys($options), array_keys(static::DEFAULT_OPTIONS));

        if ($unknown !== []) {
            throw new CookieException('Unknown option(s) "{options}" for the cookie "{name}". Allowed options: "{allowed}".', 0, null, [
                'options' => implode('", "', $unknown),
                'name' => $name,
                'allowed' => implode('", "', array_keys(static::DEFAULT_OPTIONS)),
            ]);
        }

        $options = array_replace($this->defaults, $options);
        $options['samesite'] = ucfirst(strtolower((string) $options['samesite']));

        if (!in_array($options['samesite'], static::SAMESITE, true)) {
            throw new CookieException('The SameSite option of the cookie "{name}" must be "Lax", "Strict" or "None".', 0, null, ['name' => $name]);
        }

        return [
            'lifetime' => (int) $options['lifetime'],
            'path' => (string) $options['path'],
            'domain' => $options['domain'] === null || $options['domain'] === '' ? null : (string) $options['domain'],
            'secure' => $options['secure'] === 'auto' ? $this->secureRequest : (bool) $options['secure'],
            'httponly' => (bool) $options['httponly'],
            'samesite' => $options['samesite'],
            'signed' => (bool) $options['signed'],
        ];
    }

    protected function sign(string $name, string $value): string
    {
        return $value . '.' . $this->signature($name, $value);
    }

    protected function signature(string $name, string $value): string
    {
        if ($this->secret === null || $this->secret === '') {
            throw new CookieException('Signed cookies need a secret: define APP_SECRET in .env (used by framework.app.secret).');
        }

        return hash_hmac('sha256', $name . '|' . $value, $this->secret);
    }
}