<?php

declare(strict_types=1);

namespace NeoPHP\Package\Security\Helper\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Package\Orm\Provider\OrmProvider;
use NeoPHP\Package\Security\Maker\VoterMaker;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'make:voter', description: 'Generates a voter in src/Security/Voter/')]
class MakeVoterCommand extends AbstractConsole
{
    public function __construct(protected ContainerManagerInterface $container)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('name', InputArgument::REQUIRED, 'The voter name (the "Voter" suffix is added); the subject is the entity of the same name when it exists', null, 'Name of the voter (e.g. Post)');
        $this->addExample('make:voter Post');
        $this->addExample('make:voter Post --force');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $root = (string) $this->container->get('kernel.root_path');
        $maker = new VoterMaker($root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Security' . DIRECTORY_SEPARATOR . 'Voter', 'App\\Security\\Voter');

        try {
            [$class, $file, $attributes] = $maker->make($name, $this->subject($name), (bool) $input->getOption('force'));
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $short = substr($class, (int) strrpos($class, '\\') + 1);
        $output->writeln(sprintf('  <success>created</success>  %s', $file));
        $output->success(sprintf('Voter %s created with the attributes %s.', $short, implode(', ', $attributes)));
        $output->text(sprintf('Use them with <info>$this->denyAccessUnlessGranted(%s::EDIT, $subject)</info> in a controller or <info>is_granted(\'%s\', subject)</info> in a template.', $short, $attributes[1]));

        return self::SUCCESS;
    }

    protected function subject(string $name): ?string
    {
        $name = preg_replace('/Voter$/', '', trim(str_replace('/', '\\', $name), '\\'));
        $namespace = 'App\\Entity';

        if ($this->container->has(OrmProvider::CONFIG_ID)) {
            $namespace = (string) ($this->container->get(OrmProvider::CONFIG_ID)['entity']['namespace'] ?? $namespace);
        }

        $class = $namespace . '\\' . $name;

        return class_exists($class) ? $class : null;
    }
}