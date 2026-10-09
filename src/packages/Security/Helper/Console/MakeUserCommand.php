<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Helper\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Package\Orm\Provider\OrmProvider;
use NeoPHP\Package\Security\Maker\UserMaker;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'make:user', description: 'Generates a User entity and its repository')]
class MakeUserCommand extends AbstractConsole
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('name', InputArgument::OPTIONAL, 'The entity name', 'User', 'Name of the user entity');
        $input->addOption('property', 'p', InputOption::VALUE_REQUIRED, 'The property used as identifier', 'email', 'Property used to log in (e.g. email, username)');
        $this->addExample('make:user');
        $this->addExample('make:user Admin --property=username');
        $this->addExample('make:user --force');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $property = (string) $input->getOption('property');
        $property = $property !== '' ? $property : 'email';
        $root = (string) $this->container->get('kernel.root_path');
        $orm = $this->container->has(OrmProvider::CONFIG_ID) ? $this->container->get(OrmProvider::CONFIG_ID) : [];
        $maker = new UserMaker(
            (string) ($orm['entity']['path'] ?? $root . '/src/Entity'),
            (string) ($orm['entity']['namespace'] ?? 'App\\Entity'),
            (string) ($orm['repository']['path'] ?? $root . '/src/Repository'),
            (string) ($orm['repository']['namespace'] ?? 'App\\Repository'),
        );

        try {
            [$class, $file, , $repositoryFile] = $maker->make($name, $property, (bool) $input->getOption('force'));
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf('  <success>created</success>  %s', $file));
        $output->writeln(sprintf('  <success>created</success>  %s', $repositoryFile));
        $output->success(sprintf('User entity %s created.', $class));
        $output->section('Next steps');
        $output->listing([
            'Create the table: <info>php bin/neo make:migration</info> then <info>php bin/neo migration:migrate</info>',
            implode("\n", [
                'Use it in <info>config/packages/security.yaml</info>:',
                '  <comment>providers:</comment>',
                '  <comment>  users:</comment>',
                '  <comment>    entity:</comment>',
                sprintf('  <comment>      class: %s</comment>', $class),
                sprintf('  <comment>      property: %s</comment>', $property),
            ]),
            'Hash a password: <info>php bin/neo security:hash-password</info>',
        ]);

        return self::SUCCESS;
    }
}