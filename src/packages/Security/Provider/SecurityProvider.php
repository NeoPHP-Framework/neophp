<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Provider;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Csrf\Contract\CsrfInterface;
use NeoPHP\Component\Kernel\Cache\ResourceCache;
use NeoPHP\Package\Security\Authentication\AuthenticationManager;
use NeoPHP\Package\Security\Authorization\AccessDecisionManager;
use NeoPHP\Package\Security\Authorization\AccessMap;
use NeoPHP\Package\Security\Authorization\RoleHierarchy;
use NeoPHP\Package\Security\Contract\SecurityInterface;
use NeoPHP\Package\Security\Contract\VoterInterface;
use NeoPHP\Package\Security\Discovery\VoterDiscovery;
use NeoPHP\Package\Security\Exception\SecurityException;
use NeoPHP\Package\Security\Firewall\FirewallFactory;
use NeoPHP\Package\Security\Firewall\FirewallMap;
use NeoPHP\Package\Security\Firewall\HttpUtils;
use NeoPHP\Package\Security\Hasher\UserPasswordHasher;
use NeoPHP\Package\Security\SecurityManager;
use NeoPHP\Package\Security\Token\TokenStorage;
use NeoPHP\Package\Security\Trace\SecurityTrace;
use NeoPHP\Package\Security\Voter\AuthenticatedVoter;
use NeoPHP\Package\Security\Voter\RoleVoter;

class SecurityProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.security';

    public const CONFIG_ID = 'security.config';

    public const SECRET_KEY = 'framework.app.secret';

    public const CACHE_DIRECTORY = 'security';

    public const PROFILER_CONFIG_ID = 'web_profiler.config';

    public function register(ContainerInterface $container): void
    {
        $container->singleton(self::CONFIG_ID, static fn (ContainerInterface $container): array => self::configure($container));
        $container->singleton(TokenStorage::class, static fn (): TokenStorage => new TokenStorage());
        $container->singleton(HttpUtils::class, static fn (ContainerInterface $container): HttpUtils => new HttpUtils($container));

        $container->singleton(UserPasswordHasher::class, static fn (ContainerInterface $container): UserPasswordHasher => new UserPasswordHasher($container->get(self::CONFIG_ID)['password_hashers']));

        $container->singleton(RoleHierarchy::class, static fn (ContainerInterface $container): RoleHierarchy => new RoleHierarchy($container->get(self::CONFIG_ID)['role_hierarchy']));

        $container->singleton(AccessDecisionManager::class, static function (ContainerInterface $container): AccessDecisionManager {
            $config = $container->get(self::CONFIG_ID);
            $decision = $config['access_decision_manager'];

            $manager = new AccessDecisionManager(
                static function () use ($container, $config): array {
                    $voters = [new RoleVoter($container->get(RoleHierarchy::class)), new AuthenticatedVoter()];

                    foreach ([...self::discover($container), ...$config['voters']] as $class) {
                        $voter = $container->get((string) $class);

                        if (!$voter instanceof VoterInterface) {
                            throw new SecurityException('The voter "{voter}" must implement {interface}.', 0, null, ['voter' => $class, 'interface' => VoterInterface::class]);
                        }

                        $voters[] = $voter;
                    }

                    return $voters;
                },
                (string) $decision['strategy'],
                (bool) $decision['allow_if_all_abstain'],
                (bool) $decision['allow_if_equal_granted_denied'],
            );

            return self::profilingEnabled($container) ? $manager->setTrace(new SecurityTrace()) : $manager;
        });

        $container->singleton(FirewallMap::class, static fn (ContainerInterface $container): FirewallMap => new FirewallMap($container->get(self::CONFIG_ID)['firewalls']));

        $container->singleton(FirewallFactory::class, static function (ContainerInterface $container): FirewallFactory {
            $config = $container->get(self::CONFIG_ID);

            return new FirewallFactory($container, $container->get(HttpUtils::class), $container->get(TokenStorage::class), $config, $config['throttling_path'], $config['secret']);
        });

        $container->singleton(AuthenticationManager::class, static fn (ContainerInterface $container): AuthenticationManager => new AuthenticationManager(
            $container->get(UserPasswordHasher::class),
            static fn (): ?CsrfInterface => $container->has(CsrfInterface::class) ? $container->get(CsrfInterface::class) : null,
        ));

        $container->singleton(SecurityInterface::class, static function (ContainerInterface $container): SecurityInterface {
            $config = $container->get(self::CONFIG_ID);

            return new SecurityManager(
                $container,
                $container->get(TokenStorage::class),
                $container->get(FirewallMap::class),
                $container->get(FirewallFactory::class),
                $container->get(AuthenticationManager::class),
                $container->get(AccessDecisionManager::class),
                new AccessMap($config['access_control']),
                $container->get(HttpUtils::class),
                $config['enabled'],
            );
        });

        $container->alias(SecurityManager::class, SecurityInterface::class);
        $container->alias('security', SecurityInterface::class);
    }

    public static function configure(ContainerInterface $container): array
    {
        $configuration = $container->has(ConfigInterface::class) ? $container->get(ConfigInterface::class) : null;
        $config = (array) ($configuration?->get(self::CONFIG_KEY, []) ?? []);
        $cache = $container->has('kernel.cache_path') ? (string) $container->get('kernel.cache_path') : (string) getcwd() . '/var/cache';
        $secret = $configuration?->get(self::SECRET_KEY) ?? ($_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? '');
        $decision = (array) ($config['access_decision_manager'] ?? []);

        return [
            'enabled' => (array) ($config['firewalls'] ?? []) !== [] || (array) ($config['access_control'] ?? []) !== [],
            'providers' => (array) ($config['providers'] ?? []),
            'password_hashers' => (array) ($config['password_hashers'] ?? []),
            'firewalls' => (array) ($config['firewalls'] ?? []),
            'access_control' => array_values((array) ($config['access_control'] ?? [])),
            'role_hierarchy' => (array) ($config['role_hierarchy'] ?? []),
            'access_decision_manager' => [
                'strategy' => (string) ($decision['strategy'] ?? 'affirmative'),
                'allow_if_all_abstain' => (bool) ($decision['allow_if_all_abstain'] ?? false),
                'allow_if_equal_granted_denied' => (bool) ($decision['allow_if_equal_granted_denied'] ?? true),
            ],
            'voters' => array_values(array_map('strval', (array) ($config['voters'] ?? []))),
            'throttling_path' => $cache . DIRECTORY_SEPARATOR . self::CACHE_DIRECTORY . DIRECTORY_SEPARATOR . 'throttling',
            'secret' => (string) $secret,
        ];
    }

    protected static function profilingEnabled(ContainerInterface $container): bool
    {
        if (!$container->bound(self::PROFILER_CONFIG_ID) && !$container->has(self::PROFILER_CONFIG_ID)) {
            return false;
        }

        $config = $container->get(self::PROFILER_CONFIG_ID);

        return is_array($config) && (bool) ($config['enabled'] ?? false);
    }

    protected static function discover(ContainerInterface $container): array
    {
        if (!$container->has('kernel.root_path')) {
            return [];
        }

        $source = (string) $container->get('kernel.root_path') . DIRECTORY_SEPARATOR . 'src';
        $builder = static function () use ($source): array {
            $discovery = new VoterDiscovery([$source]);

            return [$discovery->discover(), $discovery->getResources()];
        };

        if (!$container->has('kernel.cache_path')) {
            return $builder()[0];
        }

        $environment = $container->has('kernel.environment') ? (string) $container->get('kernel.environment') : 'dev';
        $debug = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');
        $file = (string) $container->get('kernel.cache_path') . DIRECTORY_SEPARATOR . self::CACHE_DIRECTORY . DIRECTORY_SEPARATOR . 'voters.' . $environment . '.php';

        return (new ResourceCache($file, $debug))->load($builder);
    }
}