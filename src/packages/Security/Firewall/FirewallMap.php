<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Firewall;

use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Routing\Route\Route;
use NeoPHP\Package\Security\Exception\SecurityException;

class FirewallMap
{
    public function __construct(protected array $firewalls = [])
    {
    }

    public function all(): array
    {
        return $this->firewalls;
    }

    public function has(string $name): bool
    {
        return isset($this->firewalls[$name]);
    }

    public function getConfig(string $name): array
    {
        if (!isset($this->firewalls[$name])) {
            throw new SecurityException('The firewall "{firewall}" does not exist. Defined firewalls: "{firewalls}".', 0, null, ['firewall' => $name, 'firewalls' => implode('", "', array_keys($this->firewalls))]);
        }

        return (array) $this->firewalls[$name];
    }

    public function match(Request $request): ?string
    {
        foreach ($this->firewalls as $name => $config) {
            if (self::matches($request, (array) $config)) {
                return (string) $name;
            }
        }

        return null;
    }

    public static function matches(Request $request, array $rule): bool
    {
        $pattern = $rule['pattern'] ?? $rule['path'] ?? null;

        if (is_string($pattern) && $pattern !== '' && preg_match('#' . str_replace('#', '\#', $pattern) . '#', Route::normalizePath(rawurldecode($request->getPath()))) !== 1) {
            return false;
        }

        $host = $rule['host'] ?? null;

        if (is_string($host) && $host !== '' && preg_match('#' . str_replace('#', '\#', $host) . '#i', $request->getHost()) !== 1) {
            return false;
        }

        $methods = array_map('strtoupper', array_map('strval', (array) ($rule['methods'] ?? [])));

        if ($methods !== [] && !in_array($request->getMethod(), $methods, true)) {
            return false;
        }

        $ips = array_map('strval', (array) ($rule['ips'] ?? []));

        return $ips === [] || in_array((string) $request->getClientIp(), $ips, true);
    }
}