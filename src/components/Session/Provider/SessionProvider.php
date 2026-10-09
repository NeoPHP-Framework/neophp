<?php

declare(strict_types=1);

namespace NeoPHP\Component\Session\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Http\Request\Request;
use NeoPHP\Component\Session\SessionManager;
use NeoPHP\Component\Session\SessionManagerInterface;

/**
 * @internal
 */
class SessionProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'framework.app.session';

    public const COOKIE_CONFIG_KEY = 'framework.app.cookie';

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(SessionManagerInterface::class, static function (ContainerManagerInterface $container): SessionManagerInterface {
            $config = $container->has(ConfigManagerInterface::class) ? $container->get(ConfigManagerInterface::class) : null;
            $session = (array) ($config?->get(self::CONFIG_KEY, []) ?? []);
            $cookie = (array) ($config?->get(self::COOKIE_CONFIG_KEY, []) ?? []);
            $request = $container->bound(Request::class) ? $container->get(Request::class) : null;
            $rootPath = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
            $secure = $cookie['secure'] ?? 'auto';

            $options = [
                'name' => $session['name'] ?? 'NEOSESSID',
                'lifetime' => $session['lifetime'] ?? 0,
                'gc_maxlifetime' => $session['gc_maxlifetime'] ?? 1440,
                'save_path' => $session['save_path'] ?? $rootPath . '/var/sessions',
                'cookie_path' => $cookie['path'] ?? '/',
                'cookie_domain' => $cookie['domain'] ?? null,
                'cookie_secure' => $secure === 'auto' ? ($request?->isSecure() ?? false) : (bool) $secure,
                'cookie_httponly' => $cookie['httponly'] ?? true,
                'cookie_samesite' => $cookie['samesite'] ?? 'Lax',
            ];

            $cookies = $request !== null ? $request->cookies->all() : $_COOKIE;

            return new SessionManager($options, isset($cookies[$options['name']]));
        });

        $container->alias(SessionManager::class, SessionManagerInterface::class);
    }
}