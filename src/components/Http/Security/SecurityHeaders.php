<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http\Security;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Http\Response\Response;

class SecurityHeaders
{
    public const DEFAULTS = [
        'enabled' => false,
        'headers' => [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ],
        'hsts' => null,
        'excluded_paths' => ['^/_profiler', '^/_wdt'],
    ];

    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_replace(self::DEFAULTS, array_filter($config, static fn (mixed $value): bool => $value !== null));
        $this->config['enabled'] = filter_var($this->config['enabled'], FILTER_VALIDATE_BOOLEAN);
        $this->config['headers'] = array_filter(array_replace(self::DEFAULTS['headers'], (array) ($config['headers'] ?? [])), static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== false);
        $this->config['excluded_paths'] = array_values((array) $this->config['excluded_paths']);
    }

    public function isEnabled(): bool
    {
        return $this->config['enabled'];
    }

    public function getHeaders(): array
    {
        return $this->config['headers'];
    }

    public function isExcluded(Request $request): bool
    {
        foreach ($this->config['excluded_paths'] as $pattern) {
            if (@preg_match('#' . str_replace('#', '\#', (string) $pattern) . '#', $request->getPath()) === 1) {
                return true;
            }
        }

        return false;
    }

    public function apply(Request $request, Response $response): Response
    {
        if (!$this->isEnabled() || $this->isExcluded($request)) {
            return $response;
        }

        foreach ($this->config['headers'] as $name => $value) {
            if (!$response->headers->has((string) $name)) {
                $response->headers->set((string) $name, (string) $value);
            }
        }

        $hsts = $this->config['hsts'];

        if ($hsts !== null && $hsts !== '' && $hsts !== false && $request->isSecure() && !$response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', is_int($hsts) || ctype_digit((string) $hsts) ? 'max-age=' . (int) $hsts . '; includeSubDomains' : (string) $hsts);
        }

        return $response;
    }
}