<?php

declare(strict_types=1);

namespace NeoPHP\Package\Orm\Helper\Console;

use NeoPHP\Package\Orm\Exception\OrmException;
use NeoPHP\Package\Orm\Maker\EntityMaker;
use NeoPHP\Package\Orm\Maker\EntityWizard;
use NeoPHP\Package\Orm\Maker\RepositoryMaker;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;

/**
 * @internal
 */
#[AsCommand(name: 'make:entity', description: 'Creates an entity and its repository, or adds fields to an existing entity')]
class MakeEntityCommand extends AbstractConsole
{
    protected ?array $wizardFields = null;

    public function __construct(protected EntityMaker $entityMaker, protected RepositoryMaker $repositoryMaker)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('name', InputArgument::REQUIRED, 'The entity name (e.g. Post, Blog/Post)');
        $input->addArgument('fields', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'The fields: name:type[:length][?] or name:Relation:Target[:mappedBy] (asked interactively when omitted)');
        $this->setHelp(implode("\n", [
            'Without fields, a wizard asks the fields one by one (type ? to list the types), with the relations and their inverse side.',
            'An existing entity is completed: the new properties and methods are added to its class.',
            'Types: ' . implode(', ', array_keys(EntityMaker::TYPES)) . ', enum:App\\Enum\\Status',
            'Relations: name:ManyToOne:Target, name:OneToOne:Target, name:OneToMany:Target[:mappedBy], name:ManyToMany:Target',
            'A trailing "?" makes the field nullable. --force regenerates an existing entity from scratch (its repository is kept).',
        ]));
        $this->addExample('make:entity');
        $this->addExample('make:entity Post');
        $this->addExample('make:entity Post title:string:120 content:text? publishedAt:datetime_immutable? category:ManyToOne:Category');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $wizard = $this->wizard($output);

        if (!$input->isArgumentProvided('name')) {
            $input->setArgument('name', $wizard->askEntity());
        }

        if ((array) $input->getArgument('fields') === []) {
            $this->wizardFields = $wizard->run((string) $input->getArgument('name'));
        }
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $force = (bool) $input->getOption('force');

        try {
            $fields = $this->wizardFields ?? $this->entityMaker->parseFields((array) $input->getArgument('fields'));
            $this->wizardFields = null;
            [$entityClass, , $relative] = $this->entityMaker->resolve($name);
            $repositoryClass = $this->repositoryMaker->getRepositoryClass($entityClass, $relative);
            $existed = $this->entityMaker->exists($name);

            if ($existed && !$force && $fields === []) {
                $output->note(sprintf('The entity %s already exists and no field was given: nothing to do.', $entityClass));

                return self::SUCCESS;
            }

            [, $entityFile, $created, $updated] = $this->entityMaker->make($name, $fields, $repositoryClass, $force);
            $repositoryFile = null;

            if ($created && !class_exists($repositoryClass)) {
                try {
                    [, $repositoryFile] = $this->repositoryMaker->make($entityClass, false, $relative);
                } catch (OrmException $exception) {
                    $output->warning($exception->getMessage());
                }
            }
        } catch (OrmException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->newLine();
        $output->writeln(sprintf('  <success>%s</success>  %s', $created ? 'created' : 'updated', $entityFile));

        if ($repositoryFile !== null) {
            $output->writeln(sprintf('  <success>created</success>  %s', $repositoryFile));
        }

        foreach ($updated as $file) {
            $output->writeln(sprintf('  <success>updated</success>  %s', $file));
        }

        foreach ($fields as $field) {
            if (($field['relation'] ?? null) === 'OneToMany' && ($field['inverse'] ?? null) === null) {
                $target = substr((string) $field['target'], (int) strrpos('\\' . $field['target'], '\\'));
                $output->warning(sprintf('%s::$%s is mapped by %s::$%s: add the ManyToOne side in %s.', $entityClass, $field['name'], $target, $field['mappedBy'] ?? lcfirst(substr($entityClass, (int) strrpos($entityClass, '\\') + 1)), $target));
            }
        }

        $output->success(sprintf('Entity %s %s with %d new field(s).', $entityClass, $created ? 'created' : 'updated', count($fields)));
        $output->text('Next: <info>php bin/neo make:migration</info> then <info>php bin/neo migration:migrate</info>');

        return self::SUCCESS;
    }

    protected function wizard(OutputInterface $output): EntityWizard
    {
        $path = rtrim($this->entityMaker->getPath(), '/\\');
        $namespace = trim($this->entityMaker->getNamespace(), '\\');
        $enumNamespace = (str_contains($namespace, '\\') ? substr($namespace, 0, (int) strrpos($namespace, '\\')) : $namespace) . '\\Enum';

        return new EntityWizard($this->entityMaker, $output, dirname($path) . DIRECTORY_SEPARATOR . 'Enum', $enumNamespace);
    }
}