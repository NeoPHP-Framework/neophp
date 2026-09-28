<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Firewall;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Database\Contract\DatabaseInterface;
use NeoPHP\Package\Orm\Contract\OrmInterface;
use NeoPHP\Package\Security\Authenticator\AccessTokenAuthenticator;
use NeoPHP\Package\Security\Authenticator\FormLoginAuthenticator;
use NeoPHP\Package\Security\Authenticator\HttpBasicAuthenticator;
use NeoPHP\Package\Security\Authenticator\RememberMeAuthenticator;
use NeoPHP\Package\Security\Contract\AccessTokenHandlerInterface;
use NeoPHP\Package\Security\Contract\AuthenticatorInterface;
use NeoPHP\Package\Security\Contract\EntryPointInterface;
use NeoPHP\Package\Security\Contract\UserCheckerInterface;
use NeoPHP\Package\Security\Contract\UserProviderInterface;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\RememberMe\DatabaseTokenProvider;
use NeoPHP\Package\Security\RememberMe\RememberMeHandler;
use NeoPHP\Package\Security\RememberMe\TokenProviderInterface;
use NeoPHP\Package\Security\Throttle\LoginThrottler;
use NeoPHP\Package\Security\Token\TokenStorage;
use NeoPHP\Package\Security\User\ChainUserProvider;
use NeoPHP\Package\Security\User\EntityUserProvider;
use NeoPHP\Package\Security\User\InMemoryUserProvider;

class FirewallFactory
{
    public const LOGOUT_OPTIONS = [
        'path' => '/logout',
        'target' => '/',
        'invalidate_session' => true,
        'enable_csrf' => false,
        'csrf_parameter' => '_csrf_token',
        'csrf_token_id' => 'logout',
        'clear_cookies' => [],
        'methods' => ['GET', 'POST'],
    ];

    protected array $providers = [];

    protected array $firewalls = [];

    public function __construct(
        protected ContainerInterface $container,
        protected HttpUtils $http,
        protected TokenStorage $tokens,
        protected array $config,
        protected string $throttlePath,
        protected string $secret,
    ) {
    }

    public function get(string $name): Firewall
    {
        return $this->firewalls[$name] ??= $this->create($name, (array) ($this->config['firewalls'][$name] ?? throw new SecurityException('The firewall "{firewall}" does not exist.', 0, null, ['firewall' => $name])));
    }

    public function create(string $name, array $config): Firewall
    {
        $config['logout'] = $this->option($config['logout'] ?? null, self::LOGOUT_OPTIONS);

        if (($config['security'] ?? true) === false) {
            return new Firewall($name, $config);
        }

        $provider = $this->firewallProvider($name, $config);
        $authenticators = [];

        foreach ((array) ($config['custom_authenticators'] ?? []) as $class) {
            $authenticators[(string) $class] = $this->service((string) $class, AuthenticatorInterface::class);
        }

        if (isset($config['access_token']) && $config['access_token'] !== false) {
            $options = is_array($config['access_token']) ? $config['access_token'] : [];
            $handler = (string) ($options['token_handler'] ?? throw new SecurityException('The "access_token" authenticator of the firewall "{firewall}" needs a "token_handler" option (a class implementing {interface}).', 0, null, ['firewall' => $name, 'interface' => AccessTokenHandlerInterface::class]));
            $authenticators['access_token'] = new AccessTokenAuthenticator($this->service($handler, AccessTokenHandlerInterface::class), $options);
        }

        if (isset($config['http_basic']) && $config['http_basic'] !== false) {
            $authenticators['http_basic'] = new HttpBasicAuthenticator((string) ((is_array($config['http_basic']) ? $config['http_basic'] : [])['realm'] ?? 'Secured Area'));
        }

        if (isset($config['form_login']) && $config['form_login'] !== false) {
            $options = is_array($config['form_login']) ? $config['form_login'] : [];
            $rememberOptions = is_array($config['remember_me'] ?? null) ? $config['remember_me'] : [];
            $options['remember_me_parameter'] = (string) ($rememberOptions['parameter'] ?? RememberMeHandler::DEFAULT_OPTIONS['parameter']);
            $authenticators['form_login'] = new FormLoginAuthenticator($this->http, $name, $options);
        }

        $rememberMe = null;

        if (isset($config['remember_me']) && $config['remember_me'] !== false) {
            if ($config['stateless'] ?? false) {
                throw new SecurityException('The remember-me feature cannot be used on the stateless firewall "{firewall}".', 0, null, ['firewall' => $name]);
            }

            $options = is_array($config['remember_me']) ? $config['remember_me'] : [];
            $rememberMe = new RememberMeHandler($this->http, (string) ($options['secret'] ?? $this->secret), $options, $this->tokenProvider($name, $options));
            $authenticators['remember_me'] = new RememberMeAuthenticator($rememberMe, $this->tokens);
        }

        $throttler = null;

        if (isset($config['login_throttling']) && $config['login_throttling'] !== false) {
            $options = is_array($config['login_throttling']) ? $config['login_throttling'] : [];
            $throttler = new LoginThrottler($this->throttlePath . DIRECTORY_SEPARATOR . $name, (int) ($options['max_attempts'] ?? 5), (int) ($options['interval'] ?? 60));
        }

        if ($provider === null && $authenticators !== [] && !($config['stateless'] ?? false)) {
            throw new SecurityException('The firewall "{firewall}" stores the user in the session and needs a user provider: set its "provider" option (or "stateless: true").', 0, null, ['firewall' => $name]);
        }

        $checker = isset($config['user_checker']) ? $this->service((string) $config['user_checker'], UserCheckerInterface::class) : null;

        return new Firewall($name, $config, $provider, $authenticators, $this->entryPoint($name, $config, $authenticators), $checker, $throttler, $rememberMe);
    }

