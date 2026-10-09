<?php

declare(strict_types=1);

namespace NeoPHP\Component\Cookie\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Cookie\CookieManager;
use NeoPHP\Component\Cookie\CookieManagerInterface;
use NeoPHP\Component\Http\Request\Request;

/**
 * @internal
 */
class CookieProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.app.cookie';

    public const SECRET_KEY = 'framework.app.secret';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(CookieManagerInterface::class, static function (ContainerManagerInterface $container): CookieManagerInterface {
            $config = $container->has(ConfigManagerInterface::class) ? $container->get(ConfigManagerInterface::class) : null;
            $request = $container->bound(Request::class) ? $container->get(Request::class) : null;
            $secret = $config?->get(self::SECRET_KEY) ?? ($_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? null);

            return new CookieManager(
                $request !== null ? $request->cookies->all() : $_COOKIE,
                (array) ($config?->get(self::CONFIG_KEY, []) ?? []),
                $secret === null ? null : (string) $secret,
                $request !== null ? $request->isSecure() : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            );
        });

        $container->alias(CookieManager::class, CookieManagerInterface::class);
    }
}