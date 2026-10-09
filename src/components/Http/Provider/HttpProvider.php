<?php

declare(strict_types=1);

namespace NeoPHP\Component\Http\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Http\HttpManager;
use NeoPHP\Component\Http\HttpManagerInterface;
use NeoPHP\Component\Http\Request\Request;

/**
 * @internal
 */
class HttpProvider extends AbstractProvider
{
    public const TRUSTED_PROXIES_KEY = 'framework.app.trusted_proxies';

    public const TRUSTED_HOSTS_KEY = 'framework.app.trusted_hosts';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(HttpManagerInterface::class, HttpManager::class);
        $container->alias(HttpManager::class, HttpManagerInterface::class);
    }

    public function boot(ContainerManagerInterface $container): void
    {
        $config = $container->has(ConfigManagerInterface::class) ? $container->get(ConfigManagerInterface::class) : null;

        if ($config === null) {
            return;
        }

        Request::setTrustedProxies($this->toList($config->get(self::TRUSTED_PROXIES_KEY, [])));
        Request::setTrustedHosts($this->toList($config->get(self::TRUSTED_HOSTS_KEY, [])));
    }

    protected function toList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), (array) ($value ?? [])), static fn (string $item): bool => $item !== ''));
    }
}