    public function provider(string $name): UserProviderInterface
    {
        if (isset($this->providers[$name])) {
            return $this->providers[$name];
        }

        $config = $this->config['providers'][$name] ?? null;

        if (!is_array($config)) {
            throw new SecurityException('The user provider "{provider}" does not exist. Defined providers: "{providers}".', 0, null, ['provider' => $name, 'providers' => implode('", "', array_keys((array) ($this->config['providers'] ?? [])))]);
        }

        return $this->providers[$name] = match (true) {
            isset($config['entity']) => new EntityUserProvider(
                $this->container->get(OrmInterface::class),
                (string) ($config['entity']['class'] ?? throw new SecurityException('The entity user provider "{provider}" needs a "class" option.', 0, null, ['provider' => $name])),
                isset($config['entity']['property']) ? (string) $config['entity']['property'] : null,
            ),
            isset($config['memory']) => new InMemoryUserProvider((array) ($config['memory']['users'] ?? [])),
            isset($config['chain']) => new ChainUserProvider(array_map(fn (mixed $provider): UserProviderInterface => $this->provider((string) $provider), array_values((array) ($config['chain']['providers'] ?? $config['chain'])))),
            isset($config['id']) => $this->service((string) $config['id'], UserProviderInterface::class),
            default => throw new SecurityException('The user provider "{provider}" must define one of "entity", "memory", "chain" or "id".', 0, null, ['provider' => $name]),
        };
    }

    protected function firewallProvider(string $name, array $config): ?UserProviderInterface
    {
        if (isset($config['provider'])) {
            return $this->provider((string) $config['provider']);
        }

        $providers = array_keys((array) ($this->config['providers'] ?? []));

        if (count($providers) === 1) {
            return $this->provider((string) $providers[0]);
        }

        return null;
    }

    protected function entryPoint(string $name, array $config, array $authenticators): ?EntryPointInterface
    {
        if (isset($config['entry_point'])) {
            $entry = (string) $config['entry_point'];
            $point = $authenticators[$entry] ?? (class_exists($entry) ? $this->service($entry, EntryPointInterface::class) : null);

            if (!$point instanceof EntryPointInterface) {
                throw new SecurityException('The entry point "{entry}" of the firewall "{firewall}" must be "form_login", "http_basic", "access_token" or a class implementing {interface}.', 0, null, ['entry' => $entry, 'firewall' => $name, 'interface' => EntryPointInterface::class]);
            }

            return $point;
        }

        foreach (['form_login', 'http_basic', 'access_token'] as $builtIn) {
            if (isset($authenticators[$builtIn])) {
                return $authenticators[$builtIn];
            }
        }

        foreach ($authenticators as $authenticator) {
            if ($authenticator instanceof EntryPointInterface) {
                return $authenticator;
            }
        }

        return null;
    }

    protected function tokenProvider(string $firewall, array $options): ?TokenProviderInterface
    {
        $storage = (string) ($options['storage'] ?? 'signature');

        if ($storage === 'signature') {
            return null;
        }

        if ($storage !== 'database') {
            return $this->service($storage, TokenProviderInterface::class);
        }

        if (!$this->container->has(DatabaseInterface::class)) {
            throw new SecurityException('The remember-me "storage: database" option of the firewall "{firewall}" needs the Database component (DATABASE_URL).', 0, null, ['firewall' => $firewall]);
        }

        $connection = $this->container->get(DatabaseInterface::class)->connection(isset($options['connection']) ? (string) $options['connection'] : null);

        return new DatabaseTokenProvider($connection, (string) ($options['table'] ?? DatabaseTokenProvider::DEFAULT_TABLE));
    }

    protected function option(mixed $value, array $defaults): ?array
    {
        if ($value === null || $value === false) {
            return null;
        }

        return array_replace($defaults, is_array($value) ? $value : []);
    }

    protected function service(string $class, string $interface): object
    {
        if (!class_exists($class)) {
            throw new SecurityException('The class "{class}" does not exist.', 0, null, ['class' => $class]);
        }

        $service = $this->container->get($class);

        if (!$service instanceof $interface) {
            throw new SecurityException('The class "{class}" must implement {interface}.', 0, null, ['class' => $class, 'interface' => $interface]);
        }

        return $service;
    }
}