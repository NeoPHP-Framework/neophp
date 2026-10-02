<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Provider;

use NeoPHP\Component\Config\Contract\ConfigInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Component\Controller\Contract\ArgumentResolverInterface;
use NeoPHP\Component\Database\Contract\DatabaseInterface;
use NeoPHP\Component\Event\Contract\EventDispatcherInterface;
use NeoPHP\Package\Orm\ArgumentResolver\EntityValueResolver;
use NeoPHP\Package\Orm\Contract\OrmInterface;
use NeoPHP\Package\Orm\Contract\EntityManagerInterface;
use NeoPHP\Package\Orm\Maker\EntityMaker;
use NeoPHP\Package\Orm\Maker\RepositoryMaker;
use NeoPHP\Package\Orm\Metadata\MetadataFactory;
use NeoPHP\Package\Orm\Migration\MigrationGenerator;
use NeoPHP\Package\Orm\Migration\Migrator;
use NeoPHP\Package\Orm\OrmManager;
use NeoPHP\Package\Orm\Proxy\ProxyFactory;
use NeoPHP\Package\Orm\Schema\SchemaTool;

class OrmProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.orm';

    public const CONFIG_ID = 'orm.config';

    public const FRAMEWORK_TABLES = ['cache_items', 'remember_me_tokens'];

    public function register(ContainerInterface $container): void
    {
        $container->singleton(self::CONFIG_ID, static fn (ContainerInterface $container): array => self::configure($container));

        $container->singleton(OrmInterface::class, static function (ContainerInterface $container): OrmInterface {
            $config = $container->get(self::CONFIG_ID);
            $debug = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');

            return new OrmManager(
                $container->get(DatabaseInterface::class)->connection($config['connection']),
                new MetadataFactory([$config['entity']['path']]),
                new ProxyFactory($config['proxy']['path'], $debug),
                $container->has(EventDispatcherInterface::class) ? $container->get(EventDispatcherInterface::class) : null,
                $container,
                $config['repository']['namespace'],
            );
        });

        $container->singleton(Migrator::class, static function (ContainerInterface $container): Migrator {
            $config = $container->get(self::CONFIG_ID);
            $orm = $container->get(OrmInterface::class);

            return new Migrator($orm->getConnection(), $orm->getPlatform(), $config['migration']['path'], $config['migration']['namespace'], $config['migration']['table']);
        });

        $container->singleton(MigrationGenerator::class, static function (ContainerInterface $container): MigrationGenerator {
            $config = $container->get(self::CONFIG_ID);

            return new MigrationGenerator($config['migration']['path'], $config['migration']['namespace']);
        });

        $container->singleton(SchemaTool::class, static function (ContainerInterface $container): SchemaTool {
            $config = $container->get(self::CONFIG_ID);

            return new SchemaTool($container->get(OrmInterface::class), [$config['migration']['table'], ...self::FRAMEWORK_TABLES, ...$config['ignore_tables']]);
        });

        $container->singleton(EntityMaker::class, static function (ContainerInterface $container): EntityMaker {
            $config = $container->get(self::CONFIG_ID);

            return new EntityMaker($config['entity']['path'], $config['entity']['namespace']);
        });

        $container->singleton(RepositoryMaker::class, static function (ContainerInterface $container): RepositoryMaker {
            $config = $container->get(self::CONFIG_ID);

            return new RepositoryMaker($config['repository']['path'], $config['repository']['namespace']);
        });

        $container->alias(OrmManager::class, OrmInterface::class);

        $container->alias(EntityManagerInterface::class, OrmInterface::class);
        $container->alias('entity_manager', OrmInterface::class);

        $container->alias('orm', OrmInterface::class);

        $container->singleton(EntityValueResolver::class, static fn (ContainerInterface $container): EntityValueResolver => new EntityValueResolver($container));
        $resolvers = $container->has(ArgumentResolverInterface::SERVICES_ID) ? (array) $container->get(ArgumentResolverInterface::SERVICES_ID) : [];
        $container->instance(ArgumentResolverInterface::SERVICES_ID, [...$resolvers, EntityValueResolver::class]);
    }

    public static function configure(ContainerInterface $container): array
    {
        $config = $container->has(ConfigInterface::class) ? (array) $container->get(ConfigInterface::class)->get(self::CONFIG_KEY, []) : [];
        $root = $container->has('kernel.root_path') ? (string) $container->get('kernel.root_path') : (string) getcwd();
        $cache = $container->has('kernel.cache_path') ? (string) $container->get('kernel.cache_path') : $root . '/var/cache';
        $absolute = static fn (string $path): string => preg_match('#^([A-Za-z]:)?[/\\\\]#', $path) === 1 ? $path : $root . DIRECTORY_SEPARATOR . $path;

        return [
            'connection' => isset($config['connection']) && $config['connection'] !== '' ? (string) $config['connection'] : null,
            'entity' => [
                'path' => $absolute((string) ($config['entity']['path'] ?? 'src/Entity')),
                'namespace' => trim((string) ($config['entity']['namespace'] ?? 'App\\Entity'), '\\'),
            ],
            'repository' => [
                'path' => $absolute((string) ($config['repository']['path'] ?? 'src/Repository')),
                'namespace' => trim((string) ($config['repository']['namespace'] ?? 'App\\Repository'), '\\'),
            ],
            'migration' => [
                'path' => $absolute((string) ($config['migration']['path'] ?? 'migrations')),
                'namespace' => trim((string) ($config['migration']['namespace'] ?? 'Migrations'), '\\'),
                'table' => (string) ($config['migration']['table'] ?? 'neo_migrations'),
            ],
            'proxy' => [
                'path' => $absolute((string) ($config['proxy']['path'] ?? $cache . '/orm/proxies')),
            ],
            'ignore_tables' => array_values(array_map('strval', (array) ($config['ignore_tables'] ?? []))),
        ];
    }
}