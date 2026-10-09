<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Provider;

use NeoPHP\Component\Config\ConfigManagerInterface;
use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Container\Contract\AbstractProvider;
use NeoPHP\Component\Controller\Contract\ArgumentResolverInterface;
use NeoPHP\Component\Database\DatabaseManagerInterface;
use NeoPHP\Component\Event\EventManagerInterface;
use NeoPHP\Package\Orm\ArgumentResolver\EntityValueResolver;
use NeoPHP\Package\Orm\Contract\EntityManagerInterface;
use NeoPHP\Package\Orm\Maker\EntityMaker;
use NeoPHP\Package\Orm\Maker\RepositoryMaker;
use NeoPHP\Package\Orm\Metadata\MetadataFactory;
use NeoPHP\Package\Orm\Migration\MigrationGenerator;
use NeoPHP\Package\Orm\Migration\Migrator;
use NeoPHP\Package\Orm\OrmManager;
use NeoPHP\Package\Orm\OrmManagerInterface;
use NeoPHP\Package\Orm\Proxy\ProxyFactory;
use NeoPHP\Package\Orm\Schema\SchemaTool;

/**
 * @internal
 */
class OrmProvider extends AbstractProvider
{
    public const CONFIG_KEY = 'packages.orm';

    public const CONFIG_ID = 'orm.config';

    public const FRAMEWORK_TABLES = ['cache_items', 'remember_me_tokens', 'neo_queue_jobs', 'neo_queue_failed'];

    public function register(ContainerManagerInterface $container): void
    {
        $container->singleton(self::CONFIG_ID, static fn (ContainerManagerInterface $container): array => self::configure($container));

        $container->singleton(OrmManagerInterface::class, static function (ContainerManagerInterface $container): OrmManagerInterface {
            $config = $container->get(self::CONFIG_ID);
            $debug = $container->has('kernel.debug') && (bool) $container->get('kernel.debug');

            return new OrmManager(
                $container->get(DatabaseManagerInterface::class)->connection($config['connection']),
                new MetadataFactory([$config['entity']['path']]),
                new ProxyFactory($config['proxy']['path'], $debug),
                $container->has(EventManagerInterface::class) ? $container->get(EventManagerInterface::class) : null,
                $container,
                $config['repository']['namespace'],
            );
        });

        $container->singleton(Migrator::class, static function (ContainerManagerInterface $container): Migrator {
            $config = $container->get(self::CONFIG_ID);
            $orm = $container->get(OrmManagerInterface::class);

            return new Migrator($orm->getConnection(), $orm->getPlatform(), $config['migration']['path'], $config['migration']['namespace'], $config['migration']['table']);
        });

        $container->singleton(MigrationGenerator::class, static function (ContainerManagerInterface $container): MigrationGenerator {
            $config = $container->get(self::CONFIG_ID);

            return new MigrationGenerator($config['migration']['path'], $config['migration']['namespace']);
        });

        $container->singleton(SchemaTool::class, static function (ContainerManagerInterface $container): SchemaTool {
            $config = $container->get(self::CONFIG_ID);

            return new SchemaTool($container->get(OrmManagerInterface::class), [$config['migration']['table'], ...self::FRAMEWORK_TABLES, ...$config['ignore_tables']]);
        });

        $container->singleton(EntityMaker::class, static function (ContainerManagerInterface $container): EntityMaker {
            $config = $container->get(self::CONFIG_ID);

            return new EntityMaker($config['entity']['path'], $config['entity']['namespace']);
        });

        $container->singleton(RepositoryMaker::class, static function (ContainerManagerInterface $container): RepositoryMaker {
            $config = $container->get(self::CONFIG_ID);

            return new RepositoryMaker($config['repository']['path'], $config['repository']['namespace']);
        });

        $container->alias(OrmManager::class, OrmManagerInterface::class);

        $container->alias(EntityManagerInterface::class, OrmManagerInterface::class);
        $container->alias('entity_manager', OrmManagerInterface::class);

        $container->alias('orm', OrmManagerInterface::class);

        $container->singleton(EntityValueResolver::class, static fn (ContainerManagerInterface $container): EntityValueResolver => new EntityValueResolver($container));
        $resolvers = $container->has(ArgumentResolverInterface::SERVICES_ID) ? (array) $container->get(ArgumentResolverInterface::SERVICES_ID) : [];
        $container->instance(ArgumentResolverInterface::SERVICES_ID, [...$resolvers, EntityValueResolver::class]);
    }

    public static function configure(ContainerManagerInterface $container): array
    {
        $config = $container->has(ConfigManagerInterface::class) ? (array) $container->get(ConfigManagerInterface::class)->get(self::CONFIG_KEY, []) : [];
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