<?php

declare(strict_types=1);

namespace NeoPHP\Component\Form\Helper\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Form\Maker\FormMaker;
use NeoPHP\Package\Orm\Maker\EntityMaker;
use NeoPHP\Package\Orm\OrmManagerInterface;
use NeoPHP\Package\Orm\Provider\OrmProvider;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\Exception\InvalidInputException;
use NeoPHP\Process\Console\IO\InputArgument;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'make:form', description: 'Generates a form class in src/Form/')]
class MakeFormCommand extends AbstractConsole
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('name', InputArgument::REQUIRED, 'The form name (the "Type" suffix is added)', null, 'Name of the form (e.g. Post, Contact)');
        $input->addArgument('entity', InputArgument::OPTIONAL, 'The entity mapped by the form (without it, the form works with an array)');
        $this->setHelp('With an entity, one field is generated per mapped property. An existing form is only replaced with --force.');
        $this->addExample('make:form Contact');
        $this->addExample('make:form Post Post');
        $this->addExample('make:form Post Post --force');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if (!$input->isArgumentProvided('name')) {
            $input->setArgument('name', $output->ask('Name of the form (e.g. Post, Contact)', null, static fn (mixed $value): string => trim((string) $value) !== '' ? trim((string) $value) : throw new InvalidInputException('A value is required.')));
        }

        if (!$input->isArgumentProvided('entity')) {
            $entities = $this->container->has(EntityMaker::class) ? $this->container->get(EntityMaker::class)->getEntities() : [];
            $entity = $output->select('Entity mapped by the form (empty for a form working with an array)', $entities, null, false);

            if (is_string($entity) && $entity !== '') {
                $input->setArgument('entity', $entity);
            }
        }
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $entity = (string) ($input->getArgument('entity') ?? '');
        $root = (string) $this->container->get('kernel.root_path');
        $orm = $this->container->has(OrmManagerInterface::class) ? $this->container->get(OrmManagerInterface::class) : null;
        $maker = new FormMaker($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Form', 'App\\Form', $orm);

        try {
            $entityClass = null;

            if ($entity !== '') {
                $entityClass = $this->resolveEntity($entity);

                if ($entityClass === null) {
                    $output->error(sprintf('The entity "%s" does not exist.', $entity));

                    return self::FAILURE;
                }
            }

            [$class, $file, $fields] = $maker->make($name, $entityClass, (bool) $input->getOption('force'));
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $output->writeln(sprintf('  <success>created</success>  %s', $file));
        $output->success(sprintf('%d field(s)%s.', count($fields), $entityClass !== null ? ' from ' . $entityClass : ''));
        $output->text(sprintf('Use it in a controller: <info>$form = $this->createForm(%s::class%s);</info>', substr($class, (int) strrpos($class, '\\') + 1), $entityClass !== null ? ', $entity' : ''));

        return self::SUCCESS;
    }

    protected function resolveEntity(string $entity): ?string
    {
        $entity = ltrim(str_replace('/', '\\', $entity), '\\');
        $namespace = 'App\\Entity';

        if ($this->container->has(OrmProvider::CONFIG_ID)) {
            $namespace = (string) ($this->container->get(OrmProvider::CONFIG_ID)['entity']['namespace'] ?? $namespace);
        }

        foreach ([$entity, $namespace . '\\' . $entity] as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}