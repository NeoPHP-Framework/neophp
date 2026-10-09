<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Helper\Console;

use NeoPHP\Package\Orm\Exception\OrmException;
use NeoPHP\Package\Orm\Maker\EntityMaker;
use NeoPHP\Package\Orm\Maker\RepositoryMaker;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;

/**
 * @internal
 */
#[AsCommand(name: 'make:repository', description: 'Generates the repository of an entity')]
class MakeRepositoryCommand extends AbstractConsole
{
    public function __construct(protected EntityMaker $entityMaker, protected RepositoryMaker $repositoryMaker)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('entity', InputArgument::REQUIRED, 'The entity name (e.g. Post)');
        $this->addExample('make:repository Post');
        $this->addExample('make:repository Post --force');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $entities = $this->entityMaker->getEntities();

        if (!$input->isArgumentProvided('entity') && $entities !== []) {
            $input->setArgument('entity', $output->select('Entity of the repository', $entities));
        }
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('entity');

        try {
            [$entityClass, , $relative] = $this->entityMaker->resolve($name);

            if (!class_exists($entityClass)) {
                $output->error(sprintf('The entity %s does not exist.', $entityClass));
                $output->text(sprintf('Create it with: <info>php bin/neo make:entity %s</info>', $name));

                return self::FAILURE;
            }

            [$repositoryClass, $file] = $this->repositoryMaker->make($entityClass, (bool) $input->getOption('force'), $relative);
        } catch (OrmException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf('  <success>created</success>  %s', $file));
        $output->success(sprintf('%s is used by getRepository(%s::class) and can be injected in controllers and services.', $repositoryClass, substr($entityClass, (int) strrpos($entityClass, '\\') + 1)));

        return self::SUCCESS;
    }
}